<?php
/**
 * HOA homeowners directory: communities (AJ Core customers) and the units / homeowners in each one.
 * No payments or portal logins. It exists so violation notices can be emailed and mailed to the right owner.
 * Screen: AJ Core > HOA Homeowners. Data: {prefix}aj_hoa_units in this site's WordPress database.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_HOA_Homeowners {

	const DB_VERSION = '1';
	const PAGE       = 'ajcore-hoa-homeowners';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_post_ajcore_hoa_unit_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ajcore_hoa_unit_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_ajcore_hoa_unit_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_ajcore_hoa_unit_export', array( __CLASS__, 'handle_export' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_hoa_units';
	}

	public static function maybe_install() {
		if ( get_option( 'ajcore_hoa_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			community_id varchar(64) NOT NULL,
			unit varchar(100) NOT NULL DEFAULT '',
			owner_name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			mailing_address text NULL,
			occupancy varchar(20) NOT NULL DEFAULT 'owner',
			email_ok tinyint(1) NOT NULL DEFAULT 1,
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY community_id (community_id)
		) {$wpdb->get_charset_collate()};" );
		update_option( 'ajcore_hoa_db_version', self::DB_VERSION );
	}

	public static function menu() {
		add_submenu_page( 'ajforms', __( 'HOA Homeowners', 'ajcore-hoa' ), __( 'HOA Homeowners', 'ajcore-hoa' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	private static function occupancies() {
		return array(
			'owner'  => __( 'Owner-occupied', 'ajcore-hoa' ),
			'tenant' => __( 'Rented', 'ajcore-hoa' ),
			'vacant' => __( 'Vacant', 'ajcore-hoa' ),
		);
	}

	/** Community id => label (AJ Core customers). */
	private static function communities() {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return array();
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		return $admin->get_service_request_customer_options();
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	private static function back( $community, $args = array() ) {
		wp_safe_redirect( self::url( array_merge( array( 'community' => $community ), $args ) ) );
		exit;
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ajcore-hoa' ) );
		}
	}

	private static function clean_row( $src ) {
		$occ = sanitize_key( (string) ( $src['occupancy'] ?? 'owner' ) );
		return array(
			'unit'            => sanitize_text_field( (string) ( $src['unit'] ?? '' ) ),
			'owner_name'      => sanitize_text_field( (string) ( $src['owner_name'] ?? '' ) ),
			'email'           => sanitize_email( (string) ( $src['email'] ?? '' ) ),
			'phone'           => sanitize_text_field( (string) ( $src['phone'] ?? '' ) ),
			'mailing_address' => sanitize_textarea_field( (string) ( $src['mailing_address'] ?? '' ) ),
			'occupancy'       => isset( self::occupancies()[ $occ ] ) ? $occ : 'owner',
			'notes'           => sanitize_textarea_field( (string) ( $src['notes'] ?? '' ) ),
		);
	}

	public static function handle_save() {
		self::guard();
		check_admin_referer( 'ajcore_hoa_unit_save' );
		global $wpdb;
		$community = sanitize_text_field( wp_unslash( $_POST['community'] ?? '' ) );
		$id        = absint( $_POST['unit_id'] ?? 0 );
		$row       = self::clean_row( wp_unslash( $_POST ) );
		if ( '' === $community || '' === $row['unit'] || '' === $row['owner_name'] ) {
			self::back( $community, array( 'hoa_error' => rawurlencode( __( 'Unit and owner name are required.', 'ajcore-hoa' ) ) ) );
		}
		$row['email_ok']    = empty( $_POST['email_ok'] ) ? 0 : 1;
		$row['updated_at']  = current_time( 'mysql' );
		if ( $id ) {
			$wpdb->update( self::table(), $row, array( 'id' => $id, 'community_id' => $community ) );
		} else {
			$row['community_id'] = $community;
			$row['created_at']   = current_time( 'mysql' );
			$wpdb->insert( self::table(), $row );
		}
		self::back( $community, array( 'hoa_msg' => $id ? 'updated' : 'added' ) );
	}

	public static function handle_delete() {
		self::guard();
		check_admin_referer( 'ajcore_hoa_unit_delete' );
		global $wpdb;
		$community = sanitize_text_field( wp_unslash( $_POST['community'] ?? '' ) );
		$ids       = array_filter( array_map( 'absint', (array) ( $_POST['unit_ids'] ?? array() ) ) );
		foreach ( $ids as $id ) {
			$wpdb->delete( self::table(), array( 'id' => $id, 'community_id' => $community ) );
		}
		self::back( $community, array( 'hoa_msg' => 'deleted', 'n' => count( $ids ) ) );
	}

	/** CSV columns: unit, owner_name, email, phone, mailing_address, occupancy, notes (a header row is optional). */
	public static function handle_import() {
		self::guard();
		check_admin_referer( 'ajcore_hoa_unit_import' );
		global $wpdb;
		$community = sanitize_text_field( wp_unslash( $_POST['community'] ?? '' ) );
		$text      = '';
		if ( ! empty( $_FILES['csv']['tmp_name'] ) && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			$text = (string) file_get_contents( $_FILES['csv']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( ! empty( $_POST['csv_text'] ) ) {
			$text = (string) wp_unslash( $_POST['csv_text'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$added   = 0;
		$skipped = 0;
		if ( '' !== $community && '' !== trim( $text ) ) {
			$cols = array( 'unit', 'owner_name', 'email', 'phone', 'mailing_address', 'occupancy', 'notes' );
			foreach ( preg_split( '/\r\n|\r|\n/', trim( $text ) ) as $i => $line ) {
				if ( '' === trim( $line ) ) {
					continue;
				}
				$cells = str_getcsv( $line );
				if ( 0 === $i && false !== stripos( (string) ( $cells[0] ?? '' ), 'unit' ) ) {
					continue; // header row
				}
				$src = array();
				foreach ( $cols as $n => $col ) {
					$src[ $col ] = $cells[ $n ] ?? '';
				}
				$row = self::clean_row( $src );
				if ( '' === $row['unit'] || '' === $row['owner_name'] ) {
					$skipped++;
					continue;
				}
				$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE community_id = %s AND unit = %s AND owner_name = %s LIMIT 1', $community, $row['unit'], $row['owner_name'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $dup ) {
					$skipped++;
					continue;
				}
				$row['community_id'] = $community;
				$row['email_ok']     = 1;
				$row['created_at']   = current_time( 'mysql' );
				$row['updated_at']   = current_time( 'mysql' );
				$wpdb->insert( self::table(), $row );
				$added++;
			}
		}
		self::back( $community, array( 'hoa_msg' => 'imported', 'n' => $added, 'skipped' => $skipped ) );
	}

	public static function handle_export() {
		self::guard();
		check_admin_referer( 'ajcore_hoa_unit_export' );
		global $wpdb;
		$community = sanitize_text_field( wp_unslash( $_GET['community'] ?? '' ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( 'SELECT unit, owner_name, email, phone, mailing_address, occupancy, notes FROM ' . self::table() . ' WHERE community_id = %s ORDER BY unit ASC', $community ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="homeowners.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'unit', 'owner_name', 'email', 'phone', 'mailing_address', 'occupancy', 'notes' ) );
		foreach ( (array) $rows as $row ) {
			fputcsv( $out, $row );
		}
		exit;
	}

	public static function render() {
		self::guard();
		global $wpdb;
		$communities = self::communities();
		$counts      = array();
		foreach ( (array) $wpdb->get_results( 'SELECT community_id, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY community_id' ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$counts[ $c->community_id ] = (int) $c->n;
		}
		$current = sanitize_text_field( wp_unslash( $_GET['community'] ?? '' ) );
		if ( '' === $current && $communities ) {
			$with = array_intersect_key( $communities, $counts );
			$current = (string) key( $with ? $with : $communities );
		}
		$units = $current ? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE community_id = %s ORDER BY unit ASC, owner_name ASC', $current ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL
		$with_email = 0;
		foreach ( $units as $u ) {
			if ( '' !== $u->email && $u->email_ok ) {
				$with_email++;
			}
		}
		$occ = self::occupancies();
		$msg = sanitize_key( $_GET['hoa_msg'] ?? '' );
		$n   = absint( $_GET['n'] ?? 0 );
		?>
		<style>
			.hoa-wrap{display:grid;grid-template-columns:260px 1fr;gap:18px;margin-top:14px;max-width:1280px}
			.hoa-rail,.hoa-main{background:#fff;border:1px solid #dcdcde;border-radius:14px}
			.hoa-rail{padding:10px;align-self:start}
			.hoa-rail input{width:100%;margin-bottom:8px}
			.hoa-rail a{display:flex;justify-content:space-between;gap:8px;padding:8px 10px;border-radius:9px;color:#1d2327;text-decoration:none;font-size:13px}
			.hoa-rail a:hover{background:#f6f7f7}
			.hoa-rail a.on{background:#1d2327;color:#fff}
			.hoa-rail a b{font-weight:600;background:#eef0f3;color:#50575e;border-radius:10px;padding:0 8px;font-size:12px;line-height:20px}
			.hoa-rail a.on b{background:#3c434a;color:#fff}
			.hoa-main{padding:14px 16px}
			.hoa-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
			.hoa-chip{background:#f0f2f5;border-radius:999px;padding:5px 12px;font-size:13px}
			.hoa-chip b{font-size:15px}
			.hoa-bar .sp{flex:1}
			.hoa-tbl{width:100%;border-collapse:collapse;font-size:13px}
			.hoa-tbl th{text-align:left;color:#646970;font-size:11px;text-transform:uppercase;letter-spacing:.04em;padding:8px 10px;border-bottom:1px solid #dcdcde}
			.hoa-tbl td{padding:10px;border-bottom:1px solid #f0f0f1;vertical-align:top}
			.hoa-tbl tr:hover td{background:#fafafa}
			.hoa-unit{font-weight:700;font-size:14px}
			.hoa-sub{color:#787c82;font-size:12px}
			.hoa-pill{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12px;background:#e8f0fe;color:#1e40af}
			.hoa-pill.tenant{background:#fef3c7;color:#92400e}.hoa-pill.vacant{background:#e5e7eb;color:#4b5563}
			.hoa-warn{color:#b45309;font-size:12px}
			dialog.hoa-dlg{border:0;border-radius:16px;padding:0;width:min(560px,94vw);box-shadow:0 20px 60px rgba(0,0,0,.3)}
			dialog.hoa-dlg::backdrop{background:rgba(0,0,0,.45)}
			.hoa-dlg form{padding:20px}
			.hoa-dlg h2{margin:0 0 12px}
			.hoa-dlg label{display:block;font-weight:600;font-size:12px;margin:10px 0 3px}
			.hoa-dlg input[type=text],.hoa-dlg input[type=email],.hoa-dlg select,.hoa-dlg textarea{width:100%}
			.hoa-g2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
			.hoa-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
			@media(max-width:900px){.hoa-wrap{grid-template-columns:1fr}}
		</style>
		<div class="wrap">
			<h1><?php esc_html_e( 'HOA Homeowners', 'ajcore-hoa' ); ?></h1>
			<?php if ( 'added' === $msg || 'updated' === $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo 'added' === $msg ? esc_html__( 'Homeowner added.', 'ajcore-hoa' ) : esc_html__( 'Homeowner updated.', 'ajcore-hoa' ); ?></p></div><?php endif; ?>
			<?php if ( 'deleted' === $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( _n( '%d homeowner deleted.', '%d homeowners deleted.', $n, 'ajcore-hoa' ), $n ) ); ?></p></div><?php endif; ?>
			<?php if ( 'imported' === $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Imported %1$d, skipped %2$d.', 'ajcore-hoa' ), $n, absint( $_GET['skipped'] ?? 0 ) ) ); ?></p></div><?php endif; ?>
			<?php if ( ! empty( $_GET['hoa_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['hoa_error'] ) ) ); ?></p></div><?php endif; ?>
			<?php if ( ! $communities ) : ?>
				<p><?php esc_html_e( 'No communities yet. Add one under Customers (use the Local type).', 'ajcore-hoa' ); ?></p>
			<?php else : ?>
			<div class="hoa-wrap">
				<div class="hoa-rail">
					<input type="search" id="hoa-comm-search" placeholder="<?php esc_attr_e( 'Find community…', 'ajcore-hoa' ); ?>">
					<?php foreach ( $communities as $cid => $label ) : ?>
						<a href="<?php echo esc_url( self::url( array( 'community' => $cid ) ) ); ?>" class="<?php echo (string) $cid === $current ? 'on' : ''; ?>" data-name="<?php echo esc_attr( strtolower( $label ) ); ?>"><span><?php echo esc_html( $label ); ?></span><b><?php echo esc_html( (int) ( $counts[ $cid ] ?? 0 ) ); ?></b></a>
					<?php endforeach; ?>
				</div>
				<div class="hoa-main">
					<div class="hoa-bar">
						<span class="hoa-chip"><b><?php echo esc_html( count( $units ) ); ?></b> <?php esc_html_e( 'units', 'ajcore-hoa' ); ?></span>
						<span class="hoa-chip"><b><?php echo esc_html( $with_email ); ?></b> <?php esc_html_e( 'can be emailed', 'ajcore-hoa' ); ?></span>
						<span class="hoa-chip"><b><?php echo esc_html( count( $units ) - $with_email ); ?></b> <?php esc_html_e( 'mail only', 'ajcore-hoa' ); ?></span>
						<input type="search" id="hoa-search" placeholder="<?php esc_attr_e( 'Search unit, owner, email…', 'ajcore-hoa' ); ?>" style="min-width:220px">
						<span class="sp"></span>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ajcore_hoa_unit_export&community=' . rawurlencode( $current ) ), 'ajcore_hoa_unit_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'ajcore-hoa' ); ?></a>
						<button type="button" class="button" data-hoa-open="hoa-import"><?php esc_html_e( 'Import CSV', 'ajcore-hoa' ); ?></button>
						<button type="button" class="button button-primary" data-hoa-edit=""><?php esc_html_e( '+ Add homeowner', 'ajcore-hoa' ); ?></button>
					</div>
					<?php if ( ! $units ) : ?>
						<p style="color:#787c82;padding:30px 0;text-align:center"><?php esc_html_e( 'No homeowners in this community yet. Add one, or import a CSV.', 'ajcore-hoa' ); ?></p>
					<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hoa-del-form" onsubmit="return confirm('Delete selected homeowners?');">
						<input type="hidden" name="action" value="ajcore_hoa_unit_delete">
						<input type="hidden" name="community" value="<?php echo esc_attr( $current ); ?>">
						<?php wp_nonce_field( 'ajcore_hoa_unit_delete' ); ?>
					</form>
					<table class="hoa-tbl" id="hoa-tbl">
						<thead><tr><th style="width:24px"><input type="checkbox" id="hoa-all"></th><th><?php esc_html_e( 'Unit', 'ajcore-hoa' ); ?></th><th><?php esc_html_e( 'Owner', 'ajcore-hoa' ); ?></th><th><?php esc_html_e( 'Contact', 'ajcore-hoa' ); ?></th><th><?php esc_html_e( 'Mailing address', 'ajcore-hoa' ); ?></th><th><?php esc_html_e( 'Status', 'ajcore-hoa' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $units as $u ) :
							$data = array( 'id' => (int) $u->id, 'unit' => $u->unit, 'owner_name' => $u->owner_name, 'email' => $u->email, 'phone' => $u->phone, 'mailing_address' => (string) $u->mailing_address, 'occupancy' => $u->occupancy, 'email_ok' => (int) $u->email_ok, 'notes' => (string) $u->notes );
							?>
							<tr data-q="<?php echo esc_attr( strtolower( $u->unit . ' ' . $u->owner_name . ' ' . $u->email . ' ' . $u->phone ) ); ?>">
								<td><input type="checkbox" class="hoa-chk" form="hoa-del-form" name="unit_ids[]" value="<?php echo esc_attr( (int) $u->id ); ?>"></td>
								<td><span class="hoa-unit"><?php echo esc_html( $u->unit ); ?></span></td>
								<td><strong><?php echo esc_html( $u->owner_name ); ?></strong><?php if ( $u->notes ) : ?><div class="hoa-sub"><?php echo esc_html( wp_trim_words( $u->notes, 10 ) ); ?></div><?php endif; ?></td>
								<td>
									<?php if ( $u->email ) : ?><a href="mailto:<?php echo esc_attr( $u->email ); ?>"><?php echo esc_html( $u->email ); ?></a><?php if ( ! $u->email_ok ) : ?> <span class="hoa-warn"><?php esc_html_e( '(do not email)', 'ajcore-hoa' ); ?></span><?php endif; ?><?php else : ?><span class="hoa-warn"><?php esc_html_e( 'No email: mail only', 'ajcore-hoa' ); ?></span><?php endif; ?>
									<?php if ( $u->phone ) : ?><div class="hoa-sub"><?php echo esc_html( $u->phone ); ?></div><?php endif; ?>
								</td>
								<td class="hoa-sub" style="white-space:pre-line"><?php echo $u->mailing_address ? esc_html( $u->mailing_address ) : '—'; ?></td>
								<td><span class="hoa-pill <?php echo esc_attr( $u->occupancy ); ?>"><?php echo esc_html( $occ[ $u->occupancy ] ?? $u->occupancy ); ?></span></td>
								<td style="text-align:right"><button type="button" class="button button-small" data-hoa-edit="<?php echo esc_attr( wp_json_encode( $data ) ); ?>"><?php esc_html_e( 'Edit', 'ajcore-hoa' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p style="margin-top:12px"><button type="submit" form="hoa-del-form" class="button" id="hoa-del-btn" style="color:#b32d2e;border-color:#b32d2e;display:none"><?php esc_html_e( 'Delete selected', 'ajcore-hoa' ); ?></button></p>
					<?php endif; ?>
				</div>
			</div>

			<dialog class="hoa-dlg" id="hoa-edit">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ajcore_hoa_unit_save">
					<input type="hidden" name="community" value="<?php echo esc_attr( $current ); ?>">
					<input type="hidden" name="unit_id" value="">
					<?php wp_nonce_field( 'ajcore_hoa_unit_save' ); ?>
					<h2 id="hoa-edit-title"><?php esc_html_e( 'Add homeowner', 'ajcore-hoa' ); ?></h2>
					<div class="hoa-g2">
						<div><label><?php esc_html_e( 'Unit / address', 'ajcore-hoa' ); ?> *</label><input type="text" name="unit" required></div>
						<div><label><?php esc_html_e( 'Owner name', 'ajcore-hoa' ); ?> *</label><input type="text" name="owner_name" required></div>
						<div><label><?php esc_html_e( 'Email', 'ajcore-hoa' ); ?></label><input type="email" name="email"></div>
						<div><label><?php esc_html_e( 'Phone', 'ajcore-hoa' ); ?></label><input type="text" name="phone"></div>
					</div>
					<label><?php esc_html_e( 'Mailing address (if not the unit)', 'ajcore-hoa' ); ?></label>
					<textarea name="mailing_address" rows="2"></textarea>
					<div class="hoa-g2">
						<div><label><?php esc_html_e( 'Status', 'ajcore-hoa' ); ?></label><select name="occupancy"><?php foreach ( $occ as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></div>
						<div><label>&nbsp;</label><label style="font-weight:400"><input type="checkbox" name="email_ok" value="1" checked> <?php esc_html_e( 'OK to email notices', 'ajcore-hoa' ); ?></label></div>
					</div>
					<label><?php esc_html_e( 'Notes', 'ajcore-hoa' ); ?></label>
					<textarea name="notes" rows="2"></textarea>
					<div class="hoa-foot"><button type="button" class="button" data-hoa-close><?php esc_html_e( 'Cancel', 'ajcore-hoa' ); ?></button><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ajcore-hoa' ); ?></button></div>
				</form>
			</dialog>

			<dialog class="hoa-dlg" id="hoa-import">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="ajcore_hoa_unit_import">
					<input type="hidden" name="community" value="<?php echo esc_attr( $current ); ?>">
					<?php wp_nonce_field( 'ajcore_hoa_unit_import' ); ?>
					<h2><?php esc_html_e( 'Import homeowners', 'ajcore-hoa' ); ?></h2>
					<p class="hoa-sub"><code>unit, owner_name, email, phone, mailing_address, occupancy, notes</code> (occupancy: owner, tenant, vacant). Same unit and owner is skipped.</p>
					<label><?php esc_html_e( 'CSV file', 'ajcore-hoa' ); ?></label><input type="file" name="csv" accept=".csv,text/csv">
					<label><?php esc_html_e( 'Or paste rows', 'ajcore-hoa' ); ?></label><textarea name="csv_text" rows="5" placeholder="101,Jane Smith,jane@example.com,555-0100"></textarea>
					<div class="hoa-foot"><button type="button" class="button" data-hoa-close><?php esc_html_e( 'Cancel', 'ajcore-hoa' ); ?></button><button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'ajcore-hoa' ); ?></button></div>
				</form>
			</dialog>
			<script>
			(function(){
				var edit=document.getElementById('hoa-edit'), imp=document.getElementById('hoa-import');
				function fill(d){
					var f=edit.querySelector('form'); d=d||{};
					['unit','owner_name','email','phone','mailing_address','notes'].forEach(function(k){ f.elements[k].value=d[k]||''; });
					f.elements.unit_id.value=d.id||''; f.elements.occupancy.value=d.occupancy||'owner';
					f.elements.email_ok.checked = d.id ? !!d.email_ok : true;
					document.getElementById('hoa-edit-title').textContent = d.id ? 'Edit homeowner' : 'Add homeowner';
				}
				document.querySelectorAll('[data-hoa-edit]').forEach(function(b){ b.addEventListener('click',function(){ var j=b.getAttribute('data-hoa-edit'); fill(j?JSON.parse(j):null); edit.showModal(); }); });
				document.querySelectorAll('[data-hoa-open]').forEach(function(b){ b.addEventListener('click',function(){ imp.showModal(); }); });
				document.querySelectorAll('dialog.hoa-dlg').forEach(function(d){
					d.addEventListener('click',function(e){ if(e.target===d||e.target.hasAttribute('data-hoa-close')) d.close(); });
					d.querySelector('form').addEventListener('submit',function(){ var s=d.querySelector('button[type=submit]'); setTimeout(function(){ s.disabled=true; },0); });
				});
				var q=document.getElementById('hoa-search');
				if(q){ q.addEventListener('input',function(){ var t=q.value.toLowerCase(); document.querySelectorAll('#hoa-tbl tbody tr').forEach(function(r){ r.style.display = r.getAttribute('data-q').indexOf(t)>-1?'':'none'; }); }); }
				var cq=document.getElementById('hoa-comm-search');
				if(cq){ cq.addEventListener('input',function(){ var t=cq.value.toLowerCase(); document.querySelectorAll('.hoa-rail a').forEach(function(a){ a.style.display = a.getAttribute('data-name').indexOf(t)>-1?'':'none'; }); }); }
				var all=document.getElementById('hoa-all'), btn=document.getElementById('hoa-del-btn');
				function sync(){ var n=document.querySelectorAll('.hoa-chk:checked').length; if(btn){ btn.style.display=n?'':'none'; btn.textContent='Delete selected ('+n+')'; } }
				if(all){ all.addEventListener('change',function(){ document.querySelectorAll('#hoa-tbl tbody tr').forEach(function(r){ if(r.style.display!=='none'){ r.querySelector('.hoa-chk').checked=all.checked; } }); sync(); }); }
				document.querySelectorAll('.hoa-chk').forEach(function(c){ c.addEventListener('change',sync); });
			})();
			</script>
			<?php endif; ?>
		</div>
		<?php
	}
}
