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
class SimpleEmailServiceByHostingNL
{
    private string $api_key;
    private string $api_url = 'https://api.simplemailservice.eu/v1/email/send';
    private string $plugin_basename;
    private bool $logging_enabled;
    private string $log_file_path;
    private string $current_log_filename;
    private bool $is_rotating_logs = false;

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

        $this->api_key = get_option('ses_by_hosting_nl_api_key', '');
        $this->plugin_basename = $pluginFile
            ? plugin_basename($pluginFile)
            : plugin_basename(__FILE__);

        // Initialize logging settings
        $logging_option = get_option('ses_by_hosting_nl_logging_enabled', false);
        $this->logging_enabled = $this->sanitizeBool($logging_option);

        // Ensure log options are properly initialized
        $this->initializeLogOptions();

        // Load or generate the current log filename
        $this->current_log_filename = $this->getCurrentLogFilename();
        $this->log_file_path = $this->getLogFilePath();

        // Verify that options are properly set (debug only)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->verifyLogOptions();
        }

        // Hook into WordPress email system
        add_action('phpmailer_init', [$this, 'disablePhpmailer']);
        add_action('wp_mail_failed', [$this, 'handleWpMailFailed']);
        add_filter('pre_wp_mail', [$this, 'sendEmail'], 10, 2);

        // Admin hooks
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        // Plugin action links
        $this->addPluginActionLinksFilter();

        // AJAX handlers for log management
        add_action('wp_ajax_ses_download_log', [$this, 'downloadLog']);
        add_action('wp_ajax_ses_clear_log', [$this, 'clearLog']);
        add_action('wp_ajax_ses_rotate_logs', [$this, 'manualLogRotation']);

        // WP-CLI command
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('ses test', [$this, 'wpCliTestEmail']);
            WP_CLI::add_command('ses rotate-logs', [$this, 'wpCliRotateLogs']);
        }

        // Daily log rotation - create new log file if needed
        $this->checkDailyLogRotation();

        // Schedule daily log rotation if not already scheduled
        if (!wp_next_scheduled('ses_log_rotation')) {
            wp_schedule_event(time(), 'daily', 'ses_log_rotation');
        }
        add_action('ses_log_rotation', [$this, 'rotateLogs']);
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
        $fromEmail = get_option('ses_by_hosting_nl_from_email', get_option('admin_email'));
        $fromName = get_option('ses_by_hosting_nl_from_name', get_bloginfo('name'));

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

        try {
            $response = wp_remote_post($this->api_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Api-Key' => $this->api_key
                ],
                'body' => json_encode($email)
            ]);

            if (is_wp_error($response)) {
                $error = new WP_Error(
                    'ses_by_hosting_nl_send_failed',
                    __('Simple Email Service: Failed to send email', 'simple-email-service-by-hosting-nl')
                );
                do_action('wp_mail_failed', $error);

                // Log failed email attempt
                $this->logEmailFailed($to, $subject, $message, $fromEmail, $fromName, 'API request failed');

                return false;
            }

            // Get response code and body
            $response_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            // Check if the request was successful based on HTTP status code
            if ($response_code !== 200) {
                $error_message = 'HTTP Error: ' . $response_code;
                if (isset($body['message'])) {
                    $error_message .= ' - ' . $body['message'];
                } elseif (isset($body['status'])) {
                    $error_message .= ' - ' . $body['status'];
                }

                $error = new WP_Error(
                    'ses_by_hosting_nl_http_error',
                    sprintf(
                        /* translators: %s: Error message from the Simple Email Service API */
                        __('Simple Email Service: %s', 'simple-email-service-by-hosting-nl'),
                        $error_message
                    )
                );
                do_action('wp_mail_failed', $error);

                // Log failed email attempt
                $this->logEmailFailed($to, $subject, $message, $fromEmail, $fromName, $error_message);

                return false;
            }

            // Check for domain not registered error in response body (even with 200 status)
            if (isset($body['status']) && $body['status'] === 'domain is not registered') {
                $error = new WP_Error(
                    'ses_by_hosting_nl_domain_not_registered',
                    __('Simple Email Service: Domain is not registered', 'simple-email-service-by-hosting-nl')
                );
                do_action('wp_mail_failed', $error);

                // Log failed email attempt
                $this->logEmailFailed($to, $subject, $message, $fromEmail, $fromName, 'Domain not registered');

                return false;
            }

            // Log successful email sending
            $this->logEmailSent($to, $subject, $fromEmail, $fromName);

            return true;
        } catch (Exception $e) {
            $error = new WP_Error(
                'ses_by_hosting_nl_exception',
                sprintf(
                    /* translators: %s: Error message from the Simple Email Service API */
                    __('Simple Email Service: %s', 'simple-email-service-by-hosting-nl'),
                    $e->getMessage()
                )
            );
            do_action('wp_mail_failed', $error);

            // Log failed email attempt
            $this->logEmailFailed($to, $subject, $message, $fromEmail, $fromName, 'Exception: ' . $e->getMessage());

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
            __('Simple Email Service: Mail error: %s', 'simple-email-service-by-hosting-nl'),
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
            __('Simple Email Service Settings', 'simple-email-service-by-hosting-nl'),
            __('Simple Email Service', 'simple-email-service-by-hosting-nl'),
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
            'ses_by_hosting_nl_api_key',
            [$this, 'sanitizeApiKey']
        );
        register_setting(
            'simple_email_service',
            'ses_by_hosting_nl_from_email',
            [$this, 'sanitizeEmail']
        );
        register_setting(
            'simple_email_service',
            'ses_by_hosting_nl_from_name',
            [$this, 'sanitizeText']
        );
        register_setting(
            'simple_email_service',
            'ses_by_hosting_nl_logging_enabled',
            [$this, 'sanitizeBool']
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
                'ses_by_hosting_nl_from_email',
                'invalid_email',
                __('Please enter a valid email address.', 'simple-email-service-by-hosting-nl')
            );
            return get_option('ses_by_hosting_nl_from_email', get_option('admin_email'));
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
     * Sanitizes a boolean value.
     *
     * @param mixed $value The boolean value to sanitize.
     * @return bool Sanitized boolean.
     */
    public function sanitizeBool($value): bool
    {
        // Handle empty values (unchecked checkbox)
        if (empty($value)) {
            return false;
        }

        // Handle string "1" from checkbox
        if ($value === '1' || $value === 'true' || $value === true) {
            return true;
        }

        // Handle string "0" or "false"
        if ($value === '0' || $value === 'false' || $value === false) {
            return false;
        }

        // Use filter_var as fallback
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Renders the settings page for the Simple Email Service.
     */
    public function renderSettingsPage()
    {
        if (isset($_POST['ses_by_hosting_nl_test_email'])) {
            // Verify nonce
            $nonce = isset($_POST['ses_by_hosting_nl_test_email_nonce']) ? sanitize_key($_POST['ses_by_hosting_nl_test_email_nonce']) : '';
            if (!wp_verify_nonce($nonce, 'ses_by_hosting_nl_test_email')) {
                wp_die(esc_html__('Security check failed', 'simple-email-service-by-hosting-nl'));
            }

            $this->sendTestEmail();
        }
        $fromEmail = get_option('ses_by_hosting_nl_from_email', get_option('admin_email'));
        $show_domain_warning = !$this->isFromEmailDomainValid($fromEmail);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors('simple_email_service'); ?>

            <?php if (isset($_GET['log_cleared']) && $_GET['log_cleared'] === '1') { ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Email log has been cleared successfully.', 'simple-email-service-by-hosting-nl'); ?></p>
                </div>
            <?php } ?>

            <?php if ($show_domain_warning) { ?>
                <div class="notice notice-error">
                    <p>
                        <?php
                            esc_html_e(
                                'No mails can be sent if the domain of the "From Email Address" is different from the site domain.',
                                'simple-email-service-by-hosting-nl'
                            );
                        ?>
                    </p>
                </div>
            <?php } ?>

            <!-- Test Email Form -->
            <form method="post" action="">
                <?php wp_nonce_field('ses_by_hosting_nl_test_email', 'ses_by_hosting_nl_test_email_nonce'); ?>
                <h2><?php esc_html_e('Send Test Email', 'simple-email-service-by-hosting-nl'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="test_email">
                                <?php esc_html_e('Test Email Address', 'simple-email-service-by-hosting-nl'); ?>
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
                <?php submit_button('Send Test Email', 'secondary', 'ses_by_hosting_nl_test_email'); ?>
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
                            <label for="ses_by_hosting_nl_api_key"><?php esc_html_e('API Key', 'simple-email-service-by-hosting-nl'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="ses_by_hosting_nl_api_key"
                                   name="ses_by_hosting_nl_api_key"
                                   value="<?php echo esc_attr(get_option('ses_by_hosting_nl_api_key')); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ses_by_hosting_nl_from_email">
                                <?php esc_html_e('From Email Address', 'simple-email-service-by-hosting-nl'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="email"
                                   id="ses_by_hosting_nl_from_email"
                                   name="ses_by_hosting_nl_from_email"
                                   value="<?php echo esc_attr(get_option('ses_by_hosting_nl_from_email', get_option('admin_email'))); ?>"
                                   class="regular-text <?php echo $show_domain_warning ? 'error' : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ses_by_hosting_nl_from_name"><?php esc_html_e('From Name', 'simple-email-service-by-hosting-nl'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="ses_by_hosting_nl_from_name"
                                   name="ses_by_hosting_nl_from_name"
                                   value="<?php echo esc_attr(get_option('ses_by_hosting_nl_from_name', get_bloginfo('name'))); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ses_by_hosting_nl_logging_enabled">
                                <?php esc_html_e('Enable Logging', 'simple-email-service-by-hosting-nl'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="checkbox"
                                   id="ses_by_hosting_nl_logging_enabled"
                                   name="ses_by_hosting_nl_logging_enabled"
                                   value="1"
                                   <?php checked(get_option('ses_by_hosting_nl_logging_enabled', false)); ?>>
                            <p class="description">
                                <?php esc_html_e('Enable logging of email sending attempts and errors.', 'simple-email-service-by-hosting-nl'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <!-- Log Viewing Section -->
            <?php if ($this->logging_enabled && file_exists($this->log_file_path)) { ?>
                <h2><?php esc_html_e('Email Log', 'simple-email-service-by-hosting-nl'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Recent email sending activity:', 'simple-email-service-by-hosting-nl'); ?>
                    <br>
                    <strong><?php esc_html_e('Note:', 'simple-email-service-by-hosting-nl'); ?></strong>
                    <?php esc_html_e('Log files are automatically rotated daily and old entries older than 7 days are removed to save disk space.', 'simple-email-service-by-hosting-nl'); ?>
                </p>

                <?php
                $log_info = $this->getLogRotationInfo();
                if ($log_info['next_rotation']) {
                    $next_rotation = human_time_diff(time(), $log_info['next_rotation']);
                    echo '<p class="description"><strong>' . esc_html__('Next automatic log rotation:', 'simple-email-service-by-hosting-nl') . '</strong> ' . esc_html($next_rotation) . '</p>';
                }
                ?>
                <div class="log-viewer">
                    <textarea
                        readonly
                        rows="15"
                        cols="100"
                        style="width: 100%; font-family: monospace; font-size: 12px;"><?php
                        // Read the last 50 lines of the log file
                        $log_content = file($this->log_file_path);
                        if ($log_content) {
                            $recent_lines = array_slice($log_content, -50);
                            echo esc_textarea(implode('', $recent_lines));
                        }
                        ?>
                    </textarea>
                </div>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=ses_download_log&nonce=' . wp_create_nonce('ses_download_log'))); ?>"
                       class="button button-secondary">
                        <?php esc_html_e('Download Full Log', 'simple-email-service-by-hosting-nl'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=ses_clear_log&nonce=' . wp_create_nonce('ses_clear_log'))); ?>"
                       class="button button-secondary"
                       onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear the log?', 'simple-email-service-by-hosting-nl'); ?>')">
                        <?php esc_html_e('Clear Log', 'simple-email-service-by-hosting-nl'); ?>
                    </a>
                    <button type="button"
                            id="rotate-logs-btn"
                            class="button button-secondary">
                        <?php esc_html_e('Rotate Logs', 'simple-email-service-by-hosting-nl'); ?>
                    </button>
                </p>
            <?php } ?>
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
            !isset($_POST['ses_by_hosting_nl_test_email_nonce'])
            || !wp_verify_nonce(sanitize_key($_POST['ses_by_hosting_nl_test_email_nonce']), 'ses_by_hosting_nl_test_email')
            ) {
            wp_die(esc_html__('Security check failed', 'simple-email-service-by-hosting-nl'));
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
                'ses_by_hosting_nl_test_success',
                __('Test email sent successfully! Check your inbox.', 'simple-email-service-by-hosting-nl'),
                'success'
            );
        } else {
            add_settings_error(
                'simple_email_service',
                'ses_by_hosting_nl_test_error',
                __('Failed to send test email. Check error logs.', 'simple-email-service-by-hosting-nl'),
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
     * WP-CLI command to rotate logs.
     *
     * @param array $args Command arguments.
     * @param array $assoc_args Command associative arguments.
     */
    public function wpCliRotateLogs($args, $assoc_args)
    {
        if (!$this->logging_enabled) {
            WP_CLI::warning("Logging is not enabled.");
            return;
        }

        WP_CLI::log("Starting log rotation...");
        $this->rotateLogs();
        WP_CLI::success("Log rotation completed successfully.");
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
                    . __('Settings', 'simple-email-service-by-hosting-nl')
                    . '</a>';
                array_unshift($links, $settingsLink);
                return $links;
            }
        );
    }

    /**
     * Enqueues admin scripts and styles.
     *
     * @param string $hook The current admin page.
     */
    public function enqueueAdminAssets($hook): void
    {
        // Only load on our settings page
        if ('settings_page_simple-email-service' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'simple-email-service-admin',
            plugin_dir_url(__FILE__) . 'css/admin.css',
            [],
            filemtime(plugin_dir_path(__FILE__) . 'css/admin.css')
        );

        wp_enqueue_script(
            'simple-email-service-admin',
            plugin_dir_url(__FILE__) . 'js/admin.js',
            ['jquery'],
            filemtime(plugin_dir_path(__FILE__) . 'js/admin.js'),
            true
        );

        wp_localize_script('simple-email-service-admin', 'sesAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ses_rotate_logs'),
            'strings' => [
                'rotating' => __('Rotating logs...', 'simple-email-service-by-hosting-nl'),
                'success' => __('Log rotation completed successfully.', 'simple-email-service-by-hosting-nl'),
                'error' => __('Log rotation failed.', 'simple-email-service-by-hosting-nl')
            ]
        ]);
    }

    /**
     * Gets the path for the log file.
     *
     * @return string The full path to the log file.
     */
    private function getLogFilePath(): string
    {
        $upload_dir = wp_upload_dir();
        $log_dir = trailingslashit($upload_dir['basedir']) . 'simple-email-service-logs';

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);

            // Create .htaccess file to protect the log directory
            $this->createHtaccessProtection($log_dir);
        }

        return trailingslashit($log_dir) . $this->current_log_filename;
    }

    /**
     * Generates a filename for the daily log file.
     *
     * @return string The filename for the daily log.
     */
    private function generateDailyLogFilename(): string
    {
        $random_string = $this->generateRandomString(16);
        $date = date('Y-m-d');
        return "simple-email-service-log-{$random_string}-{$date}.log";
    }

    /**
     * Generates a random string for log filename security.
     *
     * @param int $length The length of the random string.
     * @return string The random string.
     */
    private function generateRandomString(int $length): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $random_string = '';
        for ($i = 0; $i < $length; $i++) {
            $random_string .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $random_string;
    }

    /**
     * Checks if the current log file should be rotated based on daily rotation.
     * Creates a new log file if the current date is different from the stored log date.
     */
    private function checkDailyLogRotation(): void
    {
        if (!$this->logging_enabled || $this->is_rotating_logs) {
            return;
        }

        $current_date = date('Y-m-d');
        $stored_log_date = get_option('ses_by_hosting_nl_log_date', date('Y-m-d'));

        // Debug logging to help identify the issue
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->debugLogOptions($current_date, $stored_log_date);
        }

        // If the date has changed, create a new log file
        if ($current_date !== $stored_log_date) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("SES Debug - Date changed, creating new log file");
            }
            $this->createNewDailyLog();
        }
    }

    /**
     * Debug method to show the current state of log options.
     */
    private function debugLogOptions(string $current_date, string $stored_date): void
    {
        $random_string = get_option('ses_by_hosting_nl_log_random_string', 'NOT SET');
        $current_filename = $this->current_log_filename;

        error_log("SES Debug - Current date: $current_date, Stored date: $stored_date");
        error_log("SES Debug - Random string: $random_string");
        error_log("SES Debug - Current filename: $current_filename");
        error_log("SES Debug - Date comparison: " . ($current_date === $stored_date ? 'SAME' : 'DIFFERENT'));
    }

    /**
     * Verifies that log options are properly set.
     */
    private function verifyLogOptions(): void
    {
        $log_date = get_option('ses_by_hosting_nl_log_date', 'NOT SET');
        $random_string = get_option('ses_by_hosting_nl_log_random_string', 'NOT SET');

        if ($log_date === 'NOT SET') {
            error_log("SES Debug - ses_by_hosting_nl_log_date is NOT SET. Initializing...");
            update_option('ses_by_hosting_nl_log_date', date('Y-m-d'));
        }
        if ($random_string === 'NOT SET') {
            error_log("SES Debug - ses_by_hosting_nl_log_random_string is NOT SET. Initializing...");
            update_option('ses_by_hosting_nl_log_random_string', $this->generateRandomString(16));
        }

        error_log("SES Debug - ses_by_hosting_nl_log_date: " . $log_date);
        error_log("SES Debug - ses_by_hosting_nl_log_random_string: " . $random_string);
    }


    /**
     * Creates a new daily log file.
     */
    private function createNewDailyLog(): void
    {
        // $this->is_rotating_logs = true;

        // // Update the WordPress options with new date and random string
        // $this->updateLogDateAndRandomString();

        // // Update the current filename and path
        // $this->current_log_filename = $this->getCurrentLogFilename();
        // $this->log_file_path = $this->getLogFilePath();

        // // Create the new log file with a header
        // $header = "=== Simple Email Service Log - " . date('Y-m-d H:i:s') . " ===\n";
        // $header .= "Log file created for daily rotation\n";
        // $header .= "==========================================\n\n";

        // file_put_contents($this->log_file_path, $header);

        // // Log the rotation without calling logMessage to prevent infinite loops
        // $this->writeLogEntry("LOG ROTATED - New daily log file created: {$this->current_log_filename}");

        // Clean up old log files when creating a new daily log
        $this->rotateLogs();

        // $this->is_rotating_logs = false;
    }

    /**
     * Gets the current log filename from WordPress options.
     *
     * @return string The current log filename.
     */
    private function getCurrentLogFilename(): string
    {
        $log_date = get_option('ses_by_hosting_nl_log_date', date('Y-m-d'));
        $random_string = get_option('ses_by_hosting_nl_log_random_string', '');

        // If no random string exists, generate one and store it
        if (empty($random_string)) {
            $random_string = $this->generateRandomString(16);
            update_option('ses_by_hosting_nl_log_random_string', $random_string);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("SES Debug - Generated new random string: $random_string");
            }
        }

        $filename = "simple-email-service-log-{$random_string}-{$log_date}.log";

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("SES Debug - Generated filename: $filename");
        }

        return $filename;
    }

    /**
     * Ensures log options are properly initialized.
     */
    private function initializeLogOptions(): void
    {
        // Initialize log date if not set
        if (!get_option('ses_by_hosting_nl_log_date')) {
            update_option('ses_by_hosting_nl_log_date', date('Y-m-d'));
        }

        // Initialize random string if not set
        if (!get_option('ses_by_hosting_nl_log_random_string')) {
            update_option('ses_by_hosting_nl_log_random_string', $this->generateRandomString(16));
        }
    }

    /**
     * Updates the log date and random string in WordPress options.
     */
    private function updateLogDateAndRandomString(): void
    {
        update_option('ses_by_hosting_nl_log_date', date('Y-m-d'));
        // Don't regenerate the random string - it should persist for the entire day
        // Only generate a new random string if one doesn't exist
        if (!get_option('ses_by_hosting_nl_log_random_string')) {
            update_option('ses_by_hosting_nl_log_random_string', $this->generateRandomString(16));
        }
    }

    /**
     * Creates protection files to secure the log directory from direct access.
     * Creates both .htaccess (Apache) and index.php (Nginx) protection.
     *
     * @param string $log_dir The log directory path.
     */
    private function createHtaccessProtection(string $log_dir): void
    {
        // Create .htaccess file for Apache protection
        $htaccess_content = "# Deny access to all files in this directory\n";
        $htaccess_content .= "<Files \"*\">\n";
        $htaccess_content .= "    Order Allow,Deny\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "</Files>\n\n";
        $htaccess_content .= "# Deny access to all file types\n";
        $htaccess_content .= "<FilesMatch \".*\">\n";
        $htaccess_content .= "    Order Allow,Deny\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "</FilesMatch>\n\n";
        $htaccess_content .= "# Alternative method using Require directive (Apache 2.4+)\n";
        $htaccess_content .= "<RequireAll>\n";
        $htaccess_content .= "    Require all denied\n";
        $htaccess_content .= "</RequireAll>\n\n";
        $htaccess_content .= "# Prevent directory browsing\n";
        $htaccess_content .= "Options -Indexes\n\n";
        $htaccess_content .= "# Additional security headers\n";
        $htaccess_content .= "<IfModule mod_headers.c>\n";
        $htaccess_content .= "    Header always set X-Content-Type-Options nosniff\n";
        $htaccess_content .= "    Header always set X-Frame-Options DENY\n";
        $htaccess_content .= "    Header always set X-XSS-Protection \"1; mode=block\"\n";
        $htaccess_content .= "</IfModule>\n";

        $htaccess_file = trailingslashit($log_dir) . '.htaccess';

        // Only create if it doesn't exist
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, $htaccess_content);
        }

        // Create index.php file for Nginx protection
        $index_content = "<?php\n";
        $index_content .= "// Silence is golden.\n";

        $index_file = trailingslashit($log_dir) . 'index.php';

        // Only create if it doesn't exist
        if (!file_exists($index_file)) {
            file_put_contents($index_file, $index_content);
        }
    }

    /**
     * Writes a message to the log file without checking for rotation.
     * Used during log rotation to prevent infinite loops.
     *
     * @param string $message The message to log.
     */
    private function writeLogEntry(string $message): void
    {
        if (!$this->logging_enabled) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] $message\n";

        // Use file_put_contents for atomic writes, but handle potential race conditions
        // with flock for better reliability.
        $fp = @fopen($this->log_file_path, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $log_entry);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        } else {
            error_log("Could not open log file for writing: " . $this->log_file_path);
        }
    }

    /**
     * Writes a message to the log file.
     *
     * @param string $message The message to log.
     */
    private function logMessage(string $message): void
    {
        if (!$this->logging_enabled) {
            return;
        }

        // Check if we need to rotate based on daily rotation
        $this->checkDailyLogRotation();

        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] $message\n";

        // Use file_put_contents for atomic writes, but handle potential race conditions
        // with flock for better reliability.
        $fp = @fopen($this->log_file_path, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $log_entry);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        } else {
            error_log("Could not open log file for writing: " . $this->log_file_path);
        }
    }

    /**
     * Logs email sending details.
     *
     * @param string|array $to Recipient email(s).
     * @param string $subject Email subject.
     * @param string $fromEmail Sender email.
     * @param string $fromName Sender name.
     */
    private function logEmailSent($to, string $subject, string $fromEmail, string $fromName): void
    {
        if (!$this->logging_enabled) {
            return;
        }

        // Format recipients for logging
        $recipients = is_array($to) ? implode(', ', $to) : $to;

        $logMessage = sprintf(
            'EMAIL SENT - From: %s <%s> | To: %s | Subject: %s',
            $fromName,
            $fromEmail,
            $recipients,
            $subject
        );

        $this->logMessage($logMessage);
    }

    /**
     * Logs failed email attempts.
     *
     * @param string|array $to Recipient email(s).
     * @param string $subject Email subject.
     * @param string $fromEmail Sender email.
     * @param string $fromName Sender name.
     * @param string $errorReason Reason for the failure.
     */
    private function logEmailFailed($to, string $subject, string $message, string $fromEmail, string $fromName, string $errorReason): void
    {
        if (!$this->logging_enabled) {
            return;
        }

        // Format recipients for logging
        $recipients = is_array($to) ? implode(', ', $to) : $to;

        $logMessage = sprintf(
            'EMAIL FAILED - Error: %s | From: %s <%s> | To: %s | Subject: %s',
            $errorReason,
            $fromName,
            $fromEmail,
            $recipients,
            $subject
        );

        $this->logMessage($logMessage);
    }

    /**
     * Downloads the log file.
     */
    public function downloadLog(): void
    {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_GET['nonce'] ?? '', 'ses_download_log') || !current_user_can('manage_options')) {
            wp_die(esc_html__('Security check failed', 'simple-email-service-by-hosting-nl'));
        }

        $log_dir = dirname($this->log_file_path);
        if (!is_dir($log_dir)) {
            wp_die(esc_html__('Log directory not found', 'simple-email-service-by-hosting-nl'));
        }

        // Create temporary zip file
        $zip_filename = 'simple-email-service-logs-' . date('Y-m-d') . '.zip';
        $zip_filepath = $log_dir . '/' . $zip_filename;
        $zip = new ZipArchive();

        if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die(esc_html__('Could not create zip file', 'simple-email-service-by-hosting-nl'));
        }

        // Add all .log files to the zip
        $log_files = glob($log_dir . '/*.log');
        foreach ($log_files as $log_file) {
            if (file_exists($log_file)) {
                $zip->addFile($log_file, basename($log_file));
            }
        }
        $zip->close();

        // Set headers for zip download
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($zip_filepath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

        // Output zip file and clean up
        readfile($zip_filepath);
        unlink($zip_filepath);
        exit;
    }

    /**
     * Clears the log file.
     */
    public function clearLog(): void
    {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_GET['nonce'] ?? '', 'ses_clear_log') || !current_user_can('manage_options')) {
            wp_die(esc_html__('Security check failed', 'simple-email-service-by-hosting-nl'));
        }

        // Clear current log file and delete other log files
        $log_dir = dirname($this->log_file_path);
        if (is_dir($log_dir)) {
            $log_files = glob($log_dir . '/*.log');
            foreach ($log_files as $log_file) {
                if (file_exists($log_file)) {
                    if ($log_file === $this->log_file_path) {
                        // Clear current log file
                        file_put_contents($log_file, '');
                    } else {
                        // Delete other log files
                        unlink($log_file);
                    }
                }
            }
        }

        // Redirect back to settings page
        wp_redirect(admin_url('options-general.php?page=simple-email-service&log_cleared=1'));
        exit;
    }

    /**
     * Manually triggers log rotation via AJAX.
     */
    public function manualLogRotation(): void
    {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ses_rotate_logs') || !current_user_can('manage_options')) {
            wp_die(esc_html__('Security check failed', 'simple-email-service-by-hosting-nl'));
        }

        // Perform log rotation
        $this->rotateLogs();

        // Send success response
        wp_send_json_success(__('Log rotation completed successfully.', 'simple-email-service-by-hosting-nl'));
    }

    /**
     * Rotates and cleans up old log files.
     * Deletes log files older than 7 days.
     */
    private function rotateLogs(): void
    {
        if (!$this->logging_enabled) {
            return;
        }

        $log_dir = dirname($this->log_file_path);
        if (!is_dir($log_dir)) {
            return;
        }

        try {
            $files = array_diff(scandir($log_dir), array('.', '..'));
            $now = time();
            $deleted_count = 0;

            foreach ($files as $file) {
                $file_path = "$log_dir/$file";

                // Skip the current log file and protection files
                if ($file === $this->current_log_filename || $file === '.htaccess' || $file === 'index.php') {
                    continue;
                }

                if ($this->deleteFile($file_path, $now)) {
                    $deleted_count++;
                }
            }
            // Log the rotation if any files were deleted
            if ($deleted_count > 0) {
                $this->logMessage("LOG ROTATION - Deleted $deleted_count old log files");
            }
        } catch (Exception $e) {
            // Log error but don't fail the process
            error_log("Simple Email Service: Log rotation failed: " . $e->getMessage());
        }
    }

    /**
     * Deletes a file if it's older than the specified time.
     *
     * @param string $path The path to the file.
     * @param int $now The current timestamp.
     * @return bool True if deleted, false otherwise.
     */
    private function deleteFile(string $path, int $now) //: bool
    {
        if (file_exists($path)) {
            if ($now - filemtime($path) >= 604800) { // 7 days in seconds
                unlink($path);
                return true;
            }
        }
        return false;
    }

    /**
     * Cleans up scheduled tasks when the plugin is deactivated.
     * This method should be called from the main plugin file's deactivation hook.
     */
    public static function cleanupScheduledTasks(): void
    {
        $timestamp = wp_next_scheduled('ses_log_rotation');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ses_log_rotation');
        }
        wp_clear_scheduled_hook('ses_log_rotation');

        // Clean up log-related options
        delete_option('ses_by_hosting_nl_log_date');
        delete_option('ses_by_hosting_nl_log_random_string');
    }

    /**
     * Gets information about log rotation status.
     *
     * @return array Log rotation information.
     */
    private function getLogRotationInfo(): array
    {
        $log_dir = dirname($this->log_file_path);
        $info = [
            'next_rotation' => wp_next_scheduled('ses_log_rotation'),
            'log_dir' => $log_dir,
            'log_file_size' => file_exists($this->log_file_path) ? filesize($this->log_file_path) : 0,
            'log_file_age' => file_exists($this->log_file_path) ? time() - filemtime($this->log_file_path) : 0
        ];

        // Get count of log files
        if (is_dir($log_dir)) {
            $files = array_diff(scandir($log_dir), array('.', '..'));
            $info['total_files'] = count($files);
        } else {
            $info['total_files'] = 0;
        }

        return $info;
    }
}

