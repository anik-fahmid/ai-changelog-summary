<?php
/*
Plugin Name: AI Changelog Summary
Description: AI-powered changelog tracking and summarization with multi-provider support
Version: 2.0
Author: Fahmid Hasan
Author URI: https://fahmidsroadmap.com/
Plugin URI: https://fahmidsroadmap.com/ai-changelog-summary/
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

    private $default_url_count = 2;
    private $option_group      = 'ai-changelog-summary-settings';

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
        // Admin.
        add_action( 'admin_menu', [ $this, 'add_plugin_page' ] );
        add_action( 'admin_init', [ $this, 'init_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'admin_head', [ $this, 'add_inline_styles' ] );

        // Dashboard widget.
        add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widget' ] );

        // AJAX.
        add_action( 'wp_ajax_preview_fetch_changelog', [ $this, 'handle_changelog_fetch' ] );
        add_action( 'wp_ajax_send_test_changelog_email', [ $this, 'handle_test_email' ] );
        add_action( 'wp_ajax_test_wp_mail', [ $this, 'test_wp_mail' ] );
        add_action( 'wp_ajax_aics_force_fetch', [ $this, 'handle_force_fetch' ] );

        // Cron.
        add_filter( 'cron_schedules', [ $this, 'add_custom_schedules' ] );
        $this->maybe_schedule_cron();
        add_action( 'aics_changelog_email', [ $this, 'send_changelog_email' ] );

        // Cleanup.
        add_action( 'init', [ $this, 'clear_old_changelog_summaries' ] );

        // Reschedule cron when frequency settings change.
        add_action( 'update_option_aics_email_frequency', [ $this, 'reschedule_cron' ] );
        add_action( 'update_option_aics_email_day', [ $this, 'reschedule_cron' ] );
        add_action( 'update_option_aics_email_time', [ $this, 'reschedule_cron' ] );
    }

    /* ───────────────────────── Cron Schedules ───────────────── */

    public function add_custom_schedules( $schedules ) {
        $schedules['daily'] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => 'Once Daily',
        ];
        $schedules['weekly'] = [
            'interval' => 7 * DAY_IN_SECONDS,
            'display'  => 'Once Weekly',
        ];
        $schedules['biweekly'] = [
            'interval' => 14 * DAY_IN_SECONDS,
            'display'  => 'Every Two Weeks',
        ];
        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => 'Once Monthly',
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
            'AI Changelog Summary',
            'AI Changelog Summary',
            'manage_options',
            'ai-changelog-summary',
            [ $this, 'render_settings_page' ]
        );
    }

    /* ───────────────────────── Settings Registration ─────────── */

    public function init_settings() {
        // Changelog URLs.
        register_setting( $this->option_group, 'changelog_urls', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_changelog_urls' ],
        ] );

        // AI provider.
        register_setting( $this->option_group, 'aics_ai_provider', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'gemini',
        ] );

        // API keys — one per provider.
        register_setting( $this->option_group, 'gemini_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( $this->option_group, 'openai_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( $this->option_group, 'claude_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        // Max output tokens for AI responses.
        register_setting( $this->option_group, 'aics_max_tokens', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 2048,
        ] );

        // Notification email.
        register_setting( $this->option_group, 'notification_email', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
        ] );

        // Schedule options.
        register_setting( $this->option_group, 'aics_email_frequency', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'weekly',
        ] );
        register_setting( $this->option_group, 'aics_email_day', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'monday',
        ] );
        register_setting( $this->option_group, 'aics_email_time', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 8,
        ] );

        // General section — AI provider, API keys, changelog URLs.
        add_settings_section(
            'aics_general_section',
            'AI &amp; Changelog Sources',
            function () {
                echo '<p>Configure your AI provider and the changelog pages to monitor.</p>';
            },
            'aics-general'
        );

        // Notifications section — email, schedule.
        add_settings_section(
            'aics_notifications_section',
            'Email Schedule',
            function () {
                echo '<p>Set up notification email and delivery schedule.</p>';
            },
            'aics-notifications'
        );

        $this->add_settings_fields();
    }

    private function add_settings_fields() {
        // General tab fields.
        add_settings_field( 'aics_ai_provider', 'AI Provider', [ $this, 'render_provider_field' ], 'aics-general', 'aics_general_section' );
        add_settings_field( 'aics_api_keys', 'API Key', [ $this, 'render_api_key_fields' ], 'aics-general', 'aics_general_section' );
        add_settings_field( 'aics_max_tokens', 'Max Output Tokens', [ $this, 'render_max_tokens_field' ], 'aics-general', 'aics_general_section' );
        add_settings_field( 'changelog_urls', 'Changelog URLs', [ $this, 'render_urls_field' ], 'aics-general', 'aics_general_section' );

        // Notifications tab fields.
        add_settings_field( 'notification_email', 'Notification Email', [ $this, 'render_email_field' ], 'aics-notifications', 'aics_notifications_section' );
        add_settings_field( 'aics_frequency', 'Email Frequency', [ $this, 'render_frequency_field' ], 'aics-notifications', 'aics_notifications_section' );
        add_settings_field( 'aics_day', 'Send Day', [ $this, 'render_day_field' ], 'aics-notifications', 'aics_notifications_section' );
        add_settings_field( 'aics_time', 'Send Time', [ $this, 'render_time_field' ], 'aics-notifications', 'aics_notifications_section' );
    }

    /* ───────────────────────── Field Renderers ───────────────── */

    public function render_provider_field() {
        $current = get_option( 'aics_ai_provider', 'gemini' );
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
        $current  = get_option( 'aics_ai_provider', 'gemini' );
        $keys = [
            'gemini' => [ 'option' => 'gemini_api_key', 'label' => 'Gemini API Key',  'link' => 'https://aistudio.google.com/app/apikey' ],
            'openai' => [ 'option' => 'openai_api_key', 'label' => 'OpenAI API Key',  'link' => 'https://platform.openai.com/api-keys' ],
            'claude' => [ 'option' => 'claude_api_key', 'label' => 'Claude API Key',  'link' => 'https://console.anthropic.com/settings/keys' ],
        ];
        foreach ( $keys as $provider => $meta ) :
            $value   = get_option( $meta['option'], '' );
            $display = ( $provider === $current ) ? 'block' : 'none';
            ?>
            <div class="aics-api-key-row" data-provider="<?php echo esc_attr( $provider ); ?>" style="display:<?php echo $display; ?>;margin-bottom:8px;">
                <input
                    type="password"
                    name="<?php echo esc_attr( $meta['option'] ); ?>"
                    value="<?php echo esc_attr( $value ); ?>"
                    class="regular-text"
                    placeholder="<?php echo esc_attr( $meta['label'] ); ?>"
                >
                <a href="<?php echo esc_url( $meta['link'] ); ?>" target="_blank" style="margin-left:8px;font-size:12px;">Get key &rarr;</a>
            </div>
        <?php endforeach;
    }

    public function render_max_tokens_field() {
        $value = get_option( 'aics_max_tokens', 2048 );
        ?>
        <input type="number" name="aics_max_tokens" value="<?php echo esc_attr( $value ); ?>" min="512" max="8192" step="256" style="width:100px;">
        <p class="description">Maximum tokens for AI response. Increase if summaries are getting cut off. Default: 2048.</p>
        <?php
    }

    public function render_urls_field() {
        $urls  = get_option( 'changelog_urls', [] );
        $count = max( $this->default_url_count, count( $urls ) );
        $urls  = array_pad( $urls, $count, '' );
        ?>
        <div id="changelog-urls-container">
            <?php for ( $i = 0; $i < $count; $i++ ) : ?>
                <div class="aics-url-row" style="margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                    <input
                        type="url"
                        name="changelog_urls[<?php echo $i; ?>]"
                        value="<?php echo esc_attr( $urls[ $i ] ); ?>"
                        class="regular-text"
                        placeholder="Changelog URL #<?php echo $i + 1; ?>"
                    >
                    <?php if ( $i >= $this->default_url_count ) : ?>
                        <button type="button" class="button button-small aics-remove-url" style="color:#b91c1c;">&times;</button>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
        <button type="button" id="aics-add-url" class="button button-small" style="margin-top:4px;">+ Add URL</button>
        <p class="description">Add as many changelog URLs as you need.</p>
        <?php
    }

    public function render_email_field() {
        $email = get_option( 'notification_email', get_option( 'admin_email' ) );
        ?>
        <input type="email" name="notification_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" placeholder="Email for notifications">
        <?php
    }

    public function render_frequency_field() {
        $current = get_option( 'aics_email_frequency', 'weekly' );
        $options = apply_filters( 'aics_frequency_options', [ 'daily' => 'Daily', 'weekly' => 'Weekly', 'biweekly' => 'Every Two Weeks', 'monthly' => 'Monthly' ] );
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
        $freq    = get_option( 'aics_email_frequency', 'weekly' );
        $hidden  = ( $freq === 'daily' ) ? 'style="display:none;"' : '';
        ?>
        <select name="aics_email_day" id="aics-day">
            <?php foreach ( $days as $d ) : ?>
                <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $current, $d ); ?>><?php echo esc_html( ucfirst( $d ) ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description aics-day-note">Day of the week to send the email.</p>
        <?php
    }

    public function render_time_field() {
        $current = (int) get_option( 'aics_email_time', 8 );
        ?>
        <select name="aics_email_time" id="aics-time">
            <?php for ( $h = 0; $h < 24; $h++ ) : ?>
                <option value="<?php echo $h; ?>" <?php selected( $current, $h ); ?>>
                    <?php echo sprintf( '%02d:00', $h ); ?>
                    (<?php echo wp_date( 'g A', strtotime( $h . ':00' ) ); ?>)
                </option>
            <?php endfor; ?>
        </select>
        <p class="description">Time in your WordPress timezone (<?php echo esc_html( wp_timezone_string() ); ?>).</p>
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

            <!-- Tab Navigation -->
            <nav class="aics-tabs">
                <a class="aics-tab active" data-tab="general" href="#general">General</a>
                <a class="aics-tab" data-tab="notifications" href="#notifications">Notifications</a>
                <a class="aics-tab" data-tab="tools" href="#tools">Tools</a>
                <a class="aics-tab" data-tab="advanced" href="#advanced">Advanced</a>
            </nav>

            <!-- ============ General Tab ============ -->
            <div class="aics-tab-content active" id="aics-tab-general">
                <form method="post" action="options.php">
                    <?php
                    settings_fields( $this->option_group );
                    do_settings_sections( 'aics-general' );
                    submit_button( 'Save Settings' );
                    ?>
                </form>

                <!-- Actions -->
                <div class="aics-card" style="margin-top:20px;">
                    <h2>Actions</h2>
                    <div style="margin-top:12px;">
                        <button id="preview-changelog" class="button button-primary">Preview Changelog</button>
                        <button id="preview-fresh" class="button">Fetch Latest</button>
                        <p class="description">Preview uses cached data (30 min). <strong>Fetch Latest</strong> bypasses cache.</p>
                    </div>
                    <div style="margin-top:16px;">
                        <button id="force-fetch" class="button button-primary">Fetch &amp; Email Now</button>
                        <label style="margin-left:12px;">
                            <input type="checkbox" id="force-fetch-ignore-diff" value="1"> Send even if no changes detected
                        </label>
                        <span id="force-fetch-result" style="margin-left:10px;"></span>
                        <p class="description">Force-fetch all changelogs (bypasses cache) and send email immediately.</p>
                    </div>
                </div>

                <!-- Preview Area -->
                <div class="aics-card" style="margin-top:20px;">
                    <h2>Changelog Preview</h2>
                    <div id="changelog-preview"></div>
                </div>

                <?php do_action( 'aics_tab_general' ); ?>
            </div>

            <!-- ============ Notifications Tab ============ -->
            <div class="aics-tab-content" id="aics-tab-notifications">
                <form method="post" action="options.php">
                    <?php
                    settings_fields( $this->option_group );
                    do_settings_sections( 'aics-notifications' );
                    submit_button( 'Save Settings' );
                    ?>
                </form>

                <!-- Schedule Info -->
                <div class="aics-card" style="margin-top:20px;">
                    <h2>Schedule</h2>
                    <p>
                        <strong>Frequency:</strong> <?php echo esc_html( ucfirst( $freq ) ); ?><br>
                        <strong>Next email:</strong>
                        <?php
                        if ( $next_run ) {
                            echo esc_html( wp_date( 'l, F j, Y \a\t g:i A', $next_run ) );
                        } else {
                            echo 'Not scheduled';
                        }
                        ?>
                    </p>
                </div>

                <!-- Email Testing -->
                <div class="aics-card" style="margin-top:20px;">
                    <h2>Email Testing</h2>
                    <div style="margin-top:12px;">
                        <button id="test-wpmail" class="button button-secondary">Test WordPress Email</button>
                        <span id="wpmail-test-result" style="margin-left:10px;"></span>
                        <p class="description">Sends a basic test email to verify WordPress mail.</p>
                    </div>
                    <div style="margin-top:12px;">
                        <button id="send-test-email" class="button button-secondary">Send Test Changelog Email</button>
                        <span id="test-email-result" style="margin-left:10px;"></span>
                        <p class="description">Sends the current AI summary to your notification email.</p>
                    </div>
                </div>

                <?php do_action( 'aics_tab_notifications' ); ?>
            </div>

            <!-- ============ Tools Tab ============ -->
            <div class="aics-tab-content" id="aics-tab-tools">

                <?php do_action( 'aics_tab_tools' ); ?>
            </div>

            <!-- ============ Advanced Tab ============ -->
            <div class="aics-tab-content" id="aics-tab-advanced">

                <?php do_action( 'aics_tab_advanced' ); ?>

                <!-- Documentation -->
                <div class="aics-card" style="margin-top:20px;">
                    <h2>Documentation</h2>
                    <h4>Setup Instructions:</h4>
                    <ol>
                        <li>Select your AI provider and enter the API key</li>
                        <li>Enter the changelog URLs you want to monitor</li>
                        <li>Configure the notification email address</li>
                        <li>Set your preferred email schedule</li>
                        <li>Use the test buttons above to verify your setup</li>
                    </ol>
                    <h4>Change Detection:</h4>
                    <p>The plugin stores a fingerprint of each changelog. Scheduled emails are only sent when changes are detected. Use "Fetch &amp; Email Now" to force an email regardless.</p>
                </div>

                <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
                <div class="aics-card" style="margin-top:20px;">
                    <h2>Debug Information</h2>
                    <pre style="background:#f5f5f5;padding:10px;overflow:auto;">
PHP Version: <?php echo PHP_VERSION; ?>

WordPress Version: <?php echo get_bloginfo( 'version' ); ?>

Plugin Version: <?php echo AICS_VERSION; ?>

Timezone: <?php echo wp_timezone_string(); ?>

Provider: <?php echo esc_html( get_option( 'aics_ai_provider', 'gemini' ) ); ?>

Next Cron: <?php echo $next_run ? wp_date( 'Y-m-d H:i:s', $next_run ) : 'None'; ?>
                    </pre>
                </div>
                <?php endif; ?>

            </div>

            <?php
            // Keep legacy hooks for backward compatibility.
            do_action( 'aics_after_settings_form' );
            do_action( 'aics_settings_page_bottom' );
            ?>
        </div>
        <?php
    }

    /* ───────────────────────── Assets ─────────────────────────── */

    public function enqueue_scripts( $hook ) {
        // Settings page.
        if ( $hook === 'settings_page_ai-changelog-summary' ) {
            wp_enqueue_script( 'aics-admin', AICS_URL . 'js/changelog-script.js', [ 'jquery' ], AICS_VERSION, true );
            wp_localize_script( 'aics-admin', 'AICS', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'aics_nonce' ),
            ] );
        }

        // Dashboard page (for widget refresh button).
        if ( $hook === 'index.php' ) {
            wp_enqueue_script( 'aics-dashboard', AICS_URL . 'js/changelog-script.js', [ 'jquery' ], AICS_VERSION, true );
            wp_localize_script( 'aics-dashboard', 'AICS', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'aics_nonce' ),
            ] );
        }
    }

    public function add_inline_styles() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'settings_page_ai-changelog-summary', 'dashboard' ], true ) ) {
            return;
        }
        ?>
        <style>
            /* Tabs */
            .aics-tabs { display: flex; border-bottom: 2px solid #c3c4c7; margin: 20px 0 0; }
            .aics-tab {
                padding: 10px 20px; cursor: pointer; text-decoration: none;
                color: #50575e; font-weight: 500; font-size: 14px;
                border-bottom: 2px solid transparent; margin-bottom: -2px;
                transition: color 0.15s;
            }
            .aics-tab:hover, .aics-tab:focus { color: #2271b1; outline: none; box-shadow: none; }
            .aics-tab.active { color: #2271b1; border-bottom-color: #2271b1; }
            .aics-tab-content { display: none; padding-top: 20px; }
            .aics-tab-content.active { display: block; }
            /* Cards */
            .aics-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 6px;
                padding: 16px 24px;
            }
            .aics-card h2 { margin-top: 0; }
            .ai-summary-content { margin-top: 10px; }
            .ai-summary-content h2 { font-size: 22px; color: #333; border-bottom: 1px solid #eee; padding-bottom: 8px; }
            .ai-summary-content h3 { font-size: 18px; color: #444; margin: 12px 0 8px; }
            .ai-summary-content h4 { font-size: 15px; color: #666; margin: 8px 0; }
            .ai-summary-content ul { margin: 8px 0 16px 20px; list-style: none; }
            .ai-summary-content ul li { margin: 4px 0; line-height: 1.5; }
            .changelog-result { margin-bottom: 20px; padding: 16px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; }
            .changelog-result .status-badge {
                display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; margin-bottom: 8px;
            }
            .status-updated { background: #dcfce7; color: #166534; }
            .status-unchanged { background: #fef3c7; color: #92400e; }
            .status-error { background: #fee2e2; color: #991b1b; }
            /* Dashboard widget */
            .aics-widget-item { padding: 10px 0; border-bottom: 1px solid #eee; }
            .aics-widget-item:last-child { border-bottom: none; }
            .aics-widget-url { font-size: 12px; color: #666; word-break: break-all; }
            .aics-widget-summary { font-size: 13px; margin-top: 4px; }
            .aics-widget-time { font-size: 11px; color: #999; margin-top: 4px; }
        </style>
        <?php
    }

    /* ───────────────────────── Core: Process Changelog ────────── */

    /**
     * Fetch, extract, hash, and summarize a changelog URL.
     *
     * @param string $url         URL to fetch.
     * @param bool   $force       Bypass cache.
     * @return array { success, content, ai_summary, changed, content_hash }
     */
    public function process_changelog( $url, $force = false ) {
        $stored    = get_option( 'changelog_summaries', [] );
        $cached    = isset( $stored[ $url ] ) ? $stored[ $url ] : null;
        $provider  = get_option( 'aics_ai_provider', 'gemini' );
        $api_key   = get_option( $provider . '_api_key', '' );

        // Return cache if not forced and cache is < 30 minutes old.
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

        // Fetch.
        $response = wp_remote_get( $url, [ 'timeout' => 30, 'sslverify' => false ] );
        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => 'Fetch failed: ' . $response->get_error_message(),
            ];
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return [ 'success' => false, 'message' => 'Empty response body.' ];
        }

        // Extract content.
        $content      = AICS_Content_Extractor::extract( $body, $url );
        $content_hash = md5( $content );

        // Compare hash — detect changes.
        $old_hash = $cached['content_hash'] ?? '';
        $changed  = ( $content_hash !== $old_hash );

        // If unchanged and not forced, return cached summary.
        if ( ! $changed && ! $force && $cached ) {
            return [
                'success'      => true,
                'content'      => $content,
                'ai_summary'   => $cached['summary'],
                'changed'      => false,
                'content_hash' => $content_hash,
            ];
        }

        // Summarize with AI.
        $ai_result = AICS_AI_Providers::summarize( $content, $provider, $api_key );

        if ( ! $ai_result['success'] ) {
            return [
                'success' => false,
                'message' => $ai_result['error'] ?? 'AI summarization failed.',
            ];
        }

        // Store.
        $this->store_changelog_summary( $url, $ai_result['summary'], $content_hash );

        do_action( 'aics_changelog_processed', $url, $ai_result['summary'], $content_hash, $changed );

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
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $urls = get_option( 'changelog_urls', [] );
        if ( empty( $urls ) ) {
            wp_send_json_error( [ 'message' => 'No URLs configured.' ] );
        }

        $skip_cache = ! empty( $_POST['skip_cache'] );

        $results = [];
        foreach ( $urls as $url ) {
            if ( empty( $url ) ) continue;
            $result = $this->process_changelog( $url, $skip_cache );
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
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $urls       = array_filter( get_option( 'changelog_urls', [] ) );
        $email      = apply_filters( 'aics_notification_emails', get_option( 'notification_email', get_option( 'admin_email' ) ) );
        $ignore_diff = isset( $_POST['ignore_diff'] ) && $_POST['ignore_diff'] === '1';

        if ( empty( $urls ) ) {
            wp_send_json_error( [ 'message' => 'No URLs configured.' ] );
        }

        $summaries   = [];
        $error_urls  = [];
        $unchanged   = [];

        foreach ( $urls as $url ) {
            if ( empty( $url ) ) continue;

            $result = $this->process_changelog( $url, true ); // force = true

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

        // Decide whether to send email.
        $should_send = $ignore_diff
            ? ( ! empty( $summaries ) || ! empty( $unchanged ) )
            : ! empty( $summaries );

        if ( $should_send ) {
            // If ignoring diff, include unchanged in summaries for the email.
            $email_summaries = $summaries;
            if ( $ignore_diff ) {
                $stored = get_option( 'changelog_summaries', [] );
                foreach ( $unchanged as $u ) {
                    if ( isset( $stored[ $u ] ) ) {
                        $email_summaries[] = [ 'url' => $u, 'summary' => $stored[ $u ]['summary'] ];
                    }
                }
                $unchanged = []; // Already included in email.
            }

            $freq    = get_option( 'aics_email_frequency', 'weekly' );
            $subject = AICS_Email_Template::subject( $freq );
            $body    = AICS_Email_Template::render( $email_summaries, $error_urls, $unchanged );
            $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

            $recipients = is_array( $email ) ? $email : [ $email ];
            $sent = false;
            foreach ( $recipients as $to ) {
                if ( wp_mail( trim( $to ), $subject, $body, $headers ) ) {
                    $sent = true;
                }
            }

            do_action( 'aics_after_email_sent', $email_summaries, $error_urls, $unchanged );

            $display_email = is_array( $email ) ? implode( ', ', $email ) : $email;
            wp_send_json_success( [
                'message'    => $sent ? 'Email sent to ' . $display_email : 'Failed to send email.',
                'sent'       => $sent,
                'changed'    => count( $summaries ),
                'unchanged'  => count( $unchanged ),
                'errors'     => count( $error_urls ),
            ] );
        } else {
            wp_send_json_success( [
                'message'   => 'No changes detected. Email not sent.',
                'sent'      => false,
                'changed'   => 0,
                'unchanged' => count( $unchanged ),
                'errors'    => count( $error_urls ),
            ] );
        }
    }

    /* ───────────────────────── AJAX: Test Emails ──────────────── */

    public function handle_test_email() {
        check_ajax_referer( 'aics_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $urls    = get_option( 'changelog_urls', [] );
        $email   = get_option( 'notification_email', get_option( 'admin_email' ) );

        if ( empty( $urls ) ) {
            wp_send_json_error( [ 'message' => 'No changelog URLs configured.' ] );
        }
        if ( empty( $email ) ) {
            wp_send_json_error( [ 'message' => 'No notification email configured.' ] );
        }

        $summaries  = [];
        $error_urls = [];

        foreach ( $urls as $url ) {
            if ( empty( $url ) ) continue;
            $result = $this->process_changelog( $url );
            if ( $result['success'] && ! empty( $result['ai_summary'] ) ) {
                $summaries[] = [ 'url' => $url, 'summary' => $result['ai_summary'] ];
            } else {
                $error_urls[] = $url;
            }
        }

        if ( empty( $summaries ) ) {
            wp_send_json_error( [ 'message' => 'No summaries could be generated.', 'error_urls' => $error_urls ] );
        }

        $subject = '[Test] ' . AICS_Email_Template::subject( get_option( 'aics_email_frequency', 'weekly' ) );
        $body    = AICS_Email_Template::render( $summaries, $error_urls );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $sent = wp_mail( $email, $subject, $body, $headers );

        if ( $sent ) {
            wp_send_json_success( [ 'message' => 'Test email sent to ' . $email ] );
        } else {
            wp_send_json_error( [ 'message' => 'Failed to send email.' ] );
        }
    }

    public function test_wp_mail() {
        check_ajax_referer( 'aics_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $email   = get_option( 'notification_email', get_option( 'admin_email' ) );
        $subject = 'Test Email from AI Changelog Summary';
        $message = 'This is a test email to verify wp_mail is working correctly.';
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $sent = wp_mail( $email, $subject, $message, $headers );

        if ( $sent ) {
            wp_send_json_success( [ 'message' => 'Test email sent to ' . $email ] );
        } else {
            wp_send_json_error( [ 'message' => 'Failed to send email. Check your WordPress email config.' ] );
        }
    }

    /* ───────────────────────── Cron: Send Email ───────────────── */

    public function send_changelog_email() {
        $urls    = get_option( 'changelog_urls', [] );
        $email   = apply_filters( 'aics_notification_emails', get_option( 'notification_email', get_option( 'admin_email' ) ) );

        if ( empty( $urls ) || empty( $email ) ) {
            $this->log_error( 'Missing settings for scheduled email.' );
            return;
        }

        $summaries  = [];
        $error_urls = [];
        $unchanged  = [];

        foreach ( $urls as $url ) {
            if ( empty( $url ) ) continue;

            $result = $this->process_changelog( $url, true ); // Force fresh fetch.

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

        // Only email when at least one changelog has changed.
        if ( empty( $summaries ) ) {
            $this->log_error( 'No changelog changes detected. Skipping email.' );
            return;
        }

        $freq    = get_option( 'aics_email_frequency', 'weekly' );
        $subject = AICS_Email_Template::subject( $freq );
        $body    = AICS_Email_Template::render( $summaries, $error_urls, $unchanged );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $recipients = is_array( $email ) ? $email : [ $email ];
        $sent = false;
        foreach ( $recipients as $to ) {
            if ( wp_mail( trim( $to ), $subject, $body, $headers ) ) {
                $sent = true;
            }
        }

        if ( ! $sent ) {
            $this->log_error( 'Failed to send scheduled email.', [ 'email' => $email ] );
        }

        do_action( 'aics_after_email_sent', $summaries, $error_urls, $unchanged );
    }

    /* ───────────────────────── Dashboard Widget ───────────────── */

    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'aics_dashboard_widget',
            'AI Changelog Summary',
            [ $this, 'render_dashboard_widget' ]
        );
    }

    public function render_dashboard_widget() {
        $stored = get_option( 'changelog_summaries', [] );

        if ( empty( $stored ) ) {
            echo '<p style="color:#666;">No changelog summaries yet. <a href="' . esc_url( admin_url( 'options-general.php?page=ai-changelog-summary' ) ) . '">Configure the plugin</a> to get started.</p>';
            return;
        }

        foreach ( $stored as $url => $data ) {
            $summary_text = wp_strip_all_tags( $data['summary'] );
            $truncated    = mb_strlen( $summary_text ) > 200 ? mb_substr( $summary_text, 0, 200 ) . '...' : $summary_text;
            $time_ago     = human_time_diff( $data['timestamp'], current_time( 'timestamp' ) ) . ' ago';
            ?>
            <div class="aics-widget-item">
                <div class="aics-widget-url"><?php echo esc_html( $url ); ?></div>
                <div class="aics-widget-summary"><?php echo esc_html( $truncated ); ?></div>
                <div class="aics-widget-time"><?php echo esc_html( $time_ago ); ?></div>
            </div>
            <?php
        }
        ?>
        <div style="margin-top:12px;display:flex;gap:8px;">
            <button id="aics-widget-refresh" class="button button-small button-primary">Refresh Now</button>
            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=ai-changelog-summary' ) ); ?>" class="button button-small">View Full Summary</a>
        </div>
        <span id="aics-widget-result" style="display:block;margin-top:6px;font-size:12px;"></span>
        <?php
    }

    /* ───────────────────────── Cleanup ─────────────────────────── */

    public function clear_old_changelog_summaries() {
        $stored = get_option( 'changelog_summaries', [] );
        $now    = current_time( 'timestamp' );
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
        wp_clear_scheduled_hook( 'changelog_weekly_email' ); // v1.x cleanup.
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
