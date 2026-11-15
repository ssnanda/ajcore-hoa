<?php
/**
 * Matches mailbox text to the HOA directory (Client Portal > Homeowners): community > unit > owners.
 *
 * - community_in_text(): the one community whose name appears in the text ("Willow Creek HOA" / "Willow Creek Homeowners Association").
 * - find_unit(): the unit named by a street address ("412 Oak Ln") or "Unit 12" / "Lot 5", inside the community when known.
 * - owner_in_text(): a current owner whose full name appears in the text.
 *
 * Read-only. Cached per request.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_HOA_Match {

	private static $communities = null;
	private static $units       = null;
	private static $owners      = null;

	/** Words of a name / address, lower case, street types written one way. */
	public static function tokens( $s ) {
		$s   = strtolower( (string) $s );
		$s   = preg_replace( '/[^a-z0-9 ]+/', ' ', $s );
		$abb = array( 'road' => 'rd', 'street' => 'st', 'drive' => 'dr', 'avenue' => 'ave', 'lane' => 'ln', 'court' => 'ct', 'boulevard' => 'blvd', 'circle' => 'cir', 'place' => 'pl', 'parkway' => 'pkwy', 'terrace' => 'ter', 'trail' => 'trl' );
		$out = array();
		foreach ( preg_split( '/\s+/', trim( $s ) ) as $w ) {
			if ( '' !== $w ) {
				$out[] = isset( $abb[ $w ] ) ? $abb[ $w ] : $w;
			}
		}
		return $out;
	}

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'aj_hoa_' . $name;
	}

	/** id => name of the communities (AJ Core customers). */
	public static function communities() {
		if ( null !== self::$communities ) {
			return self::$communities;
		}
		self::$communities = array();
		if ( class_exists( 'AJCore_HOA_Rest' ) && method_exists( 'AJCore_HOA_Rest', 'community_names' ) ) {
			foreach ( (array) AJCore_HOA_Rest::community_names() as $id => $name ) {
				self::$communities[ (string) $id ] = array( 'id' => (string) $id, 'name' => (string) $name, 'tokens' => self::tokens( preg_replace( '/\b(?:homeowners?|owners?|association|assoc|hoa|poa|inc|llc|community|condominiums?|condos?)\b/i', ' ', (string) $name ) ), 'full' => self::tokens( $name ) );
			}
		}
		return self::$communities;
	}

	/** The one community named in the text (HOA / Association words ignored), or null. Names of one word need 5+ letters. */
	public static function community_in_text( $text ) {
		$hay  = ' ' . implode( ' ', self::tokens( $text ) ) . ' ';
		$hits = array();
		foreach ( self::communities() as $c ) {
			$n = implode( ' ', $c['tokens'] );
			if ( '' === $n || ( 1 === count( $c['tokens'] ) && strlen( $n ) < 5 ) ) {
				continue;
			}
			if ( false !== strpos( $hay, ' ' . $n . ' ' ) ) {
				$hits[] = $c;
			}
		}
		if ( count( $hits ) > 1 ) { // keep the most specific (longest) name; ambiguous when two share the length
			usort( $hits, static function ( $a, $b ) {
				return strlen( implode( ' ', $b['tokens'] ) ) <=> strlen( implode( ' ', $a['tokens'] ) );
			} );
			if ( strlen( implode( ' ', $hits[0]['tokens'] ) ) === strlen( implode( ' ', $hits[1]['tokens'] ) ) ) {
				return null;
			}
		}
		return $hits ? $hits[0] : null;
	}

	private static function units() {
		if ( null !== self::$units ) {
			return self::$units;
		}
		global $wpdb;
		self::$units = array();
		foreach ( (array) $wpdb->get_results( 'SELECT id, community_id, unit, address FROM ' . self::t( 'properties' ) . ' LIMIT 20000' ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$units[ (int) $r->id ] = array( 'id' => (int) $r->id, 'community_id' => (string) $r->community_id, 'unit' => (string) $r->unit, 'address' => (string) $r->address, 'tokens' => self::tokens( $r->address ), 'unit_tokens' => self::tokens( $r->unit ) );
		}
		return self::$units;
	}

	/** Street addresses written in text ("412 Oak Ln", "412 Oak Lane"). */
	public static function addresses_in_text( $text ) {
		$out = array();
		if ( preg_match_all( '/\b(\d{1,6})\s+((?:[A-Za-z][A-Za-z\.\']*\s+){0,3}?(?:Road|Rd|Street|St|Drive|Dr|Avenue|Ave|Lane|Ln|Court|Ct|Boulevard|Blvd|Circle|Cir|Place|Pl|Parkway|Pkwy|Way|Trail|Trl|Terrace|Ter)\b\.?)/i', (string) $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$out[] = trim( preg_replace( '/\s+/', ' ', $x[1] . ' ' . $x[2] ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The unit named in the text: by street address, else "Unit / Lot / Apt / # N" inside the (known) community.
	 * Returns [ id, community_id, unit, address ] or null when none or several match.
	 */
	public static function find_unit( $text, $community_id = '' ) {
		$units = self::units();
		$hits  = array();
		foreach ( self::addresses_in_text( $text ) as $cand ) {
			$want = self::tokens( $cand );
			foreach ( $units as $u ) {
				if ( '' !== $community_id && $u['community_id'] !== (string) $community_id ) {
					continue;
				}
				if ( $u['tokens'] && ! array_diff( $want, $u['tokens'] ) && ! array_diff( array_slice( $u['tokens'], 0, 3 ), $want ) ) {
					$hits[ $u['id'] ] = $u;
				}
			}
			if ( 1 === count( $hits ) ) {
				return array_values( $hits )[0];
			}
		}
		if ( '' !== $community_id && preg_match( '/\b(?:unit|lot|apt|apartment|suite|ste|#)\s*#?\s*([A-Za-z]?\d{1,4}[A-Za-z]?)\b/i', (string) $text, $m ) ) {
			$want = strtolower( $m[1] );
			$by   = array();
			foreach ( $units as $u ) {
				if ( $u['community_id'] === (string) $community_id && strtolower( trim( $u['unit'] ) ) === $want ) {
					$by[] = $u;
				}
			}
			if ( 1 === count( $by ) ) {
				return $by[0];
			}
		}
		return 1 === count( $hits ) ? array_values( $hits )[0] : null;
	}

	/** A current owner whose full name is in the text (2+ words), when exactly one: [ id, unit_id, name ]. */
	public static function owner_in_text( $text ) {
		if ( null === self::$owners ) {
			global $wpdb;
			self::$owners = array();
			foreach ( (array) $wpdb->get_results( 'SELECT id, property_id, owner_name FROM ' . self::t( 'owners' ) . ' WHERE end_date IS NULL LIMIT 20000' ) as $o ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$tok = self::tokens( $o->owner_name );
				if ( count( $tok ) >= 2 ) {
					self::$owners[] = array( 'id' => (int) $o->id, 'unit_id' => (int) $o->property_id, 'name' => (string) $o->owner_name, 'needle' => ' ' . implode( ' ', $tok ) . ' ' );
				}
			}
		}
		$hay  = ' ' . implode( ' ', self::tokens( $text ) ) . ' ';
		$hits = array();
		foreach ( self::$owners as $o ) {
			if ( false !== strpos( $hay, $o['needle'] ) ) {
				$hits[ $o['unit_id'] ] = $o;
			}
		}
		return 1 === count( $hits ) ? array_values( $hits )[0] : null;
	}

	/** A unit by id: [ id, community_id, unit, address ] or null. */
	public static function unit_by_id( $id ) {
		$u = self::units();
		return isset( $u[ (int) $id ] ) ? $u[ (int) $id ] : null;
	}
}
