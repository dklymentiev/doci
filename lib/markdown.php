<?php
/**
 * DOCI - Markdown Processing
 *
 * Functions for parsing and transforming markdown content.
 * Requires Parsedown and ParsedownExtended to be loaded.
 */

/**
 * Parse markdown content to HTML using ParsedownExtended
 *
 * @param string $markdown Markdown content
 * @return string HTML content
 */
function parse_markdown(string $markdown): string {
    $parsedown = new ParsedownExtended();
    $parsedown->setSafeMode(true); // Escape HTML for security
    $html = $parsedown->text($markdown);

    // Process footnotes [^N] and [^N]:
    $html = process_footnotes($html);

    // Allow <br> tags for line breaks in tables
    $html = str_replace('&lt;br&gt;', '<br>', $html);

    // Allow <mark> tags for highlighting (re-enable after safe mode escaping)
    $html = preg_replace('/&lt;mark&gt;(.+?)&lt;\/mark&gt;/s', '<mark>$1</mark>', $html);

    // Allow <span id="..."> for custom anchors
    $html = preg_replace('/&lt;span id="([a-z0-9\-]+)"&gt;&lt;\/span&gt;/', '<span id="$1"></span>', $html);

    // Style thread links (marked with ~ prefix) as chips: [~text](/guid) -> chip
    $html = preg_replace_callback(
        '/<a href="(\/[^"]+)">~(.+?)<\/a>/',
        function($matches) {
            $href = $matches[1];
            $text = $matches[2];
            return '<a href="' . $href . '" class="thread-chip">' . $text . '</a>';
        },
        $html
    );

    // Replace AI pending placeholder with loading animation
    $html = preg_replace(
        '/&lt;!-- ai-pending:([a-z]+) --&gt;/',
        '<div class="ai-loading" data-model="$1">
            <div class="ai-loading-bar"></div>
            <div class="ai-loading-text">AI is thinking...</div>
        </div>',
        $html
    );

    // Add target="_blank" and rel="noopener noreferrer" to external links
    $html = preg_replace(
        '/<a href="(https?:\/\/[^"]+)"/',
        '<a href="$1" target="_blank" rel="noopener noreferrer"',
        $html
    );

    return $html;
}

/**
 * Process footnote markers and definitions
 * Converts [^N] to clickable superscript links
 * Converts [^N]: to anchored definitions (each on new line)
 *
 * @param string $html HTML content
 * @return string HTML with processed footnotes
 */
function process_footnotes(string $html): string {
    // Convert [^N] in text to clickable superscript (but not [^N]: definitions)
    $html = preg_replace(
        '/\[\^(\d+)\](?!:)/',
        '<sup class="footnote-ref"><a href="#fn-$1" id="fnref-$1" title="See footnote $1">$1</a></sup>',
        $html
    );

    // Convert [^N]: to anchored definition with line break before it
    $html = preg_replace(
        '/\[\^(\d+)\]:/',
        '<br><span class="footnote-def" id="fn-$1"><a href="#fnref-$1" title="Back to reference">^$1</a>:</span>',
        $html
    );

    // Remove the first <br> in footnotes section (after ## Sources or similar)
    $html = preg_replace(
        '/(<h2[^>]*>Sources<\/h2>\s*<p>)<br>/',
        '$1',
        $html
    );

    // Also handle case where <br> is right after <p>
    $html = preg_replace(
        '/<p><br>/',
        '<p>',
        $html
    );

    return $html;
}

/**
 * Detect whether document content is a full HTML page rather than Markdown.
 *
 * Sniffs the first non-whitespace bytes for an HTML5 doctype or <html> tag.
 * HTML documents are rendered in a sandboxed iframe instead of being escaped
 * through Parsedown SafeMode.
 *
 * @param string $content Raw document content
 * @return bool
 */
function doci_is_html_document(string $content): bool {
    // Drop a leading UTF-8 BOM and surrounding whitespace before sniffing.
    $head = preg_replace('/^\xEF\xBB\xBF/', '', ltrim($content));
    return (bool) preg_match('~^<!doctype\s+html|^<html[\s>]~i', $head);
}

/**
 * Render an HTML document inside a sandboxed iframe.
 *
 * The raw HTML is embedded via the iframe `srcdoc` attribute, so it inherits
 * DOCI's CSP (script-src 'self' 'unsafe-inline') -- inline HTML/CSS/JS runs.
 * The sandbox allows scripts but omits `allow-same-origin`, so the document
 * runs in an opaque origin and cannot read DOCI's session cookie or the
 * parent DOM. X-Frame-Options does not apply to srcdoc frames.
 *
 * @param string $html Raw HTML document content
 * @return string HTML fragment containing the iframe
 */
function render_html_document(string $html): string {
    $srcdoc = htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
    return '<iframe class="doci-html-doc"'
         . ' sandbox="allow-scripts allow-popups allow-forms allow-modals"'
         . ' referrerpolicy="no-referrer"'
         . ' srcdoc="' . $srcdoc . '"'
         . ' title="HTML document"></iframe>';
}

/**
 * Extract page title from markdown content (first H1) or use filename
 *
 * @param string $markdown Markdown content
 * @param string $fallback Fallback title
 * @return string Page title
 */
function extract_title(string $markdown, string $fallback): string {
    // Try to find first H1 heading
    if (preg_match('/^#\s+(.+)$/m', $markdown, $matches)) {
        return trim($matches[1]);
    }

    // HTML documents: fall back to the <title> tag
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $markdown, $matches)) {
        $htmlTitle = trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
        if ($htmlTitle !== '') {
            return $htmlTitle;
        }
    }

    // Use fallback (filename)
    return ucwords(str_replace(['-', '_'], ' ', $fallback));
}
