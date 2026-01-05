<?php
/**
 * Plugin Name: Concurrent Checkout Tester
 * Description: Adds a button to checkout that sends concurrent requests to test for duplicate orders.
 * Version: 1.0.0
 *
 * @package concurrent-checkout-tester
 */

defined( 'ABSPATH' ) || exit;

/**
 * Concurrent Checkout Tester.
 *
 * This plugin helps replicate duplicate order issues that occur in production
 * environments with load balancers (Kong, AWS ALB) by sending concurrent
 * checkout requests to trigger the race condition.
 *
 * @since 1.0.0
 */
class Concurrent_Checkout_Tester {

	/**
	 * Meta key to identify test orders.
	 *
	 * @var string
	 */
	const TEST_ORDER_META = '_cct_test_order';

	/**
	 * Initialize the plugin.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Register admin page.
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );

		// Handle AJAX actions.
		add_action( 'wp_ajax_cct_delete_orders', [ __CLASS__, 'ajax_delete_orders' ] );

		// Only run test hooks if enabled.
		if ( ! self::is_enabled() ) {
			return;
		}

		// Tag test orders.
		add_action( 'woocommerce_new_order', [ __CLASS__, 'tag_test_order' ], 10, 2 );

		// Add test button to checkout.
		add_action( 'woocommerce_review_order_after_submit', [ __CLASS__, 'render_test_button' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_test_script' ] );
	}

	/**
	 * Check if concurrent checkout tester is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return defined( 'WC_CONCURRENT_CHECKOUT_TEST' ) && WC_CONCURRENT_CHECKOUT_TEST;
	}

	/**
	 * Add admin menu.
	 *
	 * @since 1.0.0
	 */
	public static function add_admin_menu() {
		add_submenu_page(
			'tools.php',
			'Concurrent Checkout Tester',
			'Concurrent Checkout',
			'manage_options',
			'concurrent-checkout-tester',
			[ __CLASS__, 'render_admin_page' ]
		);
	}

