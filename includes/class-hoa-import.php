<?php
/**
 * Spreadsheet import / periodic refresh of units and homeowners for one community.
 *
 * Sources (PayHOA export, generic CSV/Excel) only differ in how column headings are recognised; everything else is
 * shared: file -> canonical rows -> a plan (what would change) -> apply. The plan is always shown before anything is
 * written, and nothing is ever deleted: owners who are no longer listed are moved to "previous" with today's date.
 *
 *   GET  /ops/hoa/import/sources
 *   POST /ops/hoa/import/preview   multipart: file | token, source, community_id, mode (update|append), mapping (JSON, optional)
 *   POST /ops/hoa/import/apply     { token }
 *
 * Modes
 *   append  adds new units and new owners only; never changes or ends anything that exists.
 *   update  also refreshes contact details of owners it recognises and moves owners who are no longer listed
 *           (for a unit the file lists owners for) to previous owners as of today.
 *
 * Owner matching inside a unit (best score wins, minimum 50): same full name 100; same last name + same first
 * initial 90; same email 80; same phone 70; same last name + same mailing address 60; the unit's only current owner
 * and the file's only owner share a last name 50 (reported as "renamed" so it is visible in the preview).
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_HOA_Import {

	const TTL = 3600;

	/** Canonical fields, label, and heading aliases (compared after lower-casing and dropping punctuation). */
	private static function fields() {
		return array(
			'unit'            => array( 'Unit / lot', array( 'unit', 'unitnumber', 'unitno', 'unit#', 'lot', 'lotnumber', 'lotno', 'lot#', 'property', 'propertyid', 'suite', 'apt', 'apartment' ) ),
			'address'         => array( 'Property address', array( 'address', 'propertyaddress', 'streetaddress', 'street', 'homeaddress', 'siteaddress', 'unitaddress', 'property address 1', 'address1' ) ),
			'owner_name'      => array( 'Owner name', array( 'owner', 'ownername', 'owners', 'homeowner', 'homeownername', 'homeowners', 'name', 'fullname', 'primaryowner', 'resident', 'member', 'membername', 'contactname' ) ),
			'first_name'      => array( 'First name', array( 'firstname', 'first', 'givenname', 'ownerfirstname', 'homeownerfirstname' ) ),
			'last_name'       => array( 'Last name', array( 'lastname', 'last', 'surname', 'familyname', 'ownerlastname', 'homeownerlastname' ) ),
			'email'           => array( 'Email', array( 'email', 'emailaddress', 'primaryemail', 'owneremail', 'email1' ) ),
			'phone'           => array( 'Phone', array( 'phone', 'phonenumber', 'mobile', 'cell', 'cellphone', 'primaryphone', 'ownerphone', 'phone1' ) ),
			'mailing_address' => array( 'Mailing address', array( 'mailingaddress', 'mailing', 'billingaddress', 'mailaddress', 'mailingstreet', 'mailingaddress1' ) ),
			'mail_city'       => array( 'Mailing city', array( 'mailingcity', 'billingcity', 'mailcity' ) ),
			'mail_state'      => array( 'Mailing state', array( 'mailingstate', 'billingstate', 'mailstate' ) ),
			'mail_zip'        => array( 'Mailing ZIP', array( 'mailingzip', 'mailingpostalcode', 'billingzip', 'mailzip', 'mailingzipcode' ) ),
			'owner2_name'     => array( 'Second owner name', array( 'owner2', 'owner2name', 'secondowner', 'coowner', 'coownername', 'secondaryowner', 'secondaryname' ) ),
			'owner2_email'    => array( 'Second owner email', array( 'owner2email', 'coowneremail', 'secondaryemail', 'secondowneremail', 'email2' ) ),
			'owner2_phone'    => array( 'Second owner phone', array( 'owner2phone', 'coownerphone', 'secondaryphone', 'secondownerphone', 'phone2' ) ),
		);
	}

	/** Source key => array( label, hint, extra aliases per field ). A source only adds heading aliases. */
	public static function sources() {
		$sources = array(
			'payhoa'  => array(
				'label'   => 'PayHOA export',
				'hint'    => 'Homeowner / unit export from PayHOA (CSV or Excel). Refined once a real export is available.',
				'aliases' => array(
					'unit'       => array( 'propertyunit', 'unitlot', 'lotunit' ),
					'address'    => array( 'propertyaddressline1', 'physicaladdress', 'serviceaddress' ),
					'owner_name' => array( 'accountholder', 'accountname', 'primarymember' ),
					'email'      => array( 'primarycontactemail', 'emailprimary' ),
					'phone'      => array( 'primarycontactphone', 'phoneprimary', 'mobilephone' ),
				),
			),
			'generic' => array(
				'label'   => 'Other spreadsheet (CSV / Excel)',
				'hint'    => 'Any sheet with a unit or address and an owner name. Check the column mapping in the preview.',
				'aliases' => array(),
			),
		);
		return apply_filters( 'ajcore_hoa_import_sources', $sources );
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$ns  = AJCore_Extensions::rest_namespace();
		$ops = array( 'AJCore_Extensions', 'can_manage_ops' );
		register_rest_route( $ns, '/ops/hoa/import/sources', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'rest_sources' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/hoa/import/preview', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_preview' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/hoa/import/apply', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_apply' ), 'permission_callback' => $ops ) );
	}

	// ------------------------------------------------------------------ small helpers

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'aj_hoa_' . $name;
	}

	private static function key( $s ) {
		return preg_replace( '/[^a-z0-9#]+/', '', strtolower( (string) $s ) );
	}

	/** Lower-case, punctuation to spaces, single spaces. */
	private static function norm( $s ) {
		return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z0-9 ]+/', ' ', strtolower( (string) $s ) ) ) );
	}

	private static function street( $address ) {
		return self::norm( explode( ',', (string) $address )[0] );
	}

	private static function digits( $s ) {
		return preg_replace( '/\D+/', '', (string) $s );
	}

	private static function today() {
		return current_time( 'Y-m-d' );
	}

	// ------------------------------------------------------------------ reading files

	/** @return array<int,array<int,string>> rows of cells, empty rows dropped. */
	private static function read_rows( $path, $name ) {
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		if ( 'xlsx' === $ext ) {
			return self::read_xlsx( $path );
		}
		if ( 'xls' === $ext ) {
			return new WP_Error( 'bad_request', 'Old .xls files are not supported. Save the sheet as .xlsx or .csv.', array( 'status' => 400 ) );
		}
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$first = strtok( $raw, "\n" );
		$delim = ',';
		foreach ( array( "\t", ';', '|' ) as $d ) {
			if ( substr_count( (string) $first, $d ) > substr_count( (string) $first, $delim ) ) {
				$delim = $d;
			}
		}
		$rows = array();
		$fh   = fopen( 'php://memory', 'r+' );
		fwrite( $fh, $raw );
		rewind( $fh );
		while ( false !== ( $r = fgetcsv( $fh, 0, $delim ) ) ) {
			$r = array_map( 'trim', array_map( 'strval', $r ) );
			if ( array_filter( $r, 'strlen' ) ) {
				$rows[] = $r;
			}
		}
		fclose( $fh );
		return $rows;
	}

	/** Minimal .xlsx reader: first worksheet, shared and inline strings, numbers as text. */
	private static function read_xlsx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'unavailable', 'Excel files need the PHP zip extension. Save the sheet as .csv instead.', array( 'status' => 400 ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'bad_request', 'That file could not be read as an Excel workbook.', array( 'status' => 400 ) );
		}
		$shared = array();
		$ss     = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $ss ) {
			$x = @simplexml_load_string( $ss ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $x ) {
				foreach ( $x->si as $si ) {
					$text = '';
					if ( isset( $si->t ) ) {
						$text = (string) $si->t;
					} else {
						foreach ( $si->r as $run ) {
							$text .= (string) $run->t;
						}
					}
					$shared[] = $text;
				}
			}
		}
		$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		if ( false === $sheet ) {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$n = $zip->getNameIndex( $i );
				if ( 0 === strpos( $n, 'xl/worksheets/' ) && '.xml' === substr( $n, -4 ) ) {
					$sheet = $zip->getFromIndex( $i );
					break;
				}
			}
		}
		$zip->close();
		$x = $sheet ? @simplexml_load_string( $sheet ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $x ) {
			return new WP_Error( 'bad_request', 'The workbook has no readable sheet.', array( 'status' => 400 ) );
		}
		$rows = array();
		foreach ( $x->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $c ) {
				$ref = (string) $c['r'];
				$col = 0;
				foreach ( str_split( preg_replace( '/[^A-Z]/', '', strtoupper( $ref ) ) ) as $ch ) {
					$col = $col * 26 + ( ord( $ch ) - 64 );
				}
				$col--;
				$type = (string) $c['t'];
				if ( 's' === $type ) {
					$val = $shared[ (int) $c->v ] ?? '';
				} elseif ( 'inlineStr' === $type ) {
					$val = (string) $c->is->t;
				} else {
					$val = (string) $c->v;
				}
				$cells[ max( 0, $col ) ] = trim( $val );
			}
			if ( $cells ) {
				$out = array();
				for ( $i = 0; $i <= max( array_keys( $cells ) ); $i++ ) {
					$out[] = $cells[ $i ] ?? '';
				}
				if ( array_filter( $out, 'strlen' ) ) {
					$rows[] = $out;
				}
			}
		}
		return $rows;
	}

	// ------------------------------------------------------------------ mapping

	/** @return array<string,int> canonical field => column index. */
	private static function detect_mapping( $headers, $source ) {
		$sources = self::sources();
		$extra   = $sources[ $source ]['aliases'] ?? array();
		$map     = array();
		$used    = array();
		$keys    = array_map( array( __CLASS__, 'key' ), $headers );
		foreach ( self::fields() as $field => $def ) {
			$aliases = array_map( array( __CLASS__, 'key' ), array_merge( $extra[ $field ] ?? array(), $def[1] ) );
			foreach ( $aliases as $alias ) {
				$i = array_search( $alias, $keys, true );
				if ( false !== $i && ! isset( $used[ $i ] ) ) {
					$map[ $field ] = (int) $i;
					$used[ $i ]    = true;
					break;
				}
			}
		}
		return $map;
	}

	private static function field_list() {
		$out = array();
		foreach ( self::fields() as $k => $def ) {
			$out[] = array( 'key' => $k, 'label' => $def[0] );
		}
		return $out;
	}

	// ------------------------------------------------------------------ rows -> units

	/** "Smith, John" -> "John Smith"; collapses spaces. */
	private static function clean_name( $name ) {
		$name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
		if ( preg_match( '/^([^,]+),\s*(.+)$/', $name, $m ) && false === strpos( $m[2], ',' ) ) {
			$name = trim( $m[2] . ' ' . $m[1] );
		}
		return $name;
	}

	/** "John & Jane Smith" -> John Smith, Jane Smith. Several names in one cell become several owners. */
	private static function split_names( $cell ) {
		$cell  = trim( (string) $cell );
		if ( '' === $cell ) {
			return array();
		}
		$parts = preg_split( '/\s*(?:&|;|\/|\s+and\s+)\s*/i', $cell );
		$parts = array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
		if ( count( $parts ) > 1 ) {
			$first_tokens = preg_split( '/\s+/', $parts[0] );
			$last_tokens  = preg_split( '/\s+/', end( $parts ) );
			// "John & Jane Smith": the first part has no last name of its own, borrow the last part's.
			if ( 1 === count( $first_tokens ) && count( $last_tokens ) > 1 && false === strpos( $parts[0], ',' ) ) {
				$parts[0] .= ' ' . end( $last_tokens );
			}
		}
		return array_map( array( __CLASS__, 'clean_name' ), $parts );
	}

	private static function last_name( $name ) {
		$t = preg_split( '/\s+/', self::norm( $name ) );
		return $t ? end( $t ) : '';
	}

	private static function first_initial( $name ) {
		$n = self::norm( $name );
		return '' === $n ? '' : substr( $n, 0, 1 );
	}

	/**
	 * Canonical units from sheet rows: key => array( unit, address, owners[ array(name,email,phone,mailing_address) ] ).
	 * Several rows with the same unit add owners to that unit.
	 */
	private static function build_units( $rows, $mapping, &$skipped ) {
		$units   = array();
		$skipped = 0;
		$get     = function ( $row, $field ) use ( $mapping ) {
			return isset( $mapping[ $field ] ) ? trim( (string) ( $row[ $mapping[ $field ] ] ?? '' ) ) : '';
		};
		foreach ( $rows as $row ) {
			$unit    = $get( $row, 'unit' );
			$address = $get( $row, 'address' );
			$key     = '' !== $unit ? self::norm( $unit ) : self::street( $address );
			if ( '' === $key ) {
				$skipped++;
				continue;
			}
			if ( ! isset( $units[ $key ] ) ) {
				$units[ $key ] = array( 'unit' => '' !== $unit ? $unit : explode( ',', $address )[0], 'address' => $address, 'owners' => array() );
			} elseif ( '' === $units[ $key ]['address'] && '' !== $address ) {
				$units[ $key ]['address'] = $address;
			}
			$mail = $get( $row, 'mailing_address' );
			$tail = trim( implode( ' ', array_filter( array( $get( $row, 'mail_city' ), $get( $row, 'mail_state' ), $get( $row, 'mail_zip' ) ), 'strlen' ) ) );
			if ( '' !== $tail ) {
				$mail = trim( $mail . ( '' !== $mail ? ', ' : '' ) . $tail );
			}
			$names = self::split_names( $get( $row, 'owner_name' ) );
			if ( ! $names ) {
				$full = trim( $get( $row, 'first_name' ) . ' ' . $get( $row, 'last_name' ) );
				if ( '' !== $full ) {
					$names = array( self::clean_name( $full ) );
				}
			}
			$entries = array();
			foreach ( $names as $i => $n ) {
				// A cell with several names: contact details belong to the first only.
				$entries[] = array( 'name' => $n, 'email' => 0 === $i ? $get( $row, 'email' ) : '', 'phone' => 0 === $i ? $get( $row, 'phone' ) : '', 'mailing_address' => $mail );
			}
			$n2 = self::split_names( $get( $row, 'owner2_name' ) );
			foreach ( $n2 as $i => $n ) {
				$entries[] = array( 'name' => $n, 'email' => 0 === $i ? $get( $row, 'owner2_email' ) : '', 'phone' => 0 === $i ? $get( $row, 'owner2_phone' ) : '', 'mailing_address' => $mail );
			}
			foreach ( $entries as $e ) {
				$dupe = false;
				foreach ( $units[ $key ]['owners'] as &$existing ) {
					if ( self::norm( $existing['name'] ) === self::norm( $e['name'] ) ) {
						foreach ( array( 'email', 'phone', 'mailing_address' ) as $f ) {
							if ( '' === $existing[ $f ] ) {
								$existing[ $f ] = $e[ $f ];
							}
						}
						$dupe = true;
						break;
					}
				}
				unset( $existing );
				if ( ! $dupe ) {
					$units[ $key ]['owners'][] = $e;
				}
			}
		}
		return $units;
	}

	// ------------------------------------------------------------------ plan / apply

	private static function score( $e, $f, $sole ) {
		$en = self::norm( $e->owner_name );
		$fn = self::norm( $f['name'] );
		if ( '' !== $en && $en === $fn ) {
			return 100;
		}
		$same_last = '' !== self::last_name( $e->owner_name ) && self::last_name( $e->owner_name ) === self::last_name( $f['name'] );
		if ( $same_last && self::first_initial( $e->owner_name ) === self::first_initial( $f['name'] ) ) {
			return 90;
		}
		if ( '' !== $f['email'] && strtolower( $f['email'] ) === strtolower( (string) $e->email ) ) {
			return 80;
		}
		if ( strlen( self::digits( $f['phone'] ) ) >= 7 && self::digits( $f['phone'] ) === self::digits( $e->phone ) ) {
			return 70;
		}
		if ( $same_last && '' !== $f['mailing_address'] && self::norm( $f['mailing_address'] ) === self::norm( $e->mailing_address ) ) {
			return 60;
		}
		if ( $same_last && $sole ) {
			return 50;
		}
		return 0;
	}

	/**
	 * Diff the file against the community. Returns array( summary, changes, ops ) where ops is what apply() writes.
	 */
	private static function plan( $community, $units, $mode ) {
		global $wpdb;
		$props = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'properties' ) . ' WHERE community_id = %s', $community ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$by_key = array();
		foreach ( $props as $p ) {
			$by_key[ self::norm( $p->unit ) ]       = $p;
			$by_key[ self::street( $p->address ) ] = $by_key[ self::street( $p->address ) ] ?? $p;
		}
		$current = array();
		if ( $props ) {
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $props, 'id' ) ) );
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::t( 'owners' ) . " WHERE property_id IN ({$ids}) AND end_date IS NULL ORDER BY id" ) as $o ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$current[ $o->property_id ][] = $o;
			}
		}
		$sum = array( 'units_in_file' => count( $units ), 'units_new' => 0, 'units_existing' => 0, 'owners_new' => 0, 'owners_updated' => 0, 'owners_moved_to_previous' => 0, 'owners_unchanged' => 0 );
		$changes = array();
		$ops     = array();
		$today   = self::today();
		$update  = 'update' === $mode;

		foreach ( $units as $key => $u ) {
			$p = $by_key[ $key ] ?? ( '' !== self::street( $u['address'] ) ? ( $by_key[ self::street( $u['address'] ) ] ?? null ) : null );
			$label = $u['unit'];
			if ( ! $p ) {
				$sum['units_new']++;
				$changes[]            = array( 'unit' => $label, 'kind' => 'new_unit', 'detail' => 'New unit' . ( $u['address'] ? ' · ' . $u['address'] : '' ) );
				$ops[ $key ]          = array( 'create' => true, 'unit' => $u['unit'], 'address' => $u['address'], 'add' => array(), 'update' => array(), 'end' => array() );
				foreach ( $u['owners'] as $f ) {
					$sum['owners_new']++;
					$ops[ $key ]['add'][] = $f;
					$changes[]            = array( 'unit' => $label, 'kind' => 'new_owner', 'detail' => $f['name'] );
				}
				continue;
			}
			$sum['units_existing']++;
			$ops[ $key ] = array( 'create' => false, 'property_id' => (int) $p->id, 'address' => ( '' === (string) $p->address && '' !== $u['address'] ) ? $u['address'] : '', 'add' => array(), 'update' => array(), 'end' => array() );
			$cur      = $current[ $p->id ] ?? array();
			$claimed  = array();
			$matched  = array();
			$sole     = 1 === count( $cur ) && 1 === count( $u['owners'] );
			// Greedy best-score pairing.
			$pairs = array();
			foreach ( $u['owners'] as $fi => $f ) {
				foreach ( $cur as $ei => $e ) {
					$s = self::score( $e, $f, $sole );
					if ( $s >= 50 ) {
						$pairs[] = array( $s, $fi, $ei );
					}
				}
			}
			usort( $pairs, function ( $a, $b ) {
				return $b[0] <=> $a[0];
			} );
			foreach ( $pairs as $pr ) {
				list( $s, $fi, $ei ) = $pr;
				if ( isset( $matched[ $fi ] ) || isset( $claimed[ $ei ] ) ) {
					continue;
				}
				$matched[ $fi ] = array( $ei, $s );
				$claimed[ $ei ] = true;
			}
			foreach ( $u['owners'] as $fi => $f ) {
				if ( isset( $matched[ $fi ] ) ) {
					list( $ei, $s ) = $matched[ $fi ];
					$e      = $cur[ $ei ];
					$fields = array();
					$notes  = array();
					foreach ( array( 'owner_name' => 'name', 'email' => 'email', 'phone' => 'phone', 'mailing_address' => 'mailing_address' ) as $col => $fk ) {
						if ( '' !== $f[ $fk ] && trim( (string) $e->$col ) !== $f[ $fk ] ) {
							if ( 'owner_name' === $col && 100 === $s ) {
								continue; // same name, only spacing/case differs
							}
							$fields[ $col ] = $f[ $fk ];
							$notes[]        = str_replace( '_', ' ', $col ) . ': ' . ( '' !== (string) $e->$col ? $e->$col . ' → ' : '' ) . $f[ $fk ];
						}
					}
					if ( $fields && $update ) {
						$sum['owners_updated']++;
						$ops[ $key ]['update'][] = array( 'id' => (int) $e->id, 'fields' => $fields );
						$changes[]               = array( 'unit' => $label, 'kind' => ( 50 === $s || isset( $fields['owner_name'] ) ) ? 'renamed' : 'updated', 'detail' => $e->owner_name . ' — ' . implode( '; ', $notes ) );
					} else {
						$sum['owners_unchanged']++;
					}
				} else {
					$sum['owners_new']++;
					$ops[ $key ]['add'][] = $f;
					$changes[]            = array( 'unit' => $label, 'kind' => 'new_owner', 'detail' => $f['name'] );
				}
			}
			// Current owners the file no longer lists (only when the file lists owners for this unit).
			if ( $update && $u['owners'] ) {
				foreach ( $cur as $ei => $e ) {
					if ( ! isset( $claimed[ $ei ] ) ) {
						$sum['owners_moved_to_previous']++;
						$ops[ $key ]['end'][] = (int) $e->id;
						$changes[]            = array( 'unit' => $label, 'kind' => 'moved_previous', 'detail' => $e->owner_name . ' → previous owner (ended ' . $today . ')' );
					}
				}
			}
		}
		return array( $sum, $changes, $ops );
	}

	private static function apply_ops( $community, $ops ) {
		global $wpdb;
		$now   = current_time( 'mysql' );
		$today = self::today();
		foreach ( $ops as $op ) {
			if ( ! empty( $op['create'] ) ) {
				$wpdb->insert( self::t( 'properties' ), array( 'community_id' => $community, 'unit' => $op['unit'], 'address' => $op['address'], 'created_at' => $now, 'updated_at' => $now ) );
				$pid = (int) $wpdb->insert_id;
			} else {
				$pid = (int) $op['property_id'];
				if ( ! empty( $op['address'] ) ) {
					$wpdb->update( self::t( 'properties' ), array( 'address' => $op['address'], 'updated_at' => $now ), array( 'id' => $pid ) );
				}
			}
			if ( ! $pid ) {
				continue;
			}
			foreach ( $op['update'] as $u ) {
				$wpdb->update( self::t( 'owners' ), array_merge( $u['fields'], array( 'updated_at' => $now ) ), array( 'id' => (int) $u['id'] ) );
			}
			foreach ( $op['end'] as $id ) {
				$wpdb->update( self::t( 'owners' ), array( 'end_date' => $today, 'updated_at' => $now ), array( 'id' => (int) $id ) );
			}
			foreach ( $op['add'] as $f ) {
				$wpdb->insert( self::t( 'owners' ), array(
					'property_id'     => $pid,
					'owner_name'      => $f['name'],
					'email'           => sanitize_email( $f['email'] ),
					'phone'           => $f['phone'],
					'mailing_address' => $f['mailing_address'],
					'email_ok'        => 1,
					'start_date'      => $today,
					'end_date'        => null,
					'created_at'      => $now,
					'updated_at'      => $now,
				) );
			}
		}
	}

	// ------------------------------------------------------------------ REST

	public static function rest_sources() {
		$out = array();
		foreach ( self::sources() as $k => $s ) {
			$out[] = array( 'key' => $k, 'label' => $s['label'], 'hint' => $s['hint'] );
		}
		return rest_ensure_response( array( 'sources' => $out, 'fields' => self::field_list() ) );
	}

	private static function community_ok( $id ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return false;
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		return array_key_exists( $id, (array) $admin->get_service_request_customer_options() );
	}

	public static function rest_preview( WP_REST_Request $request ) {
		$token = sanitize_key( (string) $request->get_param( 'token' ) );
		$state = $token ? get_transient( 'ajcore_hoa_import_' . $token ) : false;
		$files = $request->get_file_params();
		$source = sanitize_key( (string) ( $request->get_param( 'source' ) ?: ( $state['source'] ?? 'generic' ) ) );
		if ( ! isset( self::sources()[ $source ] ) ) {
			$source = 'generic';
		}
		$community = sanitize_text_field( (string) ( $request->get_param( 'community_id' ) ?: ( $state['community'] ?? '' ) ) );
		$mode      = 'append' === sanitize_key( (string) ( $request->get_param( 'mode' ) ?: ( $state['mode'] ?? 'update' ) ) ) ? 'append' : 'update';
		if ( ! self::community_ok( $community ) ) {
			return new WP_Error( 'bad_request', 'Choose a community.', array( 'status' => 400 ) );
		}

		if ( ! empty( $files['file']['tmp_name'] ) ) {
			$rows = self::read_rows( $files['file']['tmp_name'], $files['file']['name'] );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			if ( count( $rows ) < 2 ) {
				return new WP_Error( 'bad_request', 'The file needs a heading row and at least one data row.', array( 'status' => 400 ) );
			}
			$headers = array_map( 'strval', array_shift( $rows ) );
			$token   = wp_generate_password( 20, false, false );
			$state   = array( 'headers' => $headers, 'rows' => $rows, 'file' => sanitize_file_name( $files['file']['name'] ), 'mapping' => null );
		} elseif ( ! $state ) {
			return new WP_Error( 'bad_request', 'Choose a file.', array( 'status' => 400 ) );
		}

		// Mapping: explicit (JSON field => header index), else the last used, else detected for this source.
		$mapping = null;
		$given   = $request->get_param( 'mapping' );
		if ( is_string( $given ) && '' !== $given ) {
			$given = json_decode( $given, true );
		}
		if ( is_array( $given ) ) {
			$mapping = array();
			foreach ( $given as $field => $idx ) {
				if ( isset( self::fields()[ $field ] ) && is_numeric( $idx ) && (int) $idx >= 0 && (int) $idx < count( $state['headers'] ) ) {
					$mapping[ $field ] = (int) $idx;
				}
			}
		}
		if ( null === $mapping ) {
			$mapping = ( $state['mapping'] && ( $state['source'] ?? '' ) === $source ) ? $state['mapping'] : self::detect_mapping( $state['headers'], $source );
		}

		$warnings = array();
		if ( ! isset( $mapping['unit'] ) && ! isset( $mapping['address'] ) ) {
			$warnings[] = 'No unit or address column found. Map one below.';
		}
		if ( ! isset( $mapping['owner_name'] ) && ! ( isset( $mapping['first_name'] ) || isset( $mapping['last_name'] ) ) ) {
			$warnings[] = 'No owner name column found. Map one below.';
		}

		$state['source']    = $source;
		$state['community'] = $community;
		$state['mode']      = $mode;
		$state['mapping']   = $mapping;
		set_transient( 'ajcore_hoa_import_' . $token, $state, self::TTL );

		$skipped = 0;
		$summary = null;
		$changes = array();
		if ( ! $warnings ) {
			$units = self::build_units( $state['rows'], $mapping, $skipped );
			list( $summary, $changes ) = self::plan( $community, $units, $mode );
			$summary['rows_skipped'] = $skipped;
			if ( $skipped ) {
				$warnings[] = $skipped . ' row(s) skipped: no unit or address.';
			}
		}
		return rest_ensure_response( array(
			'token'    => $token,
			'file'     => $state['file'],
			'source'   => $source,
			'mode'     => $mode,
			'headers'  => $state['headers'],
			'mapping'  => (object) $mapping,
			'fields'   => self::field_list(),
			'sample'   => array_slice( $state['rows'], 0, 3 ),
			'summary'  => $summary,
			'changes'  => array_slice( $changes, 0, 300 ),
			'changes_total' => count( $changes ),
			'warnings' => $warnings,
		) );
	}

	public static function rest_apply( WP_REST_Request $request ) {
		$token = sanitize_key( (string) $request->get_param( 'token' ) );
		$state = $token ? get_transient( 'ajcore_hoa_import_' . $token ) : false;
		if ( ! $state || empty( $state['mapping'] ) ) {
			return new WP_Error( 'expired', 'This preview expired. Upload the file again.', array( 'status' => 410 ) );
		}
		$skipped = 0;
		$units   = self::build_units( $state['rows'], $state['mapping'], $skipped );
		list( $summary, $changes, $ops ) = self::plan( $state['community'], $units, $state['mode'] );
		self::apply_ops( $state['community'], $ops );
		delete_transient( 'ajcore_hoa_import_' . $token );
		return rest_ensure_response( array( 'success' => true, 'summary' => $summary, 'changes_total' => count( $changes ) ) );
	}
}
