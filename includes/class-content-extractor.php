<?php
/**
 * Multi-strategy content extractor for changelog pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AICS_Content_Extractor {

    /**
     * Maximum characters to return to stay within AI token limits.
     */
    const MAX_CONTENT_LENGTH = 15000;

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

    /**
     * Tags to strip before extracting body text.
     */
    private static $noise_tags = [
        'script', 'style', 'nav', 'header', 'footer',
        'noscript', 'iframe', 'svg', 'form',
    ];

    /**
     * Classes/IDs that are typically non-content.
     */
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
     * Extract meaningful content from an HTML page.
     *
     * @param string $html Raw HTML.
     * @return string Extracted text/HTML content.
     */
    public static function extract( $html ) {
        if ( empty( $html ) ) {
            return '';
        }

        $dom = new DOMDocument();
        @$dom->loadHTML(
            mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ),
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        $xpath = new DOMXPath( $dom );

        // Strategy A: Try changelog-specific selectors.
        $content = self::try_selectors( $dom, $xpath );
        if ( self::is_meaningful( $content ) ) {
            return self::truncate( $content );
        }

        // Strategy B: Strip noise and extract body text.
        $content = self::extract_clean_body( $dom, $xpath );
        if ( self::is_meaningful( $content ) ) {
            return self::truncate( $content );
        }

        // Strategy C: Fall back to raw body text.
        $body = $xpath->query( '//body' );
        if ( $body->length > 0 ) {
            $content = $body->item( 0 )->textContent;
            $content = self::normalize_whitespace( $content );
            return self::truncate( $content );
        }

        // Last resort: return cleaned HTML.
        return self::truncate( strip_tags( $html ) );
    }

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
        // Remove noise tags.
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

        // Remove noise selectors.
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
     * Check if extracted content is long enough to be meaningful.
     */
    private static function is_meaningful( $content ) {
        $text = strip_tags( $content );
        $text = self::normalize_whitespace( $text );
        return strlen( $text ) >= 100;
    }

    /**
     * Collapse whitespace.
     */
    private static function normalize_whitespace( $text ) {
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }

    /**
     * Truncate content to max length.
     */
    private static function truncate( $content ) {
        if ( strlen( $content ) > self::MAX_CONTENT_LENGTH ) {
            $content = substr( $content, 0, self::MAX_CONTENT_LENGTH );
            // Don't cut in the middle of an HTML tag.
            $last_open = strrpos( $content, '<' );
            $last_close = strrpos( $content, '>' );
            if ( $last_open !== false && ( $last_close === false || $last_open > $last_close ) ) {
                $content = substr( $content, 0, $last_open );
            }
        }
        return $content;
    }
}
