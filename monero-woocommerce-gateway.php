<?php
/**
 * Plugin Name: Monero WooCommerce Gateway
 * Description: Enable Monero payments on your WooCommerce store.
 * Version: 1.1.0
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
    // Generate and save subaddress
    $subaddress = generate_monero_subaddress_function($order_id);
    save_subaddress_to_order($order_id, $subaddress);

    // Schedule event to check transaction status every 5 minutes
    wp_schedule_event(time(), 'five_minutes', 'monero_check_transaction_status', array($order_id));
}

// Save Subaddress to Order and Subaddress.txt
function save_subaddress_to_order($order_id, $subaddress) {
    if (!empty($subaddress)) {
        // Save subaddress to order
        update_post_meta($order_id, '_monero_subaddress', $subaddress);

        // Save subaddress to subaddress.txt
        $filtered_output = filter_monero_cli_output($subaddress);
        write_subaddress_to_file($filtered_output);
    }
}

// Display Monero Subaddress and Transaction Details on Checkout Page
add_action('woocommerce_review_order_before_submit', 'display_monero_subaddress_and_tx_details');
function display_monero_subaddress_and_tx_details() {
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
    echo '<label for="user_txid">Transaction ID:</label>';
    echo '<input type="text" name="user_txid" id="user_txid" required>';
    echo '<br>';
    echo '<label for="user_txkey">Transaction Key:</label>';
    echo '<input type="text" name="user_txkey" id="user_txkey" required>';
}

// Process Transaction Details on Checkout Submission
add_action('woocommerce_checkout_create_order', 'process_monero_transaction_details');
function process_monero_transaction_details($order) {
    $subaddress = get_post_meta($order->get_id(), '_monero_subaddress', true);

    if (!empty($subaddress) && isset($_POST['user_txid']) && isset($_POST['user_txkey'])) {
        $user_txid = sanitize_text_field($_POST['user_txid']);
        $user_txkey = sanitize_text_field($_POST['user_txkey']);

        // Confirm Monero transaction
        $confirmations = confirm_monero_transaction($user_txid, $user_txkey, $subaddress, $order->get_id());

        if ($confirmations >= 1) {
            // Payment confirmed, mark the order as complete
            $order->payment_complete();
        } else {
            // Notify the user that the payment is not yet confirmed
            wc_add_notice('Payment not confirmed. Please wait for at least one confirmation.', 'error');
        }
    }
}

// Confirm Monero Transaction Function
function confirm_monero_transaction($user_txid, $user_txkey, $subaddress, $order_id) {
    // Build the Monero CLI command
    $monero_cli_command = "check_tx_key $user_txid $user_txkey $subaddress";

    // Run the command and capture the output
    $command_output = shell_exec($monero_cli_command);

    // Store output in confirmation.txt
    $file_path = '/var/www/confirmation.txt';
    file_put_contents($file_path, $command_output);

    // Extract the number of confirmations
    $confirmations = extract_confirmations($order_id);

    return $confirmations;
}

// Extract Confirmations from Monero CLI Output
function extract_confirmations($order_id) {
    // Path to confirmation.txt file
    $file_path = '/var/www/confirmation.txt';

    // Read content from confirmation.txt
    $command_output = file_get_contents($file_path);

    // Split the output into lines
    $lines = explode("\n", $command_output);

    // Iterate through each line to find the one containing the confirmation details
    foreach ($lines as $line) {
        if (strpos($line, 'This transaction has') !== false) {
            // Extract the number of confirmations using regular expression
            preg_match('/This transaction has (\d+) confirmations/', $line, $matches);

            if (isset($matches[1])) {
                $confirmations = intval($matches[1]);

                // Cancel order if zero confirmations after 40 minutes
                if ($confirmations === 0 && (time() - get_post_meta($order_id, '_monero_order_created_time', true)) > 2400) {
                    wc_cancel_order($order_id);
                    wp_redirect(wc_get_page_permalink('shop'));
                    exit;
                }

                return $confirmations;
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

    // Execute the command and capture the output
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

// Write the important part of the output to subaddress.txt
function write_subaddress_to_file($filtered_output) {
    // Path to subaddress.txt file
    $file_path = '/var/www/subaddress.txt';

    // Open the file for writing (create if not exists)
    $file = fopen($file_path, 'a');

    if ($file) {
        // Write the filtered output to the file
        fwrite($file, $filtered_output . PHP_EOL);

        // Close the file
        fclose($file);
    }
}

// Filter the Monero CLI output to extract important information
function filter_monero_cli_output($output) {
    // Constant part to filter out
    $constant_part = "(This is the command line monero wallet. It needs to connect to a monero
    daemon to work correctly.
    WARNING: Do not reuse your Monero keys on another fork, UNLESS this fork has key reuse mitigations built in. Doing so will harm your privacy.
    Monero 'Fluorine Fermi' (v0.18.3.1-release)
    Logging to /var/www/monero-x86_64-linux-gnu-v0.18.3.1/monero-wallet-cli.log
    Opened wallet: 45mvLmqAf8p1BWRQ9GexLvRwHoXkRibEzHXfDEv6maLaAJ5a4YzCeF2DvC6g985LJwCcmyU5iGs7zD1pW3ExSVARHJpMM4W
    **********************************************************************
    Use the \"help\" command to see a simplified list of available commands.
    Use \"help all\" to see the list of all available commands.
    Use \"help <command>\" to see a command's documentation.
    **********************************************************************
    )";

    // Remove the constant part from the output
    $filtered_output = str_replace($constant_part, '', $output);

    // Trim leading and trailing whitespaces
    $filtered_output = trim($filtered_output);

    return $filtered_output;
}
