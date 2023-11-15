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

// Generate Monero Subaddress and Start 40 Minutes Timer
add_action('woocommerce_new_order', 'generate_monero_subaddress', 10, 1);
function generate_monero_subaddress($order_id) {
    // Generate subaddress
    generate_monero_subaddress_function($order_id);
}

// Implement the function to generate Monero subaddress using Monero CLI
function generate_monero_subaddress_function($order_id) {
    // Path to your Monero CLI executable
    $monero_cli_path = '/var/www/monero-x86_64-linux-gnu-v0.18.3.1/monero-wallet-cli';

    // Wallet file path
    $wallet_file = '/var/www/monero-x86_64-linux-gnu-v0.18.3.1/mronion';

    // Password file path
    $password_file = '/var/www/woo.txt';

    // Use the order ID as the label for the subaddress
    $label = 'order_' . $order_id;

    // Command to execute
    $command = escapeshellcmd("$monero_cli_path --wallet-file $wallet_file --password-file $password_file address new $label");

    // Execute the command and capture the output (not used)
    shell_exec($command);

    // Start 40 minutes timer
    start_40_minutes_timer($order_id);
}

// Start 40 Minutes Timer
function start_40_minutes_timer($order_id) {
    // Schedule event to check transaction status every 5 minutes
    wp_schedule_single_event(time() + 2400, 'monero_check_transaction_status', array($order_id));
}
