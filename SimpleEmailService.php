<?php

declare(strict_types=1);

/**
 * Simple Email Service Class
 *
 * This class integrates with the Simple Email Service API to send emails from WordPress.
 * It provides methods for configuring the service, sending emails, and handling errors.
 *
 * @package Simple_Email_Service
 * @author Arne van Hoorn - Kobalt Digital
 * @license GPL v2 or later
 * @link https://kobaltdigital.nl
 */
class SimpleEmailService
{
    private string $api_key;
    private string $api_url = 'https://api.simplemailservice.eu/v1/email/send';
    private string $plugin_basename;

    /**
     * Constructor for the Simple Email Service class.
     *
     * Initializes the API key and plugin basename, and sets up WordPress hooks for email handling.
     *
     * @param string|null $pluginFile The plugin file path.
     */
    public function __construct($pluginFile = null)
    {
        if (!defined('ABSPATH')) {
            exit;
        }

        $this->api_key = get_option('ses_api_key', '');
        $this->plugin_basename = $pluginFile
            ? plugin_basename($pluginFile)
            : plugin_basename(__FILE__);

        // Hook into WordPress email system
        add_action('phpmailer_init', [$this, 'disablePhpmailer']);
        add_action('wp_mail_failed', [$this, 'handleWpMailFailed']);
        add_filter('pre_wp_mail', [$this, 'sendEmail'], 10, 2);

        // Admin hooks
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);

        // Plugin action links
        $this->addPluginActionLinksFilter();

