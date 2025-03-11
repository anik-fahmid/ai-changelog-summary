<?php
/*
Plugin Name: Changelog Checker
Description: Fetches and displays changelog updates with AI summarization
Version: 1.1
Author: Fahmid Hasan
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ChangelogChecker {
    private $max_free_urls = 2; //Comment
    private $option_group = 'changelog-checker-settings';
        // Add error logging function here
        private function log_error($message, $data = []) {
            if (WP_DEBUG) {
                error_log('Changelog Checker Error: ' . $message);
                if (!empty($data)) {
                    error_log('Additional data: ' . print_r($data, true));
                }
            }
        }

    public function __construct() {
        add_action('admin_menu', [$this, 'add_plugin_page']);
        add_action('admin_init', [$this, 'init_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_preview_fetch_changelog', [$this, 'handle_changelog_fetch']);
        add_action('admin_head', [$this, 'add_inline_styles']);
        add_action('wp_ajax_send_test_changelog_email', [$this, 'handle_test_email']);
        add_action('wp_ajax_test_wp_mail', [$this, 'test_wp_mail']);


            // Setup cron
        add_filter('cron_schedules', [$this, 'add_weekly_schedule']);
        
        if (!wp_next_scheduled('changelog_weekly_email')) {
            wp_schedule_event(
                $this->get_next_monday_8am(),
                'weekly',
                'changelog_weekly_email'
            );
        }
        
        add_action('changelog_weekly_email', [$this, 'send_changelog_email']);
            // Clear old summaries immediately
        add_action('init', [$this, 'clear_old_changelog_summaries']);
        }

        public function handle_test_email() {
            check_ajax_referer('changelog_nonce', 'security');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Permission denied']);
                return;
            }
        
            $urls = get_option('changelog_urls', []);
            $email = get_option('notification_email', get_option('admin_email'));
            $api_key = get_option('gemini_api_key');
            
            // Check required settings
            if (empty($urls)) {
                wp_send_json_error(['message' => 'Please configure at least one changelog URL']);
                return;
            }
            if (empty($email)) {
                wp_send_json_error(['message' => 'Please configure notification email first']);
                return;
            }
            if (empty($api_key)) {
                wp_send_json_error(['message' => 'Please configure Gemini API key first']);
                return;
            }
        
            // Process all URLs
            $all_summaries = [];
            $error_urls = [];
        
            foreach ($urls as $url) {
                if (empty($url)) continue;
        
                // Fetch and process changelog
                $result = $this->process_changelog($url);
                
                if ($result['success'] && isset($result['ai_summary'])) {
                    $all_summaries[] = [
                        'url' => $url,
                        'summary' => $result['ai_summary']
                    ];
                } else {
                    $error_urls[] = $url;
                }
            }
        
            // Prepare email content
            if (!empty($all_summaries)) {
                $subject = 'Changelog AI Summaries - Test Email';
                
                $message = '<html><body>';
                $message .= '<h1>Changelog AI Summaries</h1>';
                
                foreach ($all_summaries as $index => $changelog) {
                    $message .= '<div style="margin-bottom: 20px;">';
                    $message .= '<h2>Changelog #' . ($index + 1) . ': ' . esc_html($changelog['url']) . '</h2>';
                    $message .= $changelog['summary'];
                    $message .= '</div>';
                }
                
                // Add error information if any URLs failed
                if (!empty($error_urls)) {
                    $message .= '<h2>Errors</h2>';
                    $message .= '<p>Failed to process the following URLs:</p>';
                    $message .= '<ul>';
                    foreach ($error_urls as $error_url) {
                        $message .= '<li>' . esc_html($error_url) . '</li>';
                    }
                    $message .= '</ul>';
                }
                
                $message .= '</body></html>';
                
                $headers = array(
                    'Content-Type: text/html; charset=UTF-8',
                    'From: WordPress <' . get_option('admin_email') . '>'
                );
                
                $sent = wp_mail($email, $subject, $message, $headers);
                
                if ($sent) {
                    wp_send_json_success([
                        'message' => 'AI summaries sent successfully to ' . $email,
                        'summaries_count' => count($all_summaries),
                        'errors_count' => count($error_urls)
                    ]);
                } else {
                    wp_send_json_error([
                        'message' => 'Failed to send email'
                    ]);
                }
            } else {
                wp_send_json_error([
                    'message' => 'No AI summaries could be generated',
                    'error_urls' => $error_urls
                ]);
            }
        }

        public function test_wp_mail() {
            check_ajax_referer('changelog_nonce', 'security');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Permission denied']);
                return;
            }
        
            $email = get_option('notiffication_email', get_option('admin_email'));
            $subject = 'Test Email from Changelog Checker';
            $message = 'This is a test email to verify wp_mail is working.';
            
            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: WordPress <' . get_option('admin_email') . '>'
            );
            
            $sent = wp_mail($email, $subject, $message, $headers);
            
            if ($sent) {
                wp_send_json_success([
                    'message' => 'Basic test email sent successfully to ' . $email
                ]);
            } else {
                wp_send_json_error([
                    'message' => 'Failed to send test email. Please check your WordPress email configuration.'
                ]);
            }
        }

    public function add_plugin_page() {
        add_options_page(
            'Changelog Checker',
            'Changelog Checker',
            'manage_options',
            'changelog-checker',
            [$this, 'render_settings_page']
        );
    }

    public function init_settings() {
        // Register changelog URLs setting
        register_setting($this->option_group, 'changelog_urls', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_changelog_urls'],
            'show_in_rest' => false //Comment
        ]);
    
        // Register Gemini API key setting
        register_setting($this->option_group, 'gemini_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => [$this, 'validate_settings']
        ]);
    
        // Register notification email setting
        register_setting($this->option_group, 'notification_email', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email'
        ]);
    
        // Add settings section
        add_settings_section(
            'changelog_settings_section',
            'Changelog Settings',
            [$this, 'settings_section_callback'],
            'changelog-checker'
        );
    
        // Add multiple changelog URLs field
        add_settings_field(
            'changelog_urls',
            'Changelog URLs',
            [$this, 'render_urls_field'],
            'changelog-checker',
            'changelog_settings_section'
        );
    
        // Add Gemini API key field
        add_settings_field(
            'gemini_api_key',
            'Gemini API Key',
            [$this, 'render_api_key_field'],
            'changelog-checker',
            'changelog_settings_section'
        );
    
        // Add notification email field
        add_settings_field(
            'notification_email',
            'Notification Email',
            [$this, 'render_email_field'],
            'changelog-checker',
            'changelog_settings_section'
        );
    }

    public function validate_settings($input) {
        $new_input = array();
        
        if(isset($input['gemini_api_key'])) {
            $new_input['gemini_api_key'] = sanitize_text_field($input['gemini_api_key']);
            
            // Optionally verify the API key works
            $test_result = $this->test_gemini_api_key($new_input['gemini_api_key']);
            if(!$test_result['success']) {
                add_settings_error(
                    'gemini_api_key',
                    'invalid_api_key',
                    'Invalid Gemini API key: ' . $test_result['message']
                );
            }
        }
        if(isset($input['changelog_url'])) {
            $new_input['changelog_url'] = esc_url_raw($input['changelog_url']);
        }
        
        return $new_input;
    }
    
    private function test_gemini_api_key($api_key) {
        // Simple API test
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent';
        
        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $api_key
            ],
            'body' => json_encode([
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => 'Test message'
                            ]
                        ]
                    ]
                ]
            ]),
            'method' => 'POST'
        ];
    
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message()
            ];
        }
    
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [
                'success' => false,
                'message' => 'API returned status code: ' . $code
            ];
        }
    
        return ['success' => true];
    }

    public function settings_section_callback() {
        echo '<p>Configure your changelog source and Gemini integration.</p>';
    }

    public function sanitize_changelog_urls($input) { //Comment (whole)
        $sanitized_urls = [];
        
        // Enforce free version limit
        $input = array_slice($input, 0, $this->max_free_urls);

        foreach ($input as $url) {
            $sanitized_url = esc_url_raw(trim($url));
            if (!empty($sanitized_url)) {
                $sanitized_urls[] = $sanitized_url;
            }
        }

        return $sanitized_urls;
    }


    public function render_urls_field() { //Comment (Whole)
        $urls = get_option('changelog_urls', []);
        // Pad the array to always show 2 input fields
        $urls = array_pad($urls, $this->max_free_urls, '');
        ?>
        <div id="changelog-urls-container">
            <?php for ($i = 0; $i < $this->max_free_urls; $i++): ?>
                <input 
                    type="url" 
                    name="changelog_urls[<?php echo $i; ?>]" 
                    value="<?php echo esc_attr($urls[$i]); ?>" 
                    class="regular-text"
                    placeholder="Enter changelog URL #<?php echo $i + 1; ?>"
                    style="margin-bottom: 10px;"
                >
            <?php endfor; ?>
        </div>
        <p class="description">
            Enter up to <?php echo $this->max_free_urls; ?> changelog URLs.
        </p>
        <?php
    }
    public function render_api_key_field() {
            $api_key = get_option('gemini_api_key', '');
            ?>
            <input 
                type="password" 
                name="gemini_api_key" 
                id="gemini-api-key" 
                value="<?php echo esc_attr($api_key); ?>" 
                class="regular-text"
                placeholder="Enter your Gemini API key"
            >
            <?php
        }
        public function render_email_field() {
            $email = get_option('notification_email', get_option('admin_email'));
            ?>
            <input 
                type="email" 
                name="notification_email" 
                id="notification-email" 
                value="<?php echo esc_attr($email); ?>" 
                class="regular-text"
                placeholder="Enter email for weekly updates"
            >
            <?php
        }

        public function render_settings_page() {
            ?>
            <div class="wrap">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                
                <!-- Settings Form -->
                <form method="post" action="options.php">
                    <?php
                    settings_fields($this->option_group);
                    do_settings_sections('changelog-checker');
                    submit_button('Save Settings');
                    ?>
                </form>
        
                <!-- Test Email Buttons Container -->
                <div class="email-testing-section" style="margin-top: 30px;">
                    <h2>Email Testing</h2>
                    
                    <!-- WordPress Mail Test -->
                    <div class="test-email-container" style="margin-top: 20px;">
                        <button id="test-wpmail" class="button button-secondary">
                            Test WordPress Email
                        </button>
                        <span id="wpmail-test-result" style="margin-left: 10px;"></span>
                        <p class="description">
                            Sends a basic test email to verify WordPress mail functionality.
                        </p>
                    </div>
        
                    <!-- Changelog Email Test -->
                    <div class="test-email-container" style="margin-top: 20px;">
                        <button id="send-test-email" class="button button-secondary">
                            Send Test Changelog Email
                        </button>
                        <span id="test-email-result" style="margin-left: 10px;"></span>
                        <p class="description">
                            Sends a test email with the current changelog AI summary.
                        </p>
                    </div>
                </div>
        
                <!-- Changelog Preview Section -->
                <div class="changelog-preview-section" style="margin-top: 30px;">
                    <h2>Changelog Preview</h2>
                    <div class="changelog-preview-container">
                        <button id="preview-changelog" class="button button-primary">
                            Preview Changelog
                        </button>
                        <div id="changelog-preview"></div>
                        <div id="ai-summary" style="display:none;">
                            <h3>AI Summary</h3>
                            <div class="ai-summary-content"></div>
                        </div>
                    </div>
                </div>
        
                <!-- Documentation Section -->
                <div class="documentation-section" style="margin-top: 30px;">
                    <h2>Documentation</h2>
                    <div class="documentation-content">
                        <h4>Setup Instructions:</h4>
                        <ol>
                            <li>Enter the changelog URL you want to monitor</li>
                            <li>Add your Gemini API key</li>
                            <li>Configure the notification email address</li>
                            <li>Use the test buttons above to verify your setup</li>
                        </ol>
                        <h4>Weekly Updates:</h4>
                        <p>The plugin will automatically send weekly changelog summaries every Monday at 8 AM.</p>
                    </div>
                </div>
        
                <!-- Debug Information -->
                <?php if (WP_DEBUG): ?>
                <div class="debug-section" style="margin-top: 30px;">
                    <h2>Debug Information</h2>
                    <pre style="background: #f5f5f5; padding: 10px; overflow: auto;">
                        PHP Version: <?php echo PHP_VERSION; ?>
                        WordPress Version: <?php echo get_bloginfo('version'); ?>
                        Plugin Version: 1.1
                        Timezone: <?php echo wp_timezone_string(); ?>
                    </pre>
                </div>
                <?php endif; ?>
            </div>
            <?php
        }

    public function enqueue_scripts($hook) {
        if ($hook !== 'settings_page_changelog-checker') return;

        wp_enqueue_script('jquery');
        wp_enqueue_script('changelog-checker-script', plugin_dir_url(__FILE__) . 'js/changelog-script.js', ['jquery'], '1.0', true);
        
        wp_localize_script('changelog-checker-script', 'changelogChecker', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('changelog_nonce')
        ]);
    }

    public function add_inline_styles() {
        echo '<style>
            .ai-summary {
                margin: 20px 0;
                padding: 15px;
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
            }
            .ai-summary-content {
                margin-top: 10px;
            }
            .ai-summary-content h2 {
                margin-top: 20px;
                font-size: 24px;
                color: #333;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .ai-summary-content h3 {
                font-size: 20px;
                color: #444;
                margin: 15px 0 10px;
            }
            .ai-summary-content h4 {
                font-size: 16px;
                color: #666;
                margin: 10px 0;
            }
            .ai-summary-content ul {
                margin: 10px 0 20px 20px;
                list-style-type: none;
            }
            .ai-summary-content ul li {
                margin: 5px 0;
                line-height: 1.5;
            }
            .test-email-container {
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
            #test-email-result {
                display: inline-block;
                padding: 5px 10px;
                font-weight: 500;
            }
        </style>';
    }

    public function handle_changelog_fetch() { //Comment (whole)

        check_ajax_referer('changelog_nonce', 'security');
    
        $urls = get_option('changelog_urls', []);
    
        // Enforce URL limit
    
        $urls = array_slice($urls, 0, $this->max_free_urls);
    
        if (empty($urls)) {
    
            wp_send_json_error(['message' => 'No URLs configured']);
    
            return;
    
        }
    
        $results = [];
    
        foreach ($urls as $url) {
    
            if (empty($url)) continue;
    
            $result = $this->process_changelog($url);
    
            if ($result['success']) {
    
                $results[] = [
    
                    'url' => $url,
    
                    'success' => true,
    
                    'message' => 'Changelog successfully fetched',
    
                    'content' => $result['content'] ?? '',
    
                    'ai_summary' => $result['ai_summary'] ?? ''
    
                ];
    
            } else {
    
                $results[] = [
    
                    'url' => $url,
    
                    'success' => false,
    
                    'message' => $result['message'] ?? 'Failed to fetch changelog',
    
                    'error' => $result
    
                ];
    
            }
    
        }
    
        wp_send_json_success([
    
            'message' => 'Processed ' . count($results) . ' changelogs',
    
            'results' => $results
    
        ]);
    
    }
    

// Modify handle_changelog_fetch to support multiple URLs


// Add the process_changelog function here
public function process_changelog($url) {
    // First, check if we have a recent cached summary
    $stored_summaries = get_option('changelog_summaries', []);
    $cached_summary = isset($stored_summaries[$url]) ? $stored_summaries[$url] : null;
    
    // Check if cached summary is less than 24 hours old
    if ($cached_summary && 
        (current_time('timestamp') - $cached_summary['timestamp']) < (24 * HOUR_IN_SECONDS)) {
        return [
            'success' => true,
            'content' => 'Cached content',
            'ai_summary' => $cached_summary['summary']
        ];
    }
    
    // Fetch the changelog
    $response = wp_remote_get($url, [
        'timeout' => 30,
        'sslverify' => false
    ]);
    
    if (is_wp_error($response)) {
        return [
            'success' => false,
            'message' => 'Failed to fetch changelog: ' . $response->get_error_message()
        ];
    }
    
    // Get the body of the response
    $body = wp_remote_retrieve_body($response);
    
    if (empty($body)) {
        return [
            'success' => false,
            'message' => 'Empty changelog content'
        ];
    }
    
    // Parse the HTML changelog
    $changelog_content = $this->parse_html_changelog($body);
    
    // Generate AI summary
    $ai_result = $this->summarize_with_ai($changelog_content);
    
    // Check if AI summary generation was successful
    if (!$ai_result['success']) {
        return [
            'success' => false,
            'message' => $ai_result['error'] ?? 'Failed to generate AI summary',
            'error_details' => $ai_result
        ];
    }
    
    // Store the summary
    $this->store_changelog_summary($url, $ai_result['summary']);
    
    return [
        'success' => true,
        'content' => $changelog_content,
        'ai_summary' => $ai_result['summary']
    ];
}

// Helper methods (add these if not already present)
private function format_changelog($data) {
    $html = '<div class="changelog-content">';
    
    if (is_array($data)) {
        foreach ($data as $entry) {
            $html .= '<div class="changelog-entry">';
            
            // Handle different possible structures
            if (is_array($entry)) {
                if (isset($entry['version'])) {
                    $html .= '<h3>Version: ' . esc_html($entry['version']) . '</h3>';
                }
                
                if (isset($entry['changes'])) {
                    if (is_array($entry['changes'])) {
                        $html .= '<ul>';
                        foreach ($entry['changes'] as $change) {
                            $html .= '<li>' . esc_html($change) . '</li>';
                        }
                        $html .= '</ul>';
                    } else {
                        $html .= '<p>' . esc_html($entry['changes']) . '</p>';
                    }
                }
            } elseif (is_string($entry)) {
                $html .= '<p>' . esc_html($entry) . '</p>';
            }
            
            $html .= '</div>';
        }
    } elseif (is_string($data)) {
        $html .= '<div class="changelog-entry">';
        $html .= '<p>' . esc_html($data) . '</p>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}

private function parse_html_changelog($html) {
    // Basic HTML parsing
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR);
    
    $xpath = new DOMXPath($dom);
    $changelog_elements = $xpath->query("//*[contains(@class, 'changelog') or @id='changelog']");
    
    $changelog_content = '';
    if ($changelog_elements->length > 0) {
        foreach ($changelog_elements as $element) {
            $changelog_content .= $dom->saveHTML($element);
        }
    }
    
    // If no specific changelog elements found, try to extract meaningful content
    if (empty($changelog_content)) {
        // Log the original HTML for debugging
        error_log('Original HTML: ' . substr($html, 0, 1000));
        
        // Fallback to first few paragraphs or full content
        $changelog_content = preg_match('/<p>.*?<\/p>/s', $html, $matches) 
            ? $matches[0] 
            : $html;
    }
    
    return $changelog_content ?: 'Success.';
}
private function store_changelog_summary($url, $summary) {
    $stored_summaries = get_option('changelog_summaries', []);
    $stored_summaries[$url] = [
        'summary' => $summary,
        'timestamp' => current_time('timestamp')
    ];
    update_option('changelog_summaries', $stored_summaries);
}

private function format_ai_response($text) {
    // Replace text representations with actual HTML tags
    $replacements = [
        '/^H1 /m' => '<h2>',
        '/^H2 /m' => '<h3>',
        '/^H3 /m' => '<h3>',
        '/^H4 /m' => '<h4>',
        '/ new line$/' => '</h2></h3></h4>',
        '/\n(?=-)/' => '<br>'  // Add line break before bullet points
    ];

    $formatted = preg_replace(array_keys($replacements), array_values($replacements), $text);
    
    // Wrap bullet points in a list
    $formatted = preg_replace('/(<br>- .+?)(?=<br>|$)/s', '<ul>$1</ul>', $formatted);
    
    return $formatted;
}

private function summarize_with_ai($content) {
    $api_key = get_option('gemini_api_key');
    
    if (empty($api_key)) {
        return ['error' => 'Gemini API key not configured'];
    }

    // Updated API endpoint URL
    $url = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=' . $api_key;
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => "Carefully analyze the following content. Your task is to:

                           - Provide an analysis section with:
                             <h3>Product Name: What is the product name?</h3>
                             <h4>Latest Core Version: What is the latest core/free version number, and the release date?</h4>
                             <h4>Release Date: What is the release date for the latest core version?</h4>
                             <h4>Core Release Summary:</h4> Summarize the latest core version release notes in few sentences (Highlight Key Changes, Notable Improvements, Impact Assessment, Breaking Changes).
                             <h4>Latest Pro Version: What is the latest pro/premium version number, and the release date?</h4>
                             <h4>Release Date: What is the release date for the latest pro version?</h4>
                             <h4>Pro Release Summary:</h4> Summarize the latest pro version release notes in few sentences.
                        
                        If it is NOT a changelog:
                           Respond with a clear message: <h4>NOT A CHANGELOG: This page does not appear to be a valid changelog. It may be a generic page, documentation, or unrelated content.</h4>

                        Analyze this content carefully and provide a precise response:" . $content
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.2,  // Lower temperature for more precise responses
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 1024,
        ]
    ];
    
    $args = [
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($data),
        'method' => 'POST',
        'timeout' => 30
    ];
    
    $response = wp_remote_post($url, $args);
    
    if (is_wp_error($response)) {
        return [
            'error' => 'API request failed: ' . $response->get_error_message(),
            'summary' => null
        ];
    }
    
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'error' => 'Failed to parse API response',
            'summary' => null
        ];
    }
    
    // Check for API error response
    if (isset($result['error'])) {
        return [
            'error' => 'API Error: ' . $result['error']['message'],
            'summary' => null
        ];
    }
    
    try {
        $summary = $result['candidates'][0]['content']['parts'][0]['text'];
        
        // Check if the response indicates it's not a changelog
        if (strpos($summary, 'NOT A CHANGELOG:') !== false) {
            return [
                'success' => false,
                'error' => $summary,
                'summary' => null
            ];
        }
        
        $formatted_summary = $this->format_ai_response($summary);
        
        return [
            'success' => true,
            'error' => null,
            'summary' => $formatted_summary
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Failed to extract summary: ' . $e->getMessage(),
            'summary' => null
        ];
    }
}

    /**
     * Adds weekly schedule to WordPress cron schedules
     */
    public function add_weekly_schedule($schedules) {
        $schedules['weekly'] = array(
            'interval' => 7 * 24 * 60 * 60,
            'display' => __('Once Weekly')
        );
        return $schedules;
    }

    /**
     * Calculates the timestamp for next Monday at 8 AM
     */
    private function get_next_monday_8am() {
        $date = new DateTime();
        $date->modify('next monday 8am');
        return $date->getTimestamp();
    }
    /**
     * Sends the weekly changelog email
     */
    public function send_changelog_email() {
        $urls = get_option('changelog_urls', []);
        $email = get_option('notification_email', get_option('admin_email'));
        $api_key = get_option('gemini_api_key');
        
        if (empty($urls) || empty($email) || empty($api_key)) {
            $this->log_error('Missing required settings for changelog email');
            return;
        }
        
        $all_summaries = [];
        $error_urls = [];
        
        // Process each URL
        foreach ($urls as $url) {
            if (empty($url)) continue;
            
            // Log the URL being processed
            error_log('Processing Changelog URL: ' . $url);
            
            $result = $this->process_changelog($url);
            
            if ($result['success'] && isset($result['ai_summary'])) {
                $all_summaries[] = [
                    'url' => $url,
                    'summary' => $result['ai_summary']
                ];
                
                // Log successful processing
                error_log('Successfully processed changelog for: ' . $url);
            } else {
                $error_urls[] = $url;
                $this->log_error('Failed to generate AI summary for URL', [
                    'url' => $url,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
            }
        }
        
        // Combine summaries if we have any
        if (!empty($all_summaries)) {
            $subject = 'Weekly Changelog AI Summaries';
            
            $message = '<html><body>';
            $message .= '<h1>Weekly Changelog Summaries</h1>';
            
            foreach ($all_summaries as $index => $changelog) {
                $message .= '<div style="margin-bottom: 20px;">';
                $message .= '<h2>Changelog #' . ($index + 1) . ': ' . esc_html($changelog['url']) . '</h2>';
                $message .= $changelog['summary'];
                $message .= '</div>';
            }
            
            // Add error information if any
            if (!empty($error_urls)) {
                $message .= '<h2>Errors</h2>';
                $message .= '<p>Failed to process the following URLs:</p>';
                $message .= '<ul>';
                foreach ($error_urls as $error_url) {
                    $message .= '<li>' . esc_html($error_url) . '</li>';
                }
                $message .= '</ul>';
            }
            
            $message .= '</body></html>';
            
            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: WordPress <' . get_option('admin_email') . '>'
            );
            
            $sent = wp_mail($email, $subject, $message, $headers);
            
            if (!$sent) {
                $this->log_error('Failed to send changelog email', [
                    'email' => $email
                ]);
            }
            
            // Log the entire process
            error_log('Changelog Email Sent. Processed URLs: ' . count($all_summaries));
        } else {
            error_log('No changelog summaries to send');
        }
    }

    /**
     * Cleanup on plugin deactivation
     */
    public function clear_old_changelog_summaries() {
        $stored_summaries = get_option('changelog_summaries', []);
        $current_time = current_time('timestamp');
        
        // Remove summaries older than 7 days
        foreach ($stored_summaries as $url => $summary) {
            if (($current_time - $summary['timestamp']) > (7 * DAY_IN_SECONDS)) {
                unset($stored_summaries[$url]);
            }
        }
        
        update_option('changelog_summaries', $stored_summaries);
    }
        // Add this method near the end of the class, before the closing bracket
    public function deactivate() {
        // Clear scheduled hooks
        wp_clear_scheduled_hook('changelog_weekly_email');
        wp_clear_scheduled_hook('clear_old_changelog_summaries');
        
        // Delete stored options
        delete_option('changelog_summaries');
    }

}

// Add this after the class definition
register_deactivation_hook(__FILE__, function() {
    $changelog_checker = new ChangelogChecker();
    $changelog_checker->deactivate();
});

// Initialize the plugin
add_action('plugins_loaded', function() {
    new ChangelogChecker();
});