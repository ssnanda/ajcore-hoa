<?php
/**
 * HOA request workflows. Registered through AJ Core's request filters, so they appear in Requests
 * (status dropdown, step tracker, Workflows view, next-step buttons) like any built-in workflow.
 *
 * - Work order to invoice to accounts payable (hoa_work_order_invoice)
 * - Violation, from new to hearing to fine (hoa_violation)
 *
 * A request uses a workflow when its request_type is the workflow key, or (for requests entered by name)
 * when its service name contains the keyword: "violation" for violations; "work order", "repair" or
 * "maintenance" for work orders. Status keys are prefixed hoa_ so they never collide with AJ Core or
 * another extension. Nothing here needs a payment: next-step buttons work for unpaid requests.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_HOA_Workflows {

	const WORK_ORDER = 'hoa_work_order_invoice';
	const VIOLATION  = 'hoa_violation';

	public static function init() {
		add_filter( 'ajcore_service_status_labels', array( __CLASS__, 'status_labels' ) );
		add_filter( 'ajcore_service_status_pipelines', array( __CLASS__, 'pipelines' ) );
		add_filter( 'ajcore_service_workflow_labels', array( __CLASS__, 'workflow_labels' ) );
		add_filter( 'ajcore_service_request_product_type', array( __CLASS__, 'product_type' ), 20, 3 );
		add_filter( 'ajcore_service_request_quick_actions', array( __CLASS__, 'quick_actions' ), 10, 4 );
		add_filter( 'ajcore_service_request_action_map', array( __CLASS__, 'action_map' ) );
		add_filter( 'ajcore_service_request_types', array( __CLASS__, 'request_types' ) );
		// Owners and community members are not Stripe customers by default.
		add_filter( 'ajcore_default_customer_billing_type', array( __CLASS__, 'default_customer_type' ) );
	}

	public static function default_customer_type() {
		return 'local';
	}

	/** Step key => display name. */
	private static function steps() {
		return array(
			// Work order to invoice to accounts payable.
			'hoa_vendor_assigned'      => __( 'Vendor Assigned', 'ajcore-hoa' ),
			'hoa_work_in_progress'     => __( 'Work In Progress', 'ajcore-hoa' ),
			'hoa_work_complete'        => __( 'Work Complete', 'ajcore-hoa' ),
			'hoa_invoice_received'     => __( 'Invoice Received', 'ajcore-hoa' ),
			'hoa_invoice_approved'     => __( 'Invoice Approved', 'ajcore-hoa' ),
			'hoa_in_accounts_payable'  => __( 'In Accounts Payable', 'ajcore-hoa' ),
			'hoa_paid'                 => __( 'Paid', 'ajcore-hoa' ),
			// Violation, new to hearing to fine.
			'hoa_courtesy_notice'      => __( 'Courtesy Notice Sent', 'ajcore-hoa' ),
			'hoa_first_notice'         => __( 'First Notice Sent', 'ajcore-hoa' ),
			'hoa_final_notice'         => __( 'Final Notice Sent', 'ajcore-hoa' ),
			'hoa_hearing_scheduled'    => __( 'Hearing Scheduled', 'ajcore-hoa' ),
			'hoa_hearing_held'         => __( 'Hearing Held', 'ajcore-hoa' ),
			'hoa_fine_issued'          => __( 'Fine Issued', 'ajcore-hoa' ),
		);
	}

	/** Workflow key => ordered step keys. "completed" is the end of the line: Closed for work orders, Resolved for violations. */
	private static function flows() {
		return array(
			self::WORK_ORDER => array(
				'new',
				'hoa_vendor_assigned',
				'hoa_work_in_progress',
				'hoa_work_complete',
				'hoa_invoice_received',
				'hoa_invoice_approved',
				'hoa_in_accounts_payable',
				'hoa_paid',
				'completed',
				'cancelled',
			),
			self::VIOLATION  => array(
				'new',
				'hoa_courtesy_notice',
				'hoa_first_notice',
				'hoa_final_notice',
				'hoa_hearing_scheduled',
				'hoa_hearing_held',
				'hoa_fine_issued',
				'completed',
				'cancelled',
			),
		);
	}

	/** Quick-action key for moving a request to a step (hoa_to_courtesy_notice, hoa_to_completed). */
	private static function action_key( $step ) {
		return 'hoa_to_' . preg_replace( '/^hoa_/', '', (string) $step );
	}

	/** Types offered in New request. */
	public static function request_types( $types ) {
		$types[ self::VIOLATION ]  = __( 'Violation', 'ajcore-hoa' );
		$types[ self::WORK_ORDER ] = __( 'Work order to invoice to accounts payable', 'ajcore-hoa' );
		return $types;
	}

	public static function status_labels( $labels ) {
		return is_array( $labels ) ? $labels + self::steps() : $labels;
	}

	public static function pipelines( $sets ) {
		return is_array( $sets ) ? array_merge( $sets, self::flows() ) : $sets;
	}

	public static function workflow_labels( $names ) {
		$names[ self::WORK_ORDER ] = __( 'Work Order to Invoice to Accounts Payable', 'ajcore-hoa' );
		$names[ self::VIOLATION ]  = __( 'Violation', 'ajcore-hoa' );
		return $names;
	}

	/** request_type wins; otherwise the service name (lowercased, separators already removed by AJ Core). */
	public static function product_type( $type, $name, $request = null ) {
		$request_type = is_object( $request ) && isset( $request->request_type ) ? sanitize_key( (string) $request->request_type ) : '';
		if ( in_array( $request_type, array( self::VIOLATION, self::WORK_ORDER ), true ) ) {
			return $request_type;
		}
		if ( false !== strpos( (string) $name, 'violation' ) ) {
			return self::VIOLATION;
		}
		foreach ( array( 'work order', 'repair', 'maintenance' ) as $keyword ) {
			if ( false !== strpos( (string) $name, $keyword ) ) {
				return self::WORK_ORDER;
			}
		}
		return $type;
	}

	/**
	 * Next-step buttons for HOA requests, with no payment condition. Replaces AJ Core's payment-driven buttons
	 * for these product types; Cancel and Delete are added by AJ Core afterwards.
	 */
	public static function quick_actions( $actions, $request, $product_type, $service_status ) {
		$flows = self::flows();
		if ( ! isset( $flows[ $product_type ] ) || ! is_array( $actions ) ) {
			return $actions;
		}
		$actions = array();
		if ( in_array( $service_status, array( 'completed', 'cancelled' ), true ) ) {
			return $actions;
		}
		$steps    = array_values( array_diff( $flows[ $product_type ], array( 'cancelled' ) ) );
		$labels   = self::steps() + array( 'completed' => self::VIOLATION === $product_type ? __( 'Resolved', 'ajcore-hoa' ) : __( 'Closed', 'ajcore-hoa' ) );
		$position = array_search( $service_status, $steps, true );
		$next     = false === $position ? $steps[1] : ( $steps[ $position + 1 ] ?? '' );
		if ( '' !== $next ) {
			$actions[ self::action_key( $next ) ] = sprintf(
				/* translators: %s: name of the next workflow step */
				__( 'Move to %s', 'ajcore-hoa' ),
				$labels[ $next ] ?? $next
			);
		}
		// A violation can be cured at any point after it is reported, which ends it without a fine.
		if ( self::VIOLATION === $product_type && 'new' !== $service_status && 'completed' !== $next ) {
			$actions['complete'] = __( 'Mark Resolved (cured)', 'ajcore-hoa' );
		}
		return $actions;
	}

	/** Every step's action key sets that step as the request's workflow status. */
	public static function action_map( $map ) {
		if ( ! is_array( $map ) ) {
			return $map;
		}
		foreach ( self::flows() as $flow ) {
			foreach ( $flow as $step ) {
				$map[ self::action_key( $step ) ] = $step;
			}
		}
		return $map;
	}
}
