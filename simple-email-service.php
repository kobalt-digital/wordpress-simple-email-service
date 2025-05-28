<?php
/**
 * Plugin Name: Simple Email Service by hosting.nl
 * Plugin URI: https://kobaltdigital.nl/simple-email-service
 * Description: Send WordPress emails through the Simple Email Service API
 * Version: 1.0.2
 * Tested up to: 6.8
 * Author: Arne van Hoorn - Kobalt Digital
 * Author URI: https://kobaltdigital.nl
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simple-email-service-by-hosting-nl
 * Send WordPress emails through the Simple Email Service API by hosting.nl
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/SimpleEmailService.php';

new SimpleEmailService(__FILE__);
