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
    
    // Display form for users to input txid and txkey
    echo '<p><strong>Enter Transaction Details:</strong></p>';
    echo '<form method="post" action="' . esc_url(wc_get_checkout_url()) . '">';
    echo '<label for="user_txid">Transaction ID:</label>';
    echo '<input type="text" name="user_txid" id="user_txid" required>';
    echo '<br>';
    echo '<label for="user_txkey">Transaction Key:</label>';
    echo '<input type="text" name="user_txkey" id="user_txkey" required>';
    echo '<br>';
    echo '<input type="submit" name="submit_tx_details" value="Submit">';
    echo '</form>';
}

// Check and Confirm Monero Transaction
add_action('woocommerce_thankyou', 'check_and_confirm_monero_transaction', 10, 1);
function check_and_confirm_monero_transaction($order_id) {
    $subaddress = get_post_meta($order_id, '_monero_subaddress', true);

    if (!empty($subaddress)) {
        if (isset($_POST['submit_tx_details'])) {
            $user_txid = sanitize_text_field($_POST['user_txid']);
            $user_txkey = sanitize_text_field($_POST['user_txkey']);

            // Confirm Monero transaction
            $confirmations = confirm_monero_transaction($user_txid, $user_txkey, $subaddress);

            if ($confirmations >= 1) {
                // Redirect user to home page for successful transaction
                wp_redirect(home_url());
                exit;
            } else {
                // Notify the user that the payment is not yet confirmed
                echo 'Payment not confirmed. Please wait for at least one confirmation.';
            }
        }
    }
}

// Confirm Monero Transaction Function
function confirm_monero_transaction($user_txid, $user_txkey, $subaddress) {
    // Build the Monero CLI command
    $monero_cli_command = "check_tx_key $user_txid $user_txkey $subaddress";

    // Run the command and capture the output
    $command_output = shell_exec($monero_cli_command);

    // Extract the number of confirmations
    $confirmations = extract_confirmations($command_output);

    return $confirmations;
}

// Extract Confirmations from Monero CLI Output
function extract_confirmations($output) {
    // Split the output into lines
    $lines = explode("\n", $output);

    // Iterate through each line to find the one containing the confirmation details
    foreach ($lines as $line) {
        if (strpos($line, 'This transaction has') !== false) {
            // Extract the number of confirmations using regular expression
            preg_match('/This transaction has (\d+) confirmations/', $line, $matches);

            if (isset($matches[1])) {
                return intval($matches[1]);
            }
        }
    }

    // Return 0 if no confirmation details found
    return 0;
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

    // Execute the command
    $output = shell_exec($command);

    // Process the output to extract the new subaddress
    $lines = explode("\n", trim($output));

    foreach ($lines as $line) {
        if (strpos($line, $label) !== false) {
            // Extract the subaddress from the line
            $parts = explode(" ", $line);
            $subaddress = $parts[1]; // Assuming subaddress is the second part
            return $subaddress;
        }
    }

    // Return null if no subaddress is found
    return null;
}