	/**
	 * Render admin page.
	 *
	 * @since 1.0.0
	 */
	public static function render_admin_page() {
		$test_orders = self::get_test_orders();
		$fix_enabled = defined( 'WC_DUPLICATE_ORDER_FIX' ) && WC_DUPLICATE_ORDER_FIX;
		?>
		<div class="wrap">
			<h1>Concurrent Checkout Tester</h1>

			<!-- How to Use -->
			<div class="card" style="max-width:900px;padding:20px;margin-top:20px;background:#f0f6fc;border-left:4px solid #0073aa;">
				<h2 style="margin-top:0;">📖 How to Replicate Duplicate Orders</h2>
				<p>This plugin sends <strong>concurrent checkout requests</strong> to demonstrate the race condition that causes duplicate orders in multi-pod environments.</p>

				<h3>Root Cause</h3>
				<p>When multiple checkout requests arrive simultaneously (from AWS ALB retries, browser retries, or concurrent tabs), WooCommerce lacks atomic locking to prevent duplicate order creation:</p>
				<table class="widefat" style="max-width:100%;margin-top:10px;">
					<thead>
						<tr>
							<th>The Race Condition</th>
							<th>Result</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>Request 1 → Check cart valid ✓ → Create order</td>
							<td>Order #101 created</td>
						</tr>
						<tr>
							<td>Request 2 → Check cart valid ✓ → Create order (before #101 completes)</td>
							<td>Order #102 created (DUPLICATE!)</td>
						</tr>
						<tr>
							<td>Request 3 → Check cart valid ✓ → Create order (before #101 completes)</td>
							<td>Order #103 created (DUPLICATE!)</td>
						</tr>
					</tbody>
				</table>

				<h3>Quick Start</h3>
				<ol style="margin-left:20px;">
					<li><strong>Disable duplicate order fix</strong></li>
					<li><strong>Add a product to shopping cart</strong></li>
					<li><strong>Go to checkout page</strong> - Fill in billing details</li>
					<li><strong>Click "Run Concurrent Test"</strong> - Sends concurrent requests</li>
					<li><strong>Check results</strong> - Multiple order IDs = duplicates!</li>
					<li><strong>Enable the fix</strong> and repeat - Only 1 order created</li>
				</ol>

				<h3>The Fix: Atomic MySQL Locking</h3>
				<p>The <code>prevent-duplicate-orders.php</code> plugin uses MySQL <code>GET_LOCK()</code> to serialize requests. Only one request proceeds at a time - others wait, then fail naturally because the cart is empty.</p>
			</div>

			<!-- Configuration -->
			<div class="card" style="max-width:900px;padding:20px;margin-top:20px;">
				<h2 style="margin-top:0;">Configurations</h2>
				<table class="widefat" style="max-width:600px;">
				<tr>
						<td><code>WC_DUPLICATE_ORDER_FIX</code></td>
						<td><?php echo $fix_enabled ? '✅ Enabled' : '❌ Disabled'; ?></td>
					</tr>
					<tr>
						<td><code>WC_CONCURRENT_CHECKOUT_TEST</code></td>
						<td><?php echo self::is_enabled() ? '✅ Enabled' : '❌ Disabled'; ?></td>
					</tr>
					<tr>
						<td><code>WC_CONCURRENT_CHECKOUT_REQUESTS</code></td>
						<td><?php echo defined( 'WC_CONCURRENT_CHECKOUT_REQUESTS' ) ? esc_html( WC_CONCURRENT_CHECKOUT_REQUESTS ) . ' requests' : '5 (default)'; ?></td>
					</tr>
				</table>

				<?php if ( ! self::is_enabled() ) : ?>
					<div style="margin-top:15px;padding:15px;background:#fff3cd;border-left:4px solid #856404;">
						<strong>⚠️ Plugin is disabled!</strong>
						<p style="margin:5px 0 0;">Add the constants above to wp-config.php to enable concurrent checkout testing.</p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Delete Test Orders -->
			<div class="card" style="max-width:900px;padding:20px;margin-top:20px;">
				<h2 style="margin-top:0;">Delete Test Orders</h2>
				<p>
					Found <strong><?php echo count( $test_orders ); ?></strong> test orders (tagged with _cct_test_order meta)
				</p>
				<?php if ( ! empty( $test_orders ) ) : ?>
					<p>
						<strong>Order IDs:</strong>
						<?php echo esc_html( implode( ', ', array_slice( $test_orders, 0, 20 ) ) ); ?>
						<?php if ( count( $test_orders ) > 20 ) : ?>
							... and <?php echo count( $test_orders ) - 20; ?> more
						<?php endif; ?>
					</p>
					<p>
						<button id="cct-delete-orders" class="button button-secondary" style="color:#a00;">
							Delete All <?php echo count( $test_orders ); ?> Test Orders
						</button>
					</p>
					<div id="cct-delete-results"></div>
				<?php else : ?>
					<p style="color:#666;">No test orders found.</p>
				<?php endif; ?>
			</div>
		</div>

		<script>
		(function($) {
			var AJAX_URL = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
			var NONCE = '<?php echo esc_js( wp_create_nonce( 'cct_nonce' ) ); ?>';

			// Delete orders.
			$('#cct-delete-orders').on('click', function() {
				if (!confirm('Are you sure you want to permanently delete all test orders?')) return;

				var btn = $(this);
				btn.prop('disabled', true).text('Deleting...');

				$.post(AJAX_URL, {
					action: 'cct_delete_orders',
					nonce: NONCE
				}, function(response) {
					if (response.success) {
						$('#cct-delete-results').html('<div style="color:#080;padding:10px;background:#efe;margin-top:10px;">✅ ' + response.data.message + '</div>');
						setTimeout(function() { location.reload(); }, 1500);
					} else {
						$('#cct-delete-results').html('<div style="color:#a00;padding:10px;background:#fee;margin-top:10px;">❌ ' + response.data + '</div>');
						btn.prop('disabled', false);
					}
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * Get test orders.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_test_orders() {
		return wc_get_orders(
			[
				'meta_key' => self::TEST_ORDER_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'limit'    => -1,
				'return'   => 'ids',
			]
		);
	}

	/**
	 * Delete test orders.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function delete_test_orders() {
		$deleted   = 0;
		$order_ids = self::get_test_orders();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true );
				++$deleted;
			}
		}

		if ( class_exists( '\Automattic\WooCommerce\Caches\OrderCountCache' ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCountCache::class )->flush();
		}

		return $deleted;
	}

	/**
	 * AJAX: Delete test orders.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_delete_orders() {
		check_ajax_referer( 'cct_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$deleted = self::delete_test_orders();

		wp_send_json_success(
			[
				'deleted' => $deleted,
				'message' => sprintf( 'Deleted %d test orders.', $deleted ),
			]
		);
	}

	/**
	 * Tag order as test order.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public static function tag_test_order( $order_id, $order ) {
		$order->update_meta_data( self::TEST_ORDER_META, time() );
		$order->update_meta_data( '_cct_timestamp', microtime( true ) );
		$order->save();
	}

	/**
	 * Render test button on checkout page.
	 *
	 * @since 1.0.0
	 */
	public static function render_test_button() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$requests   = defined( 'WC_CONCURRENT_CHECKOUT_REQUESTS' ) ? (int) WC_CONCURRENT_CHECKOUT_REQUESTS : 5;
		$fix_status = defined( 'WC_DUPLICATE_ORDER_FIX' ) && ! WC_DUPLICATE_ORDER_FIX ? 'DISABLED' : 'ENABLED';
		?>
		<div style="margin-top:20px;padding:15px;background:#fff3cd;border:2px dashed #856404;border-radius:5px;">
			<strong>🧪 Concurrent Checkout Test</strong>
			<p style="margin:8px 0;font-size:13px;">
				Sends <?php echo esc_html( $requests ); ?> simultaneous requests.
				Fix: <strong><?php echo esc_html( $fix_status ); ?></strong>
			</p>
			<button type="button" id="cct-run-test" class="button" style="background:#856404;color:#fff;border:none;">
				Run Concurrent Test
			</button>
			<div id="cct-results" style="margin-top:10px;font-family:monospace;font-size:12px;"></div>
		</div>
		<?php
	}

	/**
	 * Render JavaScript for concurrent test.
	 *
	 * @since 1.0.0
	 */
	public static function render_test_script() {
		if ( ! is_checkout() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$requests     = defined( 'WC_CONCURRENT_CHECKOUT_REQUESTS' ) ? (int) WC_CONCURRENT_CHECKOUT_REQUESTS : 5;
		$checkout_url = wc_get_checkout_url();
		?>
		<script>
		document.body.addEventListener('click', async function(e) {
			if (!e.target.matches('#cct-run-test')) return;
			e.preventDefault();
			const btn = e.target;
			const results = document.getElementById('cct-results');
			const form = document.querySelector('form.checkout');

			if (!form) return alert('Checkout form not found');

			const formData = new URLSearchParams(new FormData(form)).toString();
			const REQUESTS = <?php echo (int) $requests; ?>;

			btn.disabled = true;
			btn.textContent = 'Running...';
			results.innerHTML = '🔄 Sending ' + REQUESTS + ' simultaneous requests...<br>';

			const sendRequest = async (id) => {
				try {
					const res = await fetch('<?php echo esc_url( $checkout_url ); ?>?wc-ajax=checkout', {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: formData,
						credentials: 'same-origin'
					});
					const data = await res.json();
					const orderId = data.order_id || (data.redirect?.match(/order-received\/(\d+)/)?.[1]);
					results.innerHTML += `[${id}] ${orderId ? '✅ Order #' + orderId : '❌ ' + (data.messages?.replace(/<[^>]*>/g, '').trim().slice(0, 80) || 'Failed')}<br>`;
					return orderId;
				} catch (e) {
					results.innerHTML += `[${id}] ❌ Network error<br>`;
					return null;
				}
			};

			// Launch all requests simultaneously.
			const orderIds = await Promise.all([...Array(REQUESTS)].map((_, i) => sendRequest(i + 1)));
			const unique = [...new Set(orderIds.filter(Boolean))];

			results.innerHTML += '<br><strong>';
			if (unique.length === 0) {
				results.innerHTML += '⚠️ No orders created';
			} else if (unique.length === 1) {
				results.innerHTML += '✅ FIX WORKING - Only 1 order: #' + unique[0];
			} else {
				results.innerHTML += '🚨 DUPLICATES! ' + unique.length + ' orders: #' + unique.join(', #');
			}
			results.innerHTML += '</strong>';

			btn.disabled = false;
			btn.textContent = 'Run Concurrent Test';
		});
		</script>
		<?php
	}
}

// Initialize plugin.
add_action( 'plugins_loaded', [ 'Concurrent_Checkout_Tester', 'init' ] );