        // WP-CLI command
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('ses test', [$this, 'wpCliTestEmail']);
        }
    }

    /**
     * Disables the default PHPMailer settings.
     *
     * @param PHPMailer $phpmailer The PHPMailer instance.
     */
    public function disablePhpmailer($phpmailer)
    {
        $phpmailer->Mailer = 'smtp';
        $phpmailer->Host = 'localhost';
        $phpmailer->SMTPAuth = false;
        $phpmailer->Port = 1025;
    }

    /**
     * Sends an email using the Simple Email Service API.
     *
     * @param bool $pre_wp_mail Whether to preempt the wp_mail function.
     * @param array $atts Email attributes.
     * @return bool Whether the email was sent successfully.
     */
    public function sendEmail($pre_wp_mail, $atts)
    {
        if (empty($this->api_key)) {
            return false;
        }

        $to = $atts['to'];
        $subject = $atts['subject'];
        $message = $atts['message'];
        $headers = $atts['headers'];

        // Parse headers
        $fromEmail = get_option('ses_from_email', get_option('admin_email'));
        $fromName = get_option('ses_from_name', get_bloginfo('name'));

        if (!empty($headers) && is_string($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }

        if (!empty($headers) && is_array($headers)) {
            foreach ($headers as $header) {
                if (strpos($header, 'From:') !== 0) {
                    continue;
                }
                $from = trim(str_replace('From:', '', $header));

                // Extract name and email
                if (preg_match('/(.*?)<(.+)>/', $from, $matches)) {
                    $fromName = trim($matches[1]);
                    $fromEmail = trim($matches[2]);
                    break;
                }
                $fromEmail = $from;
                break;
            }
        }

        // Prepare recipients
        $recipients = [];
        if (is_array($to)) {
            foreach ($to as $recipient) {
                $recipients[] = ['email' => $recipient];
            }
        } else {
            $recipients[] = ['email' => $to];
        }

        // Prepare email payload
        $email = [
            'from' => [
                'name' => $fromName,
                'email' => $fromEmail
            ],
            'recipients' => $recipients,
            'content' => [
                'subject' => $subject,
                'text_body' => wp_strip_all_tags($message),
                'html_body' => $message
            ]
        ];

        $options = [
            'http' => [
                'header' => [
                    'Content-type: application/json',
                    'X-Api-Key: ' . $this->api_key
                ],
                'method' => 'POST',
                'content' => json_encode($email)
            ]
        ];

        try {
            $context = stream_context_create($options);
            $response = file_get_contents(
                $this->api_url,
                false,
                $context
            );

            if ($response === false) {
                $error = new WP_Error(
                    'ses_send_failed',
                    __('Simple Email Service: Failed to send email', 'simple-email-service')
                );
                do_action('wp_mail_failed', $error);
                return false;
            }

            return true;
        } catch (Exception $e) {
            $error = new WP_Error(
                'ses_exception',
                sprintf(
                    /* translators: %s: Error message from the Simple Email Service API */
                    __('Simple Email Service: %s', 'simple-email-service'),
                    $e->getMessage()
                )
            );
            do_action('wp_mail_failed', $error);
            return false;
        }
    }

    /**
     * Handles failed email attempts.
     *
     * @param WP_Error $error The error object.
     */
    public function handleWpMailFailed($error)
    {
        $errorMessage = sprintf(
            /* translators: %s: Error message from WordPress mail function */
            __('Simple Email Service: Mail error: %s', 'simple-email-service'),
            $error->get_error_message()
        );

        // Only log in development environment
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($errorMessage);
        }
    }

    /**
     * Adds the Simple Email Service settings page to the WordPress admin menu.
     */
    public function addAdminMenu()
    {
        add_options_page(
            __('Simple Email Service Settings', 'simple-email-service'),
            __('Simple Email Service', 'simple-email-service'),
            'manage_options',
            'simple-email-service',
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Registers the settings for the Simple Email Service.
     */
    public function registerSettings()
    {
        register_setting(
            'simple_email_service',
            'ses_api_key',
            [$this, 'sanitizeApiKey']
        );
        register_setting(
            'simple_email_service',
            'ses_from_email',
            [$this, 'sanitizeEmail']
        );
        register_setting(
            'simple_email_service',
            'ses_from_name',
            [$this, 'sanitizeText']
        );
    }

    /**
     * Sanitizes the API key.
     *
     * @param string $value The API key to sanitize.
     * @return string Sanitized API key.
     */
    public function sanitizeApiKey($value): string
    {
        return sanitize_text_field($value);
    }

    /**
     * Sanitizes the email address.
     *
     * @param string $value The email address to sanitize.
     * @return string Sanitized email address.
     */
    public function sanitizeEmail($value): string
    {
        $sanitized = sanitize_email($value);
        if (!is_email($sanitized)) {
            add_settings_error(
                'ses_from_email',
                'invalid_email',
                __('Please enter a valid email address.', 'simple-email-service')
            );
            return get_option('ses_from_email', get_option('admin_email'));
        }
        return $sanitized;
    }

    /**
     * Sanitizes the text input.
     *
     * @param string $value The text to sanitize.
     * @return string Sanitized text.
     */
    public function sanitizeText($value): string
    {
        return sanitize_text_field($value);
    }

    /**
     * Renders the settings page for the Simple Email Service.
     */
    public function renderSettingsPage()
    {
        if (isset($_POST['ses_test_email'])) {
            // Verify nonce
            $nonce = isset($_POST['ses_test_email_nonce']) ? sanitize_key($_POST['ses_test_email_nonce']) : '';
            if (!wp_verify_nonce($nonce, 'ses_test_email')) {
                wp_die(esc_html__('Security check failed', 'simple-email-service'));
            }

            $this->sendTestEmail();
        }
        $fromEmail = get_option('ses_from_email', get_option('admin_email'));
        $show_domain_warning = !$this->isFromEmailDomainValid($fromEmail);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <style>
                .regular-text.error {
                    border-color: #dc3232;
                    box-shadow: 0 0 2px rgba(220, 50, 50, 0.8);
                }
            </style>

            <?php settings_errors('simple_email_service'); ?>

            <?php if ($show_domain_warning) { ?>
                <div class="notice notice-error">
                    <p>
                        <?php
                            esc_html_e(
                                'No mails can be sent if the domain of the "From Email Address" is different from the site domain.',
                                'simple-email-service'
                            );
                        ?>
                    </p>
                </div>
            <?php } ?>

            <!-- Test Email Form -->
            <form method="post" action="">
                <?php wp_nonce_field('ses_test_email', 'ses_test_email_nonce'); ?>
                <h2><?php esc_html_e('Send Test Email', 'simple-email-service'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="test_email">
                                <?php esc_html_e('Test Email Address', 'simple-email-service'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="email"
                                   id="test_email"
                                   name="test_email"
                                   value="<?php echo esc_attr(get_option('admin_email')); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button('Send Test Email', 'secondary', 'ses_test_email'); ?>
            </form>

            <!-- Settings Form -->
            <form action="options.php" method="post">
                <?php
                settings_fields('simple_email_service');
                do_settings_sections('simple_email_service');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ses_api_key"><?php esc_html_e('API Key', 'simple-email-service'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="ses_api_key"
                                   name="ses_api_key"
                                   value="<?php echo esc_attr(get_option('ses_api_key')); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ses_from_email">
                                <?php esc_html_e('From Email Address', 'simple-email-service'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="email"
                                   id="ses_from_email"
                                   name="ses_from_email"
                                   value="<?php echo esc_attr(get_option('ses_from_email', get_option('admin_email'))); ?>"
                                   class="regular-text <?php echo $show_domain_warning ? 'error' : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ses_from_name"><?php esc_html_e('From Name', 'simple-email-service'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="ses_from_name"
                                   name="ses_from_name"
                                   value="<?php echo esc_attr(get_option('ses_from_name', get_bloginfo('name'))); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Sends a test email to verify the configuration.
     */
    private function sendTestEmail()
    {
        // Verify nonce
        if (
            !isset($_POST['ses_test_email_nonce'])
            || !wp_verify_nonce(sanitize_key($_POST['ses_test_email_nonce']), 'ses_test_email')
            ) {
            wp_die(esc_html__('Security check failed', 'simple-email-service'));
        }

        $test_email = isset($_POST['test_email'])
            ? sanitize_email(wp_unslash($_POST['test_email']))
            : get_option('admin_email');

        $to = $test_email;
        $subject = 'Test Email from Simple Email Service';
        $message = '<h1>This is a test email</h1><p>
            If you receive this, the Simple Email Service plugin is working correctly!</p>';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $result = wp_mail($to, $subject, $message, $headers);

        if ($result) {
            add_settings_error(
                'simple_email_service',
                'ses_test_success',
                __('Test email sent successfully! Check your inbox.', 'simple-email-service'),
                'success'
            );
        } else {
            add_settings_error(
                'simple_email_service',
                'ses_test_error',
                __('Failed to send test email. Check error logs.', 'simple-email-service'),
                'error'
            );
        }
    }

    /**
     * WP-CLI command to send a test email.
     *
     * @param array $args Command arguments.
     * @param array $assoc_args Command associative arguments.
     */
    public function wpCliTestEmail($args, $assoc_args)
    {
        $to = WP_CLI\Utils\get_flag_value($assoc_args, 'to', get_option('admin_email'));

        $result = wp_mail(
            $to,
            'Test Email from Simple Email Service',
            '<h1>This is a test email</h1><p>Sent via WP-CLI!</p>',
            ['Content-Type: text/html; charset=UTF-8']
        );

        if ($result) {
            WP_CLI::success("Test email sent to $to");
        } else {
            WP_CLI::error("Failed to send test email");
        }
    }

    /**
     * Checks if the from email domain is valid.
     *
     * @param string $fromEmail The from email address.
     * @return bool Whether the domain is valid.
     */
    private function isFromEmailDomainValid($fromEmail): bool
    {
        $siteUrl = get_site_url();
        $siteDomain = preg_replace('/^www\./', '', wp_parse_url($siteUrl, PHP_URL_HOST));
        $fromDomain = substr(strrchr($fromEmail, '@'), 1);
        $fromDomain = preg_replace('/^www\./', '', $fromDomain);
        $fromDomain = strtolower($fromDomain);
        $siteDomain = strtolower($siteDomain);
        return $fromDomain === $siteDomain;
    }

    /**
     * Adds a filter for plugin action links.
     */
    private function addPluginActionLinksFilter(): void
    {
        add_filter(
            'plugin_action_links_' . $this->plugin_basename,
            function ($links) {
                $settingsLink = '<a href="options-general.php?page=simple-email-service">'
                    . __('Settings', 'simple-email-service')
                    . '</a>';
                array_unshift($links, $settingsLink);
                return $links;
            }
        );
    }
}
