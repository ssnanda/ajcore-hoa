<?php
/**
 * HOA properties and homeowners, inside AJ Core > Client Portal (tab "Homeowners", next to Customers).
 *
 * Community (an AJ Core customer) > Unit > Owners. A unit has one or more current owners (a group of owners),
 * each with a start date. When a home is sold, the old owners get an end date and move to history, and the new
 * owners start on the sale date. Violations are requests linked to the UNIT, so they stay with the property
 * through every owner change; each keeps a note of who owned it at the time.
 *
 * Tables (site WordPress database): aj_hoa_properties, aj_hoa_owners, aj_hoa_unit_requests.
 * No payments and no owner logins: the directory exists to send violation notices to the right people.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_HOA_Homeowners {

	const DB_VERSION = '2';
	const TAB        = 'hoa-homeowners';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_filter( 'ajcore_admin_portal_tabs', array( __CLASS__, 'admin_tab' ), 9 );
		add_action( 'ajcore_admin_portal_tab_render', array( __CLASS__, 'render_admin_tab' ) );
		foreach ( array( 'unit_save', 'unit_delete', 'owner_save', 'owner_end', 'owner_delete', 'transfer', 'violation_add', 'import', 'export' ) as $a ) {
			add_action( 'admin_post_ajcore_hoa_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/** Shown right after Customers. */
	public static function admin_tab( $tabs ) {
		$tabs[ self::TAB ] = array( 'label' => __( 'Homeowners', 'ajcore-hoa' ), 'after' => 'portal-users' );
		return $tabs;
	}

	public static function render_admin_tab( $tab ) {
		if ( self::TAB === $tab ) {
			self::render();
		}
	}

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'aj_hoa_' . $name;
	}

	public static function maybe_install() {
		if ( get_option( 'ajcore_hoa_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE " . self::t( 'properties' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			community_id varchar(64) NOT NULL,
			unit varchar(100) NOT NULL DEFAULT '',
			address varchar(255) NOT NULL DEFAULT '',
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY community_id (community_id)
		) {$cc};" );
		dbDelta( "CREATE TABLE " . self::t( 'owners' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			property_id bigint(20) unsigned NOT NULL,
			owner_name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			mailing_address text NULL,
			email_ok tinyint(1) NOT NULL DEFAULT 1,
			start_date date NULL,
			end_date date NULL,
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY property_id (property_id)
		) {$cc};" );
		dbDelta( "CREATE TABLE " . self::t( 'unit_requests' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			property_id bigint(20) unsigned NOT NULL,
			request_id bigint(20) unsigned NOT NULL,
			owners_snapshot text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY property_id (property_id),
			KEY request_id (request_id)
		) {$cc};" );
		// Version 1 kept one owner per row in aj_hoa_units: move those rows over once (the old table is left in place).
		$old = $wpdb->prefix . 'aj_hoa_units';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) === $old && ! $wpdb->get_var( 'SELECT id FROM ' . self::t( 'properties' ) . ' LIMIT 1' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$now = current_time( 'mysql' );
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$old} ORDER BY id ASC" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$pid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'properties' ) . ' WHERE community_id = %s AND unit = %s', $r->community_id, $r->unit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( ! $pid ) {
					$wpdb->insert( self::t( 'properties' ), array( 'community_id' => $r->community_id, 'unit' => $r->unit, 'created_at' => $now, 'updated_at' => $now ) );
					$pid = (int) $wpdb->insert_id;
				}
				$wpdb->insert( self::t( 'owners' ), array( 'property_id' => $pid, 'owner_name' => $r->owner_name, 'email' => $r->email, 'phone' => $r->phone, 'mailing_address' => $r->mailing_address, 'email_ok' => (int) $r->email_ok, 'notes' => $r->notes, 'created_at' => $now, 'updated_at' => $now ) );
			}
		}
		update_option( 'ajcore_hoa_db_version', self::DB_VERSION );
	}

	// ---------------------------------------------------------------- helpers

	private static function communities() {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return array();
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		return $admin->get_service_request_customer_options();
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'ajforms-client-portal', 'tab' => self::TAB ), $args ), admin_url( 'admin.php' ) );
	}

	private static function back( $args = array() ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ajcore-hoa' ) );
		}
		check_admin_referer( $nonce );
	}

	private static function date( $v ) {
		$v = sanitize_text_field( (string) $v );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
	}

	private static function today() {
		return current_time( 'Y-m-d' );
	}

	private static function post( $k ) {
		return isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security
	}

	private static function owner_fields( $src ) {
		return array(
			'owner_name'      => sanitize_text_field( (string) ( $src['owner_name'] ?? '' ) ),
			'email'           => sanitize_email( (string) ( $src['email'] ?? '' ) ),
			'phone'           => sanitize_text_field( (string) ( $src['phone'] ?? '' ) ),
			'mailing_address' => sanitize_textarea_field( (string) ( $src['mailing_address'] ?? '' ) ),
			'notes'           => sanitize_textarea_field( (string) ( $src['notes'] ?? '' ) ),
		);
	}

	private static function insert_owner( $property_id, $fields, $start, $end = null ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( self::t( 'owners' ), array_merge( $fields, array(
			'property_id' => (int) $property_id,
			'email_ok'    => 1,
			'start_date'  => $start,
			'end_date'    => $end,
			'created_at'  => $now,
			'updated_at'  => $now,
		) ) );
		return (int) $wpdb->insert_id;
	}

	private static function current_owner_names( $property_id ) {
		global $wpdb;
		return implode( ', ', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT owner_name FROM ' . self::t( 'owners' ) . ' WHERE property_id = %d AND end_date IS NULL ORDER BY id', $property_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	// ---------------------------------------------------------------- handlers

	public static function handle_unit_save() {
		self::guard( 'ajcore_hoa_unit_save' );
		global $wpdb;
		$community = sanitize_text_field( self::post( 'community' ) );
		$id        = absint( self::post( 'unit_id' ) );
		$unit      = sanitize_text_field( self::post( 'unit' ) );
		if ( '' === $community || '' === $unit ) {
			self::back( array( 'community' => $community, 'hoa_error' => rawurlencode( __( 'Unit is required.', 'ajcore-hoa' ) ) ) );
		}
		$row = array( 'unit' => $unit, 'address' => sanitize_text_field( self::post( 'address' ) ), 'notes' => sanitize_textarea_field( self::post( 'notes' ) ), 'updated_at' => current_time( 'mysql' ) );
		if ( $id ) {
			$wpdb->update( self::t( 'properties' ), $row, array( 'id' => $id ) );
		} else {
			$dup = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'properties' ) . ' WHERE community_id = %s AND unit = %s', $community, $unit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $dup ) {
				self::back( array( 'community' => $community, 'unit' => $dup, 'hoa_error' => rawurlencode( __( 'That unit already exists.', 'ajcore-hoa' ) ) ) );
			}
			$row['community_id'] = $community;
			$row['created_at']   = current_time( 'mysql' );
			$wpdb->insert( self::t( 'properties' ), $row );
			$id = (int) $wpdb->insert_id;
			// Optional first owner entered with the unit.
			$owner = self::owner_fields( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security
			if ( '' !== $owner['owner_name'] ) {
				self::insert_owner( $id, $owner, self::date( self::post( 'start_date' ) ) );
			}
		}
		self::back( array( 'community' => $community, 'unit' => $id, 'hoa_msg' => 'saved' ) );
	}

	public static function handle_unit_delete() {
		self::guard( 'ajcore_hoa_unit_delete' );
		global $wpdb;
		$community = sanitize_text_field( self::post( 'community' ) );
		foreach ( array_filter( array_map( 'absint', (array) self::post( 'unit_ids' ) ) ) as $id ) {
			$has_v = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'unit_requests' ) . ' WHERE property_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $has_v ) {
				continue; // a unit with violation history is never deleted
			}
			$wpdb->delete( self::t( 'owners' ), array( 'property_id' => $id ) );
			$wpdb->delete( self::t( 'properties' ), array( 'id' => $id ) );
		}
		self::back( array( 'community' => $community, 'hoa_msg' => 'deleted' ) );
	}

	public static function handle_owner_save() {
		self::guard( 'ajcore_hoa_owner_save' );
		global $wpdb;
		$pid    = absint( self::post( 'property_id' ) );
		$id     = absint( self::post( 'owner_id' ) );
		$fields = self::owner_fields( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security
		$prop   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'properties' ) . ' WHERE id = %d', $pid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $prop ) {
			self::back();
		}
		if ( '' === $fields['owner_name'] ) {
			self::back( array( 'community' => $prop->community_id, 'unit' => $pid, 'hoa_error' => rawurlencode( __( 'Owner name is required.', 'ajcore-hoa' ) ) ) );
		}
		$start = self::date( self::post( 'start_date' ) );
		$end   = self::date( self::post( 'end_date' ) );
		if ( $id ) {
			$wpdb->update( self::t( 'owners' ), array_merge( $fields, array( 'email_ok' => empty( $_POST['email_ok'] ) ? 0 : 1, 'start_date' => $start, 'end_date' => $end, 'updated_at' => current_time( 'mysql' ) ) ), array( 'id' => $id, 'property_id' => $pid ) );
		} else {
			$new = self::insert_owner( $pid, $fields, $start ?: self::today() );
			if ( $new && empty( $_POST['email_ok'] ) ) {
				$wpdb->update( self::t( 'owners' ), array( 'email_ok' => 0 ), array( 'id' => $new ) );
			}
		}
		self::back( array( 'community' => $prop->community_id, 'unit' => $pid, 'hoa_msg' => 'saved' ) );
	}

	/** Ends one owner (e.g. a co-owner who left the title) on a date; they move to history. */
	public static function handle_owner_end() {
		self::guard( 'ajcore_hoa_owner_end' );
		global $wpdb;
		$id    = absint( self::post( 'owner_id' ) );
		$owner = $wpdb->get_row( $wpdb->prepare( 'SELECT o.*, p.community_id FROM ' . self::t( 'owners' ) . ' o JOIN ' . self::t( 'properties' ) . ' p ON p.id = o.property_id WHERE o.id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $owner ) {
			$wpdb->update( self::t( 'owners' ), array( 'end_date' => self::date( self::post( 'end_date' ) ) ?: self::today(), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			self::back( array( 'community' => $owner->community_id, 'unit' => $owner->property_id, 'hoa_msg' => 'saved' ) );
		}
		self::back();
	}

	public static function handle_owner_delete() {
		self::guard( 'ajcore_hoa_owner_delete' );
		global $wpdb;
		$id    = absint( self::post( 'owner_id' ) );
		$owner = $wpdb->get_row( $wpdb->prepare( 'SELECT o.property_id, p.community_id FROM ' . self::t( 'owners' ) . ' o JOIN ' . self::t( 'properties' ) . ' p ON p.id = o.property_id WHERE o.id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $owner ) {
			$wpdb->delete( self::t( 'owners' ), array( 'id' => $id ) );
			self::back( array( 'community' => $owner->community_id, 'unit' => $owner->property_id, 'hoa_msg' => 'saved' ) );
		}
		self::back();
	}

	/** Sale: every current owner ends on the sale date, the new owners (one per line: name, email, phone) start on it. */
	public static function handle_transfer() {
		self::guard( 'ajcore_hoa_transfer' );
		global $wpdb;
		$pid  = absint( self::post( 'property_id' ) );
		$prop = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'properties' ) . ' WHERE id = %d', $pid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $prop ) {
			self::back();
		}
		$date = self::date( self::post( 'sale_date' ) ) ?: self::today();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'owners' ) . ' SET end_date = %s, updated_at = %s WHERE property_id = %d AND end_date IS NULL', $date, current_time( 'mysql' ), $pid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$added = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::post( 'new_owners' ) ) as $line ) {
			$cells = array_map( 'trim', str_getcsv( $line ) );
			if ( '' === ( $cells[0] ?? '' ) ) {
				continue;
			}
			self::insert_owner( $pid, self::owner_fields( array( 'owner_name' => $cells[0], 'email' => $cells[1] ?? '', 'phone' => $cells[2] ?? '' ) ), $date );
			$added++;
		}
		self::back( array( 'community' => $prop->community_id, 'unit' => $pid, 'hoa_msg' => 'sold', 'n' => $added ) );
	}

	/** Creates a violation request for the community and links it to the unit (it stays with the property). */
	public static function handle_violation_add() {
		self::guard( 'ajcore_hoa_violation_add' );
		global $wpdb;
		$pid  = absint( self::post( 'property_id' ) );
		$prop = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'properties' ) . ' WHERE id = %d', $pid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $prop || ! class_exists( 'AJForms' ) || ! AJForms::$instance ) {
			self::back();
		}
		$what   = sanitize_text_field( self::post( 'title' ) );
		$owners = self::current_owner_names( $pid );
		$notes  = trim( sanitize_textarea_field( self::post( 'notes' ) ) . "\nUnit: " . $prop->unit . ( $owners ? "\nOwner(s) at the time: " . $owners : '' ) );
		$res    = AJForms::$instance->api_create_staff_service_request( $prop->community_id, 'Violation, Unit ' . $prop->unit . ( '' !== $what ? ': ' . $what : '' ), AJCore_HOA_Workflows::VIOLATION, $notes );
		if ( is_wp_error( $res ) ) {
			self::back( array( 'community' => $prop->community_id, 'unit' => $pid, 'hoa_error' => rawurlencode( $res->get_error_message() ) ) );
		}
		$dup = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'unit_requests' ) . ' WHERE request_id = %d', (int) $res['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $dup ) {
			$wpdb->insert( self::t( 'unit_requests' ), array( 'property_id' => $pid, 'request_id' => (int) $res['id'], 'owners_snapshot' => $owners, 'created_at' => current_time( 'mysql' ) ) );
		}
		self::back( array( 'community' => $prop->community_id, 'unit' => $pid, 'hoa_msg' => 'violation' ) );
	}

	/** CSV: unit, owner_name, email, phone, mailing_address, start_date, end_date, notes. Several rows per unit = several owners. */
	public static function handle_import() {
		self::guard( 'ajcore_hoa_import' );
		global $wpdb;
		$community = sanitize_text_field( self::post( 'community' ) );
		$text      = '';
		if ( ! empty( $_FILES['csv']['tmp_name'] ) && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			$text = (string) file_get_contents( $_FILES['csv']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			$text = (string) self::post( 'csv_text' );
		}
		$units = 0;
		$added = 0;
		$skip  = 0;
		if ( '' !== $community && '' !== trim( $text ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', trim( $text ) ) as $i => $line ) {
				if ( '' === trim( $line ) ) {
					continue;
				}
				$c = str_getcsv( $line );
				if ( 0 === $i && false !== stripos( (string) ( $c[0] ?? '' ), 'unit' ) ) {
					continue;
				}
				$unit = sanitize_text_field( (string) ( $c[0] ?? '' ) );
				$name = sanitize_text_field( (string) ( $c[1] ?? '' ) );
				if ( '' === $unit ) {
					$skip++;
					continue;
				}
				$pid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'properties' ) . ' WHERE community_id = %s AND unit = %s', $community, $unit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( ! $pid ) {
					$now = current_time( 'mysql' );
					$wpdb->insert( self::t( 'properties' ), array( 'community_id' => $community, 'unit' => $unit, 'created_at' => $now, 'updated_at' => $now ) );
					$pid = (int) $wpdb->insert_id;
					$units++;
				}
				if ( '' === $name ) {
					continue;
				}
				$start = self::date( $c[5] ?? '' );
				$dup   = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'owners' ) . ' WHERE property_id = %d AND owner_name = %s AND ( start_date <=> %s ) LIMIT 1', $pid, $name, $start ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $dup ) {
					$skip++;
					continue;
				}
				self::insert_owner( $pid, self::owner_fields( array( 'owner_name' => $name, 'email' => $c[2] ?? '', 'phone' => $c[3] ?? '', 'mailing_address' => $c[4] ?? '', 'notes' => $c[7] ?? '' ) ), $start, self::date( $c[6] ?? '' ) );
				$added++;
			}
		}
		self::back( array( 'community' => $community, 'hoa_msg' => 'imported', 'n' => $added, 'u' => $units, 'skipped' => $skip ) );
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ajcore-hoa' ) );
		}
		check_admin_referer( 'ajcore_hoa_export' );
		global $wpdb;
		$community = sanitize_text_field( wp_unslash( $_GET['community'] ?? '' ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( 'SELECT p.unit, o.owner_name, o.email, o.phone, o.mailing_address, o.start_date, o.end_date, o.notes FROM ' . self::t( 'properties' ) . ' p LEFT JOIN ' . self::t( 'owners' ) . ' o ON o.property_id = p.id WHERE p.community_id = %s ORDER BY p.unit ASC, o.start_date ASC', $community ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="homeowners.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'unit', 'owner_name', 'email', 'phone', 'mailing_address', 'start_date', 'end_date', 'notes' ) );
		foreach ( (array) $rows as $row ) {
			fputcsv( $out, $row );
		}
		exit;
	}

	// ---------------------------------------------------------------- screens

	private static function css() {
		?>
		<style>
			.hoa-wrap{display:grid;grid-template-columns:240px 1fr;gap:16px;align-items:start}
			.hoa-rail,.hoa-main{background:#fff;border:1px solid #dbe7f3;border-radius:18px}
			.hoa-rail{padding:10px}
			.hoa-rail input{width:100%;margin-bottom:8px}
			.hoa-rail a{display:flex;justify-content:space-between;gap:8px;padding:8px 10px;border-radius:10px;color:#0f172a;text-decoration:none;font-size:13px}
			.hoa-rail a:hover{background:#f1f5f9}.hoa-rail a.on{background:#0f172a;color:#fff}
			.hoa-rail a b{font-weight:600;background:#eef2f7;color:#475569;border-radius:10px;padding:0 8px;font-size:12px;line-height:20px}
			.hoa-rail a.on b{background:#334155;color:#fff}
			.hoa-main{padding:14px 16px}
			.hoa-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}.hoa-bar .sp{flex:1}
			.hoa-chip{background:#f1f5f9;border-radius:999px;padding:5px 12px;font-size:13px}.hoa-chip b{font-size:15px}
			.hoa-tbl{width:100%;border-collapse:collapse;font-size:13px}
			.hoa-tbl th{text-align:left;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;padding:8px 10px;border-bottom:1px solid #e2e8f0}
			.hoa-tbl td{padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:top}
			.hoa-tbl tbody tr.hoa-row{cursor:pointer}.hoa-tbl tbody tr.hoa-row:hover td{background:#f8fafc}
			.hoa-unit{font-weight:700;font-size:14px}.hoa-sub{color:#64748b;font-size:12px}
			.hoa-names span{display:inline-block;background:#e8f0fe;color:#1e40af;border-radius:999px;padding:1px 10px;margin:0 4px 3px 0;font-size:12px}
			.hoa-pill{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12px;background:#e5e7eb;color:#4b5563}
			.hoa-pill.red{background:#fee2e2;color:#b91c1c}.hoa-pill.green{background:#dcfce7;color:#166534}
			.hoa-warn{color:#b45309;font-size:12px}
			.hoa-h{display:flex;align-items:center;gap:10px;margin:18px 0 8px}.hoa-h h3{margin:0;font-size:15px}.hoa-h .sp{flex:1}
			.hoa-hist td,.hoa-hist th{color:#64748b}
			dialog.hoa-dlg{border:0;border-radius:18px;padding:0;width:min(560px,94vw);box-shadow:0 20px 60px rgba(0,0,0,.3)}
			dialog.hoa-dlg::backdrop{background:rgba(0,0,0,.45)}
			.hoa-dlg form{padding:20px}.hoa-dlg h2{margin:0 0 6px}
			.hoa-dlg label{display:block;font-weight:600;font-size:12px;margin:10px 0 3px}
			.hoa-dlg input[type=text],.hoa-dlg input[type=email],.hoa-dlg input[type=date],.hoa-dlg textarea{width:100%}
			.hoa-g2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
			.hoa-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
			@media(max-width:900px){.hoa-wrap{grid-template-columns:1fr}}
		</style>
		<?php
	}

	private static function notices() {
		$msg = sanitize_key( $_GET['hoa_msg'] ?? '' );
		$n   = absint( $_GET['n'] ?? 0 );
		$map = array(
			'saved'     => __( 'Saved.', 'ajcore-hoa' ),
			'deleted'   => __( 'Deleted. Units with violation history are kept.', 'ajcore-hoa' ),
			'violation' => __( 'Violation created and linked to this unit.', 'ajcore-hoa' ),
			/* translators: %d: new owners */
			'sold'      => sprintf( __( 'Sale recorded. Previous owners moved to history; %d new owner(s) added.', 'ajcore-hoa' ), $n ),
			/* translators: 1: owners added, 2: units added, 3: skipped */
			'imported'  => sprintf( __( 'Imported %1$d owner(s) and %2$d new unit(s); skipped %3$d.', 'ajcore-hoa' ), $n, absint( $_GET['u'] ?? 0 ), absint( $_GET['skipped'] ?? 0 ) ),
		);
		if ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
		}
		if ( ! empty( $_GET['hoa_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['hoa_error'] ) ) ) . '</p></div>';
		}
	}

	private static function hidden( $action, $nonce, $extra = array() ) {
		echo '<input type="hidden" name="action" value="ajcore_hoa_' . esc_attr( $action ) . '">';
		wp_nonce_field( $nonce );
		foreach ( $extra as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
	}

	private static function fmt( $d ) {
		return $d ? mysql2date( 'M j, Y', $d ) : '';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$communities = self::communities();
		self::css();
		echo '<div class="ajcore-admin-panel" style="padding:14px">';
		self::notices();
		if ( ! $communities ) {
			echo '<p>' . esc_html__( 'No communities yet. Add one under Customers (type Local).', 'ajcore-hoa' ) . '</p></div>';
			return;
		}
		$counts = array();
		foreach ( (array) $wpdb->get_results( 'SELECT community_id, COUNT(*) AS n FROM ' . self::t( 'properties' ) . ' GROUP BY community_id' ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$counts[ $c->community_id ] = (int) $c->n;
		}
		$unit_id = absint( $_GET['unit'] ?? 0 );
		$prop    = $unit_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'properties' ) . ' WHERE id = %d', $unit_id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL
		$current = sanitize_text_field( wp_unslash( $_GET['community'] ?? '' ) );
		if ( $prop ) {
			$current = $prop->community_id;
		}
		if ( '' === $current ) {
			$with    = array_intersect_key( $communities, $counts );
			$current = (string) key( $with ? $with : $communities );
		}
		echo '<div class="hoa-wrap"><div class="hoa-rail"><input type="search" id="hoa-comm-search" placeholder="' . esc_attr__( 'Find community…', 'ajcore-hoa' ) . '">';
		foreach ( $communities as $cid => $label ) {
			printf( '<a href="%s" class="%s" data-name="%s"><span>%s</span><b>%d</b></a>', esc_url( self::url( array( 'community' => $cid ) ) ), (string) $cid === $current ? 'on' : '', esc_attr( strtolower( $label ) ), esc_html( $label ), (int) ( $counts[ $cid ] ?? 0 ) );
		}
		echo '</div><div class="hoa-main">';
		if ( $prop ) {
			self::render_unit( $prop, $communities[ $current ] ?? $current );
		} else {
			self::render_list( $current, $communities[ $current ] ?? $current );
		}
		echo '</div></div>';
		?>
		<script>
		(function(){
			var cq=document.getElementById('hoa-comm-search');
			if(cq){cq.addEventListener('input',function(){var t=cq.value.toLowerCase();document.querySelectorAll('.hoa-rail a').forEach(function(a){a.style.display=a.getAttribute('data-name').indexOf(t)>-1?'':'none';});});}
			document.querySelectorAll('[data-hoa-dlg]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();var d=document.getElementById(b.getAttribute('data-hoa-dlg'));var j=b.getAttribute('data-fill');if(j){var o=JSON.parse(j);Object.keys(o).forEach(function(k){var f=d.querySelector('[name="'+k+'"]');if(!f)return;if(f.type==='checkbox'){f.checked=!!o[k];}else{f.value=o[k]==null?'':o[k];}});}d.showModal();});});
			document.querySelectorAll('dialog.hoa-dlg').forEach(function(d){
				d.addEventListener('click',function(e){if(e.target===d||e.target.hasAttribute('data-hoa-close'))d.close();});
				d.querySelector('form').addEventListener('submit',function(){var s=d.querySelector('button[type=submit]');setTimeout(function(){s.disabled=true;},0);});
			});
			document.querySelectorAll('tr[data-href]').forEach(function(r){r.addEventListener('click',function(e){if(e.target.closest('input,a,button'))return;window.location=r.getAttribute('data-href');});});
			var q=document.getElementById('hoa-search');
			if(q){q.addEventListener('input',function(){var t=q.value.toLowerCase();document.querySelectorAll('#hoa-tbl tbody tr').forEach(function(r){r.style.display=r.getAttribute('data-q').indexOf(t)>-1?'':'none';});});}
			var all=document.getElementById('hoa-all'),btn=document.getElementById('hoa-del-btn');
			function sync(){var n=document.querySelectorAll('.hoa-chk:checked').length;if(btn){btn.style.display=n?'':'none';btn.textContent='Delete selected ('+n+')';}}
			if(all){all.addEventListener('change',function(){document.querySelectorAll('.hoa-chk').forEach(function(c){if(c.closest('tr').style.display!=='none')c.checked=all.checked;});sync();});}
			document.querySelectorAll('.hoa-chk').forEach(function(c){c.addEventListener('change',sync);});
		})();
		</script>
		<?php
		echo '</div>';
	}

	private static function render_list( $community, $label ) {
		global $wpdb;
		$props = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'properties' ) . ' WHERE community_id = %s ORDER BY unit ASC', $community ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$owners_by = array();
		$viol_by   = array();
		if ( $props ) {
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $props, 'id' ) ) );
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::t( 'owners' ) . " WHERE property_id IN ({$ids}) AND end_date IS NULL ORDER BY id" ) as $o ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$owners_by[ $o->property_id ][] = $o;
			}
			foreach ( (array) $wpdb->get_results( 'SELECT property_id, COUNT(*) AS n FROM ' . self::t( 'unit_requests' ) . " WHERE property_id IN ({$ids}) GROUP BY property_id" ) as $v ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$viol_by[ $v->property_id ] = (int) $v->n;
			}
		}
		$emailable = 0;
		$vacant    = 0;
		foreach ( $props as $p ) {
			$cur = $owners_by[ $p->id ] ?? array();
			if ( ! $cur ) {
				$vacant++;
			}
			foreach ( $cur as $o ) {
				if ( $o->email && $o->email_ok ) {
					$emailable++;
					break;
				}
			}
		}
		echo '<div class="hoa-bar"><strong style="font-size:15px">' . esc_html( $label ) . '</strong>';
		printf( '<span class="hoa-chip"><b>%d</b> %s</span><span class="hoa-chip"><b>%d</b> %s</span><span class="hoa-chip"><b>%d</b> %s</span>', count( $props ), esc_html__( 'units', 'ajcore-hoa' ), $emailable, esc_html__( 'emailable', 'ajcore-hoa' ), $vacant, esc_html__( 'no current owner', 'ajcore-hoa' ) );
		echo '<input type="search" id="hoa-search" placeholder="' . esc_attr__( 'Search unit or owner…', 'ajcore-hoa' ) . '" style="min-width:200px"><span class="sp"></span>';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ajcore_hoa_export&community=' . rawurlencode( $community ) ), 'ajcore_hoa_export' ) ) . '">' . esc_html__( 'Export CSV', 'ajcore-hoa' ) . '</a>';
		echo '<button type="button" class="button" data-hoa-dlg="hoa-import">' . esc_html__( 'Import CSV', 'ajcore-hoa' ) . '</button>';
		echo '<button type="button" class="button button-primary" data-hoa-dlg="hoa-unit-new">' . esc_html__( '+ Add unit', 'ajcore-hoa' ) . '</button></div>';

		if ( ! $props ) {
			echo '<p style="color:#64748b;padding:30px 0;text-align:center">' . esc_html__( 'No units yet. Add a unit, or import a CSV.', 'ajcore-hoa' ) . '</p>';
		} else {
			echo '<form method="post" id="hoa-del-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Delete selected units? Units with violations are kept.\');">';
			self::hidden( 'unit_delete', 'ajcore_hoa_unit_delete', array( 'community' => $community ) );
			echo '</form>';
			echo '<table class="hoa-tbl" id="hoa-tbl"><thead><tr><th style="width:24px"><input type="checkbox" id="hoa-all"></th><th>' . esc_html__( 'Unit', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Current owners', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Contact', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Violations', 'ajcore-hoa' ) . '</th></tr></thead><tbody>';
			foreach ( $props as $p ) {
				$cur   = $owners_by[ $p->id ] ?? array();
				$names = implode( ' ', wp_list_pluck( $cur, 'owner_name' ) );
				$v     = $viol_by[ $p->id ] ?? 0;
				printf( '<tr class="hoa-row" data-href="%s" data-q="%s"><td><input type="checkbox" class="hoa-chk" form="hoa-del-form" name="unit_ids[]" value="%d"></td>', esc_url( self::url( array( 'unit' => $p->id ) ) ), esc_attr( strtolower( $p->unit . ' ' . $p->address . ' ' . $names ) ), (int) $p->id );
				echo '<td><span class="hoa-unit">' . esc_html( $p->unit ) . '</span>' . ( $p->address ? '<div class="hoa-sub">' . esc_html( $p->address ) . '</div>' : '' ) . '</td><td class="hoa-names">';
				if ( $cur ) {
					foreach ( $cur as $o ) {
						echo '<span>' . esc_html( $o->owner_name ) . '</span>';
					}
				} else {
					echo '<span class="hoa-warn">' . esc_html__( 'No current owner', 'ajcore-hoa' ) . '</span>';
				}
				echo '</td><td class="hoa-sub">';
				$any = false;
				foreach ( $cur as $o ) {
					if ( $o->email ) {
						$any = true;
						echo esc_html( $o->email ) . ( $o->email_ok ? '' : ' <span class="hoa-warn">' . esc_html__( '(do not email)', 'ajcore-hoa' ) . '</span>' ) . '<br>';
					}
				}
				if ( $cur && ! $any ) {
					echo '<span class="hoa-warn">' . esc_html__( 'No email: mail only', 'ajcore-hoa' ) . '</span>';
				}
				echo '</td><td>' . ( $v ? '<span class="hoa-pill red">' . (int) $v . '</span>' : '<span class="hoa-sub">—</span>' ) . '</td></tr>';
			}
			echo '</tbody></table><p style="margin-top:12px"><button type="submit" form="hoa-del-form" class="button" id="hoa-del-btn" style="color:#b32d2e;border-color:#b32d2e;display:none">' . esc_html__( 'Delete selected', 'ajcore-hoa' ) . '</button></p>';
		}
		?>
		<dialog class="hoa-dlg" id="hoa-unit-new"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::hidden( 'unit_save', 'ajcore_hoa_unit_save', array( 'community' => $community ) ); ?>
			<h2><?php esc_html_e( 'Add unit', 'ajcore-hoa' ); ?></h2>
			<div class="hoa-g2"><div><label><?php esc_html_e( 'Unit', 'ajcore-hoa' ); ?> *</label><input type="text" name="unit" required></div><div><label><?php esc_html_e( 'Street address', 'ajcore-hoa' ); ?></label><input type="text" name="address"></div></div>
			<p class="hoa-sub" style="margin:12px 0 0"><?php esc_html_e( 'First owner (optional, more can be added on the unit):', 'ajcore-hoa' ); ?></p>
			<div class="hoa-g2"><div><label><?php esc_html_e( 'Owner name', 'ajcore-hoa' ); ?></label><input type="text" name="owner_name"></div><div><label><?php esc_html_e( 'Owner since', 'ajcore-hoa' ); ?></label><input type="date" name="start_date"></div><div><label><?php esc_html_e( 'Email', 'ajcore-hoa' ); ?></label><input type="email" name="email"></div><div><label><?php esc_html_e( 'Phone', 'ajcore-hoa' ); ?></label><input type="text" name="phone"></div></div>
			<div class="hoa-foot"><button type="button" class="button" data-hoa-close><?php esc_html_e( 'Cancel', 'ajcore-hoa' ); ?></button><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ajcore-hoa' ); ?></button></div>
		</form></dialog>
		<dialog class="hoa-dlg" id="hoa-import"><form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::hidden( 'import', 'ajcore_hoa_import', array( 'community' => $community ) ); ?>
			<h2><?php esc_html_e( 'Import units and owners', 'ajcore-hoa' ); ?></h2>
			<p class="hoa-sub"><code>unit, owner_name, email, phone, mailing_address, start_date, end_date, notes</code><br><?php esc_html_e( 'One row per owner: repeat the unit for each co-owner. Dates are YYYY-MM-DD; an end date puts the owner in history.', 'ajcore-hoa' ); ?></p>
			<label><?php esc_html_e( 'CSV file', 'ajcore-hoa' ); ?></label><input type="file" name="csv" accept=".csv,text/csv">
			<label><?php esc_html_e( 'Or paste rows', 'ajcore-hoa' ); ?></label><textarea name="csv_text" rows="5" placeholder="101,Jane Smith,jane@example.com,555-0100,,2021-06-01"></textarea>
			<div class="hoa-foot"><button type="button" class="button" data-hoa-close><?php esc_html_e( 'Cancel', 'ajcore-hoa' ); ?></button><button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'ajcore-hoa' ); ?></button></div>
		</form></dialog>
		<?php
	}

	private static function render_unit( $p, $label ) {
		global $wpdb;
		$owners  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'owners' ) . ' WHERE property_id = %d ORDER BY start_date DESC, id DESC', $p->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$current = array_values( array_filter( $owners, function ( $o ) { return empty( $o->end_date ); } ) );
		$past    = array_values( array_filter( $owners, function ( $o ) { return ! empty( $o->end_date ); } ) );
		usort( $past, function ( $a, $b ) { return strcmp( (string) $b->end_date, (string) $a->end_date ); } );
		$links   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'unit_requests' ) . ' WHERE property_id = %d ORDER BY id DESC', $p->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$requests = array();
		$labels   = array();
		if ( $links && class_exists( 'AJCore_Extensions' ) ) {
			$kit = AJCore_Extensions::rest_toolkit();
			$db  = isset( $kit['portal_db'] ) ? $kit['portal_db']() : null;
			if ( $db ) {
				$tbl = $kit['portal_table']( 'aj_portal_service_requests' );
				$ids = implode( ',', array_map( 'intval', wp_list_pluck( $links, 'request_id' ) ) );
				foreach ( (array) $db->get_results( "SELECT id, service_request_number, service_name, service_status, created_at FROM `{$tbl}` WHERE id IN ({$ids})" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
					$requests[ (int) $r->id ] = $r;
				}
			}
			if ( class_exists( 'AJForms_Admin' ) ) {
				$labels = ( AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin() )->get_service_status_labels_for_ops();
			}
		}

		echo '<div class="hoa-bar"><a class="button" href="' . esc_url( self::url( array( 'community' => $p->community_id ) ) ) . '">← ' . esc_html( $label ) . '</a><strong style="font-size:17px">' . esc_html( sprintf( __( 'Unit %s', 'ajcore-hoa' ), $p->unit ) ) . '</strong>';
		if ( $p->address ) {
			echo '<span class="hoa-sub">' . esc_html( $p->address ) . '</span>';
		}
		echo '<span class="sp"></span><button type="button" class="button" data-hoa-dlg="hoa-unit-edit">' . esc_html__( 'Edit unit', 'ajcore-hoa' ) . '</button></div>';
		if ( $p->notes ) {
			echo '<p class="hoa-sub" style="white-space:pre-line">' . esc_html( $p->notes ) . '</p>';
		}

		// Current owners.
		echo '<div class="hoa-h"><h3>' . esc_html__( 'Current owners', 'ajcore-hoa' ) . '</h3><span class="sp"></span>';
		echo '<button type="button" class="button" data-hoa-dlg="hoa-sale">' . esc_html__( 'Record a sale', 'ajcore-hoa' ) . '</button>';
		echo '<button type="button" class="button button-primary" data-hoa-dlg="hoa-owner" data-fill="' . esc_attr( wp_json_encode( array( 'owner_id' => '', 'owner_name' => '', 'email' => '', 'phone' => '', 'mailing_address' => '', 'start_date' => self::today(), 'end_date' => '', 'notes' => '', 'email_ok' => 1 ) ) ) . '">' . esc_html__( '+ Add owner', 'ajcore-hoa' ) . '</button></div>';
		if ( ! $current ) {
			echo '<p class="hoa-warn">' . esc_html__( 'No current owner on record.', 'ajcore-hoa' ) . '</p>';
		} else {
			echo '<table class="hoa-tbl"><thead><tr><th>' . esc_html__( 'Owner', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Contact', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Mailing address', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Owner since', 'ajcore-hoa' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $current as $o ) {
				self::owner_row( $o, true );
			}
			echo '</tbody></table>';
		}

		// Violations (stay with the unit).
		echo '<div class="hoa-h"><h3>' . esc_html__( 'Violations', 'ajcore-hoa' ) . '</h3><span class="hoa-sub">' . esc_html__( 'Stay with the property, whoever owns it.', 'ajcore-hoa' ) . '</span><span class="sp"></span><button type="button" class="button button-primary" data-hoa-dlg="hoa-violation">' . esc_html__( '+ New violation', 'ajcore-hoa' ) . '</button></div>';
		if ( ! $links ) {
			echo '<p class="hoa-sub">' . esc_html__( 'No violations on record for this unit.', 'ajcore-hoa' ) . '</p>';
		} else {
			echo '<table class="hoa-tbl"><thead><tr><th>' . esc_html__( 'Request', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Violation', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Step', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Owner(s) at the time', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Opened', 'ajcore-hoa' ) . '</th></tr></thead><tbody>';
			foreach ( $links as $l ) {
				$r      = $requests[ (int) $l->request_id ] ?? null;
				$status = $r ? (string) $r->service_status : '';
				$done   = in_array( $status, array( 'completed', 'cancelled' ), true );
				echo '<tr><td><a href="' . esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'requests', 's' => $r ? $r->service_request_number : '' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $r ? $r->service_request_number : '#' . $l->request_id ) . '</a></td><td>' . esc_html( $r ? $r->service_name : __( '(request deleted)', 'ajcore-hoa' ) ) . '</td><td><span class="hoa-pill ' . ( $done ? 'green' : 'red' ) . '">' . esc_html( $labels[ $status ] ?? ( '' !== $status ? $status : '—' ) ) . '</span></td><td class="hoa-sub">' . esc_html( $l->owners_snapshot ?: '—' ) . '</td><td class="hoa-sub">' . esc_html( self::fmt( $l->created_at ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// History.
		echo '<div class="hoa-h"><h3>' . esc_html__( 'Previous owners', 'ajcore-hoa' ) . '</h3></div>';
		if ( ! $past ) {
			echo '<p class="hoa-sub">' . esc_html__( 'None. When the home is sold, use Record a sale and the old owners are kept here.', 'ajcore-hoa' ) . '</p>';
		} else {
			echo '<table class="hoa-tbl hoa-hist"><thead><tr><th>' . esc_html__( 'Owner', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Contact', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Mailing address', 'ajcore-hoa' ) . '</th><th>' . esc_html__( 'Owned', 'ajcore-hoa' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $past as $o ) {
				self::owner_row( $o, false );
			}
			echo '</tbody></table>';
		}

		self::unit_dialogs( $p, $current );
	}

	private static function owner_row( $o, $is_current ) {
		$data = array( 'owner_id' => (int) $o->id, 'owner_name' => $o->owner_name, 'email' => $o->email, 'phone' => $o->phone, 'mailing_address' => (string) $o->mailing_address, 'start_date' => (string) $o->start_date, 'end_date' => (string) $o->end_date, 'notes' => (string) $o->notes, 'email_ok' => (int) $o->email_ok );
		echo '<tr><td><strong>' . esc_html( $o->owner_name ) . '</strong>' . ( $o->notes ? '<div class="hoa-sub">' . esc_html( wp_trim_words( $o->notes, 10 ) ) . '</div>' : '' ) . '</td><td>';
		if ( $o->email ) {
			echo '<a href="mailto:' . esc_attr( $o->email ) . '">' . esc_html( $o->email ) . '</a>' . ( $o->email_ok ? '' : ' <span class="hoa-warn">' . esc_html__( '(do not email)', 'ajcore-hoa' ) . '</span>' );
		} elseif ( $is_current ) {
			echo '<span class="hoa-warn">' . esc_html__( 'No email: mail only', 'ajcore-hoa' ) . '</span>';
		}
		echo ( $o->phone ? '<div class="hoa-sub">' . esc_html( $o->phone ) . '</div>' : '' ) . '</td><td class="hoa-sub" style="white-space:pre-line">' . ( $o->mailing_address ? esc_html( $o->mailing_address ) : '—' ) . '</td>';
		echo '<td class="hoa-sub">' . esc_html( $o->start_date ? self::fmt( $o->start_date ) : __( 'unknown', 'ajcore-hoa' ) ) . ( $o->end_date ? ' → ' . esc_html( self::fmt( $o->end_date ) ) : ( $is_current ? ' → ' . esc_html__( 'present', 'ajcore-hoa' ) : '' ) ) . '</td>';
		echo '<td style="text-align:right;white-space:nowrap"><button type="button" class="button button-small" data-hoa-dlg="hoa-owner" data-fill="' . esc_attr( wp_json_encode( $data ) ) . '">' . esc_html__( 'Edit', 'ajcore-hoa' ) . '</button> ';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( $is_current ? __( 'Remove this owner from the current owners (kept in history)?', 'ajcore-hoa' ) : __( 'Delete this historical owner record?', 'ajcore-hoa' ) ) . '\');">';
		if ( $is_current ) {
			self::hidden( 'owner_end', 'ajcore_hoa_owner_end', array( 'owner_id' => $o->id, 'end_date' => self::today() ) );
			echo '<button type="submit" class="button button-small">' . esc_html__( 'End', 'ajcore-hoa' ) . '</button>';
		} else {
			self::hidden( 'owner_delete', 'ajcore_hoa_owner_delete', array( 'owner_id' => $o->id ) );
			echo '<button type="submit" class="button button-small" style="color:#b32d2e">' . esc_html__( 'Delete', 'ajcore-hoa' ) . '</button>';
		}
		echo '</form></td></tr>';
	}

	private static function unit_dialogs( $p, $current ) {
		$names = implode( ', ', wp_list_pluck( $current, 'owner_name' ) );
		?>
		<dialog class="hoa-dlg" id="hoa-unit-edit"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::hidden( 'unit_save', 'ajcore_hoa_unit_save', array( 'community' => $p->community_id, 'unit_id' => $p->id ) ); ?>
			<h2><?php esc_html_e( 'Edit unit', 'ajcore-hoa' ); ?></h2>
			<div class="hoa-g2"><div><label><?php esc_html_e( 'Unit', 'ajcore-hoa' ); ?> *</label><input type="text" name="unit" required value="<?php echo esc_attr( $p->unit ); ?>"></div><div><label><?php esc_html_e( 'Street address', 'ajcore-hoa' ); ?></label><input type="text" name="address" value="<?php echo esc_attr( $p->address ); ?>"></div></div>
			<label><?php esc_html_e( 'Notes', 'ajcore-hoa' ); ?></label><textarea name="notes" rows="3"><?php echo esc_textarea( (string) $p->notes ); ?></textarea>
			<div class="hoa-foot"><button type="button" class="button" data-hoa-close><?php esc_html_e( 'Cancel', 'ajcore-hoa' ); ?></button><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ajcore-hoa' ); ?></button></div>
		</form></dialog>

		<dialog class="hoa-dlg" id="hoa-owner"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::hidden( 'owner_save', 'ajcore_hoa_owner_save', array( 'property_id' => $p->id ) ); ?>
			<input type="hidden" name="owner_id" value="">
			<h2><?php esc_html_e( 'Owner', 'ajcore-hoa' ); ?></h2>
			<div class="hoa-g2">
				<div><label><?php esc_html_e( 'Name', 'ajcore-hoa' ); ?> *</label><input type="text" name="owner_name" required></div>
				<div><label><?php esc_html_e( 'Phone', 'ajcore-hoa' ); ?></label><input type="text" name="phone"></div>
				<div><label><?php esc_html_e( 'Email', 'ajcore-hoa' ); ?></label><input type="email" name="email"></div>
				<div><label>&nbsp;</label><label style="font-weight:400"><input type="checkbox" name="email_ok" value="1" checked> <?php esc_html_e( 'OK to email notices', 'ajcore-hoa' ); ?></label></div>
				<div><label><?php esc_html_e( 'Owner since', 'ajcore-hoa' ); ?></label><input type="date" name="start_date"></div>
				<div><label><?php esc_html_e( 'Owned until (blank = current)', 'ajcore-hoa' ); ?></label><input type="date" name="end_date"></div>
			</div>
			<label><?php esc_html_e( 'Mailing address (if not the unit)', 'ajcore-hoa' ); ?></label><textarea name="mailing_address" rows="2"></textarea>
			<label><?php esc_html_e( 'Notes', 'ajcore-hoa' ); ?></label><textarea name="notes" rows="2"></textarea>
			<div class="hoa-foot"><button type="button" class="button" data-hoa-close><?php esc_html_e( 'Cancel', 'ajcore-hoa' ); ?></button><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ajcore-hoa' ); ?></button></div>
		</form></dialog>

		<dialog class="hoa-dlg" id="hoa-sale"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::hidden( 'transfer', 'ajcore_hoa_transfer', array( 'property_id' => $p->id ) ); ?>
			<h2><?php esc_html_e( 'Record a sale', 'ajcore-hoa' ); ?></h2>
			<p class="hoa-sub"><?php echo esc_html( $names ? sprintf( __( 'Current owners (%s) move to Previous owners on the sale date. Violations stay with the unit.', 'ajcore-hoa' ), $names ) : __( 'Violations stay with the unit.', 'ajcore-hoa' ) ); ?></p>
			<label><?php esc_html_e( 'Sale date', 'ajcore-hoa' ); ?></label><input type="date" name="sale_date" value="<?php echo esc_attr( self::today() ); ?>">
			<label><?php esc_html_e( 'New owners (one per line: name, email, phone)', 'ajcore-hoa' ); ?></label><textarea name="new_owners" rows="4" placeholder="Jane Smith, jane@example.com, 555-0100&#10;John Smith"></textarea>
			<div class="hoa-foot"><button type="button" class="button" data-hoa-close><?php esc_html_e( 'Cancel', 'ajcore-hoa' ); ?></button><button type="submit" class="button button-primary"><?php esc_html_e( 'Record sale', 'ajcore-hoa' ); ?></button></div>
		</form></dialog>

		<dialog class="hoa-dlg" id="hoa-violation"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::hidden( 'violation_add', 'ajcore_hoa_violation_add', array( 'property_id' => $p->id ) ); ?>
			<h2><?php esc_html_e( 'New violation', 'ajcore-hoa' ); ?></h2>
			<p class="hoa-sub"><?php esc_html_e( 'Creates a Violation request and links it to this unit. It starts at New and moves through the notice steps under Requests.', 'ajcore-hoa' ); ?></p>
			<label><?php esc_html_e( 'What is the violation?', 'ajcore-hoa' ); ?></label><input type="text" name="title" maxlength="150" placeholder="Trash cans left out">
			<label><?php esc_html_e( 'Notes', 'ajcore-hoa' ); ?></label><textarea name="notes" rows="3"></textarea>
			<div class="hoa-foot"><button type="button" class="button" data-hoa-close><?php esc_html_e( 'Cancel', 'ajcore-hoa' ); ?></button><button type="submit" class="button button-primary"><?php esc_html_e( 'Create violation', 'ajcore-hoa' ); ?></button></div>
		</form></dialog>
		<?php
	}
}
