<?php
/**
 * Tests for the GET /products/stats endpoint.
 *
 * Covers the two acceptance-critical behaviours:
 *   1. Permission gating — an unauthenticated request is rejected with 401
 *      via the shared Rest_Controller::read_permission.
 *   2. Query / response shape — the handler groups by product/variation,
 *      restricts to view_item / add_to_cart, and returns the canonical
 *      interval/subtotals envelope used by the other analytics endpoints.
 *
 * @package Imans\Analytics\Tests
 */

declare( strict_types=1 );

use Brain\Monkey;
use Brain\Monkey\Functions;
use Imans\Analytics\Rest\Products_Stats_Endpoint;
use PHPUnit\Framework\TestCase;

/**
 * Recording $wpdb double. Captures the prepared SQL + bound args and
 * returns whatever rows the test queued via ->queue_rows().
 */
class Fake_Wpdb {
	public $prefix = 'wp_';
	public $last_query = '';
	public $last_args = array();
	private $rows = array();

	public function queue_rows( array $rows ) {
		$this->rows = $rows;
	}

	public function prepare( $query, ...$args ) {
		// Flatten a single array arg the way wpdb does, then record.
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->last_args = $args;
		// Return the raw query (with %s placeholders) — good enough for the
		// handler, which passes the result straight to get_results().
		return $query;
	}

	public function get_results( $query, $output = OBJECT ) {
		$this->last_query = $query;
		return $this->rows;
	}
}

final class ProductsStatsEndpointTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'OBJECT' ) ) {
			define( 'OBJECT', 'OBJECT' );
		}
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}

		// Note: gmdate()/strtotime() (used only by date_param()'s default
		// branch) are PHP internals — we leave them real and always pass
		// explicit after/before, so that branch is never exercised.
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/* --------------------------------------------------------------- */
	/* 1. Permission gating                                            */
	/* --------------------------------------------------------------- */

	public function test_permission_rejects_unauthenticated_request(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$result = Products_Stats_Endpoint::permission( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_permission_rejects_authenticated_without_read_scope(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = Products_Stats_Endpoint::permission( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_permission_allows_user_with_read_scope(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) {
				return 'read_private_shop_orders' === $cap;
			}
		);

		$result = Products_Stats_Endpoint::permission( new WP_REST_Request() );

		$this->assertTrue( $result );
	}

	/* --------------------------------------------------------------- */
	/* 2. Query / response shape                                       */
	/* --------------------------------------------------------------- */

	public function test_handle_groups_by_product_variation_and_returns_subtotals(): void {
		$wpdb = new Fake_Wpdb();
		$wpdb->queue_rows(
			array(
				array(
					'stat_date'         => '2026-06-10',
					'product_id'        => '42',
					'variation_id'      => '101',
					'view_item_count'   => '7',
					'add_to_cart_count' => '3',
				),
				array(
					'stat_date'         => '2026-06-10',
					'product_id'        => '42',
					'variation_id'      => null, // simple product / no variation
					'view_item_count'   => '5',
					'add_to_cart_count' => '0',
				),
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		$request = new WP_REST_Request(
			array(
				'after'  => '2026-06-01',
				'before' => '2026-06-30',
			)
		);

		$response = Products_Stats_Endpoint::handle( $request );

		// Envelope.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'intervals', $data );
		$this->assertCount( 2, $data['intervals'] );

		// First interval — per-variation counts in the canonical shape.
		$first = $data['intervals'][0];
		$this->assertSame( '2026-06-10 00:00:00', $first['date_start'] );
		$this->assertSame( '2026-06-10 23:59:59', $first['date_end'] );
		$this->assertSame( 42, $first['product_id'] );
		$this->assertSame( 101, $first['variation_id'] );
		$this->assertSame(
			array(
				'view_item_count'   => 7,
				'add_to_cart_count' => 3,
			),
			$first['subtotals']
		);

		// NULL variation collapses to 0 (simple product).
		$this->assertSame( 0, $data['intervals'][1]['variation_id'] );
		$this->assertSame( 42, $data['intervals'][1]['product_id'] );

		// Query restricts to the two tracked event types, groups correctly,
		// and scans the raw events table on-the-fly (no daily_stats table).
		$sql = $wpdb->last_query;
		$this->assertStringContainsString( "event_type IN ('view_item', 'add_to_cart')", $sql );
		$this->assertStringContainsString( 'GROUP BY stat_date, product_id, variation_id', $sql );
		$this->assertStringContainsString( 'wp_imans_events', $sql );
		$this->assertStringNotContainsString( 'imans_daily_stats', $sql );

		// Date window bound as start-of-day / exclusive-before-day.
		$this->assertSame( '2026-06-01 00:00:00', $wpdb->last_args[0] );
		$this->assertSame( '2026-06-30 00:00:00', $wpdb->last_args[1] );
	}

	public function test_aggregated_event_types_are_exactly_view_item_and_add_to_cart(): void {
		$this->assertSame(
			array( 'view_item', 'add_to_cart' ),
			Products_Stats_Endpoint::AGGREGATED_EVENT_TYPES
		);
	}
}
