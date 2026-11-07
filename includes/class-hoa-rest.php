<?php
/**
 * Read-only REST for the community > unit > owners directory, used by AJOps and the iOS app.
 * Writes (add unit, record a sale, import) stay in WP admin > Client Portal > Homeowners.
 *
 *   GET /ops/hoa/communities              communities (AJ Core customers) with unit/owner/violation counts
 *   GET /ops/hoa/communities/{id}         one community with its units and current owners
 *   GET /ops/hoa/units/{id}               one unit: current owners, previous owners, violations
 *   GET /ops/hoa/violation-types          violation types offered when inspecting (filter ajcore_hoa_violation_types)
 *   GET /ops/hoa/address-violations       what is already on file for an address (inspection "discovery")
 *   POST /ops/hoa/inspections             one inspection finding from the app: photo + address + type -> Violation request
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_HOA_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Menu item + number in AJOps.
		add_filter( 'ajcore_ops_modules', function ( $m ) {
			return array_merge( (array) $m, array( 'communities' ) );
		} );
		add_filter( 'ajcore_ops_counts', function ( $counts ) {
			global $wpdb;
			$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'properties' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$counts['communities'] = array( 'value' => $n, 'label' => 'Units', 'alert' => false );
			// Open requests by HOA type (the app shows Violations and Work Orders as their own tiles).
			foreach ( array( 'violations' => AJCore_HOA_Workflows::VIOLATION, 'work-orders' => AJCore_HOA_Workflows::WORK_ORDER ) as $key => $type ) {
				$counts[ $key ] = array( 'value' => self::open_requests( $type ), 'label' => 'Open', 'alert' => true );
			}
			return $counts;
		} );
	}

	/** Open (not completed/cancelled) requests of one request type. */
	private static function open_requests( $type ) {
		$kit = AJCore_Extensions::rest_toolkit();
		$db  = isset( $kit['portal_db'] ) ? $kit['portal_db']() : null;
		if ( ! $db ) {
			return 0;
		}
		$tbl = $kit['portal_table']( 'aj_portal_service_requests' );
		return (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM `{$tbl}` WHERE request_type = %s AND COALESCE(NULLIF(service_status,''),'new') NOT IN ('completed','cancelled')", $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function register_routes() {
		$ns  = AJCore_Extensions::rest_namespace();
		$ops = array( 'AJCore_Extensions', 'can_manage_ops' );
		register_rest_route( $ns, '/ops/hoa/communities', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'communities' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/hoa/communities/(?P<id>[A-Za-z0-9_\-]+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'community' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/hoa/violation-types', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'violation_types' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/hoa/address-violations', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'address_violations' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/hoa/inspections', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'create_inspection' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/hoa/units/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'unit' ), 'permission_callback' => $ops ) );
	}

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'aj_hoa_' . $name;
	}

	/** id => name, from AJ Core's customer list (labels may carry the email in brackets). */
	private static function customer_names() {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return array();
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		$out   = array();
		foreach ( (array) $admin->get_service_request_customer_options() as $id => $label ) {
			$out[ (string) $id ] = trim( preg_replace( '/\s*\([^)]*@[^)]*\)\s*$/', '', (string) $label ) );
		}
		return $out;
	}

	private static function owner( $o ) {
		return array(
			'id'              => (int) $o->id,
			'name'            => (string) $o->owner_name,
			'email'           => (string) $o->email,
			'phone'           => (string) $o->phone,
			'mailing_address' => (string) $o->mailing_address,
			'email_ok'        => (bool) $o->email_ok,
			'start_date'      => (string) $o->start_date,
			'end_date'        => (string) $o->end_date,
			'notes'           => (string) $o->notes,
		);
	}

	public static function communities() {
		global $wpdb;
		$names = self::customer_names();
		$stats = array();
		foreach ( (array) $wpdb->get_results( 'SELECT community_id, COUNT(*) AS units FROM ' . self::t( 'properties' ) . ' GROUP BY community_id' ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$stats[ $r->community_id ] = array( 'units' => (int) $r->units, 'owners' => 0, 'occupied' => 0, 'violations' => 0 );
		}
		foreach ( (array) $wpdb->get_results( 'SELECT p.community_id, COUNT(o.id) AS owners, COUNT(DISTINCT p.id) AS occupied FROM ' . self::t( 'properties' ) . ' p JOIN ' . self::t( 'owners' ) . ' o ON o.property_id = p.id AND o.end_date IS NULL GROUP BY p.community_id' ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( isset( $stats[ $r->community_id ] ) ) {
				$stats[ $r->community_id ]['owners']   = (int) $r->owners;
				$stats[ $r->community_id ]['occupied'] = (int) $r->occupied;
			}
		}
		foreach ( (array) $wpdb->get_results( 'SELECT p.community_id, COUNT(*) AS n FROM ' . self::t( 'unit_requests' ) . ' u JOIN ' . self::t( 'properties' ) . ' p ON p.id = u.property_id GROUP BY p.community_id' ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( isset( $stats[ $r->community_id ] ) ) {
				$stats[ $r->community_id ]['violations'] = (int) $r->n;
			}
		}
		$out = array();
		foreach ( $names as $id => $name ) {
			$s     = $stats[ $id ] ?? array( 'units' => 0, 'owners' => 0, 'occupied' => 0, 'violations' => 0 );
			$out[] = array( 'id' => (string) $id, 'name' => $name, 'units' => $s['units'], 'owners' => $s['owners'], 'vacant' => $s['units'] - $s['occupied'], 'violations' => $s['violations'] );
		}
		// Communities with units first, then by name.
		usort( $out, function ( $a, $b ) {
			return ( $b['units'] <=> $a['units'] ) ?: strcasecmp( $a['name'], $b['name'] );
		} );
		return rest_ensure_response( array( 'communities' => $out ) );
	}

	public static function community( WP_REST_Request $request ) {
		global $wpdb;
		$id    = sanitize_text_field( (string) $request['id'] );
		$names = self::customer_names();
		if ( ! isset( $names[ $id ] ) ) {
			return new WP_Error( 'not_found', 'Community not found.', array( 'status' => 404 ) );
		}
		$props = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'properties' ) . ' WHERE community_id = %s ORDER BY unit ASC', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$cur   = array();
		$past  = array();
		$viol  = array();
		if ( $props ) {
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $props, 'id' ) ) );
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::t( 'owners' ) . " WHERE property_id IN ({$ids}) ORDER BY start_date DESC, id DESC" ) as $o ) { // phpcs:ignore WordPress.DB.PreparedSQL
				if ( empty( $o->end_date ) ) {
					$cur[ $o->property_id ][] = self::owner( $o );
				} else {
					$past[ $o->property_id ] = ( $past[ $o->property_id ] ?? 0 ) + 1;
				}
			}
			foreach ( (array) $wpdb->get_results( 'SELECT property_id, COUNT(*) AS n FROM ' . self::t( 'unit_requests' ) . " WHERE property_id IN ({$ids}) GROUP BY property_id" ) as $v ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$viol[ $v->property_id ] = (int) $v->n;
			}
		}
		$units = array();
		foreach ( $props as $p ) {
			$units[] = array(
				'id'             => (int) $p->id,
				'unit'           => (string) $p->unit,
				'address'        => (string) $p->address,
				'owners'         => $cur[ $p->id ] ?? array(),
				'previous_count' => $past[ $p->id ] ?? 0,
				'violations'     => $viol[ $p->id ] ?? 0,
			);
		}
		return rest_ensure_response( array( 'community' => array( 'id' => $id, 'name' => $names[ $id ] ), 'units' => $units ) );
	}

	public static function unit( WP_REST_Request $request ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'properties' ) . ' WHERE id = %d', (int) $request['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $p ) {
			return new WP_Error( 'not_found', 'Unit not found.', array( 'status' => 404 ) );
		}
		$names   = self::customer_names();
		$current = array();
		$past    = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'owners' ) . ' WHERE property_id = %d ORDER BY start_date DESC, id DESC', $p->id ) ) as $o ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( empty( $o->end_date ) ) {
				$current[] = self::owner( $o );
			} else {
				$past[] = self::owner( $o );
			}
		}
		usort( $past, function ( $a, $b ) {
			return strcmp( $b['end_date'], $a['end_date'] );
		} );

		$violations = array();
		$links      = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'unit_requests' ) . ' WHERE property_id = %d ORDER BY id DESC', $p->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $links ) {
			$kit    = AJCore_Extensions::rest_toolkit();
			$db     = isset( $kit['portal_db'] ) ? $kit['portal_db']() : null;
			$rows   = array();
			$labels = array();
			if ( $db ) {
				$tbl = $kit['portal_table']( 'aj_portal_service_requests' );
				$ids = implode( ',', array_map( 'intval', wp_list_pluck( $links, 'request_id' ) ) );
				foreach ( (array) $db->get_results( "SELECT id, service_request_number, service_name, service_status, created_at FROM `{$tbl}` WHERE id IN ({$ids})" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
					$rows[ (int) $r->id ] = $r;
				}
			}
			if ( class_exists( 'AJForms_Admin' ) ) {
				$labels = ( AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin() )->get_service_status_labels_for_ops();
			}
			foreach ( $links as $l ) {
				$r = $rows[ (int) $l->request_id ] ?? null;
				$s = $r ? ( '' !== (string) $r->service_status ? (string) $r->service_status : 'new' ) : '';
				$violations[] = array(
					'request_id'      => (int) $l->request_id,
					'number'          => $r ? (string) $r->service_request_number : '',
					'title'           => $r ? (string) $r->service_name : 'Request #' . (int) $l->request_id,
					'status'          => $s,
					'status_label'    => $s ? (string) ( $labels[ $s ] ?? ucwords( str_replace( '_', ' ', $s ) ) ) : '',
					'created_at'      => $r ? (string) $r->created_at : (string) $l->created_at,
					'owners_at_time'  => (string) $l->owners_snapshot,
				);
			}
		}
		return rest_ensure_response( array(
			'unit'       => array( 'id' => (int) $p->id, 'unit' => (string) $p->unit, 'address' => (string) $p->address, 'notes' => (string) $p->notes, 'community_id' => (string) $p->community_id, 'community_name' => $names[ $p->community_id ] ?? '' ),
			'current'    => $current,
			'previous'   => $past,
			'violations' => $violations,
		) );
	}

	public static function violation_types() {
		$types = apply_filters( 'ajcore_hoa_violation_types', array(
			'Trash cans left out',
			'Parking violation',
			'Landscaping / lawn',
			'Unapproved exterior change',
			'Exterior maintenance',
			'Noise',
			'Pets',
			'Signage',
			'Other',
		) );
		return rest_ensure_response( array( 'types' => array_values( array_filter( array_map( 'sanitize_text_field', (array) $types ) ) ) ) );
	}

	/** Unit of the community whose stored address matches the street of $address (first comma part), or null. */
	private static function find_unit( $community, $address ) {
		global $wpdb;
		$street = self::norm_address( explode( ',', (string) $address )[0] );
		if ( '' === $street ) {
			return null;
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'properties' ) . " WHERE community_id = %s AND address <> ''", $community ) ) as $p ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$a = self::norm_address( explode( ',', (string) $p->address )[0] );
			if ( '' !== $a && ( $a === $street || false !== strpos( $street, $a ) || false !== strpos( $a, $street ) ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Everything already on file for an address: violations linked to the matching unit, plus violations whose title
	 * names the street (filed before the unit existed). Open ones first.
	 */
	public static function address_violations( WP_REST_Request $request ) {
		global $wpdb;
		$community = sanitize_text_field( (string) $request->get_param( 'community_id' ) );
		$address   = sanitize_text_field( (string) $request->get_param( 'address' ) );
		$names     = self::customer_names();
		if ( ! isset( $names[ $community ] ) || '' === trim( $address ) ) {
			return rest_ensure_response( array( 'matched' => false, 'violations' => array(), 'open_count' => 0 ) );
		}
		$unit   = self::find_unit( $community, $address );
		$ids    = array();
		$owners_at = array();
		if ( $unit ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT request_id, owners_snapshot FROM ' . self::t( 'unit_requests' ) . ' WHERE property_id = %d', $unit->id ) ) as $l ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$ids[ (int) $l->request_id ]       = true;
				$owners_at[ (int) $l->request_id ] = (string) $l->owners_snapshot;
			}
		}
		$kit = AJCore_Extensions::rest_toolkit();
		$db  = isset( $kit['portal_db'] ) ? $kit['portal_db']() : null;
		$tbl = $db ? $kit['portal_table']( 'aj_portal_service_requests' ) : '';
		$street_raw = trim( explode( ',', $address )[0] );
		if ( $db && '' !== $street_raw ) {
			foreach ( (array) $db->get_col( $db->prepare( "SELECT id FROM `{$tbl}` WHERE stripe_customer_id = %s AND request_type = %s AND service_name LIKE %s", $community, AJCore_HOA_Workflows::VIOLATION, '%' . $db->esc_like( $street_raw ) . '%' ) ) as $rid ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$ids[ (int) $rid ] = true;
			}
		}
		$out = array();
		if ( $db && $ids ) {
			$in     = implode( ',', array_map( 'intval', array_keys( $ids ) ) );
			$labels = class_exists( 'AJForms_Admin' ) ? ( AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin() )->get_service_status_labels_for_ops() : array();
			foreach ( (array) $db->get_results( "SELECT id, service_request_number, service_name, service_status, created_at FROM `{$tbl}` WHERE id IN ({$in}) ORDER BY id DESC" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$st    = '' !== (string) $r->service_status ? (string) $r->service_status : 'new';
				$out[] = array(
					'request_id'     => (int) $r->id,
					'number'         => (string) $r->service_request_number,
					'title'          => (string) $r->service_name,
					'status'         => $st,
					'status_label'   => (string) ( $labels[ $st ] ?? ucwords( str_replace( '_', ' ', $st ) ) ),
					'created_at'     => (string) $r->created_at,
					'owners_at_time' => $owners_at[ (int) $r->id ] ?? '',
					'open'           => ! in_array( $st, array( 'completed', 'cancelled' ), true ),
				);
			}
			usort( $out, function ( $a, $b ) {
				return ( (int) $b['open'] <=> (int) $a['open'] ) ?: ( $b['request_id'] <=> $a['request_id'] );
			} );
		}
		$owner_names = array();
		if ( $unit ) {
			$owner_names = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT owner_name FROM ' . self::t( 'owners' ) . ' WHERE property_id = %d AND end_date IS NULL ORDER BY id', $unit->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return rest_ensure_response( array(
			'matched'    => (bool) $unit,
			'unit_id'    => $unit ? (int) $unit->id : null,
			'unit'       => $unit ? (string) $unit->unit : '',
			'owners'     => array_values( $owner_names ),
			'violations' => $out,
			'open_count' => count( array_filter( $out, function ( $v ) {
				return $v['open'];
			} ) ),
		) );
	}

	private static function norm_address( $a ) {
		return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z0-9 ]+/', ' ', strtolower( (string) $a ) ) ) );
	}

	/**
	 * One finding from an inspection: creates a Violation request for the community, links it to the unit whose
	 * address matches (when one does), and keeps the photo in the Media Library.
	 * Multipart: community_id, address, violation_type, notes?, lat?, lng?, photo?
	 */
	public static function create_inspection( WP_REST_Request $request ) {
		global $wpdb;
		$community = sanitize_text_field( (string) $request->get_param( 'community_id' ) );
		$address   = sanitize_text_field( (string) $request->get_param( 'address' ) );
		$type      = sanitize_text_field( (string) $request->get_param( 'violation_type' ) );
		$notes     = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		$lat       = (string) $request->get_param( 'lat' );
		$lng       = (string) $request->get_param( 'lng' );
		$names     = self::customer_names();
		if ( ! isset( $names[ $community ] ) ) {
			return new WP_Error( 'bad_request', 'Choose a community.', array( 'status' => 400 ) );
		}
		// Idempotency: the app sends one client_request_id per submission. add_option() is atomic, so a double tap or a
		// retry that arrives while the first is still running gets the first result back instead of a second request.
		$cid = sanitize_key( (string) $request->get_param( 'client_request_id' ) );
		if ( '' !== $cid ) {
			$opt = 'ajcore_hoa_insp_' . substr( $cid, 0, 40 );
			if ( ! add_option( $opt, 'pending', '', 'no' ) ) {
				$prev = json_decode( (string) get_option( $opt ), true );
				return rest_ensure_response( is_array( $prev ) ? array_merge( $prev, array( 'duplicate' => true ) ) : array( 'success' => true, 'duplicate' => true, 'request_id' => 0, 'unit_id' => null, 'unit' => '', 'photo_url' => '' ) );
			}
		}
		$release = function () use ( $cid ) {
			if ( '' !== $cid ) {
				delete_option( 'ajcore_hoa_insp_' . substr( $cid, 0, 40 ) ); // failed: let a retry through
			}
		};
		if ( '' === $type ) {
			$release();
			return new WP_Error( 'bad_request', 'Choose a violation type.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'AJForms' ) || ! AJForms::$instance ) {
			$release();
			return new WP_Error( 'unavailable', 'AJ Core is not ready.', array( 'status' => 500 ) );
		}

		// Photo -> Media Library.
		$photo_url = '';
		$files     = $request->get_file_params();
		if ( ! empty( $files['photo']['tmp_name'] ) ) {
			$check = wp_check_filetype( (string) $files['photo']['name'] );
			if ( 0 !== strpos( (string) $check['type'], 'image/' ) ) {
				$release();
				return new WP_Error( 'bad_request', 'The photo must be an image.', array( 'status' => 400 ) );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$_FILES['photo'] = $files['photo']; // phpcs:ignore WordPress.Security.NonceVerification
			$att = media_handle_upload( 'photo', 0 );
			if ( ! is_wp_error( $att ) ) {
				$photo_url = (string) wp_get_attachment_url( $att );
			}
		}

		// Unit whose stored address matches the street the photo was taken at.
		$unit = self::find_unit( $community, $address );
		$owners = '';
		if ( $unit ) {
			$owners = implode( ', ', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT owner_name FROM ' . self::t( 'owners' ) . ' WHERE property_id = %d AND end_date IS NULL ORDER BY id', $unit->id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		$lines   = array( 'Inspection finding' );
		$lines[] = 'Address: ' . ( '' !== $address ? $address : 'not recorded' );
		if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
			$lines[] = sprintf( 'Location: %s, %s (https://maps.apple.com/?ll=%s,%s)', $lat, $lng, $lat, $lng );
		}
		if ( $unit ) {
			$lines[] = 'Unit: ' . $unit->unit . ( $owners ? "\nOwner(s) at the time: " . $owners : '' );
		}
		if ( '' !== $photo_url ) {
			$lines[] = 'Photo: ' . $photo_url;
		}
		if ( '' !== $notes ) {
			$lines[] = $notes;
		}
		$where = $unit ? 'Unit ' . $unit->unit : ( '' !== $address ? explode( ',', $address )[0] : $names[ $community ] );
		$res   = AJForms::$instance->api_create_staff_service_request( $community, 'Violation, ' . $where . ': ' . $type, AJCore_HOA_Workflows::VIOLATION, implode( "\n", $lines ) );
		if ( is_wp_error( $res ) ) {
			$release();
			return $res;
		}
		if ( $unit && ! empty( $res['id'] ) ) {
			$dup = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'unit_requests' ) . ' WHERE request_id = %d', (int) $res['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $dup ) {
				$wpdb->insert( self::t( 'unit_requests' ), array( 'property_id' => (int) $unit->id, 'request_id' => (int) $res['id'], 'owners_snapshot' => $owners, 'created_at' => current_time( 'mysql' ) ) );
			}
		}
		$out = array( 'success' => true, 'request_id' => (int) ( $res['id'] ?? 0 ), 'unit_id' => $unit ? (int) $unit->id : null, 'unit' => $unit ? (string) $unit->unit : '', 'photo_url' => $photo_url );
		if ( '' !== $cid ) {
			update_option( 'ajcore_hoa_insp_' . substr( $cid, 0, 40 ), wp_json_encode( $out ), false );
		}
		return rest_ensure_response( $out );
	}
}
