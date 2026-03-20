<?php
/**
 * Multi-strategy content extractor for changelog pages.
 *
 * Strategy 1: Jina Reader API — converts any URL to clean markdown.
 * Strategy 2: Local DOMDocument — XPath selectors + noise stripping.
 * Strategy 3: Raw body text fallback.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AICS_Content_Extractor {

    /**
     * Maximum characters to return to stay within AI token limits.
     */
    const MAX_CONTENT_LENGTH = 30000;

    /**
     * Max version entries to keep per section in a changelog.
     */
    const MAX_VERSIONS_PER_SECTION = 5;

    /**
     * Extract content from a URL using the best available strategy.
     *
     * @param string $html Raw HTML (used for local fallback).
     * @param string $url  Original URL (used for Jina Reader).
     * @return string Extracted content (markdown or HTML).
     */
    public static function extract( $html, $url = '' ) {
        // Strategy 1: Jina Reader API — clean markdown from any page.
        if ( ! empty( $url ) ) {
            $content = self::extract_via_jina( $url );
            if ( self::is_meaningful( $content ) ) {
                $content = self::trim_changelog_sections( $content );
                return self::truncate( $content );
            }
        }

        // Strategy 2: Local DOMDocument extraction.
        if ( ! empty( $html ) ) {
            $content = self::extract_from_html( $html );
            if ( ! empty( $content ) ) {
                return $content;
            }
        }

        return '';
    }

    /**
     * Fetch clean markdown via Jina Reader API.
     *
     * @param string $url The page URL.
     * @return string Markdown content or empty on failure.
     */
    private static function extract_via_jina( $url ) {
        $jina_url = 'https://r.jina.ai/' . $url;

        $response = wp_remote_get( $jina_url, [
            'timeout'    => 30,
            'headers'    => [
                'Accept'     => 'text/plain',
                'x-no-cache' => 'true',
            ],
            'user-agent' => 'Changelog Tracker WordPress Plugin',
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return '';
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return '';
        }

        // Jina returns markdown with a metadata header — strip it.
        $body = self::strip_jina_metadata( $body );

        return $body;
    }

    /**
     * Strip Jina Reader metadata header (Title:, URL:, etc.) from response.
     */
    private static function strip_jina_metadata( $text ) {
        // Jina prepends lines like "Title: ...", "URL Source: ...", "Markdown Content:".
        // Find where the actual content starts.
        $lines = explode( "\n", $text );
        $start = 0;

        for ( $i = 0, $len = min( 20, count( $lines ) ); $i < $len; $i++ ) {
            $line = trim( $lines[ $i ] );
            if ( preg_match( '/^(Title|URL Source|Markdown Content)\s*:/i', $line ) ) {
                $start = $i + 1;
            } elseif ( $start > 0 && $line === '' ) {
                $start = $i + 1;
                break;
            }
        }

        return implode( "\n", array_slice( $lines, $start ) );
    }

    /**
     * Local HTML-based extraction (fallback).
     */
    private static function extract_from_html( $html ) {
        $dom = new DOMDocument();
        @$dom->loadHTML(
            mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ),
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        $xpath = new DOMXPath( $dom );

        // Try changelog-specific selectors.
        $content = self::try_selectors( $dom, $xpath );
        if ( self::is_meaningful( $content ) ) {
            return self::truncate( $content );
        }

        // Strip noise and extract body text.
        $content = self::extract_clean_body( $dom, $xpath );
        if ( self::is_meaningful( $content ) ) {
            return self::truncate( $content );
        }

        // Fall back to raw body text.
        $body = $xpath->query( '//body' );
        if ( $body->length > 0 ) {
            $content = $body->item( 0 )->textContent;
            $content = self::normalize_whitespace( $content );
            return self::truncate( $content );
        }

        return self::truncate( strip_tags( $html ) );
    }

    /**
     * Selectors likely to contain changelog content (XPath).
     */
    private static $changelog_selectors = [
        "//*[contains(@class, 'changelog')]",
        "//*[@id='changelog']",
        "//*[contains(@class, 'release-notes')]",
        "//*[contains(@class, 'releases')]",
        "//*[contains(@class, 'versions')]",
        "//*[contains(@class, 'whats-new')]",
        "//*[contains(@class, 'update-log')]",
        "//*[contains(@class, 'entry-content')]",
        "//*[contains(@class, 'wp-block-post-content')]",
        "//*[contains(@class, 'markdown-body')]",
        "//article",
        "//main",
        "//*[@role='main']",
    ];

    private static $noise_tags = [
        'script', 'style', 'nav', 'header', 'footer',
        'noscript', 'iframe', 'svg', 'form',
    ];

    private static $noise_selectors = [
        "//*[contains(@class, 'menu')]",
        "//*[contains(@class, 'nav')]",
        "//*[contains(@class, 'sidebar')]",
        "//*[contains(@class, 'footer')]",
        "//*[contains(@class, 'header')]",
        "//*[contains(@class, 'breadcrumb')]",
        "//*[contains(@class, 'cookie')]",
        "//*[contains(@class, 'popup')]",
        "//*[contains(@class, 'modal')]",
        "//*[contains(@class, 'advertisement')]",
        "//*[contains(@class, 'widget')]",
        "//*[contains(@id, 'sidebar')]",
        "//*[contains(@id, 'footer')]",
        "//*[contains(@id, 'header')]",
        "//*[contains(@id, 'menu')]",
        "//*[contains(@id, 'nav')]",
    ];

    /**
     * Try changelog-specific selectors and return combined HTML.
     */
    private static function try_selectors( $dom, $xpath ) {
        foreach ( self::$changelog_selectors as $selector ) {
            $nodes = $xpath->query( $selector );
            if ( $nodes->length > 0 ) {
                $html = '';
                foreach ( $nodes as $node ) {
                    $html .= $dom->saveHTML( $node );
                }
                return $html;
            }
        }
        return '';
    }

    /**
     * Strip noise elements and return cleaned body content.
     */
    private static function extract_clean_body( $dom, $xpath ) {
        foreach ( self::$noise_tags as $tag ) {
            $elements = $dom->getElementsByTagName( $tag );
            $to_remove = [];
            foreach ( $elements as $el ) {
                $to_remove[] = $el;
            }
            foreach ( $to_remove as $el ) {
                if ( $el->parentNode ) {
                    $el->parentNode->removeChild( $el );
                }
            }
        }

        foreach ( self::$noise_selectors as $selector ) {
            $nodes = $xpath->query( $selector );
            $to_remove = [];
            foreach ( $nodes as $node ) {
                $to_remove[] = $node;
            }
            foreach ( $to_remove as $node ) {
                if ( $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }

        $body = $xpath->query( '//body' );
        if ( $body->length > 0 ) {
            return $dom->saveHTML( $body->item( 0 ) );
        }

        return '';
    }

    /**
     * Trim long changelogs by keeping only the latest N versions per section.
     *
     * Detects markdown sections (## headings) and version entries within them.
     * This ensures multi-column changelogs (e.g. Free + Pro) both fit within
     * the character limit instead of the Pro section being truncated entirely.
     */
    private static function trim_changelog_sections( $content ) {
        if ( strlen( $content ) <= self::MAX_CONTENT_LENGTH ) {
            return $content;
        }

        $lines    = explode( "\n", $content );
        $sections = [];
        $current  = [ 'header' => '', 'versions' => [], 'preamble' => [] ];
        $in_version = false;
        $version_lines = [];

        foreach ( $lines as $line ) {
            // Detect a major section header (## ProductName, not a version number).
            if ( preg_match( '/^## (?!v?\d)(.+)/i', $line ) ) {
                // Save previous version block if any.
                if ( $in_version && ! empty( $version_lines ) ) {
                    $current['versions'][] = $version_lines;
                    $version_lines = [];
                }
                // Save previous section.
                if ( ! empty( $current['header'] ) || ! empty( $current['versions'] ) || ! empty( $current['preamble'] ) ) {
                    $sections[] = $current;
                }
                $current    = [ 'header' => $line, 'versions' => [], 'preamble' => [] ];
                $in_version = false;
                continue;
            }

            // Detect a version entry (## v1.2.3 or ## 1.2.3).
            if ( preg_match( '/^## v?\d/i', $line ) ) {
                if ( $in_version && ! empty( $version_lines ) ) {
                    $current['versions'][] = $version_lines;
                }
                $version_lines = [ $line ];
                $in_version    = true;
                continue;
            }

            if ( $in_version ) {
                $version_lines[] = $line;
            } else {
                $current['preamble'][] = $line;
            }
        }

        // Flush last version and section.
        if ( $in_version && ! empty( $version_lines ) ) {
            $current['versions'][] = $version_lines;
        }
        $sections[] = $current;

        // If no clear sections with versions detected, return as-is.
        $has_versions = false;
        foreach ( $sections as $s ) {
            if ( count( $s['versions'] ) > self::MAX_VERSIONS_PER_SECTION ) {
                $has_versions = true;
                break;
            }
        }
        if ( ! $has_versions ) {
            return $content;
        }

        // Rebuild with only the latest N versions per section.
        $output = [];
        foreach ( $sections as $s ) {
            if ( ! empty( $s['header'] ) ) {
                $output[] = $s['header'];
            }
            if ( ! empty( $s['preamble'] ) ) {
                $output[] = implode( "\n", $s['preamble'] );
            }
            $kept = array_slice( $s['versions'], 0, self::MAX_VERSIONS_PER_SECTION );
            foreach ( $kept as $v ) {
                $output[] = implode( "\n", $v );
            }
        }

        return implode( "\n", $output );
    }

    private static function is_meaningful( $content ) {
        $text = strip_tags( $content );
        $text = self::normalize_whitespace( $text );
        return strlen( $text ) >= 100;
    }

    private static function normalize_whitespace( $text ) {
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }

    private static function truncate( $content ) {
        if ( strlen( $content ) > self::MAX_CONTENT_LENGTH ) {
            $content = substr( $content, 0, self::MAX_CONTENT_LENGTH );
            // Don't cut in the middle of a markdown heading or HTML tag.
            $last_newline = strrpos( $content, "\n" );
            if ( $last_newline !== false && $last_newline > self::MAX_CONTENT_LENGTH - 500 ) {
                $content = substr( $content, 0, $last_newline );
            } else {
                $last_open = strrpos( $content, '<' );
                $last_close = strrpos( $content, '>' );
                if ( $last_open !== false && ( $last_close === false || $last_open > $last_close ) ) {
                    $content = substr( $content, 0, $last_open );
                }
            }
        }
        return $content;
    }
}
