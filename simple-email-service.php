<?php
/**
 * Plugin Name: Simple Email Service
 * Plugin URI: https://kobaltdigital.nl/simple-email-service
 * Description: Send WordPress emails through the Simple Email Service API
 * Version: 1.0.0
 * Author: Arne van Hoorn - Kobalt Digital
 * Author URI: https://kobaltdigital.nl
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simple-email-service
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/SimpleEmailService.php';

new SimpleEmailService(__FILE__);
