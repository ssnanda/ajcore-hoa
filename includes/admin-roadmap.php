<?php
/**
 * AJ Core > AJCore HOA: read-only roadmap of what this plugin will provide, so it is visible what is planned,
 * what is built, and what AJ Core itself supplies. No settings, no actions. Update the status flags here in the
 * same change that builds a feature. The longer write-up is docs/architecture/roadmap.md.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

add_action( 'admin_menu', 'ajcore_hoa_add_roadmap_menu', 32 );

function ajcore_hoa_add_roadmap_menu() {
	add_submenu_page( 'ajforms', __( 'AJCore HOA', 'ajcore-hoa' ), __( 'AJCore HOA', 'ajcore-hoa' ), 'manage_options', 'ajcore-hoa', 'ajcore_hoa_render_roadmap_page' );
}

/**
 * @return array<string,array<string,array{0:string,1?:string}>> Group => feature => array( status, note ).
 * Status: 'planned' (not started), 'building', 'done', 'core' (AJ Core already provides the engine; HOA adds templates or fields).
 */
function ajcore_hoa_roadmap() {
	return array(
		'Violations' => array(
			'Violation workflow: new, notices, hearing, fine'            => array( 'done', 'Requests > Workflows; next-step buttons, no payment needed' ),
			'Report a violation (unit, owner, rule, photos, date)'      => array( 'planned', 'Request type with its own workflow' ),
			'Notice letters: courtesy, first, second, final'            => array( 'planned', 'Emailed to the homeowner, or printed for mailing' ),
			'Cure deadline and reminders'                               => array( 'planned', 'Uses Compliance reminders' ),
			'Fines and hearing step'                                    => array( 'planned' ),
			'Owner response and photo of the fix'                       => array( 'planned' ),
		),
		'Requests and invoices' => array(
			'Work order to invoice to accounts payable workflow'        => array( 'done', 'Vendor assigned through invoice received, approved, in accounts payable, paid, closed' ),
			'Board approval step above a set amount'                    => array( 'planned', 'Extra step in the work order workflow' ),
			'Architectural change request (ARC)'                        => array( 'planned', 'Submit, committee review, approved or denied' ),
			'Generic Requests engine and invoice template'              => array( 'core', 'In AJ Core; HOA adds its own variants' ),
		),
		'Inspections' => array(
			'Scheduled and ad-hoc inspections'                          => array( 'planned', 'A request type with steps' ),
			'Checklist with photos per unit or common area'             => array( 'planned' ),
			'Follow-up that can open a violation or a repair request'   => array( 'planned' ),
		),
		'Compliance' => array(
			'HOA compliance templates'                                  => array( 'planned', 'Annual meeting, insurance renewal, reserve study, state filings' ),
			'Generic Compliance feature and templates'                  => array( 'core' ),
		),
		'Owners and community' => array(
			'Units and owners directory (AJ Core > HOA Homeowners)'     => array( 'done', 'Search, add, edit, CSV import and export' ),
			'Dues and assessments'                                      => array( 'planned', 'Billing through Stripe' ),
			'Board and meetings: agendas, minutes'                      => array( 'planned' ),
			'Community documents: CC&Rs, rules, forms'                  => array( 'planned', 'Uses File Library' ),
			'Announcements to owners'                                   => array( 'planned' ),
			'Portal, leads, payments, files, email'                     => array( 'core' ),
		),
		'AJOps and iOS' => array(
			'HOA module in AJOps'                                       => array( 'planned', 'Shown only on sites that report AJCore HOA' ),
			'HOA screens in the iOS app'                                => array( 'planned' ),
		),
	);
}

function ajcore_hoa_render_roadmap_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$groups = ajcore_hoa_roadmap();
	$total  = 0;
	$done   = 0;
	foreach ( $groups as $features ) {
		foreach ( $features as $feature ) {
			if ( 'core' === $feature[0] ) {
				continue;
			}
			$total++;
			if ( 'done' === $feature[0] ) {
				$done++;
			}
		}
	}

	$pills = array(
		'planned'  => '<span style="background:#dcdcde;color:#50575e;padding:2px 10px;border-radius:10px">Planned</span>',
		'building' => '<span style="background:#dba617;color:#fff;padding:2px 10px;border-radius:10px;font-weight:600">Building</span>',
		'done'     => '<span style="background:#00a32a;color:#fff;padding:2px 10px;border-radius:10px;font-weight:600">Done</span>',
		'core'     => '<span style="background:#dbeafe;color:#1e40af;padding:2px 10px;border-radius:10px;white-space:nowrap">In AJCore</span>',
	);
	$rows = array(
		'planned'  => 'color:#50575e',
		'building' => 'background:#fcf9e8',
		'done'     => 'background:#edfaef;font-weight:600',
		'core'     => 'color:#50575e;background:#f6f7f7',
	);

	echo '<div class="wrap"><h1>' . esc_html__( 'AJCore HOA', 'ajcore-hoa' ) . ' <small>' . esc_html( AJCORE_HOA_VERSION ) . '</small></h1>';
	echo '<p style="font-size:14px"><strong>' . esc_html( sprintf( '%d of %d built', $done, $total ) ) . '</strong> · ' . esc_html__( 'Two request workflows are active; the rest is planned. Details: docs/architecture/roadmap.md', 'ajcore-hoa' ) . '</p>';
	echo '<table class="widefat" style="max-width:820px;font-size:14px"><tbody>';
	foreach ( $groups as $group => $features ) {
		echo '<tr><th colspan="2" style="background:#1d2327;color:#fff;padding:8px 12px">' . esc_html( $group ) . '</th></tr>';
		foreach ( $features as $label => $feature ) {
			$status = $feature[0];
			$note   = isset( $feature[1] ) ? ' <span style="font-weight:400;color:#787c82">· ' . esc_html( $feature[1] ) . '</span>' : '';
			echo '<tr style="' . esc_attr( $rows[ $status ] ) . '"><td style="padding:8px 12px">' . esc_html( $label ) . $note . '</td><td style="width:90px;text-align:right;padding:8px 12px">' . $pills[ $status ] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
		}
	}
	echo '</tbody></table></div>';
}
