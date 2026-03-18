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

    public static function get_default_prompt() {
        return self::$default_prompt;
    }

    private static function get_prompt( $content ) {
        $prompt = apply_filters( 'aics_ai_prompt', self::$default_prompt, $content );
        return $prompt . "\n\n" . $content;
    }

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
                'error'   => ucfirst( $provider ) . ' API key not configured.',
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
        $url = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=' . $api_key;

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
                'maxOutputTokens' => 1024,
            ],
        ];

        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $data ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            return self::error( 'Gemini request failed: ' . $response->get_error_message() );
        }

        $body   = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );

        if ( isset( $result['error'] ) ) {
            return self::error( 'Gemini API error: ' . $result['error']['message'] );
        }

        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ( ! $text ) {
            return self::error( 'Gemini returned empty response.' );
        }

        return self::parse_ai_text( $text );
    }

    /**
     * OpenAI (gpt-4o-mini).
     */
    private static function summarize_with_openai( $content, $api_key ) {
        $url = 'https://api.openai.com/v1/chat/completions';

        $data = [
            'model'       => 'gpt-4o-mini',
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => self::get_prompt( $content ),
                ],
            ],
            'temperature' => 0.2,
            'max_tokens'  => 1024,
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
            return self::error( 'OpenAI request failed: ' . $response->get_error_message() );
        }

        $body   = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );

        if ( isset( $result['error'] ) ) {
            return self::error( 'OpenAI API error: ' . ( $result['error']['message'] ?? 'Unknown error' ) );
        }

        $text = $result['choices'][0]['message']['content'] ?? null;
        if ( ! $text ) {
            return self::error( 'OpenAI returned empty response.' );
        }

        return self::parse_ai_text( $text );
    }

    /**
     * Anthropic Claude (claude-sonnet-4-20250514).
     */
    private static function summarize_with_claude( $content, $api_key ) {
        $url = 'https://api.anthropic.com/v1/messages';

        $data = [
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 1024,
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
            return self::error( 'Claude request failed: ' . $response->get_error_message() );
        }

        $body   = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );

        if ( isset( $result['error'] ) ) {
            return self::error( 'Claude API error: ' . ( $result['error']['message'] ?? 'Unknown error' ) );
        }

        $text = $result['content'][0]['text'] ?? null;
        if ( ! $text ) {
            return self::error( 'Claude returned empty response.' );
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

        $result = [
            'success' => true,
            'error'   => null,
            'summary' => self::format_ai_response( $text ),
        ];

        return apply_filters( 'aics_ai_result', $result, $text );
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
            return [ 'success' => false, 'message' => 'API key is empty.' ];
        }

        switch ( $provider ) {
            case 'openai':
                $response = wp_remote_get( 'https://api.openai.com/v1/models', [
                    'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
                    'timeout' => 15,
                ] );
                break;

            case 'claude':
                $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
                    'headers' => [
                        'Content-Type'      => 'application/json',
                        'x-api-key'         => $api_key,
                        'anthropic-version' => '2023-06-01',
                    ],
                    'body'    => wp_json_encode( [
                        'model'      => 'claude-sonnet-4-20250514',
                        'max_tokens' => 10,
                        'messages'   => [ [ 'role' => 'user', 'content' => 'Hi' ] ],
                    ] ),
                    'timeout' => 15,
                ] );
                break;

            case 'gemini':
            default:
                $url      = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=' . $api_key;
                $response = wp_remote_post( $url, [
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
            return [ 'success' => true, 'message' => 'API key is valid.' ];
        }

        return [ 'success' => false, 'message' => 'API returned status ' . $code ];
    }
}
