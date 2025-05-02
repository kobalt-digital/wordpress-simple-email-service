# Simple Email Service

Send WordPress emails through the [Simple Email Service API](https://api.simplemailservice.eu/). This plugin integrates with the [Simple Email Service](https://hosting.nl/products/simple-email-service/) by Hosting.NL, a Dutch email service provider that offers a scalable, privacy-friendly solution for sending transactional emails. The service supports both SMTP and REST API, ensuring reliable and efficient email delivery.

## Features

- Seamlessly routes all WordPress emails through the Simple Email Service API.
- Easy configuration of API key, sender email, and sender name via the WordPress admin.
- Test email functionality from the admin and WP-CLI.
- Error logging for failed emails.
- Internationalization (i18n) ready with a `.pot` file included.
- GPL v2 or later licensed.

## Installation

1. Upload the plugin files to the `/wp-content/plugins/simple-email-service` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to **Settings > Simple Email Service** to configure the plugin.

## Configuration

- **API Key**: Enter your Simple Email Service API key.
- **From Email Address**: Set the sender email address (should match your site domain for best deliverability).
- **From Name**: Set the sender name.

## Sending Test Emails

### From the Admin

- Go to **Settings > Simple Email Service**.
- Use the "Send Test Email" form to verify your configuration.

### Using WP-CLI

You can send a test email via WP-CLI:

```sh
wp ses test --to=you@example.com
```

If the `--to` flag is omitted, the admin email will be used.

## Internationalization

- Translation template is available in `languages/simple-email-service.pot`.
- Text domain: `simple-email-service`.

## Troubleshooting

- Check the WordPress error log for any issues with sending emails.
- Ensure your "From Email Address" domain matches your site domain to avoid deliverability issues.

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for details.

## Author

[Arne van Hoorn - Kobalt Digital](https://kobaltdigital.nl)