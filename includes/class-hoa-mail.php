<?php
/**
 * Parsers for the four HOA mailbox sub-pipelines (Invoice, Legal, Closing, Insurance): one email -> kind, reference number,
 * amount, due / deadline date, community + unit, and the other party (vendor, attorney, buyer / title company, carrier or vendor).
 *
 * Output keys follow the AJCore mail engine: kind, ref_no, amount, due_date, property_tag (community and unit as written),
 * property_name, tenant (the other party), facts[], label ("notice" for mail that is not work).
 *
 * Property here = community (an AJ Core customer) and, when the text names one, the unit from the Homeowners directory.
 * Pure functions of (item, text) plus the directory lookups in AJCore_HOA_Match.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_HOA_Mail {

	/** Bump when the rules change: stored mail is parsed again. */
	const VERSION = 1;

	private static function U() {
		return 'AJCore_Mail_Util';
	}

	/** Display name of the sender ("Acme Landscaping <billing@acme.com>" -> "Acme Landscaping"; else the domain's first label). */
	public static function party_of( $sender ) {
		$name = trim( preg_replace( '/<[^>]*>/', '', (string) $sender ), " \t\"'" );
		if ( '' !== $name && false === strpos( $name, '@' ) && ! preg_match( '/^(?:no-?reply|billing|accounts?|invoices?|info|admin|support)$/i', $name ) ) {
			return $name;
		}
		$d = AJCore_Mail_Util::domain_of( $sender );
		if ( '' === $d ) {
			return '';
		}
		$parts = explode( '.', $d );
		$label = count( $parts ) > 1 ? $parts[ count( $parts ) - 2 ] : $parts[0];
		return ucfirst( $label );
	}

	/** Community (+ unit) from the text; fills facts and returns [ tag, name ]. */
	private static function place( $subject, $text, array &$facts, $unit_hint = true ) {
		$comm = AJCore_HOA_Match::community_in_text( $subject . ' ' . $text );
		$unit = AJCore_HOA_Match::find_unit( $subject . ' ' . $text, $comm ? $comm['id'] : '' );
		if ( $unit && ! $comm ) {
			$cs   = AJCore_HOA_Match::communities();
			$comm = isset( $cs[ $unit['community_id'] ] ) ? $cs[ $unit['community_id'] ] : null;
		}
		$tag  = '';
		$name = '';
		if ( $comm ) {
			$name                  = $comm['name'];
			$tag                   = $comm['name'];
			$facts['community_id'] = $comm['id'];
		}
		if ( $unit ) {
			$facts['unit_id'] = $unit['id'];
			$tag              = trim( ( '' !== $unit['address'] ? $unit['address'] : $comm['name'] . ' ' . $unit['unit'] ) . ( '' !== $unit['unit'] && '' !== $unit['address'] ? ' (unit ' . $unit['unit'] . ')' : '' ) );
		}
		if ( '' === $tag ) { // no directory yet (or not in it): the association as written
			$as = AJCore_Mail_Util::association_name( $subject, $text );
			if ( '' !== $as ) {
				$tag                      = $as;
				$name                     = $as;
				$facts['community_text']  = $as;
			}
		}
		if ( $unit_hint && ! $unit ) {
			$addr = AJCore_HOA_Match::addresses_in_text( $subject . ' ' . $text );
			if ( $addr ) {
				$facts['address'] = $addr[0]; // the street address named (a unit not in the directory)
				$tag              = '' !== $tag ? $tag . ' · ' . $addr[0] : $addr[0];
			}
		}
		return array( $tag, $name );
	}

	/** The first labelled reference number: "Invoice #4821", "Case No. 24-CV-1182", "File: 55102". */
	private static function ref_after( $text, array $labels ) {
		foreach ( $labels as $l ) {
			if ( preg_match( '/' . $l . '\s*(?:number|no\.?|#|num)?\s*[:\-]?\s*#?\s*([A-Z0-9][A-Z0-9\-\/]{2,24})\b/i', (string) $text, $m ) && preg_match( '/\d/', $m[1] ) ) {
				return strtoupper( $m[1] );
			}
		}
		return '';
	}

	private static function amount( $text, array $labels ) {
		$a = AJCore_Mail_Util::amount_after( $text, $labels );
		if ( null === $a && preg_match( '/\$\s*([\d,]+\.\d{2})/', (string) $text, $m ) ) {
			$a = (float) str_replace( ',', '', $m[1] ); // the first dollar figure when nothing is labelled
		}
		return $a;
	}

	private static function finish( $kind, $ref, $amount, $due, $tag, $name, $party, array $facts, array $issues_for, $label = '' ) {
		$issues = array();
		if ( in_array( 'property', $issues_for, true ) && '' === $tag ) {
			$issues[] = 'no_property';
		}
		if ( in_array( 'ref', $issues_for, true ) && '' === $ref ) {
			$issues[] = 'no_ref';
		}
		if ( in_array( 'amount', $issues_for, true ) && null === $amount ) {
			$issues[] = 'no_amount';
		}
		if ( $issues ) {
			$facts['issues'] = $issues;
		}
		return array( 'kind' => $kind, 'ref_no' => $ref, 'amount' => $amount, 'due_date' => $due, 'property_tag' => $tag, 'property_name' => $name, 'tenant' => $party, 'facts' => $facts, 'label' => $label );
	}

	// ── Invoice: vendor invoices and utility bills for communities ──────────────

	private static $utilities = array( 'Charlotte Water' => 'charlotte water|cltwater', 'Duke Energy' => 'duke energy|duke-energy', 'Piedmont Natural Gas' => 'piedmont natural gas', 'Spectrum' => 'spectrum|charter comm', 'AT&T' => 'at&t|att\\.com', 'Waste Management' => 'waste management|wm\\.com', 'Republic Services' => 'republic services' );

	/** The vendor: from the subject ("Invoice 27083 from M and R Landscaping"), a quoted "From:" on our own replies, a utility name, else the sender. */
	private static function vendor_of( $subject, $text, $sender, $ours ) {
		$U = self::U();
		foreach ( self::$utilities as $name => $re ) {
			if ( preg_match( '/' . $re . '/i', $subject . ' ' . $sender . ' ' . substr( $text, 0, 300 ) ) ) {
				return $name;
			}
		}
		$subject = preg_replace( '/^(?:\s*(?:RE|Fwd?|FW|Automatic reply)\s*:\s*)+/i', '', $subject );
		if ( preg_match( '/\b(?:invoice|payment request|bill)\b.{0,40}?\bfrom\s+([A-Z][A-Za-z0-9&\.\', ]{2,50}?)(?=\s+-\s|\s*\(|\s+invoice|$)/i', $subject, $m ) ) {
			return trim( $m[1], " .," );
		}
		if ( preg_match( '/^([A-Z][A-Za-z0-9&\.\' ]{2,40}?(?:Companies|Inc\.?|LLC|Services|Landscaping|Pool|Group|Restoration|Construction))\s*-\s*(?:invoice|statement)/i', $subject, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/\((\s*[A-Z][A-Za-z0-9&\.\' ]{2,40}\s*)\)\s*$/', $subject, $m ) ) {
			return trim( $m[1] ); // "Payment confirmation: Invoice #50160-(DoodyCalls of Charlotte)"
		}
		if ( $ours && preg_match( '/\bFrom:\s*"?([^<"@:]{3,50}?)"?\s*(?:<[^>]*@[^>]*>)/', $text, $m ) ) {
			$n = trim( $m[1], " \"'" );
			if ( ! preg_match( '/wozig|^z-/i', $n ) ) {
				return $n; // the vendor who wrote the quoted mail we are replying to
			}
		}
		if ( preg_match( '/\b(?:Thank you,?|Sincerely,?|Regards,?)\s+((?:[A-Z][A-Za-z&\.]+\s?){1,5}(?:Restoration|Landscaping|Management|Construction|Pool Service|Services|Inc\.?|LLC))/', $text, $m ) ) {
			return trim( $m[1] );
		}
		$p = self::party_of( $sender );
		return preg_match( '/^(?:Quickbooks|Encircle|Intuit|Invoice|Customercare|Z-)/i', $p ) ? '' : $p;
	}

	/** The community an invoice is for: "Bill to Fountaingrove HOA c/o ...", "Dear Wozig Management:Fountain Grove,", else the association written. */
	private static function community_of_invoice( $subject, $text ) {
		if ( preg_match( '/\bBill to\s+([A-Z][A-Za-z0-9&\.\' ]{3,60}?)\s+c\/o\b/', $text, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/\bDear\s+(?:zz\d+\s+)?(?:WOZIG|Wozig)(?:\s+(?:Management|Team|AP))?\s*[:\-]+\s*([A-Z][A-Za-z0-9&\.\' ]{3,50}?)\s*,/', $text, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	public static function parse_invoice( array $item, $body, $html = '' ) {
		$U       = self::U();
		$subject = $U::clean( $item['subject'] );
		$text    = $U::clean( $body );
		$all     = $subject . ' ' . $text;
		$top     = $subject . ' ' . $U::top_text( $body ); // the newest message of the thread decides what it is
		$facts   = array();
		$label   = '';
		$ours    = (bool) preg_match( '/@wozig\.com$/i', $U::email_of( $item['sender'] ) );
		$stage   = '';
		$kind    = 'invoice';
		$topt    = $U::top_text( $body );
		if ( $ours && preg_match( '/^(?:Hello|Hi|Hey)?[, ]*(?:Paid|Payment (?:sent|made|issued)|We paid|Sent)\b.{0,60}?(?:via|by|through)\s+(?:ACH|direct deposit|check|wire|credit card|QuickBooks|Zelle)/i', $topt ) ) {
			$kind  = 'paid';
			$stage = 'paid'; // our own reply "Paid via ACH ... Memo: Invoice #10625": the invoice is paid
			if ( preg_match( '/\bvia\s+(ACH|direct deposit|check|wire|credit card)/i', $topt, $m ) ) {
				$facts['paid_via'] = strtolower( $m[1] );
			}
		} elseif ( preg_match( '/new secure ebill|statement(?:s)? (?:is|are) ready|your online bill is ready|e-?bill\b/i', $subject . ' ' . substr( $topt, 0, 200 ) ) ) {
			$kind = 'utility';
		} elseif ( preg_match( '/payment (?:received|confirmation)|receipt for your payment|thank you for your payment|paid in full|\breceipt\b|payment confirmation/i', $subject ) ) {
			$kind = 'receipt';
		} elseif ( preg_match( '/past due|overdue|final notice|second notice|collections?|outstanding invoices|reminder/i', $subject . ' ' . substr( $topt, 0, 200 ) ) ) {
			$kind = 'past_due';
		} elseif ( preg_match( '/\b(?:statement of account|account statement|monthly statement)\b/i', $subject ) ) {
			$kind = 'statement';
		} elseif ( preg_match( '/\b(?:quote|estimate|proposal|bid)\b/i', $subject ) ) {
			$kind = 'quote';
		} elseif ( preg_match( '/payment request|requesting payment|your invoice is ready|invoice (?:#|no|number)?\s*[0-9A-Z]{4,}|balance due|amount due|invoice\b/i', $subject . ' ' . substr( $topt, 0, 300 ) ) ) {
			$kind = 'invoice';
		} elseif ( $ours || preg_match( '/@/', $item['sender'] ) && ! preg_match( '/invoice|bill|statement|payment|due/i', $all ) ) {
			$kind  = $ours ? 'vendor_thread' : 'other';
			$label = 'other' === $kind ? 'notice' : '';
		}
		if ( self::is_noise( $subject, $text, $item['sender'] ) && ! in_array( $kind, array( 'invoice', 'utility', 'receipt', 'paid', 'past_due' ), true ) ) {
			$kind  = 'other';
			$label = 'notice';
		}
		list( $tag, $name ) = self::place( $subject, strip_tags( preg_replace( '/c\/o\s+[A-Za-z0-9 &\.]{2,40}\s+\d{1,6}\s+[A-Za-z0-9 \.]{2,40}(?:Pl|Place|St|Street|Dr|Rd|Ln|Ave|Blvd)\b\.?/i', ' ', $text ) ), $facts );
		$cb = self::community_of_invoice( $subject, $text );
		if ( '' !== $cb && ( '' === $name || ! empty( $facts['community_text'] ) ) ) {
			$name = $cb;
			$tag  = $cb;
			$facts['community_text'] = $cb;
		}
		$ref = self::ref_after( $all, array( 'invoice', 'inv', 'bill', 'statement' ) );
		$vendor = self::vendor_of( $subject, $text, $item['sender'], $ours );
		$amount = null;
		$due    = '';
		if ( 'utility' === $kind ) {
			// A utility email can list several accounts (Charlotte Water): keep each, total them.
			$accounts = array();
			if ( preg_match_all( '/(?:Customer Name\s+([A-Z][A-Z0-9 &\.\']+?),?\s+)?Account Number:?\s*([0-9][0-9\-]{5,20})\s+(?:Billing Date:?\s*[A-Za-z0-9\., ]+?\s+)?Amount Due:?\s*\$?\s*([\d,]+\.\d{2})\s+(?:Prior Balance:?\s*\$?[\d,\.]+\s+)?Due Date:?\s*([A-Za-z0-9\.\/ ,]{6,16}?\d{2,4})/i', $text, $mm, PREG_SET_ORDER ) ) {
				foreach ( $mm as $x ) {
					$accounts[] = array( 'account' => $x[2], 'customer' => trim( $x[1] ), 'amount' => (float) str_replace( ',', '', $x[3] ), 'due' => $U::date( str_replace( array( '.', ',' ), array( '', '' ), $x[4] ) ) ?: $U::date( $x[4] ) );
				}
			}
			if ( $accounts ) {
				$facts['accounts'] = array_slice( $accounts, 0, 12 );
				$amount            = array_sum( array_column( $accounts, 'amount' ) );
				$ref               = $accounts[0]['account'];
				$due               = min( array_filter( array_column( $accounts, 'due' ) ) ?: array( '' ) );
				if ( count( $accounts ) > 1 ) {
					$facts['account_count'] = count( $accounts );
				}
			}
		}
		if ( null === $amount ) {
			$amount = self::amount( $topt, array( 'in the amount of', 'BALANCE DUE', 'Amount Due', 'Total Due', 'Balance Due', 'Total Amount', 'Invoice Total', 'Total' ) );
			if ( null === $amount && in_array( $kind, array( 'invoice', 'receipt', 'past_due' ), true ) ) {
				$amount = AJCore_Mail_Util::amount_after( $text, array( 'Amount Due', 'Total Due', 'Balance Due', 'Invoice Total', 'Total' ) );
			}
		}
		if ( '' === $due ) {
			$due = $U::date_after( $topt, array( 'Due Date', 'Due', 'Payment Due', 'Pay by', 'remit by', 'Due on', 'payable by' ) );
		}
		if ( preg_match( '/\bwork order\s*(?:#|no\.?|number)?\s*:?\s*#?\s*(\d{2,10})\b/i', $all, $m ) ) {
			$facts['work_order'] = $m[1]; // ties the invoice to the work order request
		}
		if ( preg_match( '/\b(?:PO|purchase order)\s*(?:#|no\.?|number)?\s*:?\s*#?\s*([A-Z0-9\-]{3,16})\b/i', $all, $m ) && preg_match( '/\d/', $m[1] ) ) {
			$facts['po'] = strtoupper( $m[1] );
		}
		if ( preg_match( '/\bnet\s*(\d{1,3})\b/i', $text, $m ) ) {
			$facts['terms'] = 'Net ' . $m[1];
		}
		if ( '' !== $name ) {
			$facts['community_key'] = preg_replace( '/(?:homeowners?|association|assoc|hoa|inc|the|of|mecklenburg)/', '', preg_replace( '/[^a-z]/', '', strtolower( $name ) ) );
		}
		if ( 'utility' === $kind && ! empty( $facts['accounts'][0]['customer'] ) ) {
			$name = $tag = AJCore_Mail_Util::association_name( '', $facts['accounts'][0]['customer'] . ' HOA' ) ?: $facts['accounts'][0]['customer'];
		} elseif ( 'utility' === $kind && '' !== $tag && empty( $facts['community_text'] ) ) {
			$name = $tag = ''; // an address in a utility mail is the utility's own
		}
		// A vendor or utility account remembered for a community (learned from earlier mail) fills in when this mail names none.
		$learn = array();
		if ( class_exists( 'AJCore_Mail_Pipelines' ) ) {
			if ( '' === $tag ) {
				foreach ( array( array( 'ref', $ref ), array( 'vendor', strtolower( $vendor ) ) ) as $k ) {
					if ( '' !== (string) $k[1] ) {
						$l = AJCore_Mail_Pipelines::link_lookup( 'hoa_invoice', $k[0], $k[1] );
						if ( $l && '' !== (string) $l['property_tag'] ) {
							$tag  = (string) $l['property_tag'];
							$name = (string) $l['property_name'];
							break;
						}
					}
				}
			} elseif ( '' !== $name ) {
				if ( '' !== $ref && in_array( $kind, array( 'utility' ), true ) ) {
					$learn[] = array( 'type' => 'ref', 'key' => $ref, 'property_tag' => $tag, 'property_name' => $name, 'tenant' => '' );
				}
				if ( '' !== $vendor && ! in_array( $kind, array( 'utility' ), true ) ) {
					$learn[] = array( 'type' => 'vendor', 'key' => strtolower( $vendor ), 'property_tag' => $tag, 'property_name' => $name, 'tenant' => '' );
				}
			}
		}
		$more = in_array( $kind, array( 'invoice', 'past_due', 'statement', 'utility' ), true ) ? array( 'property', 'amount' ) : ( 'receipt' === $kind ? array( 'amount' ) : array() );
		if ( in_array( $kind, array( 'invoice', 'past_due', 'statement' ), true ) ) {
			$more[] = 'ref';
		}
		if ( 'past_due' === $kind ) {
			$facts['urgent'] = true;
		}
		$out = self::finish( $kind, $ref, $amount, $due, $tag, $name, $vendor, $facts, $more, $label );
		$out['learn'] = $learn;
		if ( '' !== $stage ) {
			$out['stage'] = $stage;
		}
		return $out;
	}

	/** Mail that is not work: newsletters, webinars, meeting invites, sales outreach, account / login notices. */
	private static function is_noise( $subject, $text, $sender ) {
		$d = AJCore_Mail_Util::domain_of( $sender );
		if ( preg_match( '/newsletter|webinar|we saved you a spot|platform demo|save the date|register now|unsubscribe|view (?:it )?in your browser|resources? (?:and|&) expertise|risk management resources|protect your (?:business|mission)|hard freeze|recall\b|we value your opinion|take our survey|new event created/i', $subject . ' ' . substr( $text, 0, 400 ) . ' ' . substr( $text, -300 ) ) ) {
			return true;
		}
		return (bool) preg_match( '/zohocalendar\.com$|marketing@|^no-?reply@.*(?:news|offers)/i', $d . ' ' . AJCore_Mail_Util::email_of( $sender ) );
	}

	/** Date "11/20" (no year) written after a word, with the year of the email ("CLOSING 11/20"). */
	private static function short_date_after( $text, $label, $received ) {
		if ( preg_match( '#(?i:' . $label . ')\s*[:\-]?\s*(\d{1,2})/(\d{1,2})(?!\d|/)#', (string) $text, $m ) ) {
			$y  = (int) substr( (string) $received, 0, 4 );
			$y  = $y > 2000 ? $y : (int) gmdate( 'Y' );
			$d  = AJCore_Mail_Util::date( sprintf( '%02d/%02d/%04d', $m[1], $m[2], $y ) );
			if ( '' !== $d && $d < substr( (string) $received, 0, 10 ) ) {
				$d = AJCore_Mail_Util::date( sprintf( '%02d/%02d/%04d', $m[1], $m[2], $y + 1 ) );
			}
			return $d;
		}
		return '';
	}

	// ── Legal: attorney and court mail ──────────────────────────────────────────

	public static function parse_legal( array $item, $body, $html = '' ) {
		$U       = self::U();
		$subject = $U::clean( $item['subject'] );
		$text    = $U::clean( $body );
		$all     = $subject . ' ' . $text;
		$top     = $subject . ' ' . $U::top_text( $body ); // the newest message of the thread decides what it is
		$facts   = array();
		$label   = '';
		$kind    = 'counsel';
		if ( self::is_noise( $subject, $text, $item['sender'] ) || preg_match( '/axela|partnership agreement|strategic implementation|schedule b|easy collect/i', $top ) ) {
			$kind  = 'vendor';
			$label = 'notice'; // collections-software sales, meeting invites, newsletters
		} elseif ( preg_match( '/bankruptcy|chapter (?:7|11|13)\b/i', $top ) ) {
			$kind = 'bankruptcy';
		} elseif ( preg_match( '/summons|subpoena|docket|magistrate|small claims|\b\d{2}\s?CV\s?\d{4,8}|\bv\.\s|\bvs\.?\s|proposed settlement|settlement agreement|complaint\b/i', $top ) ) {
			$kind = preg_match( '/invoice|fees for legal services|statement of services/i', $subject . ' ' . substr( $text, 0, 300 ) ) ? 'legal_bill' : 'litigation';
		} elseif ( preg_match( '/updated ledger|ledger for|payment ledger|account ledger|payoff (?:statement|figures)|balance owed/i', $top ) ) {
			$kind = 'ledger_request'; // a collections attorney asking for the homeowner's ledger
		} elseif ( preg_match( '/foreclos|notice of lis pendens|claim of lien|notice of intent to lien|start the lien process|lien\b/i', $top ) ) {
			$kind = preg_match( '/foreclos/i', $top ) ? 'foreclosure' : 'lien';
		} elseif ( preg_match( '/demand letter|collection|delinquen|past due assessments?|payment plan/i', $top ) ) {
			$kind = 'collection';
		} elseif ( preg_match( '/violation|covenant|cc&?rs|enforcement|cease and desist|notice of hearing|fines?\b/i', $top ) ) {
			$kind = 'enforcement';
		} elseif ( preg_match( '/invoice|statement of services|billing|retainer|legal fees/i', $subject ) ) {
			$kind = 'legal_bill';
		}
		list( $tag, $name ) = self::place( $subject, $text, $facts );
		$ref = '';
		if ( preg_match( '/\b(\d{2}\s?CV\s?\d{4,8}(?:-\d+)?)\b/i', $all, $m ) ) {
			$ref = strtoupper( str_replace( ' ', '', $m[1] ) ); // NC civil case number
		}
		if ( '' === $ref ) {
			$ref = self::ref_after( $all, array( 'case', 'matter', 'file', 'docket', 'our file', 'your file' ) );
		}
		$amount = self::amount( $U::top_text( $body ), array( 'Balance Due', 'Amount Due', 'Total Due', 'amount owed', 'Payoff', 'Total Amount', 'Legal Fees', 'Total' ) );
		if ( in_array( $kind, array( 'vendor', 'counsel', 'ledger_request' ), true ) ) {
			$amount = null; // dollar figures in these are not a balance
		}
		$due = $U::date_after( $U::top_text( $body ), array( 'Hearing Date', 'hearing on', 'Respond by', 'Response due', 'Deadline', 'no later than', 'Due Date', 'paid in full by', 'Pay by', 'pay before', 'on or before' ) );
		if ( '' === $due ) {
			$due = self::short_date_after( $text, 'by\s+(?:Fri(?:day)?|Mon(?:day)?|Tue(?:s|sday)?|Wed(?:nesday)?|Thu(?:rs|rsday)?|Sat(?:urday)?|Sun(?:day)?)', $item['received_at'] ?? '' );
		}
		if ( preg_match( '/\b(?:homeowner|owner)\s+([A-Z][A-Za-z\.\' ]{3,40}?)\s+(?:located|residing|of|at)\b/', $text, $m ) ) {
			$facts['owner'] = trim( $m[1] );
		}
		if ( preg_match( '/\b(?:His|Her|Their)\s+HOA\s+is\s+(?:the\s+)?([A-Z][A-Za-z0-9&\.\', ]{4,70}?)\.?\s+(?:Can|Please|Thank|We|$)/', $text, $m ) ) {
			$facts['association'] = trim( $m[1], " ." );
			if ( empty( $facts['community_text'] ) && '' === $name ) {
				$name = $tag = $facts['association'];
			}
		}
		$owner = AJCore_HOA_Match::owner_in_text( $all );
		if ( $owner ) {
			$facts['owner'] = $owner['name'];
			if ( empty( $facts['unit_id'] ) ) {
				$u = AJCore_HOA_Match::unit_by_id( $owner['unit_id'] );
				if ( $u ) {
					$facts['unit_id'] = $u['id'];
					$cs               = AJCore_HOA_Match::communities();
					$name             = isset( $cs[ $u['community_id'] ] ) ? $cs[ $u['community_id'] ]['name'] : $name;
					$tag              = '' !== $u['address'] ? $u['address'] : $name . ' ' . $u['unit'];
				}
			}
		}
		$party = self::party_of( $item['sender'] );
		$hot   = array( 'bankruptcy', 'foreclosure', 'litigation', 'lien' );
		if ( in_array( $kind, array_merge( $hot, array( 'collection', 'enforcement', 'ledger_request' ) ), true ) ) {
			$facts['urgent'] = in_array( $kind, $hot, true ) || '' !== $due;
		}
		$needs = 'legal_bill' === $kind ? array( 'ref', 'amount' ) : ( in_array( $kind, array( 'litigation', 'foreclosure', 'lien', 'collection', 'enforcement', 'ledger_request', 'bankruptcy' ), true ) ? array( 'property' ) : array() );
		return self::finish( $kind, $ref, $amount, $due, $tag, $name, $party, $facts, $needs, $label );
	}

	// ── Closing: resales, HOA information requests, payoffs, ownership transfers ─

	public static function parse_closing( array $item, $body, $html = '' ) {
		$U       = self::U();
		$subject = $U::clean( $item['subject'] );
		$text    = $U::clean( $body );
		$all     = $subject . ' ' . $text;
		$top     = $subject . ' ' . $U::top_text( $body ); // the newest message of the thread decides what it is
		$facts   = array();
		$label   = '';
		$kind    = 'request';
		$ours    = preg_match( '/@wozig\.com$/i', $U::email_of( $item['sender'] ) );
		if ( self::is_noise( $subject, $text, $item['sender'] ) ) {
			$kind  = 'other';
			$label = 'notice';
		} elseif ( preg_match( '/new invoice from wozig|invoice #?[0-9A-Z]{6,}|total due \$|pay this invoice/i', $top ) ) {
			$kind = 'invoice'; // our Stripe invoice for the closing package
		} elseif ( preg_match( '/terminated|contract (?:was )?terminated|cancel(?:l)?ed|delete this order|no longer needed/i', $subject . ' ' . substr( $text, 0, 300 ) ) ) {
			$kind = 'terminated';
		} elseif ( ! $ours && strlen( $text ) < 700 && preg_match( '/^(?:received|thank you|thanks|will do|got it|noted|ok|okay|confirmed|great)\b[^?]{0,60}$/i', substr( $U::top_text( $body ), 0, 90 ) ) ) {
			$kind  = 'ack';
			$label = 'notice'; // "Received. Thank you!"
		} elseif ( preg_match( '/recorded deed|buyer info(?:rmation)? form|new homeowners? (?:worksheet|questionnaire)|closing statement|closed on\s+(?:\d|[A-Z][a-z]+\.? \d)|has closed|new owner\b|ownership (?:change|transfer)/i', $top ) && ! $ours ) {
			$kind = 'transfer'; // the other side sends the deed / buyer form: the unit's owner changes
		} elseif ( preg_match( '/\b(?:was |has been |is )?paid\b|payment (?:sent|made|received|confirmation)|wire (?:sent|initiated)|check (?:was )?(?:sent|mailed)/i', substr( $U::top_text( $body ), 0, 250 ) ) && ! $ours ) {
			$kind = 'payment';
		} elseif ( preg_match( '/proceed with|please proceed|go ahead|we approve|approved\b|charge the (?:document )?fee/i', $top ) && ! $ours ) {
			$kind = 'approval'; // the requester accepts the fee / turnaround
		} elseif ( preg_match( '/status of|checking (?:on )?the status|any update|following up|follow up|still waiting|never received|URGENT|contingency date|closing (?:is )?(?:tomorrow|today)/i', $top ) && ! $ours ) {
			$kind = 'status_check';
		} elseif ( preg_match( '/\?|could you (?:please )?(?:review|confirm|advise|clarify)|please (?:confirm|advise|review|remove|correct)|incorrect|remove (?:this|the)/i', substr( $U::top_text( $body ), 0, 500 ) ) && ! $ours ) {
			$kind = 'question'; // a dispute or clarification about the statement / charges
		} elseif ( $ours && preg_match( '/fee schedule|available documents|attached|documents attached|please find the closing documents/i', $top ) ) {
			$kind = 'docs_sent';
		} elseif ( $ours ) {
			$kind = 'reply';
		} elseif ( preg_match( '/lender|questionnaire|condo questionnaire|certification/i', $top ) ) {
			$kind = 'lender_request';
		} elseif ( preg_match( '/payoff|statement of unpaid|unpaid assessments|balance statement|hoa statement|resale|estoppel|hoa information|closing documents|hoa query|resale docs|association documents/i', $top ) ) {
			$kind = 'request';
		}
		list( $tag, $name ) = self::place( $subject, $text, $facts );
		$ref = '';
		if ( preg_match( '/\binvoice\s*#?\s*([0-9A-Z]{6,}(?:-\d+)?)/i', $all, $m ) && preg_match( '/\d/', $m[1] ) ) {
			$ref = strtoupper( $m[1] );
		} elseif ( preg_match( '/#([0-9A-Z]{6,}-\d{3,5})\b/', $all, $m ) ) {
			$ref = strtoupper( $m[1] ); // Stripe: "New invoice from Wozig #9JCYMFSK-0005"
		}
		if ( '' === $ref ) {
			$ref = self::ref_after( $all, array( 'order', 'file', 'escrow', 'reference', 'ref', 'loan', 'ObjectID' ) );
		}
		$amount = self::amount( $U::top_text( $body ), array( 'Total due', 'Amount Due', 'Statement of Unpaid Assessments', 'Payoff', 'Transfer Fee', 'Fee', 'Total' ) );
		if ( in_array( $kind, array( 'status_check', 'request', 'terminated', 'lender_request', 'ack', 'question', 'approval', 'payment', 'transfer' ), true ) ) {
			$amount = null;
		}
		$due = $U::date_after( $U::top_text( $body ), array( 'Closing Date', 'closing on', 'closed on', 'Settlement Date', 'Needed by', 'Due' ) );
		if ( '' === $due ) {
			$due = self::short_date_after( $subject . ' ' . $text, 'closing', $item['received_at'] ?? '' );
		}
		if ( preg_match( '/\b(?i:buyer|purchaser)s?\s*:?\s*([A-Z][A-Za-z\.\' ]{2,40}?)(?=\s+(?:and|,|\.|seller|property|closing|\()|$)/', $text, $m ) ) {
			$facts['buyer'] = trim( $m[1], " .," );
		}
		if ( preg_match( '/\$\s*(\d{2,4})\s*(?:\+|Expedited|Standard)/i', $text, $m ) || preg_match( '/Expedited Processing:\s*\+?\$\s*(\d+)/i', $text, $m ) ) {
			$facts['expedite_fee'] = (float) $m[1];
		}
		if ( preg_match( '/expedit|rush|urgent|48 hours/i', $all ) ) {
			$facts['rush'] = true;
		}
		$party = self::party_of( $item['sender'] );
		if ( 'transfer' === $kind ) {
			$facts['record_sale'] = true; // Homeowners > Record a sale
		}
		if ( in_array( $kind, array( 'status_check', 'question' ), true ) || ! empty( $facts['rush'] ) ) {
			$facts['urgent'] = true;
		}
		$needs = in_array( $kind, array( 'request', 'invoice', 'transfer', 'status_check', 'docs_sent', 'lender_request' ), true ) ? array( 'property' ) : array();
		if ( 'invoice' === $kind ) {
			$needs[] = 'amount';
		}
		return self::finish( $kind, $ref, $amount, $due, $tag, $name, $party, $facts, $needs, $label );
	}

	// ── Insurance: master policies, payments, certificates, claims ──────────────

	public static function parse_insurance( array $item, $body, $html = '' ) {
		$U       = self::U();
		$subject = $U::clean( $item['subject'] );
		$text    = $U::clean( $body );
		$all     = $subject . ' ' . $text;
		$top     = $subject . ' ' . $U::top_text( $body ); // the newest message of the thread decides what it is
		$facts   = array();
		$label   = '';
		$kind    = 'other';
		$tt = $U::top_text( $body );
		if ( preg_match( '/hard freeze|weather guidance|guidance for policyholders|winter storm|recall\b|risk management resources|resources (?:and|&) expertise|solutions that protect|we value your opinion|take (?:a|our) (?:brief )?survey|heat stress|cyber safety|newsletter|webinar|protect your (?:business|mission)|rate (?:change|increase) (?:notice|information)/i', $subject ) ) {
			$kind  = 'promo';
			$label = 'notice';
		} elseif ( preg_match( '/confirm your email|delivery preference|paperless|security method|welcome to the online portal|new sign-on|sign-on notification|activation code|verify your identity|online access|enroll|unenroll|automatic payment setup|autopay|payment method update|profile (?:has been )?updated|account (?:created|confirmation)|one-time|password|you.ve set up an ach/i', $subject ) ) {
			$kind  = 'account';
			$label = 'notice';
		} elseif ( preg_match( '/receipt for your payment|payment (?:received|confirmation|applied|scheduled)|ach debit authorization|thank you for your payment|online payment confirmation|payment to /i', $subject ) ) {
			$kind = 'payment';
		} elseif ( preg_match( '/payment confirmation you requested|payment (?:amount|recieved on|received on)\s*:/i', substr( $tt, 0, 600 ) ) ) {
			$kind = 'payment';
		} elseif ( preg_match( '/credit card processing fee|fee\b.*effective|rate change/i', $subject ) ) {
			$kind  = 'info';
			$label = 'notice';
		} elseif ( preg_match( '/invoice for (?:honeycomb )?polic|your (?:honeycomb )?invoice|is due\b|premium (?:due|notice)|installment/i', $subject ) ) {
			$kind = 'premium';
		} elseif ( preg_match( '/rescission|rescind|cancell?ation (?:notice|date)|notice of cancell?ation|will be cancell?ed|has been cancell?ed|avoid non-?payment|non-?renew|lapse[d]?\b/i', $subject . ' ' . substr( $tt, 0, 400 ) ) && ! preg_match( '/certificate of insurance|\bCOI\b/i', $subject ) ) {
			$kind = preg_match( '/non-?renew/i', $subject . ' ' . substr( $tt, 0, 400 ) ) ? 'nonrenewal' : 'cancel_notice';
		} elseif ( preg_match( '/amount of the debt|debt collector|collection agency|recovery services|referred to collections|time sensitive/i', $subject . ' ' . $tt ) ) {
			$kind = 'collection'; // an insurer's premium-audit debt sent to collections
		} elseif ( preg_match( '/\bclaim (?:number|no|#|filed|received|adjuster)|loss notice|adjuster|date of loss|incident report|notice of loss|file a claim/i', $subject . ' ' . substr( $tt, 0, 400 ) ) ) {
			$kind = 'claim';
		} elseif ( preg_match( '/certificate of insurance|\bCOI\b|certificate holder|additional insured|evidence of (?:insurance|coverage)/i', $subject . ' ' . $tt ) ) {
			$kind = 'certificate';
		} elseif ( preg_match( '/notice of renewal|renewal|renew\b|proposal|quote|binder|declarations?|policy documents|endorsement|welcome aboard/i', $subject . ' ' . substr( $tt, 0, 600 ) ) ) {
			$kind = preg_match( '/binder/i', $subject ) ? 'binder' : 'renewal';
		} elseif ( self::is_noise( $subject, $text, $item['sender'] ) ) {
			$kind  = 'promo';
			$label = 'notice';
		}
		if ( 'other' === $kind && ! preg_match( '/no-?reply|donotreply|noreply|notification|alerts?@/i', $item['sender'] ) ) {
			$kind = 'agency'; // a person (agent / broker / manager) writing
		}
		list( $tag, $name ) = self::place( $subject, $text, $facts, false );
		if ( preg_match( '/\b(?:Named Insured|Account holder)\s*:\s*([A-Z][A-Za-z0-9&\.\', ]{4,70}?)(?=\s+(?:Property Address|Insured Location|Billing|Policy|Payment|$))/', $text, $m ) ) {
			$facts['insured'] = trim( $m[1], " .," );
			if ( '' === $name || ! empty( $facts['community_text'] ) ) {
				$name = $tag = AJCore_Mail_Util::title_name( strtolower( $facts['insured'] ) ) === $facts['insured'] ? $facts['insured'] : $facts['insured'];
			}
		}
		if ( preg_match( '/\b(?:Property Address|Insured Location)\s*:\s*([0-9][^:]{4,80}?)(?=\s+(?:Payment|Policy|Named|Renewing|If|Thank|$))/', $text, $m ) ) {
			$facts['location'] = trim( $m[1], " .," );
		}
		$policies = array();
		if ( preg_match_all( '/\b(?:polic(?:y|ies)|certificate)\s*(?:number|no\.?|#)?\s*:?\s*#?\s*((?=[A-Z0-9\-]{6,26}\b)[A-Z\-]*\d[A-Z0-9\-]*)\b/i', $all, $m ) ) { // a policy number always has a digit
			foreach ( $m[1] as $p ) {
				$policies[] = strtoupper( $p );
			}
		}
		if ( preg_match_all( '/\b([A-Z]{2,6}\d{8,14}|[A-Z]\d{7}-[A-Z]{2,4})\b/', $all, $m3 ) ) {
			foreach ( $m3[1] as $p ) {
				$policies[] = strtoupper( $p ); // "ACPBP012282978119", "B4281635-BIP"
			}
		}
		if ( preg_match_all( '/\b(\d?-?HNY-[A-Z]{2}-\d{2}-\d{6,10}(?:-\d{2})?)\b/i', $all, $m2 ) ) {
			foreach ( $m2[1] as $p ) {
				$policies[] = strtoupper( $p );
			}
		}
		$policies = array_values( array_unique( $policies ) );
		$ref      = $policies ? $policies[0] : '';
		if ( count( $policies ) > 1 ) {
			$facts['policy_nos'] = array_slice( $policies, 0, 6 );
		}
		$amount = self::amount( $U::top_text( $body ), array( 'policy premium of', 'Total Premium', 'Annual Premium', 'Payment Amount', 'Amount of the Debt', 'Amount Paid', 'Amount Due', 'Installment', 'Total Due', 'Premium', 'Amount', 'Total' ) );
		$due    = $U::date_after( $U::top_text( $body ), 'cancel_notice' === $kind || 'nonrenewal' === $kind
			? array( 'Effective Date of Cancellation', 'Cancellation Date', 'cease on', 'effective', 'Payment Du\\w* Date', 'due date of', 'due on' )
			: array( 'Renewing Policy Term', 'Policy Period', 'Expiration Date', 'Expires', 'Renewal Date', 'Payment Du\\w* Date', 'Due Date', 'due date of', 'Payment Date', 'Debit Date', 'Policy Expiration', 'is due on', 'due on', 'coming up on', 'effective' ) );
		if ( preg_match( '/\b(?:general liability|directors? (?:and|&) officers?|D&O|crime|fidelity|property|umbrella|workers.? comp\w*|flood|earthquake|cyber)\b/i', $all, $m ) ) {
			$facts['coverage'] = ucwords( strtolower( $m[0] ) );
		}
		$party = self::party_of( $item['sender'] );
		if ( 'certificate' === $kind && preg_match( '/\b(?:insured|named insured)\s*:?\s*([A-Z][A-Za-z0-9&\.\', ]{3,50}?)(?=\s+(?:policy|certificate|address|producer|\.)|$)/', $text, $m ) ) {
			$facts['insured'] = trim( $m[1] );
		}
		if ( in_array( $kind, array( 'cancel_notice', 'nonrenewal', 'collection' ), true ) ) {
			$facts['urgent'] = true;
		}
		$needs = in_array( $kind, array( 'renewal', 'cancel_notice', 'nonrenewal', 'premium', 'binder', 'certificate' ), true ) ? array( 'ref' ) : array();
		if ( in_array( $kind, array( 'premium', 'payment' ), true ) ) {
			$needs[] = 'amount';
		}
		return self::finish( $kind, $ref, $amount, $due, $tag, $name, $party, $facts, $needs, $label );
	}
}
