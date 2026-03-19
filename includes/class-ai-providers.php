<?php
/**
 * AI provider abstraction — Gemini, OpenAI, Claude.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AICS_AI_Providers {

    /**
     * Shared prompt used across all providers.
     */
    private static $default_prompt = "Carefully analyze the following content. Your task is to:

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

Analyze this content carefully and provide a precise response:";

    private static function get_prompt( $content ) {
        return self::$default_prompt . "\n\n" . $content;
    }

    /**
     * Model preferences per provider (newest/best first).
     */
    private static $model_preferences = [
        'gemini' => [ 'gemini-2.5-flash', 'gemini-2.0-flash' ],
        'openai' => [ 'gpt-4o-mini', 'gpt-4o' ],
        'claude' => [ 'claude-sonnet-4-20250514' ],
    ];

    /**
     * Available providers.
     */
    public static function get_providers() {
        return [
            'gemini'  => 'Google Gemini',
            'openai'  => 'OpenAI',
            'claude'  => 'Anthropic Claude',
        ];
    }

    /**
     * Resolve the best available model for a provider.
     *
     * Queries provider List Models APIs so the plugin auto-adapts
     * when models are deprecated.
     */
    private static function resolve_model( $provider, $api_key ) {
        $available   = [];
        $preferences = self::$model_preferences[ $provider ] ?? [];

        switch ( $provider ) {
            case 'gemini':
                $available = self::fetch_gemini_models( $api_key );
                break;
            case 'openai':
                $available = self::fetch_openai_models( $api_key );
                break;
            case 'claude':
                // Anthropic has no public list-models endpoint.
                break;
        }

        if ( ! empty( $available ) ) {
            foreach ( $preferences as $pref ) {
                if ( in_array( $pref, $available, true ) ) {
                    return $pref;
                }
            }

            if ( 'gemini' === $provider ) {
                foreach ( $available as $m ) {
                    if ( strpos( $m, 'flash' ) !== false ) {
                        return $m;
                    }
                }
            }

            if ( 'openai' === $provider ) {
                foreach ( $available as $m ) {
                    if ( strpos( $m, 'gpt-4o' ) !== false ) {
                        return $m;
                    }
                }
            }
        }

        return $preferences[0] ?? '';
    }

    /**
     * Fetch available Gemini models that support generateContent.
     */
    private static function fetch_gemini_models( $api_key ) {
        $response = wp_remote_get(
            'https://generativelanguage.googleapis.com/v1/models?key=' . $api_key,
            [ 'timeout' => 15 ]
        );
        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $models = [];
        foreach ( $body['models'] ?? [] as $m ) {
            $methods = $m['supportedGenerationMethods'] ?? [];
            if ( in_array( 'generateContent', $methods, true ) ) {
                $models[] = str_replace( 'models/', '', $m['name'] );
            }
        }
        return $models;
    }

    /**
     * Fetch available OpenAI models.
     */
    private static function fetch_openai_models( $api_key ) {
        $response = wp_remote_get( 'https://api.openai.com/v1/models', [
            'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
            'timeout' => 15,
        ] );
        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $models = [];
        foreach ( $body['data'] ?? [] as $m ) {
            $models[] = $m['id'];
        }
        return $models;
    }

    /**
     * Get the configured max output tokens.
     */
    private static function get_max_tokens() {
        return (int) get_option( 'aics_max_tokens', 2048 );
    }

    /**
     * Dispatch to the correct provider.
     *
     * @param string $content    Extracted page content.
     * @param string $provider   Provider key (gemini|openai|claude).
     * @param string $api_key    API key for the provider.
     * @return array { success: bool, summary: string|null, error: string|null }
     */
    public static function summarize( $content, $provider, $api_key ) {
        if ( empty( $api_key ) ) {
            return [
                'success' => false,
                /* translators: %s: AI provider name (Gemini, OpenAI, Claude) */
                'error'   => sprintf( __( '%s API key not configured.', 'ai-changelog-summary' ), ucfirst( $provider ) ),
                'summary' => null,
            ];
        }

        switch ( $provider ) {
            case 'openai':
                return self::summarize_with_openai( $content, $api_key );
            case 'claude':
                return self::summarize_with_claude( $content, $api_key );
            case 'gemini':
            default:
                return self::summarize_with_gemini( $content, $api_key );
        }
    }

    /**
     * Google Gemini.
     */
    private static function summarize_with_gemini( $content, $api_key ) {
        $model = self::resolve_model( 'gemini', $api_key );
        $url   = 'https://generativelanguage.googleapis.com/v1/models/' . $model . ':generateContent?key=' . $api_key;

        $data = [
            'contents' => [
                [
                    'parts' => [
                        [ 'text' => self::get_prompt( $content ) ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'    => 0.2,
                'topK'           => 40,
                'topP'           => 0.95,
                'maxOutputTokens' => self::get_max_tokens(),
            ],
        ];

        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $data ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            /* translators: %s: error message from the HTTP request */
            return self::error( sprintf( __( 'Gemini request failed: %s', 'ai-changelog-summary' ), $response->get_error_message() ) );
        }

        $body   = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );

        if ( isset( $result['error'] ) ) {
            /* translators: %s: error message from Gemini API */
            return self::error( sprintf( __( 'Gemini API error: %s', 'ai-changelog-summary' ), ( $result['error']['message'] ?? '' ) ) );
        }

        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ( ! $text ) {
            return self::error( __( 'Gemini returned empty response.', 'ai-changelog-summary' ) );
        }

        return self::parse_ai_text( $text );
    }

    /**
     * OpenAI (gpt-4o-mini).
     */
    private static function summarize_with_openai( $content, $api_key ) {
        $model = self::resolve_model( 'openai', $api_key );
        $url   = 'https://api.openai.com/v1/chat/completions';

        $data = [
            'model'       => $model,
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => self::get_prompt( $content ),
                ],
            ],
            'temperature' => 0.2,
            'max_tokens'  => self::get_max_tokens(),
        ];

        $response = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => wp_json_encode( $data ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            /* translators: %s: error message from the HTTP request */
            return self::error( sprintf( __( 'OpenAI request failed: %s', 'ai-changelog-summary' ), $response->get_error_message() ) );
        }

        $body   = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );

        if ( isset( $result['error'] ) ) {
            /* translators: %s: error message from OpenAI API */
            return self::error( sprintf( __( 'OpenAI API error: %s', 'ai-changelog-summary' ), ( $result['error']['message'] ?? __( 'Unknown error', 'ai-changelog-summary' ) ) ) );
        }

        $text = $result['choices'][0]['message']['content'] ?? null;
        if ( ! $text ) {
            return self::error( __( 'OpenAI returned empty response.', 'ai-changelog-summary' ) );
        }

        return self::parse_ai_text( $text );
    }

    /**
     * Anthropic Claude (claude-sonnet-4-20250514).
     */
    private static function summarize_with_claude( $content, $api_key ) {
        $model = self::resolve_model( 'claude', $api_key );
        $url   = 'https://api.anthropic.com/v1/messages';

        $data = [
            'model'      => $model,
            'max_tokens' => self::get_max_tokens(),
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => self::get_prompt( $content ),
                ],
            ],
        ];

        $response = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body'    => wp_json_encode( $data ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            /* translators: %s: error message from the HTTP request */
            return self::error( sprintf( __( 'Claude request failed: %s', 'ai-changelog-summary' ), $response->get_error_message() ) );
        }

        $body   = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );

        if ( isset( $result['error'] ) ) {
            /* translators: %s: error message from Claude API */
            return self::error( sprintf( __( 'Claude API error: %s', 'ai-changelog-summary' ), ( $result['error']['message'] ?? __( 'Unknown error', 'ai-changelog-summary' ) ) ) );
        }

        $text = $result['content'][0]['text'] ?? null;
        if ( ! $text ) {
            return self::error( __( 'Claude returned empty response.', 'ai-changelog-summary' ) );
        }

        return self::parse_ai_text( $text );
    }

    /**
     * Parse AI response text and check for NOT A CHANGELOG.
     */
    private static function parse_ai_text( $text ) {
        if ( strpos( $text, 'NOT A CHANGELOG' ) !== false ) {
            return [
                'success' => false,
                'error'   => $text,
                'summary' => null,
            ];
        }

        return [
            'success' => true,
            'error'   => null,
            'summary' => self::format_ai_response( $text ),
        ];
    }

    /**
     * Format raw AI text into HTML.
     */
    private static function format_ai_response( $text ) {
        $replacements = [
            '/^H1 /m'     => '<h2>',
            '/^H2 /m'     => '<h3>',
            '/^H3 /m'     => '<h3>',
            '/^H4 /m'     => '<h4>',
            '/ new line$/' => '</h2></h3></h4>',
            '/\n(?=-)/'   => '<br>',
        ];

        $formatted = preg_replace( array_keys( $replacements ), array_values( $replacements ), $text );
        $formatted = preg_replace( '/(<br>- .+?)(?=<br>|$)/s', '<ul>$1</ul>', $formatted );

        return $formatted;
    }

    /**
     * Helper to build error response.
     */
    private static function error( $message ) {
        return [
            'success' => false,
            'error'   => $message,
            'summary' => null,
        ];
    }

    /**
     * Test that an API key works for the given provider.
     *
     * @param string $provider Provider key.
     * @param string $api_key  API key.
     * @return array { success: bool, message: string }
     */
    public static function test_api_key( $provider, $api_key ) {
        if ( empty( $api_key ) ) {
            return [ 'success' => false, 'message' => __( 'API key is empty.', 'ai-changelog-summary' ) ];
        }

        switch ( $provider ) {
            case 'openai':
                $response = wp_remote_get( 'https://api.openai.com/v1/models', [
                    'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
                    'timeout' => 15,
                ] );
                break;

            case 'claude':
                $claude_model = self::resolve_model( 'claude', $api_key );
                $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
                    'headers' => [
                        'Content-Type'      => 'application/json',
                        'x-api-key'         => $api_key,
                        'anthropic-version' => '2023-06-01',
                    ],
                    'body'    => wp_json_encode( [
                        'model'      => $claude_model,
                        'max_tokens' => 10,
                        'messages'   => [ [ 'role' => 'user', 'content' => 'Hi' ] ],
                    ] ),
                    'timeout' => 15,
                ] );
                break;

            case 'gemini':
            default:
                $gemini_model = self::resolve_model( 'gemini', $api_key );
                $url          = 'https://generativelanguage.googleapis.com/v1/models/' . $gemini_model . ':generateContent?key=' . $api_key;
                $response     = wp_remote_post( $url, [
                    'headers' => [ 'Content-Type' => 'application/json' ],
                    'body'    => wp_json_encode( [
                        'contents' => [ [ 'parts' => [ [ 'text' => 'Test' ] ] ] ],
                    ] ),
                    'timeout' => 15,
                ] );
                break;
        }

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            return [ 'success' => true, 'message' => __( 'API key is valid.', 'ai-changelog-summary' ) ];
        }

        /* translators: %d: HTTP status code */
        return [ 'success' => false, 'message' => sprintf( __( 'API returned status %d', 'ai-changelog-summary' ), $code ) ];
    }
}
