<?php
/**
 * Add this to your plugin to enable WP-CLI testing
 */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('ses test', function($args, $assoc_args) {
        $to = WP_CLI::get_flag_value($assoc_args, 'to', get_option('admin_email'));

        $result = wp_mail(
            $to,
            'Test Email from Simple Email Service',
            '<h1>This is a test email</h1><p>Sent via WP-CLI!</p>',
            array('Content-Type: text/html; charset=UTF-8')
        );

        if ($result) {
            WP_CLI::success("Test email sent to $to");
        } else {
            WP_CLI::error("Failed to send test email");
        }
    });
}