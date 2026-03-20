<?php
/**
 * Auto-detect changelog URLs from a given domain.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AICS_Auto_Detect {

    private static $common_paths = [
        '/changelog',
        '/changelog/',
        '/changelogs',
        '/release-notes',
        '/release-notes/',
        '/releases',
        '/whats-new',
        '/what-is-new',
        '/updates',
        '/version-history',
    ];

    public function __construct() {
        add_action( 'wp_ajax_aics_detect_changelog', [ $this, 'handle_detect' ] );
    }

    /**
     * AJAX: Try to find a changelog page on a given domain.
     */
    public function handle_detect() {
        check_ajax_referer( 'aics_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'changelog-tracker' ) ] );
        }

        $domain = isset( $_POST['domain'] ) ? esc_url_raw( trim( $_POST['domain'] ) ) : '';
        if ( empty( $domain ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a domain URL.', 'changelog-tracker' ) ] );
        }

        // Normalize domain.
        $parsed = wp_parse_url( $domain );
        $base   = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? $domain );

        $found = [];

        // Try common changelog paths.
        foreach ( self::$common_paths as $path ) {
            $url      = $base . $path;
            $response = wp_remote_head( $url, [ 'timeout' => 5, 'redirection' => 3, 'sslverify' => false ] );

            if ( ! is_wp_error( $response ) ) {
                $code = wp_remote_retrieve_response_code( $response );
                if ( $code >= 200 && $code < 400 ) {
                    $found[] = $url;
                }
            }
        }

        // Try sitemap.xml for changelog references.
        $sitemap_url = $base . '/sitemap.xml';
        $sitemap     = wp_remote_get( $sitemap_url, [ 'timeout' => 10, 'sslverify' => false ] );
        if ( ! is_wp_error( $sitemap ) ) {
            $body     = wp_remote_retrieve_body( $sitemap );
            $keywords = [ 'changelog', 'release-notes', 'releases', 'whats-new', 'updates' ];
            foreach ( $keywords as $kw ) {
                if ( preg_match_all( '/<loc>([^<]*' . preg_quote( $kw, '/' ) . '[^<]*)<\/loc>/i', $body, $matches ) ) {
                    $found = array_merge( $found, $matches[1] );
                }
            }
        }

        $found = array_unique( $found );

        if ( empty( $found ) ) {
            wp_send_json_error( [
                /* translators: %s: domain base URL */
                'message' => sprintf( __( 'No changelog pages found on %s.', 'changelog-tracker' ), esc_html( $base ) ),
            ] );
        }

        wp_send_json_success( [
            /* translators: %d: number of changelog pages found */
            'message' => sprintf( __( 'Found %d potential changelog page(s).', 'changelog-tracker' ), count( $found ) ),
            'urls'    => array_values( $found ),
        ] );
    }
}

new AICS_Auto_Detect();
