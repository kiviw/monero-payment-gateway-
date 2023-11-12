<?php
/**
 * Plugin Name: Monero WooCommerce Gateway
 * Description: Enable Monero payments on your WooCommerce store.
 * Version: 1.0.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include our Gateway Class and register Payment Gateway with WooCommerce
add_action('plugins_loaded', 'init_monero_gateway_class');
function init_monero_gateway_class() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once dirname(__FILE__) . '/class-wc-monero-gateway.php';

    add_filter('woocommerce_payment_gateways', 'add_monero_gateway');
}

// Add custom action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'add_action_links');
function add_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=monero_gateway') . '">' . __('Settings', 'monero-woocommerce-gateway') . '</a>';
    return array_merge(array($settings_link), $links);
}

// Enqueue Countdown Timer Script
add_action('wp_enqueue_scripts', 'enqueue_countdown_timer_script');
function enqueue_countdown_timer_script() {
    wp_enqueue_script('countdown-timer', plugin_dir_url(__FILE__) . 'countdown-timer.js', array('jquery'), '1.0', true);
    $localized_data = array(
        'order_id' => wc_get_order_id_by_order_key(WC()->session->get('order_awaiting_payment')),
        'expiration_time' => strtotime('+40 minutes', current_time('timestamp')),
    );
    wp_localize_script('countdown-timer', 'countdown_timer_data', $localized_data);
}

// Generate Monero Subaddress and Save to Order
add_action('woocommerce_new_order', 'generate_monero_subaddress', 10, 1);
function generate_monero_subaddress($order_id) {
    $subaddress = generate_monero_subaddress_function($order_id);
    update_post_meta($order_id, '_monero_subaddress', $subaddress);

    // Save additional information like product ID for redirection
    $order = wc_get_order($order_id);
    $items = $order->get_items();
    foreach ($items as $item) {
        $product_id = $item->get_product_id();
        update_post_meta($order_id, '_product_id', $product_id);
        break; // Assuming only one product in the order
    }
}

// Display Monero Subaddress on Checkout Page
add_action('woocommerce_review_order_before_submit', 'display_monero_subaddress');
function display_monero_subaddress() {
    $order_id = wc_get_order_id_by_order_key(WC()->session->get('order_awaiting_payment'));
    $subaddress = get_post_meta($order_id, '_monero_subaddress', true);

    if (!empty($subaddress)) {
        echo '<p><strong>Monero Payment Details:</strong></p>';
        echo '<p>Send Monero to the following subaddress:</p>';
        echo "<p><code>$subaddress</code></p>";
        echo '<p>Payment expires in <span id="countdown-timer"></span></p>';
    }
}

// Check Monero Transaction Status and Redirect
add_action('woocommerce_thankyou', 'check_monero_transaction_status', 10, 1);
function check_monero_transaction_status($order_id) {
    // Your logic to check Monero transaction status here
    $subaddress = get_post_meta($order_id, '_monero_subaddress', true);

    if (!empty($subaddress)) {
        // Assume your function to check Monero transactions is named check_monero_transactions
        $transaction_status = check_monero_transactions($subaddress);

        if ($transaction_status >= 1) {
            // Redirect user to home page for successful transaction
            wp_redirect(home_url());
            exit;
        } elseif ($transaction_status === 0) {
            // Redirect user to product page if the transaction is in progress (0 confirmation)
            $product_id = get_post_meta($order_id, '_product_id', true); // Adjust this based on your setup
            wp_redirect(get_permalink($product_id));
            exit;
        } else {
            // Transaction failed or not confirmed after 40 minutes, cancel the order
            wc_cancel_order($order_id);
            // Redirect user to the shop page
            wp_redirect(wc_get_page_permalink('shop'));
            exit;
        }
    }
}

// Implement the function to generate Monero subaddress using Monero CLI
function generate_monero_subaddress_function($order_id) {
    // Path to your Monero CLI executable
    $monero_cli_path = '~/monero-x86_64-linux-gnu-v0.18.3.1/monero-wallet-cli';

    // Wallet file path
    $wallet_file = '~/monero-x86_64-linux-gnu-v0.18.3.1/mronion';

    // Password file path
    $password_file = '~/woo.txt';

    // Use the order ID as the label for the subaddress
    $label = 'order_' . $order_id;

    // Command to execute
    $command = escapeshellcmd("$monero_cli_path --wallet-file $wallet_file --password-file $password_file --command \"address new $label\"");

    // Execute the command
    $output = shell_exec($command);

    // Process the output to extract the new subaddress
    // Assuming the subaddress is the last line of the output
    $output_lines = explode("\n", trim($output));
    $subaddress = end($output_lines);

    return $subaddress;
}
