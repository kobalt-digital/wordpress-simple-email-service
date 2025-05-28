<?php

/**
 * WP-CLI Command for Simple Email Service
 *
 * This file adds a WP-CLI command to send test emails using the Simple Email Service.
 * It allows users to verify their email configuration directly from the command line.
 *
 * @package Simple_Email_Service
 * @author Arne van Hoorn - Kobalt Digital
 * @license GPL v2 or later
 * @link https://kobaltdigital.nl
 */

 if (!defined('ABSPATH')) {
    exit;
}

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Registers a WP-CLI command to send a test email.
     *
     * @param array $args Command arguments.
     * @param array $assoc_args Command associative arguments.
     */
    WP_CLI::add_command('ses test', function ($args, $assoc_args) {
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