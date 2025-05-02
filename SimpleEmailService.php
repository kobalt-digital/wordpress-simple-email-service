<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Simple_Email_Service
{
    private string $api_key;
    private string $api_url = 'https://api.simplemailservice.eu/v1/email/send';
    private const TEXT_DOMAIN = 'simple-email-service';
    private string $plugin_basename;

    public function __construct($plugin_file = null)
    {
        $this->api_key = get_option('ses_api_key', '');
        $this->plugin_basename = $plugin_file ? plugin_basename($plugin_file) : plugin_basename(__FILE__);

        // Hook into WordPress email system
        add_action('phpmailer_init', [$this, 'disable_phpmailer']);
        add_action('wp_mail_failed', [$this, 'handle_wp_mail_failed']);
        add_filter('pre_wp_mail', [$this, 'send_email'], 10, 2);

        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Plugin action links
        $this->add_plugin_action_links_filter();

        // WP-CLI command
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('ses test', [$this, 'wp_cli_test_email']);
        }
    }

    public function disable_phpmailer($phpmailer)
    {
        // Disable default PHPMailer
        $phpmailer->Mailer = 'smtp';
        $phpmailer->Host = 'localhost';
        $phpmailer->SMTPAuth = false;
        $phpmailer->Port = 1025;
    }

    public function send_email($pre_wp_mail, $atts)
    {
        if (empty($this->api_key)) {
            return false;
        }

        $to = $atts['to'];
        $subject = $atts['subject'];
        $message = $atts['message'];
        $headers = $atts['headers'];

        // Parse headers
        $from_email = get_option('ses_from_email', get_option('admin_email'));
        $from_name = get_option('ses_from_name', get_bloginfo('name'));

        if (!empty($headers) && is_string($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }

        if (!empty($headers) && is_array($headers)) {
            foreach ($headers as $header) {
                if (strpos($header, 'From:') !== 0) {
                    continue;
                }
                $from = str_replace('From:', '', $header);
                $from = trim($from);

                // Extract name and email
                if (preg_match('/(.*?)<(.+)>/', $from, $matches)) {
                    $from_name = trim($matches[1]);
                    $from_email = trim($matches[2]);
                    break;
                }
                $from_email = $from;
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
                'name' => $from_name,
                'email' => $from_email
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
                error_log(__('Simple Email Service: Failed to send email', self::TEXT_DOMAIN));
                return false;
            }

            return true;
        } catch (Exception $e) {
            error_log(sprintf(__('Simple Email Service: %s', self::TEXT_DOMAIN), $e->getMessage()));
            return false;
        }
    }

    public function handle_wp_mail_failed($error)
    {
        error_log(sprintf(__('Simple Email Service: Mail error: %s', self::TEXT_DOMAIN), $error->get_error_message()));
    }

    public function add_admin_menu()
    {
        add_options_page(
            __('Simple Email Service Settings', self::TEXT_DOMAIN),
            __('Simple Email Service', self::TEXT_DOMAIN),
            'manage_options',
            'simple-email-service',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings()
    {
        register_setting('simple_email_service', 'ses_api_key');
        register_setting('simple_email_service', 'ses_from_email');
        register_setting('simple_email_service', 'ses_from_name');
    }

    public function render_settings_page()
    {
        if (isset($_POST['ses_test_email'])) {
            $this->send_test_email();
        }
        $from_email = get_option('ses_from_email', get_option('admin_email'));
        $show_domain_warning = !$this->is_from_email_domain_valid($from_email);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors('simple_email_service'); ?>

            <?php if ($show_domain_warning): ?>
                <div class="notice notice-warning">
                    <p><?php _e('No mails can be sent if the domain of the "From Email Address" is different from the site domain.', self::TEXT_DOMAIN); ?></p>
                </div>
            <?php endif; ?>

            <!-- Test Email Form -->
            <form method="post" action="">
                <h2><?php _e('Send Test Email', self::TEXT_DOMAIN); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="test_email"><?php _e('Test Email Address', self::TEXT_DOMAIN); ?></label>
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
                            <label for="ses_api_key"><?php _e('API Key', self::TEXT_DOMAIN); ?></label>
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
                            <label for="ses_from_email"><?php _e('From Email Address', self::TEXT_DOMAIN); ?></label>
                        </th>
                        <td>
                            <input type="email"
                                   id="ses_from_email"
                                   name="ses_from_email"
                                   value="<?php echo esc_attr(get_option('ses_from_email', get_option('admin_email'))); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ses_from_name"><?php _e('From Name', self::TEXT_DOMAIN); ?></label>
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

    private function send_test_email()
    {
        $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : get_option('admin_email');

        $to = $test_email;
        $subject = 'Test Email from Simple Email Service';
        $message = '<h1>This is a test email</h1><p>If you receive this, the Simple Email Service plugin is working correctly!</p>';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $result = wp_mail($to, $subject, $message, $headers);

        if ($result) {
            add_settings_error(
                'simple_email_service',
                'ses_test_success',
                __('Test email sent successfully! Check your inbox.', self::TEXT_DOMAIN),
                'success'
            );
        } else {
            add_settings_error(
                'simple_email_service',
                'ses_test_error',
                __('Failed to send test email. Check error logs.', self::TEXT_DOMAIN),
                'error'
            );
        }
    }

    /**
     * WP-CLI command to send a test email
     *
     * @param array $args Positional arguments
     * @param array $assoc_args Associative arguments
     */
    public function wp_cli_test_email($args, $assoc_args)
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

    private function is_from_email_domain_valid($from_email): bool
    {
        $site_url = get_site_url();
        $site_domain = preg_replace('/^www\./', '', parse_url($site_url, PHP_URL_HOST));
        $from_domain = substr(strrchr($from_email, '@'), 1);
        $from_domain = preg_replace('/^www\./', '', $from_domain);
        $from_domain = strtolower($from_domain);
        $site_domain = strtolower($site_domain);
        return $from_domain === $site_domain;
    }

    private function add_plugin_action_links_filter(): void
    {
        add_filter(
            'plugin_action_links_' . $this->plugin_basename,
            function ($links) {
                $settings_link = '<a href="options-general.php?page=simple-email-service">' . __('Settings', self::TEXT_DOMAIN) . '</a>';
                array_unshift($links, $settings_link);
                return $links;
            }
        );
    }
}