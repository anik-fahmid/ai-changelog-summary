<?php
/*
Plugin Name: AI Changelog Summary
Plugin URI: https://fahmidsroadmap.com/ai-changelog-summary/
Description: AI-powered changelog tracking and summarization with multi-provider support.
Version: 2.0
Author: Fahmid Hasan
Author URI: https://fahmidsroadmap.com/
Text Domain: ai-changelog-summary
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

class AIChangelogSummary {

	private $default_url_count   = 2;
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
			'display'  => esc_html__( 'Once Weekly', 'ai-changelog-summary' ),
		];
		$schedules['biweekly'] = [
			'interval' => 14 * DAY_IN_SECONDS,
			'display'  => esc_html__( 'Every Two Weeks', 'ai-changelog-summary' ),
		];
		$schedules['monthly'] = [
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => esc_html__( 'Once Monthly', 'ai-changelog-summary' ),
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
			__( 'AI Changelog Summary', 'ai-changelog-summary' ),
			__( 'AI Changelog Summary', 'ai-changelog-summary' ),
			'manage_options',
			'ai-changelog-summary',
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

		/* ── Sections ── */
		add_settings_section(
			'aics_general_section',
			esc_html__( 'AI & Changelog Sources', 'ai-changelog-summary' ),
			function () {
				echo '<p>' . esc_html__( 'Configure your AI provider and the changelog pages to monitor.', 'ai-changelog-summary' ) . '</p>';
			},
			'aics-general'
		);

		add_settings_section(
			'aics_notifications_section',
			esc_html__( 'Email Settings', 'ai-changelog-summary' ),
			function () {
				echo '<p>' . esc_html__( 'Set up notification email, sender details, and delivery schedule.', 'ai-changelog-summary' ) . '</p>';
			},
			'aics-notifications'
		);

		$this->add_settings_fields();
	}

	private function add_settings_fields() {
		/* ── General fields ── */
		add_settings_field( 'aics_ai_provider', esc_html__( 'AI Provider', 'ai-changelog-summary' ), [ $this, 'render_provider_field' ], 'aics-general', 'aics_general_section' );
		add_settings_field( 'aics_api_keys', esc_html__( 'API Key', 'ai-changelog-summary' ), [ $this, 'render_api_key_fields' ], 'aics-general', 'aics_general_section' );
		add_settings_field( 'aics_max_tokens', esc_html__( 'Max Output Tokens', 'ai-changelog-summary' ), [ $this, 'render_max_tokens_field' ], 'aics-general', 'aics_general_section' );
		add_settings_field( 'changelog_urls', esc_html__( 'Changelog URLs', 'ai-changelog-summary' ), [ $this, 'render_urls_field' ], 'aics-general', 'aics_general_section' );

		/* ── Notification fields ── */
		add_settings_field( 'notification_email', esc_html__( 'Notification Email', 'ai-changelog-summary' ), [ $this, 'render_email_field' ], 'aics-notifications', 'aics_notifications_section' );
		add_settings_field( 'aics_email_from_name', esc_html__( 'From Name', 'ai-changelog-summary' ), [ $this, 'render_from_name_field' ], 'aics-notifications', 'aics_notifications_section' );
		add_settings_field( 'aics_email_from_address', esc_html__( 'From Email', 'ai-changelog-summary' ), [ $this, 'render_from_address_field' ], 'aics-notifications', 'aics_notifications_section' );
		add_settings_field( 'aics_frequency', esc_html__( 'Email Frequency', 'ai-changelog-summary' ), [ $this, 'render_frequency_field' ], 'aics-notifications', 'aics_notifications_section' );
		add_settings_field( 'aics_day', esc_html__( 'Send Day', 'ai-changelog-summary' ), [ $this, 'render_day_field' ], 'aics-notifications', 'aics_notifications_section' );
		add_settings_field( 'aics_time', esc_html__( 'Send Time', 'ai-changelog-summary' ), [ $this, 'render_time_field' ], 'aics-notifications', 'aics_notifications_section' );
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
			'gemini' => [ 'option' => 'gemini_api_key', 'label' => __( 'Gemini API Key', 'ai-changelog-summary' ), 'link' => 'https://aistudio.google.com/app/apikey' ],
			'openai' => [ 'option' => 'openai_api_key', 'label' => __( 'OpenAI API Key', 'ai-changelog-summary' ), 'link' => 'https://platform.openai.com/api-keys' ],
			'claude' => [ 'option' => 'claude_api_key', 'label' => __( 'Claude API Key', 'ai-changelog-summary' ), 'link' => 'https://console.anthropic.com/settings/keys' ],
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
					<?php esc_html_e( 'Get key', 'ai-changelog-summary' ); ?> &rarr;
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
		<p class="description"><?php esc_html_e( 'Maximum tokens for AI response. Increase if summaries are getting cut off. Default: 2048.', 'ai-changelog-summary' ); ?></p>
		<?php
	}

	public function render_urls_field() {
		$urls  = get_option( 'changelog_urls', [] );
		$count = max( $this->default_url_count, count( $urls ) );
		$urls  = array_pad( $urls, $count, '' );
		?>
		<div id="changelog-urls-container">
			<?php for ( $i = 0; $i < $count; $i++ ) : ?>
				<div class="aics-url-row">
					<input
						type="url"
						name="changelog_urls[<?php echo esc_attr( $i ); ?>]"
						value="<?php echo esc_attr( $urls[ $i ] ); ?>"
						class="regular-text"
						<?php /* translators: %d: URL number */ ?>
						placeholder="<?php echo esc_attr( sprintf( __( 'Changelog URL #%d', 'ai-changelog-summary' ), $i + 1 ) ); ?>"
					>
					<?php if ( $i >= $this->default_url_count ) : ?>
						<button type="button" class="button button-small aics-remove-url">&times;</button>
					<?php endif; ?>
				</div>
			<?php endfor; ?>
		</div>
		<button type="button" id="aics-add-url" class="button button-small">
			<?php esc_html_e( '+ Add URL', 'ai-changelog-summary' ); ?>
		</button>
		<p class="description"><?php esc_html_e( 'Add as many changelog URLs as you need.', 'ai-changelog-summary' ); ?></p>
		<?php
	}

	public function render_email_field() {
		$email = get_option( 'notification_email', get_option( 'admin_email' ) );
		?>
		<input type="email" name="notification_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Email for notifications', 'ai-changelog-summary' ); ?>">
		<p class="description"><?php esc_html_e( 'The email address that receives changelog notifications.', 'ai-changelog-summary' ); ?></p>
		<?php
	}

	public function render_from_name_field() {
		$value = get_option( 'aics_email_from_name', '' );
		?>
		<input type="text" name="aics_email_from_name" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<p class="description"><?php esc_html_e( 'Sender name for emails. Leave empty to use your site name.', 'ai-changelog-summary' ); ?></p>
		<?php
	}

	public function render_from_address_field() {
		$value = get_option( 'aics_email_from_address', '' );
		?>
		<input type="email" name="aics_email_from_address" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
		<p class="description"><?php esc_html_e( 'Sender email address. Leave empty to use WordPress default.', 'ai-changelog-summary' ); ?></p>
		<?php
	}

	public function render_frequency_field() {
		$current = get_option( 'aics_email_frequency', 'weekly' );
		$options = apply_filters( 'aics_frequency_options', [
			'weekly'   => __( 'Weekly', 'ai-changelog-summary' ),
			'biweekly' => __( 'Every Two Weeks', 'ai-changelog-summary' ),
			'monthly'  => __( 'Monthly', 'ai-changelog-summary' ),
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
		<p class="description aics-day-note"><?php esc_html_e( 'Day of the week to send the email.', 'ai-changelog-summary' ); ?></p>
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
		$tz_note = sprintf( __( 'Time in your WordPress timezone (%s).', 'ai-changelog-summary' ), wp_timezone_string() );
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
		return $sanitized;
	}

	/* ───────────────────────── Settings Page ──────────────────── */

	public function render_settings_page() {
		$next_run = wp_next_scheduled( 'aics_changelog_email' );
		$freq     = get_option( 'aics_email_frequency', 'weekly' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<nav class="aics-tabs" role="tablist">
				<a class="aics-tab active" data-tab="general" href="#general" role="tab" aria-selected="true"><?php esc_html_e( 'General', 'ai-changelog-summary' ); ?></a>
				<a class="aics-tab" data-tab="notifications" href="#notifications" role="tab" aria-selected="false"><?php esc_html_e( 'Notifications', 'ai-changelog-summary' ); ?></a>
				<a class="aics-tab" data-tab="help" href="#help" role="tab" aria-selected="false"><?php esc_html_e( 'Help', 'ai-changelog-summary' ); ?></a>
			</nav>

			<!-- ============ General Tab ============ -->
			<div class="aics-tab-content active" id="aics-tab-general" role="tabpanel">
				<form method="post" action="options.php">
					<?php
					settings_fields( $this->general_group );
					do_settings_sections( 'aics-general' );
					submit_button( esc_html__( 'Save Settings', 'ai-changelog-summary' ) );
					?>
				</form>

				<div class="aics-card">
					<h2><?php esc_html_e( 'Actions', 'ai-changelog-summary' ); ?></h2>
					<div style="margin-top:12px;">
						<div class="aics-actions-group">
							<button id="preview-changelog" class="button button-primary"><?php esc_html_e( 'Preview Changelog', 'ai-changelog-summary' ); ?></button>
							<button id="preview-fresh" class="button"><?php esc_html_e( 'Fetch Latest', 'ai-changelog-summary' ); ?></button>
						</div>
						<p class="description">
							<?php
							printf(
								/* translators: %s: "Fetch Latest" button label */
								esc_html__( 'Preview uses cached data (30 min). %s bypasses cache.', 'ai-changelog-summary' ),
								'<strong>' . esc_html__( 'Fetch Latest', 'ai-changelog-summary' ) . '</strong>'
							);
							?>
						</p>
					</div>
					<div style="margin-top:16px;">
						<div class="aics-actions-group">
							<button id="force-fetch" class="button button-primary"><?php esc_html_e( 'Fetch & Email Now', 'ai-changelog-summary' ); ?></button>
							<label>
								<input type="checkbox" id="force-fetch-ignore-diff" value="1">
								<?php esc_html_e( 'Send even if no changes detected', 'ai-changelog-summary' ); ?>
							</label>
						</div>
						<span id="force-fetch-result" style="margin-left:10px;"></span>
						<p class="description"><?php esc_html_e( 'Force-fetch all changelogs (bypasses cache) and send email immediately.', 'ai-changelog-summary' ); ?></p>
					</div>
					<div style="margin-top:16px;">
						<div class="aics-actions-group">
							<button id="test-wpmail" class="button"><?php esc_html_e( 'Test Email Delivery', 'ai-changelog-summary' ); ?></button>
							<span id="wpmail-test-result"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Sends a test email to your notification address to verify WordPress mail is working.', 'ai-changelog-summary' ); ?></p>
					</div>
				</div>

				<div class="aics-card">
					<h2><?php esc_html_e( 'Changelog Preview', 'ai-changelog-summary' ); ?></h2>
					<div id="changelog-preview"></div>
				</div>
			</div>

			<!-- ============ Notifications Tab ============ -->
			<div class="aics-tab-content" id="aics-tab-notifications" role="tabpanel">
				<form method="post" action="options.php">
					<?php
					settings_fields( $this->notifications_group );
					do_settings_sections( 'aics-notifications' );
					submit_button( esc_html__( 'Save Settings', 'ai-changelog-summary' ) );
					?>
				</form>

				<div class="aics-card">
					<h2><?php esc_html_e( 'Schedule', 'ai-changelog-summary' ); ?></h2>
					<p>
						<strong><?php esc_html_e( 'Frequency:', 'ai-changelog-summary' ); ?></strong> <?php echo esc_html( ucfirst( $freq ) ); ?><br>
						<strong><?php esc_html_e( 'Next email:', 'ai-changelog-summary' ); ?></strong>
						<?php
						if ( $next_run ) {
							echo esc_html( wp_date( 'l, F j, Y \a\t g:i A', $next_run ) );
						} else {
							esc_html_e( 'Not scheduled', 'ai-changelog-summary' );
						}
						?>
					</p>
				</div>
			</div>

			<!-- ============ Help Tab ============ -->
			<div class="aics-tab-content" id="aics-tab-help" role="tabpanel">
				<div class="aics-card aics-help-section">
					<h2><?php esc_html_e( 'Documentation', 'ai-changelog-summary' ); ?></h2>
					<h4><?php esc_html_e( 'Setup Instructions', 'ai-changelog-summary' ); ?></h4>
					<ol>
						<li><?php esc_html_e( 'Select your AI provider and enter the API key.', 'ai-changelog-summary' ); ?></li>
						<li><?php esc_html_e( 'Enter the changelog URLs you want to monitor.', 'ai-changelog-summary' ); ?></li>
						<li><?php esc_html_e( 'Configure the notification email address.', 'ai-changelog-summary' ); ?></li>
						<li><?php esc_html_e( 'Set your preferred email schedule.', 'ai-changelog-summary' ); ?></li>
						<li><?php esc_html_e( 'Use the test buttons to verify your setup.', 'ai-changelog-summary' ); ?></li>
					</ol>
					<h4><?php esc_html_e( 'Change Detection', 'ai-changelog-summary' ); ?></h4>
					<p><?php esc_html_e( 'The plugin stores a fingerprint of each changelog. Scheduled emails are only sent when changes are detected. Use "Fetch & Email Now" to force an email regardless.', 'ai-changelog-summary' ); ?></p>
				</div>

				<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
				<div class="aics-card">
					<h2><?php esc_html_e( 'Debug Information', 'ai-changelog-summary' ); ?></h2>
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
		if ( 'settings_page_ai-changelog-summary' === $hook ) {
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
	public function process_changelog( $url, $force = false ) {
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
				'message' => sprintf( __( 'Fetch failed: %s', 'ai-changelog-summary' ), $response->get_error_message() ),
			];
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return [ 'success' => false, 'message' => __( 'Empty response body.', 'ai-changelog-summary' ) ];
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
				'message' => $ai_result['error'] ?? __( 'AI summarization failed.', 'ai-changelog-summary' ),
			];
		}

		$this->store_changelog_summary( $url, $ai_result['summary'], $content_hash );

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
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-changelog-summary' ) ] );
		}

		$urls = get_option( 'changelog_urls', [] );
		if ( empty( $urls ) ) {
			wp_send_json_error( [ 'message' => __( 'No URLs configured.', 'ai-changelog-summary' ) ] );
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
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-changelog-summary' ) ] );
		}

		$urls        = array_filter( get_option( 'changelog_urls', [] ) );
		$email       = get_option( 'notification_email', get_option( 'admin_email' ) );
		$ignore_diff = isset( $_POST['ignore_diff'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['ignore_diff'] ) );

		if ( empty( $urls ) ) {
			wp_send_json_error( [ 'message' => __( 'No URLs configured.', 'ai-changelog-summary' ) ] );
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
					$summaries[] = [ 'url' => $url, 'summary' => $result['ai_summary'] ];
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
						$email_summaries[] = [ 'url' => $u, 'summary' => $stored[ $u ]['summary'] ];
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
				'message'   => $sent ? sprintf( __( 'Email sent to %s', 'ai-changelog-summary' ), $display_email ) : __( 'Failed to send email.', 'ai-changelog-summary' ),
				'sent'      => $sent,
				'changed'   => count( $summaries ),
				'unchanged' => count( $unchanged ),
				'errors'    => count( $error_urls ),
			] );
		} else {
			wp_send_json_success( [
				'message'   => __( 'No changes detected. Email not sent.', 'ai-changelog-summary' ),
				'sent'      => false,
				'changed'   => 0,
				'unchanged' => count( $unchanged ),
				'errors'    => count( $error_urls ),
			] );
		}
	}

	/* ───────────────────────── AJAX: Test Email ──────────────── */

	public function test_wp_mail() {
		check_ajax_referer( 'aics_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-changelog-summary' ) ] );
		}

		$email   = get_option( 'notification_email', get_option( 'admin_email' ) );
		$subject = __( 'Test Email from AI Changelog Summary', 'ai-changelog-summary' );
		$message = '<p>' . __( 'This is a test email to verify your WordPress email configuration is working correctly.', 'ai-changelog-summary' ) . '</p>'
			. '<p>' . __( 'If you received this email, your setup is ready to send changelog notifications.', 'ai-changelog-summary' ) . '</p>';
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
			wp_send_json_success( [ 'message' => sprintf( __( 'Test email sent to %s', 'ai-changelog-summary' ), $email ) ] );
		} else {
			$error_msg = ! empty( $mail_error )
				? $mail_error
				: __( 'wp_mail() returned false. Check your WordPress email configuration or install an SMTP plugin.', 'ai-changelog-summary' );
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

			$result = $this->process_changelog( $url, true );

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
			__( 'AI Changelog Summary', 'ai-changelog-summary' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	public function render_dashboard_widget() {
		$stored = get_option( 'changelog_summaries', [] );

		if ( empty( $stored ) ) {
			echo '<p>' . wp_kses_post(
				sprintf(
					/* translators: %s: settings page URL */
					__( 'No changelog summaries yet. <a href="%s">Configure the plugin</a> to get started.', 'ai-changelog-summary' ),
					esc_url( admin_url( 'options-general.php?page=ai-changelog-summary' ) )
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
					echo esc_html( sprintf( __( '%s ago', 'ai-changelog-summary' ), $time_ago ) );
					?>
				</div>
			</div>
			<?php
		}
		?>
		<div style="margin-top:12px;display:flex;gap:8px;">
			<button id="aics-widget-refresh" class="button button-small button-primary"><?php esc_html_e( 'Refresh Now', 'ai-changelog-summary' ); ?></button>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=ai-changelog-summary' ) ); ?>" class="button button-small"><?php esc_html_e( 'View Full Summary', 'ai-changelog-summary' ); ?></a>
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
