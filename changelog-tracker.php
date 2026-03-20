<?php
/*
Plugin Name: Changelog Tracker
Plugin URI: https://fahmidsroadmap.com/changelog-tracker/
Description: AI-powered changelog tracking and summarization with multi-provider support.
Version: 2.0
Author: Fahmid Hasan
Author URI: https://fahmidsroadmap.com/
Text Domain: changelog-tracker
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AICS_VERSION', '2.0' );
define( 'AICS_PATH', plugin_dir_path( __FILE__ ) );
define( 'AICS_URL', plugin_dir_url( __FILE__ ) );

require_once AICS_PATH . 'includes/class-content-extractor.php';
require_once AICS_PATH . 'includes/class-ai-providers.php';
require_once AICS_PATH . 'includes/class-email-template.php';
require_once AICS_PATH . 'includes/class-auto-detect.php';

class AIChangelogSummary {

	private $default_url_count   = 4;
	private $max_url_count       = 5;
	private $general_group       = 'aics-general-settings';
	private $notifications_group = 'aics-notifications-settings';

	/* ───────────────────────── Logging ───────────────────────── */

	private function log_error( $message, $data = [] ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'AICS Error: ' . $message );
			if ( ! empty( $data ) ) {
				error_log( 'AICS Data: ' . print_r( $data, true ) );
			}
		}
	}

	/* ───────────────────────── Constructor ───────────────────── */

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_plugin_page' ] );
		add_action( 'admin_init', [ $this, 'init_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widget' ] );

		add_action( 'wp_ajax_preview_fetch_changelog', [ $this, 'handle_changelog_fetch' ] );
		add_action( 'wp_ajax_test_wp_mail', [ $this, 'test_wp_mail' ] );
		add_action( 'wp_ajax_aics_force_fetch', [ $this, 'handle_force_fetch' ] );

		add_action( 'phpmailer_init', [ $this, 'configure_smtp' ] );

		add_filter( 'cron_schedules', [ $this, 'add_custom_schedules' ] );
		$this->maybe_schedule_cron();
		add_action( 'aics_changelog_email', [ $this, 'send_changelog_email' ] );

		add_action( 'init', [ $this, 'clear_old_changelog_summaries' ] );

		add_action( 'update_option_aics_email_frequency', [ $this, 'reschedule_cron' ] );
		add_action( 'update_option_aics_email_day', [ $this, 'reschedule_cron' ] );
		add_action( 'update_option_aics_email_time', [ $this, 'reschedule_cron' ] );
	}

	/* ───────────────────────── Cron Schedules ───────────────── */

	public function add_custom_schedules( $schedules ) {
		$schedules['weekly'] = [
			'interval' => 7 * DAY_IN_SECONDS,
			'display'  => esc_html__( 'Once Weekly', 'changelog-tracker' ),
		];
		$schedules['biweekly'] = [
			'interval' => 14 * DAY_IN_SECONDS,
			'display'  => esc_html__( 'Every Two Weeks', 'changelog-tracker' ),
		];
		$schedules['monthly'] = [
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => esc_html__( 'Once Monthly', 'changelog-tracker' ),
		];
		return apply_filters( 'aics_cron_schedules', $schedules );
	}

	private function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( 'aics_changelog_email' ) ) {
			wp_schedule_event(
				$this->get_next_scheduled_time(),
				$this->get_frequency(),
				'aics_changelog_email'
			);
		}
	}

	public function reschedule_cron() {
		wp_clear_scheduled_hook( 'aics_changelog_email' );
		wp_schedule_event(
			$this->get_next_scheduled_time(),
			$this->get_frequency(),
			'aics_changelog_email'
		);
	}

	private function get_frequency() {
		return get_option( 'aics_email_frequency', 'weekly' );
	}

	private function get_next_scheduled_time() {
		$day  = get_option( 'aics_email_day' );
		$hour = (int) get_option( 'aics_email_time', 8 );
		$freq = $this->get_frequency();

		$valid_days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
		if ( empty( $day ) || ! in_array( $day, $valid_days, true ) ) {
			$day = 'monday';
		}

		$tz   = wp_timezone();
		$now  = new DateTime( 'now', $tz );

		if ( $freq === 'daily' ) {
			$next = new DateTime( 'today', $tz );
			$next->setTime( $hour, 0 );
			if ( $next <= $now ) {
				$next->modify( '+1 day' );
			}
		} else {
			$next = new DateTime( 'next ' . $day, $tz );
			$next->setTime( $hour, 0 );
		}

		return $next->getTimestamp();
	}

	/* ───────────────────────── Admin Menu ────────────────────── */

	public function add_plugin_page() {
		add_options_page(
			__( 'Changelog Tracker', 'changelog-tracker' ),
			__( 'Changelog Tracker', 'changelog-tracker' ),
			'manage_options',
			'changelog-tracker',
			[ $this, 'render_settings_page' ]
		);
	}

	/* ───────────────────────── Settings Registration ─────────── */

	public function init_settings() {
		/* ── General group ── */
		register_setting( $this->general_group, 'changelog_urls', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_changelog_urls' ],
		] );
		register_setting( $this->general_group, 'aics_ai_provider', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'gemini',
		] );
		register_setting( $this->general_group, 'gemini_api_key', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( $this->general_group, 'openai_api_key', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( $this->general_group, 'claude_api_key', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( $this->general_group, 'aics_max_tokens', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 2048,
		] );

		/* ── Notifications group ── */
		register_setting( $this->notifications_group, 'notification_email', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
		] );
		register_setting( $this->notifications_group, 'aics_email_from_name', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( $this->notifications_group, 'aics_email_from_address', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
		] );
		register_setting( $this->notifications_group, 'aics_email_frequency', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'weekly',
		] );
		register_setting( $this->notifications_group, 'aics_email_day', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'monday',
		] );
		register_setting( $this->notifications_group, 'aics_email_time', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 8,
		] );

		/* ── SMTP settings ── */
		register_setting( $this->notifications_group, 'aics_smtp_enabled', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		] );
		register_setting( $this->notifications_group, 'aics_smtp_host', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( $this->notifications_group, 'aics_smtp_port', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 587,
		] );
		register_setting( $this->notifications_group, 'aics_smtp_encryption', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'tls',
		] );
		register_setting( $this->notifications_group, 'aics_smtp_username', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( $this->notifications_group, 'aics_smtp_password', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		/* ── Sections ── */
		add_settings_section(
			'aics_general_section',
			esc_html__( 'AI & Changelog Sources', 'changelog-tracker' ),
			function () {
				echo '<p>' . esc_html__( 'Configure your AI provider and the changelog pages to monitor.', 'changelog-tracker' ) . '</p>';
			},
			'aics-general'
		);

		add_settings_section(
			'aics_notifications_section',
			esc_html__( 'Email Settings', 'changelog-tracker' ),
			function () {
				echo '<p>' . esc_html__( 'Set up notification email, sender details, and delivery schedule.', 'changelog-tracker' ) . '</p>';
			},
			'aics-notifications'
		);

		add_settings_section(
			'aics_smtp_section',
			esc_html__( 'SMTP Configuration', 'changelog-tracker' ),
			function () {
				echo '<p>' . esc_html__( 'Configure a custom SMTP server to ensure emails are delivered to real inboxes. Required on local environments or when WordPress default mail fails.', 'changelog-tracker' ) . '</p>';
			},
			'aics-notifications'
		);

		$this->add_settings_fields();
	}

	private function add_settings_fields() {
		/* ── General fields ── */
		add_settings_field( 'aics_ai_provider', esc_html__( 'AI Provider', 'changelog-tracker' ), [ $this, 'render_provider_field' ], 'aics-general', 'aics_general_section' );
		add_settings_field( 'aics_api_keys', esc_html__( 'API Key', 'changelog-tracker' ), [ $this, 'render_api_key_fields' ], 'aics-general', 'aics_general_section' );
		add_settings_field( 'aics_max_tokens', esc_html__( 'Max Output Tokens', 'changelog-tracker' ), [ $this, 'render_max_tokens_field' ], 'aics-general', 'aics_general_section' );
		add_settings_field( 'changelog_urls', esc_html__( 'Changelog URLs', 'changelog-tracker' ), [ $this, 'render_urls_field' ], 'aics-general', 'aics_general_section' );

		/* ── Notification fields ── */
		add_settings_field( 'notification_email', esc_html__( 'Notification Email', 'changelog-tracker' ), [ $this, 'render_email_field' ], 'aics-notifications', 'aics_notifications_section' );
		add_settings_field( 'aics_email_from_name', esc_html__( 'From Name', 'changelog-tracker' ), [ $this, 'render_from_name_field' ], 'aics-notifications', 'aics_notifications_section' );
		add_settings_field( 'aics_email_from_address', esc_html__( 'From Email', 'changelog-tracker' ), [ $this, 'render_from_address_field' ], 'aics-notifications', 'aics_notifications_section' );
		add_settings_field( 'aics_frequency', esc_html__( 'Email Frequency', 'changelog-tracker' ), [ $this, 'render_frequency_field' ], 'aics-notifications', 'aics_notifications_section' );
		add_settings_field( 'aics_day', esc_html__( 'Send Day', 'changelog-tracker' ), [ $this, 'render_day_field' ], 'aics-notifications', 'aics_notifications_section' );
		add_settings_field( 'aics_time', esc_html__( 'Send Time', 'changelog-tracker' ), [ $this, 'render_time_field' ], 'aics-notifications', 'aics_notifications_section' );

		/* ── SMTP fields ── */
		add_settings_field( 'aics_smtp_enabled', esc_html__( 'Enable SMTP', 'changelog-tracker' ), [ $this, 'render_smtp_enabled_field' ], 'aics-notifications', 'aics_smtp_section' );
		add_settings_field( 'aics_smtp_host', esc_html__( 'SMTP Host', 'changelog-tracker' ), [ $this, 'render_smtp_host_field' ], 'aics-notifications', 'aics_smtp_section' );
		add_settings_field( 'aics_smtp_port', esc_html__( 'SMTP Port', 'changelog-tracker' ), [ $this, 'render_smtp_port_field' ], 'aics-notifications', 'aics_smtp_section' );
		add_settings_field( 'aics_smtp_encryption', esc_html__( 'Encryption', 'changelog-tracker' ), [ $this, 'render_smtp_encryption_field' ], 'aics-notifications', 'aics_smtp_section' );
		add_settings_field( 'aics_smtp_username', esc_html__( 'Username', 'changelog-tracker' ), [ $this, 'render_smtp_username_field' ], 'aics-notifications', 'aics_smtp_section' );
		add_settings_field( 'aics_smtp_password', esc_html__( 'Password', 'changelog-tracker' ), [ $this, 'render_smtp_password_field' ], 'aics-notifications', 'aics_smtp_section' );
	}

	/* ───────────────────────── Field Renderers ───────────────── */

	public function render_provider_field() {
		$current   = get_option( 'aics_ai_provider', 'gemini' );
		$providers = AICS_AI_Providers::get_providers();
		?>
		<select name="aics_ai_provider" id="aics-ai-provider">
			<?php foreach ( $providers as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_api_key_fields() {
		$current = get_option( 'aics_ai_provider', 'gemini' );
		$keys    = [
			'gemini' => [ 'option' => 'gemini_api_key', 'label' => __( 'Gemini API Key', 'changelog-tracker' ), 'link' => 'https://aistudio.google.com/app/apikey' ],
			'openai' => [ 'option' => 'openai_api_key', 'label' => __( 'OpenAI API Key', 'changelog-tracker' ), 'link' => 'https://platform.openai.com/api-keys' ],
			'claude' => [ 'option' => 'claude_api_key', 'label' => __( 'Claude API Key', 'changelog-tracker' ), 'link' => 'https://console.anthropic.com/settings/keys' ],
		];
		foreach ( $keys as $provider => $meta ) :
			$value   = get_option( $meta['option'], '' );
			$display = ( $provider === $current ) ? 'flex' : 'none';
			?>
			<div class="aics-api-key-row" data-provider="<?php echo esc_attr( $provider ); ?>" style="display:<?php echo esc_attr( $display ); ?>;">
				<input
					type="password"
					name="<?php echo esc_attr( $meta['option'] ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					class="regular-text"
					placeholder="<?php echo esc_attr( $meta['label'] ); ?>"
				>
				<a href="<?php echo esc_url( $meta['link'] ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Get key', 'changelog-tracker' ); ?> &rarr;
				</a>
			</div>
		<?php endforeach;
	}

	public function render_max_tokens_field() {
		$value = (int) get_option( 'aics_max_tokens', 2048 );
		if ( $value < 512 ) {
			$value = 2048;
		}
		?>
		<input type="number" name="aics_max_tokens" value="<?php echo esc_attr( $value ); ?>" min="512" max="8192" step="256" class="regular-text" style="max-width:200px;">
		<p class="description"><?php esc_html_e( 'Maximum tokens for AI response. Increase if summaries are getting cut off. Default: 2048.', 'changelog-tracker' ); ?></p>
		<?php
	}

	public function render_urls_field() {
		$urls = array_pad( array_slice( (array) get_option( 'changelog_urls', [] ), 0, $this->max_url_count ), $this->default_url_count, '' );
		?>
		<div id="changelog-urls-container">
			<?php for ( $i = 0; $i < $this->default_url_count; $i++ ) : ?>
				<div class="aics-url-row">
					<input
						type="url"
						name="changelog_urls[<?php echo esc_attr( $i ); ?>]"
						value="<?php echo esc_attr( $urls[ $i ] ); ?>"
						class="regular-text"
						<?php /* translators: %d: URL number */ ?>
						placeholder="<?php echo esc_attr( sprintf( __( 'Changelog URL #%d', 'changelog-tracker' ), $i + 1 ) ); ?>"
					>
				</div>
			<?php endfor; ?>
		</div>
		<div style="display:flex;align-items:center;gap:8px;margin-top:8px;">
			<input type="url" id="aics-detect-domain" class="regular-text" placeholder="<?php esc_attr_e( 'Enter domain to auto-detect changelog URL', 'changelog-tracker' ); ?>" style="max-width:300px;">
			<button type="button" id="aics-detect-url" class="button button-secondary">
				<?php esc_html_e( 'Auto Detect', 'changelog-tracker' ); ?>
			</button>
			<span id="aics-detect-result" style="font-size:13px;"></span>
		</div>
		<p class="description"><?php esc_html_e( 'Enter up to 4 changelog URLs, or use Auto Detect to find them automatically.', 'changelog-tracker' ); ?></p>
		<?php
	}

	public function render_email_field() {
		$email = get_option( 'notification_email', get_option( 'admin_email' ) );
		?>
		<input type="email" name="notification_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Email for notifications', 'changelog-tracker' ); ?>">
		<p class="description"><?php esc_html_e( 'The email address that receives changelog notifications.', 'changelog-tracker' ); ?></p>
		<?php
	}

	public function render_from_name_field() {
		$value = get_option( 'aics_email_from_name', '' );
		?>
		<input type="text" name="aics_email_from_name" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<p class="description"><?php esc_html_e( 'Sender name for emails. Leave empty to use your site name.', 'changelog-tracker' ); ?></p>
		<?php
	}

	public function render_from_address_field() {
		$value = get_option( 'aics_email_from_address', '' );
		?>
		<input type="email" name="aics_email_from_address" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
		<p class="description"><?php esc_html_e( 'Sender email address. Leave empty to use WordPress default.', 'changelog-tracker' ); ?></p>
		<?php
	}

	public function render_smtp_enabled_field() {
		$enabled = (int) get_option( 'aics_smtp_enabled', 0 );
		?>
		<label>
			<input type="checkbox" name="aics_smtp_enabled" id="aics-smtp-enabled" value="1" <?php checked( 1, $enabled ); ?>>
			<?php esc_html_e( 'Use custom SMTP server for sending emails', 'changelog-tracker' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Enable this if emails are not arriving (e.g. on local environments). WordPress default mail uses PHP mail() which is often blocked.', 'changelog-tracker' ); ?></p>
		<?php
	}

	public function render_smtp_host_field() {
		$value = get_option( 'aics_smtp_host', '' );
		?>
		<input type="text" name="aics_smtp_host" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="smtp.gmail.com">
		<p class="description"><?php esc_html_e( 'Your SMTP server hostname. e.g. smtp.gmail.com, smtp.mailgun.org, smtp.sendgrid.net', 'changelog-tracker' ); ?></p>
		<?php
	}

	public function render_smtp_port_field() {
		$value = (int) get_option( 'aics_smtp_port', 587 );
		?>
		<input type="number" name="aics_smtp_port" value="<?php echo esc_attr( $value ); ?>" min="1" max="65535" class="small-text">
		<p class="description"><?php esc_html_e( 'Common ports: 587 (TLS), 465 (SSL), 25 (none).', 'changelog-tracker' ); ?></p>
		<?php
	}

	public function render_smtp_encryption_field() {
		$current = get_option( 'aics_smtp_encryption', 'tls' );
		$options = [
			'tls'  => __( 'TLS (recommended)', 'changelog-tracker' ),
			'ssl'  => __( 'SSL', 'changelog-tracker' ),
			'none' => __( 'None', 'changelog-tracker' ),
		];
		?>
		<select name="aics_smtp_encryption">
			<?php foreach ( $options as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current, $val ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_smtp_username_field() {
		$value = get_option( 'aics_smtp_username', '' );
		?>
		<input type="text" name="aics_smtp_username" value="<?php echo esc_attr( $value ); ?>" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'your@email.com', 'changelog-tracker' ); ?>">
		<?php
	}

	public function render_smtp_password_field() {
		$value = get_option( 'aics_smtp_password', '' );
		?>
		<input type="password" name="aics_smtp_password" value="<?php echo esc_attr( $value ); ?>" class="regular-text" autocomplete="new-password">
		<p class="description"><?php esc_html_e( 'For Gmail, use an App Password (not your account password). Two-factor authentication must be enabled.', 'changelog-tracker' ); ?></p>
		<?php
	}

	public function render_frequency_field() {
		$current = get_option( 'aics_email_frequency', 'weekly' );
		$options = apply_filters( 'aics_frequency_options', [
			'weekly'   => __( 'Weekly', 'changelog-tracker' ),
			'biweekly' => __( 'Every Two Weeks', 'changelog-tracker' ),
			'monthly'  => __( 'Monthly', 'changelog-tracker' ),
		] );
		?>
		<select name="aics_email_frequency" id="aics-frequency">
			<?php foreach ( $options as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current, $val ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_day_field() {
		$current = get_option( 'aics_email_day', 'monday' );
		$days    = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
		?>
		<select name="aics_email_day" id="aics-day">
			<?php foreach ( $days as $d ) : ?>
				<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $current, $d ); ?>><?php echo esc_html( ucfirst( $d ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description aics-day-note"><?php esc_html_e( 'Day of the week to send the email.', 'changelog-tracker' ); ?></p>
		<?php
	}

	public function render_time_field() {
		$current = (int) get_option( 'aics_email_time', 8 );
		?>
		<select name="aics_email_time" id="aics-time">
			<?php for ( $h = 0; $h < 24; $h++ ) : ?>
				<option value="<?php echo esc_attr( $h ); ?>" <?php selected( $current, $h ); ?>>
					<?php echo esc_html( sprintf( '%02d:00', $h ) ); ?>
					(<?php echo esc_html( wp_date( 'g A', strtotime( $h . ':00' ) ) ); ?>)
				</option>
			<?php endfor; ?>
		</select>
		<?php
		/* translators: %s: WordPress timezone string */
		$tz_note = sprintf( __( 'Time in your WordPress timezone (%s).', 'changelog-tracker' ), wp_timezone_string() );
		?>
		<p class="description"><?php echo esc_html( $tz_note ); ?></p>
		<?php
	}

	/* ───────────────────────── Sanitizers ─────────────────────── */

	public function sanitize_changelog_urls( $input ) {
		$sanitized = [];
		foreach ( (array) $input as $url ) {
			$url = esc_url_raw( trim( $url ) );
			if ( ! empty( $url ) ) {
				$sanitized[] = $url;
			}
		}
		return array_slice( $sanitized, 0, $this->max_url_count );
	}

	/* ───────────────────────── Settings Page ──────────────────── */

	public function render_settings_page() {
		$next_run = wp_next_scheduled( 'aics_changelog_email' );
		$freq     = get_option( 'aics_email_frequency', 'weekly' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<nav class="aics-tabs" role="tablist">
				<a class="aics-tab active" data-tab="general" href="#general" role="tab" aria-selected="true"><?php esc_html_e( 'General', 'changelog-tracker' ); ?></a>
				<a class="aics-tab" data-tab="notifications" href="#notifications" role="tab" aria-selected="false"><?php esc_html_e( 'Notifications', 'changelog-tracker' ); ?></a>
				<a class="aics-tab" data-tab="help" href="#help" role="tab" aria-selected="false"><?php esc_html_e( 'Help', 'changelog-tracker' ); ?></a>
			</nav>

			<!-- ============ General Tab ============ -->
			<div class="aics-tab-content active" id="aics-tab-general" role="tabpanel">
				<form method="post" action="options.php">
					<?php
					settings_fields( $this->general_group );
					do_settings_sections( 'aics-general' );
					submit_button( esc_html__( 'Save Settings', 'changelog-tracker' ) );
					?>
				</form>

				<div class="aics-card">
					<h2><?php esc_html_e( 'Actions', 'changelog-tracker' ); ?></h2>
					<div style="margin-top:12px;">
						<div class="aics-actions-group">
							<button id="preview-changelog" class="button button-primary"><?php esc_html_e( 'Preview Changelog', 'changelog-tracker' ); ?></button>
							<button id="preview-fresh" class="button"><?php esc_html_e( 'Fetch Latest', 'changelog-tracker' ); ?></button>
						</div>
						<p class="description">
							<?php
							printf(
								/* translators: %s: "Fetch Latest" button label */
								esc_html__( 'Preview uses cached data (30 min). %s bypasses cache.', 'changelog-tracker' ),
								'<strong>' . esc_html__( 'Fetch Latest', 'changelog-tracker' ) . '</strong>'
							);
							?>
						</p>
					</div>
					<div style="margin-top:16px;">
						<div class="aics-actions-group">
							<button id="force-fetch" class="button button-primary"><?php esc_html_e( 'Fetch & Email Now', 'changelog-tracker' ); ?></button>
							<label>
								<input type="checkbox" id="force-fetch-ignore-diff" value="1">
								<?php esc_html_e( 'Send even if no changes detected', 'changelog-tracker' ); ?>
							</label>
						</div>
						<span id="force-fetch-result" style="margin-left:10px;"></span>
						<p class="description">
							<?php esc_html_e( 'Force-fetch all changelogs and send email immediately.', 'changelog-tracker' ); ?>
							<strong><?php esc_html_e( 'This will not affect your scheduled emails', 'changelog-tracker' ); ?></strong> — <?php esc_html_e( 'it is for testing only and does not update the change tracking used by the scheduler.', 'changelog-tracker' ); ?>
						</p>
					</div>
					<div style="margin-top:16px;">
						<div class="aics-actions-group">
							<button id="test-wpmail" class="button"><?php esc_html_e( 'Test Email Delivery', 'changelog-tracker' ); ?></button>
							<span id="wpmail-test-result"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Sends a test email to your notification address to verify WordPress mail is working.', 'changelog-tracker' ); ?></p>
					</div>
				</div>

				<div class="aics-card">
					<h2><?php esc_html_e( 'Changelog Preview', 'changelog-tracker' ); ?></h2>
					<div id="changelog-preview"></div>
				</div>
			</div>

			<!-- ============ Notifications Tab ============ -->
			<div class="aics-tab-content" id="aics-tab-notifications" role="tabpanel">
				<form method="post" action="options.php">
					<?php settings_fields( $this->notifications_group ); ?>

					<div class="aics-card">
						<h2><?php esc_html_e( 'Email Settings', 'changelog-tracker' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="notification_email"><?php esc_html_e( 'To (Recipient Email)', 'changelog-tracker' ); ?></label></th>
								<td><?php $this->render_email_field(); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="aics_email_from_name"><?php esc_html_e( 'From Name', 'changelog-tracker' ); ?></label></th>
								<td><?php $this->render_from_name_field(); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="aics_email_from_address"><?php esc_html_e( 'From Email', 'changelog-tracker' ); ?></label></th>
								<td><?php $this->render_from_address_field(); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="aics-frequency"><?php esc_html_e( 'Email Frequency', 'changelog-tracker' ); ?></label></th>
								<td><?php $this->render_frequency_field(); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="aics-day"><?php esc_html_e( 'Send Day', 'changelog-tracker' ); ?></label></th>
								<td><?php $this->render_day_field(); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="aics-time"><?php esc_html_e( 'Send Time', 'changelog-tracker' ); ?></label></th>
								<td><?php $this->render_time_field(); ?></td>
							</tr>
						</table>
					</div>

					<div class="aics-card">
						<h2><?php esc_html_e( 'SMTP Configuration', 'changelog-tracker' ); ?></h2>
						<p><?php esc_html_e( 'Configure a custom SMTP server to ensure emails are delivered. Required on local environments or when WordPress default mail fails.', 'changelog-tracker' ); ?></p>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable SMTP', 'changelog-tracker' ); ?></th>
								<td><?php $this->render_smtp_enabled_field(); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="aics_smtp_host"><?php esc_html_e( 'SMTP Host', 'changelog-tracker' ); ?></label></th>
								<td><?php $this->render_smtp_host_field(); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="aics_smtp_port"><?php esc_html_e( 'SMTP Port', 'changelog-tracker' ); ?></label></th>
								<td><?php $this->render_smtp_port_field(); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="aics_smtp_encryption"><?php esc_html_e( 'Encryption', 'changelog-tracker' ); ?></label></th>
								<td><?php $this->render_smtp_encryption_field(); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="aics_smtp_username"><?php esc_html_e( 'Username', 'changelog-tracker' ); ?></label></th>
								<td><?php $this->render_smtp_username_field(); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="aics_smtp_password"><?php esc_html_e( 'Password', 'changelog-tracker' ); ?></label></th>
								<td><?php $this->render_smtp_password_field(); ?></td>
							</tr>
						</table>
					</div>

					<?php submit_button( esc_html__( 'Save Settings', 'changelog-tracker' ) ); ?>
				</form>

				<div class="aics-card">
					<h2><?php esc_html_e( 'Schedule', 'changelog-tracker' ); ?></h2>
					<p>
						<strong><?php esc_html_e( 'Frequency:', 'changelog-tracker' ); ?></strong> <?php echo esc_html( ucfirst( $freq ) ); ?><br>
						<strong><?php esc_html_e( 'Next email:', 'changelog-tracker' ); ?></strong>
						<?php
						if ( $next_run ) {
							echo esc_html( wp_date( 'l, F j, Y \a\t g:i A', $next_run ) );
						} else {
							esc_html_e( 'Not scheduled', 'changelog-tracker' );
						}
						?>
					</p>
				</div>
			</div>

			<!-- ============ Help Tab ============ -->
			<div class="aics-tab-content" id="aics-tab-help" role="tabpanel">
				<div class="aics-card aics-help-section">
					<h2><?php esc_html_e( 'Documentation', 'changelog-tracker' ); ?></h2>
					<h4><?php esc_html_e( 'Setup Instructions', 'changelog-tracker' ); ?></h4>
					<ol>
						<li><?php esc_html_e( 'Select your AI provider and enter the API key.', 'changelog-tracker' ); ?></li>
						<li><?php esc_html_e( 'Enter the changelog URLs you want to monitor.', 'changelog-tracker' ); ?></li>
						<li><?php esc_html_e( 'Configure the notification email address.', 'changelog-tracker' ); ?></li>
						<li><?php esc_html_e( 'Set your preferred email schedule.', 'changelog-tracker' ); ?></li>
						<li><?php esc_html_e( 'Use the test buttons to verify your setup.', 'changelog-tracker' ); ?></li>
					</ol>
					<h4><?php esc_html_e( 'Change Detection', 'changelog-tracker' ); ?></h4>
					<p><?php esc_html_e( 'The plugin stores a fingerprint of each changelog. Scheduled emails are only sent when changes are detected. Use "Fetch & Email Now" to force an email regardless.', 'changelog-tracker' ); ?></p>
				</div>

				<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
				<div class="aics-card">
					<h2><?php esc_html_e( 'Debug Information', 'changelog-tracker' ); ?></h2>
					<pre class="aics-debug-pre">
<?php echo esc_html( 'PHP Version: ' . PHP_VERSION ); ?>

<?php echo esc_html( 'WordPress Version: ' . get_bloginfo( 'version' ) ); ?>

<?php echo esc_html( 'Plugin Version: ' . AICS_VERSION ); ?>

<?php echo esc_html( 'Timezone: ' . wp_timezone_string() ); ?>

<?php echo esc_html( 'Provider: ' . get_option( 'aics_ai_provider', 'gemini' ) ); ?>

<?php echo esc_html( 'Next Cron: ' . ( $next_run ? wp_date( 'Y-m-d H:i:s', $next_run ) : 'None' ) ); ?>
					</pre>
				</div>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}

	/* ───────────────────────── Assets ─────────────────────────── */

	public function enqueue_scripts( $hook ) {
		if ( 'settings_page_changelog-tracker' === $hook ) {
			wp_enqueue_style( 'aics-admin', AICS_URL . 'css/admin.css', [], AICS_VERSION );
			wp_enqueue_script( 'aics-admin', AICS_URL . 'js/changelog-script.js', [ 'jquery' ], AICS_VERSION, true );
			wp_localize_script( 'aics-admin', 'AICS', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'aics_nonce' ),
			] );
		}

		if ( 'index.php' === $hook ) {
			wp_enqueue_style( 'aics-admin', AICS_URL . 'css/admin.css', [], AICS_VERSION );
			wp_enqueue_script( 'aics-dashboard', AICS_URL . 'js/changelog-script.js', [ 'jquery' ], AICS_VERSION, true );
			wp_localize_script( 'aics-dashboard', 'AICS', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'aics_nonce' ),
			] );
		}
	}

	/* ───────────────────────── Core: Process Changelog ────────── */

	/**
	 * Fetch, extract, hash, and summarize a changelog URL.
	 *
	 * @param string $url   URL to fetch.
	 * @param bool   $force Bypass cache.
	 * @return array
	 */
	/**
	 * @param bool $force      Skip 30-min cache and always fetch fresh.
	 * @param bool $store      Persist hash/summary after processing.
	 *                         Defaults to true only when not force (preview/test).
	 */
	public function process_changelog( $url, $force = false, $store = null ) {
		// Derive default: preview/force-fetch = don't store; scheduled = store.
		$should_store = is_null( $store ) ? ! $force : $store;

		$stored   = get_option( 'changelog_summaries', [] );
		$cached   = isset( $stored[ $url ] ) ? $stored[ $url ] : null;
		$provider = get_option( 'aics_ai_provider', 'gemini' );
		$api_key  = get_option( $provider . '_api_key', '' );

		if ( ! $force && $cached &&
			 ( current_time( 'timestamp' ) - $cached['timestamp'] ) < ( 30 * MINUTE_IN_SECONDS ) ) {
			return [
				'success'      => true,
				'content'      => 'Cached content',
				'ai_summary'   => $cached['summary'],
				'changed'      => false,
				'content_hash' => $cached['content_hash'] ?? '',
			];
		}

		$response = wp_remote_get( $url, [ 'timeout' => 30, 'sslverify' => false ] );
		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				/* translators: %s: error message */
				'message' => sprintf( __( 'Fetch failed: %s', 'changelog-tracker' ), $response->get_error_message() ),
			];
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return [ 'success' => false, 'message' => __( 'Empty response body.', 'changelog-tracker' ) ];
		}

		$content      = AICS_Content_Extractor::extract( $body, $url );
		$content_hash = md5( $content );

		$old_hash = $cached['content_hash'] ?? '';
		$changed  = ( $content_hash !== $old_hash );

		if ( ! $changed && ! $force && $cached ) {
			return [
				'success'      => true,
				'content'      => $content,
				'ai_summary'   => $cached['summary'],
				'changed'      => false,
				'content_hash' => $content_hash,
			];
		}

		$ai_result = AICS_AI_Providers::summarize( $content, $provider, $api_key );

		if ( ! $ai_result['success'] ) {
			return [
				'success' => false,
				'message' => $ai_result['error'] ?? __( 'AI summarization failed.', 'changelog-tracker' ),
			];
		}

		if ( $should_store ) {
			$this->store_changelog_summary( $url, $ai_result['summary'], $content_hash );
		}

		return [
			'success'      => true,
			'content'      => $content,
			'ai_summary'   => $ai_result['summary'],
			'changed'      => $changed,
			'content_hash' => $content_hash,
		];
	}

	private function store_changelog_summary( $url, $summary, $content_hash ) {
		$stored = get_option( 'changelog_summaries', [] );
		$stored[ $url ] = [
			'summary'      => $summary,
			'content_hash' => $content_hash,
			'timestamp'    => current_time( 'timestamp' ),
		];
		update_option( 'changelog_summaries', $stored );
	}

	/* ───────────────────────── AJAX: Preview ──────────────────── */

	public function handle_changelog_fetch() {
		check_ajax_referer( 'aics_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'changelog-tracker' ) ] );
		}

		$urls = get_option( 'changelog_urls', [] );
		if ( empty( $urls ) ) {
			wp_send_json_error( [ 'message' => __( 'No URLs configured.', 'changelog-tracker' ) ] );
		}

		$skip_cache = isset( $_POST['skip_cache'] ) ? absint( $_POST['skip_cache'] ) : 0;

		$results = [];
		foreach ( $urls as $url ) {
			if ( empty( $url ) ) {
				continue;
			}
			$result    = $this->process_changelog( $url, (bool) $skip_cache );
			$results[] = [
				'url'        => $url,
				'success'    => $result['success'],
				'message'    => $result['message'] ?? 'OK',
				'ai_summary' => $result['ai_summary'] ?? '',
				'changed'    => $result['changed'] ?? null,
			];
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	/* ───────────────────────── AJAX: Force Fetch & Email ──────── */

	public function handle_force_fetch() {
		check_ajax_referer( 'aics_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'changelog-tracker' ) ] );
		}

		$urls        = array_filter( get_option( 'changelog_urls', [] ) );
		$email       = get_option( 'notification_email', get_option( 'admin_email' ) );
		$ignore_diff = isset( $_POST['ignore_diff'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['ignore_diff'] ) );

		if ( empty( $urls ) ) {
			wp_send_json_error( [ 'message' => __( 'No URLs configured.', 'changelog-tracker' ) ] );
		}

		$summaries  = [];
		$error_urls = [];
		$unchanged  = [];

		foreach ( $urls as $url ) {
			if ( empty( $url ) ) {
				continue;
			}

			$result = $this->process_changelog( $url, true );

			if ( $result['success'] ) {
				if ( $result['changed'] ) {
					$summaries[] = [ 'url' => $url, 'summary' => $result['ai_summary'], 'changed' => true ];
				} else {
					$unchanged[] = $url;
				}
			} else {
				$error_urls[] = $url;
			}
		}

		$should_send = $ignore_diff
			? ( ! empty( $summaries ) || ! empty( $unchanged ) )
			: ! empty( $summaries );

		if ( $should_send ) {
			$email_summaries = $summaries;
			if ( $ignore_diff ) {
				$stored = get_option( 'changelog_summaries', [] );
				foreach ( $unchanged as $u ) {
					if ( isset( $stored[ $u ] ) ) {
						$email_summaries[] = [ 'url' => $u, 'summary' => $stored[ $u ]['summary'], 'changed' => false ];
					}
				}
				$unchanged = [];
			}

			$freq    = get_option( 'aics_email_frequency', 'weekly' );
			$subject = AICS_Email_Template::subject( $freq );
			$body    = AICS_Email_Template::render( $email_summaries, $error_urls, $unchanged );
			$headers = $this->get_email_headers();

			$recipients = is_array( $email ) ? $email : [ $email ];
			$sent       = false;
			foreach ( $recipients as $to ) {
				if ( wp_mail( trim( $to ), $subject, $body, $headers ) ) {
					$sent = true;
				}
			}

			$display_email = is_array( $email ) ? implode( ', ', $email ) : $email;
			wp_send_json_success( [
				/* translators: %s: email address(es) */
				'message'   => $sent ? sprintf( __( 'Email sent to %s', 'changelog-tracker' ), $display_email ) : __( 'Failed to send email.', 'changelog-tracker' ),
				'sent'      => $sent,
				'changed'   => count( $summaries ),
				'unchanged' => count( $unchanged ),
				'errors'    => count( $error_urls ),
			] );
		} else {
			wp_send_json_success( [
				'message'   => __( 'No changes detected. Email not sent.', 'changelog-tracker' ),
				'sent'      => false,
				'changed'   => 0,
				'unchanged' => count( $unchanged ),
				'errors'    => count( $error_urls ),
			] );
		}
	}

	/* ───────────────────────── SMTP ───────────────────────────── */

	public function configure_smtp( $phpmailer ) {
		if ( ! (int) get_option( 'aics_smtp_enabled', 0 ) ) {
			return;
		}

		$host       = get_option( 'aics_smtp_host', '' );
		$port       = (int) get_option( 'aics_smtp_port', 587 );
		$encryption = get_option( 'aics_smtp_encryption', 'tls' );
		$username   = get_option( 'aics_smtp_username', '' );
		$password   = get_option( 'aics_smtp_password', '' );

		if ( empty( $host ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = sanitize_text_field( $host );
		$phpmailer->Port       = $port;
		$phpmailer->SMTPAuth   = ! empty( $username );
		$phpmailer->Username   = sanitize_text_field( $username );
		$phpmailer->Password   = $password;

		if ( 'ssl' === $encryption ) {
			$phpmailer->SMTPSecure = 'ssl';
		} elseif ( 'tls' === $encryption ) {
			$phpmailer->SMTPSecure = 'tls';
		} else {
			$phpmailer->SMTPSecure = '';
			$phpmailer->SMTPAutoTLS = false;
		}
	}

	/* ───────────────────────── AJAX: Test Email ──────────────── */

	public function test_wp_mail() {
		check_ajax_referer( 'aics_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'changelog-tracker' ) ] );
		}

		$email   = get_option( 'notification_email', get_option( 'admin_email' ) );
		$subject = __( 'Test Email from AI Changelog Summary', 'changelog-tracker' );
		$message = '<p>' . __( 'This is a test email to verify your WordPress email configuration is working correctly.', 'changelog-tracker' ) . '</p>'
			. '<p>' . __( 'If you received this email, your setup is ready to send changelog notifications.', 'changelog-tracker' ) . '</p>';
		$headers = $this->get_email_headers();

		// Capture wp_mail errors.
		$mail_error = '';
		$error_handler = function ( $wp_error ) use ( &$mail_error ) {
			$mail_error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $error_handler );

		$sent = wp_mail( $email, $subject, $message, $headers );

		remove_action( 'wp_mail_failed', $error_handler );

		if ( $sent ) {
			/* translators: %s: email address */
			wp_send_json_success( [ 'message' => sprintf( __( 'Test email sent to %s', 'changelog-tracker' ), $email ) ] );
		} else {
			$error_msg = ! empty( $mail_error )
				? $mail_error
				: __( 'wp_mail() returned false. Check your WordPress email configuration or install an SMTP plugin.', 'changelog-tracker' );
			wp_send_json_error( [ 'message' => $error_msg ] );
		}
	}

	/**
	 * Build email headers with configured From name/address.
	 */
	private function get_email_headers() {
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$from_name    = get_option( 'aics_email_from_name', '' );
		$from_address = get_option( 'aics_email_from_address', '' );

		if ( ! empty( $from_name ) && ! empty( $from_address ) ) {
			$headers[] = 'From: ' . sanitize_text_field( $from_name ) . ' <' . sanitize_email( $from_address ) . '>';
		} elseif ( ! empty( $from_address ) ) {
			$headers[] = 'From: ' . sanitize_email( $from_address );
		} elseif ( ! empty( $from_name ) ) {
			$headers[] = 'From: ' . sanitize_text_field( $from_name ) . ' <' . get_option( 'admin_email' ) . '>';
		}

		return $headers;
	}

	/* ───────────────────────── Cron: Send Email ───────────────── */

	public function send_changelog_email() {
		$urls  = get_option( 'changelog_urls', [] );
		$email = get_option( 'notification_email', get_option( 'admin_email' ) );

		if ( empty( $urls ) || empty( $email ) ) {
			$this->log_error( 'Missing settings for scheduled email.' );
			return;
		}

		$summaries  = [];
		$error_urls = [];
		$unchanged  = [];

		foreach ( $urls as $url ) {
			if ( empty( $url ) ) {
				continue;
			}

			// force=true: always fetch fresh (bypass 30-min cache).
			// store=true: persist hash so next run detects real changes only.
			$result = $this->process_changelog( $url, true, true );

			if ( $result['success'] ) {
				if ( $result['changed'] ) {
					$summaries[] = [ 'url' => $url, 'summary' => $result['ai_summary'] ];
				} else {
					$unchanged[] = $url;
				}
			} else {
				$error_urls[] = $url;
				$this->log_error( 'Failed URL: ' . $url, [ 'error' => $result['message'] ?? '' ] );
			}
		}

		if ( empty( $summaries ) ) {
			$this->log_error( 'No changelog changes detected. Skipping email.' );
			return;
		}

		$freq    = get_option( 'aics_email_frequency', 'weekly' );
		$subject = AICS_Email_Template::subject( $freq );
		$body    = AICS_Email_Template::render( $summaries, $error_urls, $unchanged );
		$headers = $this->get_email_headers();

		$recipients = is_array( $email ) ? $email : [ $email ];
		$sent       = false;
		foreach ( $recipients as $to ) {
			if ( wp_mail( trim( $to ), $subject, $body, $headers ) ) {
				$sent = true;
			}
		}

		if ( ! $sent ) {
			$this->log_error( 'Failed to send scheduled email.', [ 'email' => $email ] );
		}
	}

	/* ───────────────────────── Dashboard Widget ───────────────── */

	public function add_dashboard_widget() {
		wp_add_dashboard_widget(
			'aics_dashboard_widget',
			__( 'AI Changelog Summary', 'changelog-tracker' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	public function render_dashboard_widget() {
		$stored = get_option( 'changelog_summaries', [] );

		if ( empty( $stored ) ) {
			echo '<p>' . wp_kses_post(
				sprintf(
					/* translators: %s: settings page URL */
					__( 'No changelog summaries yet. <a href="%s">Configure the plugin</a> to get started.', 'changelog-tracker' ),
					esc_url( admin_url( 'options-general.php?page=changelog-tracker' ) )
				)
			) . '</p>';
			return;
		}

		foreach ( $stored as $url => $data ) {
			$summary_text = wp_strip_all_tags( $data['summary'] );
			$truncated    = mb_strlen( $summary_text ) > 200 ? mb_substr( $summary_text, 0, 200 ) . '...' : $summary_text;
			$time_ago     = human_time_diff( $data['timestamp'], current_time( 'timestamp' ) );
			?>
			<div class="aics-widget-item">
				<div class="aics-widget-url"><?php echo esc_html( $url ); ?></div>
				<div class="aics-widget-summary"><?php echo esc_html( $truncated ); ?></div>
				<div class="aics-widget-time">
					<?php
					/* translators: %s: human-readable time difference */
					echo esc_html( sprintf( __( '%s ago', 'changelog-tracker' ), $time_ago ) );
					?>
				</div>
			</div>
			<?php
		}
		?>
		<div style="margin-top:12px;display:flex;gap:8px;">
			<button id="aics-widget-refresh" class="button button-small button-primary"><?php esc_html_e( 'Refresh Now', 'changelog-tracker' ); ?></button>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=changelog-tracker' ) ); ?>" class="button button-small"><?php esc_html_e( 'View Full Summary', 'changelog-tracker' ); ?></a>
		</div>
		<span id="aics-widget-result" style="display:block;margin-top:6px;font-size:12px;"></span>
		<?php
	}

	/* ───────────────────────── Cleanup ─────────────────────────── */

	public function clear_old_changelog_summaries() {
		$stored  = get_option( 'changelog_summaries', [] );
		$now     = current_time( 'timestamp' );
		$changed = false;

		foreach ( $stored as $url => $data ) {
			if ( ( $now - $data['timestamp'] ) > ( 30 * DAY_IN_SECONDS ) ) {
				unset( $stored[ $url ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'changelog_summaries', $stored );
		}
	}

	public function deactivate() {
		wp_clear_scheduled_hook( 'aics_changelog_email' );
		wp_clear_scheduled_hook( 'changelog_weekly_email' );
	}
}

/* ───────────────────────── Bootstrap ─────────────────────────── */

register_deactivation_hook( __FILE__, function () {
	$plugin = new AIChangelogSummary();
	$plugin->deactivate();
} );

add_action( 'plugins_loaded', function () {
	new AIChangelogSummary();
} );
