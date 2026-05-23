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
    // Extract {{embed: path/to.html [| height=320]}} shortcodes BEFORE
    // Parsedown's safe-mode strips HTML. Replace each with a stable
    // placeholder; after Parsedown runs, expand the placeholders into
    // sandboxed iframes that share DOCI's theme (via render_html_document).
    $embeds = [];
    $markdown = preg_replace_callback(
        '/\{\{embed:\s*([\w\-\.\/]+\.html)(?:\s*\|\s*height=(\d+))?\s*\}\}/',
        function ($m) use (&$embeds) {
            $token = 'DOCIEMBED' . count($embeds) . 'XX';
            $embeds[$token] = ['path' => $m[1], 'height' => isset($m[2]) ? (int)$m[2] : 320];
            return $token;
        },
        $markdown
    );

    $parsedown = new ParsedownExtended();
    $parsedown->setSafeMode(true); // Escape HTML for security
    $html = $parsedown->text($markdown);

    if (!empty($embeds)) {
        foreach ($embeds as $token => $opts) {
            $filePath = __DIR__ . '/../files/' . ltrim($opts['path'], '/');
            if (file_exists($filePath) && is_file($filePath)) {
                $real = realpath($filePath);
                $filesRoot = realpath(__DIR__ . '/../files');
                if ($real !== false && strpos($real, $filesRoot) === 0) {
                    $rendered = render_html_document(file_get_contents($filePath));
                    // render_html_document yields an iframe; tag it as an embed
                    // and pin a height so it doesn't stretch like a full doc.
                    $rendered = preg_replace(
                        '#class="doci-html-doc"#',
                        'class="doci-html-doc doci-embed" style="height:' . (int)$opts['height'] . 'px"',
                        $rendered,
                        1
                    );
                    $replacement = $rendered;
                } else {
                    $replacement = '<div class="doci-embed-error">Embed path outside files/: ' . htmlspecialchars($opts['path']) . '</div>';
                }
            } else {
                $replacement = '<div class="doci-embed-error">Embed not found: ' . htmlspecialchars($opts['path']) . '</div>';
            }
            // Parsedown wraps lone tokens in <p>...</p>; handle both forms.
            $html = str_replace('<p>' . $token . '</p>', $replacement, $html);
            $html = str_replace($token, $replacement, $html);
        }
    }

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

    // Rewrite internal path links to stable GUID links: links survive
    // file renames and folder moves. Markdown authors keep writing
    // human-readable paths; the rendered href is /<guid>.
    $html = rewrite_links_to_guids($html);

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
    // Inject DOCI theme stylesheets (absolute URL — the iframe runs in an
    // opaque origin, so relative URLs would resolve against about:srcdoc).
    // The browser fetches and applies the stylesheets even though the iframe
    // can't read them via CSSOM. Documents that use DOCI theme variables
    // (var(--bg-main), var(--text-primary), etc.) pick up the active theme.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = htmlspecialchars($scheme . '://' . $host, ENT_QUOTES, 'UTF-8');
    $themeLink = '<link rel="stylesheet" href="' . $baseUrl . '/assets/doci-theme.css">';

    // Listen for theme updates from the parent. The parent posts the active
    // theme on iframe load and on every theme toggle so the iframe stays
    // in sync with the surrounding DOCI chrome.
    $themeListener = '<script>window.addEventListener("message",function(e){'
        . 'if(e.data&&typeof e.data.docTheme==="string"){'
        . 'document.documentElement.dataset.theme=e.data.docTheme}});</script>';

    // Default <html data-theme> to "dark" so the iframe matches the parent's
    // default before the postMessage arrives.
    if (preg_match('#<html(?![^>]*data-theme=)([^>]*)>#i', $html)) {
        $html = preg_replace('#<html(?![^>]*data-theme=)([^>]*)>#i', '<html$1 data-theme="dark">', $html, 1);
    }

    $injection = $themeLink . $themeListener;
    if (preg_match('#<head[^>]*>#i', $html)) {
        $html = preg_replace('#(<head[^>]*>)#i', '$1' . $injection, $html, 1);
    } elseif (preg_match('#<html[^>]*>#i', $html)) {
        $html = preg_replace('#(<html[^>]*>)#i', '$1<head>' . $injection . '</head>', $html, 1);
    } else {
        $html = '<head>' . $injection . '</head>' . $html;
    }

    $srcdoc = htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
    // allow-top-navigation-by-user-activation lets links inside the
    // iframe navigate the parent page (target="_top") on user click --
    // needed for showcase widgets with CTA buttons that point at DOCI
    // pages. Still no allow-same-origin, so the iframe is opaque to
    // DOCI's session.
    return '<iframe class="doci-html-doc"'
         . ' sandbox="allow-scripts allow-popups allow-forms allow-modals allow-top-navigation-by-user-activation"'
         . ' referrerpolicy="no-referrer"'
         . ' srcdoc="' . $srcdoc . '"'
         . ' title="HTML document"></iframe>';
}

/**
 * Rewrite internal path links inside markdown SOURCE to stable GUID links.
 *
 * Called on every save (UI, REST, CLI, MCP) so the file on disk only
 * ever contains GUID links. Authors and agents can write either form
 * (`[Inbox](/tour/03-inbox)` or `[Inbox](/<guid>)`); the controller
 * normalises to the second. After a rename or move, the GUID still
 * resolves -- the entire reason GUIDs exist as identifiers.
 *
 * Handles inline `[text](url)` and reference `[label]: url` syntaxes.
 * Leaves untouched: external URLs, anchors (#x), static paths
 * (/assets/, /api/), paths that don't resolve to a document.
 *
 * @param string $markdown
 * @return string
 */
function rewrite_md_links_to_guids(string $markdown): string {
    if (!function_exists('get_db')) {
        return $markdown;
    }

    // Inline: [text](url) or [text](url "title")
    $markdown = preg_replace_callback(
        '/\[([^\]\n]+)\]\(\s*([^)\s]+)(\s+"[^"]*")?\s*\)/',
        function ($m) {
            $newUrl = path_to_guid_url($m[2]);
            return '[' . $m[1] . '](' . $newUrl . ($m[3] ?? '') . ')';
        },
        $markdown
    );

    // Reference: [label]: url  (line-anchored)
    $markdown = preg_replace_callback(
        '/^(\s{0,3}\[[^\]\n]+\]:\s+)(\S+)/m',
        function ($m) {
            return $m[1] . path_to_guid_url($m[2]);
        },
        $markdown
    );

    return $markdown;
}

/**
 * Resolve a single URL string from `/some/path` to `/<guid>` if it
 * matches a known document; otherwise return it unchanged.
 *
 * @param string $url
 * @return string
 */
function path_to_guid_url(string $url): string {
    if ($url === '' || $url[0] !== '/') return $url;
    if (preg_match('#^/(assets|api|files/\.data)/#', $url)) return $url;
    // Already a GUID URL? leave alone.
    if (preg_match('#^/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(/|$|\?|\#)#', $url)) return $url;

    $path = $url; $tail = '';
    if (($pos = strpos($path, '#')) !== false) { $tail = substr($path, $pos) . $tail; $path = substr($path, 0, $pos); }
    if (($pos = strpos($path, '?')) !== false) { $tail = substr($path, $pos) . $tail; $path = substr($path, 0, $pos); }
    $clean = preg_replace('/\.html$/', '', rtrim($path, '/'));
    $key = ltrim($clean, '/');
    if ($key === '' || $key === 'index') return $url;

    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT path, guid FROM documents WHERE path IN (?, ?) AND deleted_at IS NULL LIMIT 2");
        $stmt->execute([$key . '.md', $key . '/index.md']);
        $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Prefer exact .md match over folder/index.md
        foreach ([$key . '.md', $key . '/index.md'] as $candidate) {
            foreach ($found as $row) {
                if ($row['path'] === $candidate) {
                    return '/' . $row['guid'] . $tail;
                }
            }
        }
    } catch (Throwable $e) {
        // DB unavailable -- keep original
    }
    return $url;
}

/**
 * Resolve internal path links in a rendered HTML fragment to GUID links.
 *
 * Markdown like `[Inbox](/tour/03-inbox)` renders to
 * `<a href="/tour/03-inbox">Inbox</a>`. After this rewrite, the href
 * becomes `/<guid>` -- so when the underlying file is moved or
 * renamed, the link keeps resolving. The link TEXT is untouched, only
 * the destination URL changes.
 *
 * One DB query per render (batched IN-clause), then string-replace.
 *
 * Skipped:
 *  - external URLs (http://, https://, mailto:)
 *  - in-page anchors (#section)
 *  - static asset paths (/assets/, /api/)
 *  - paths that do not resolve to a document in the table
 *
 * @param string $html
 * @return string
 */
function rewrite_links_to_guids(string $html): string {
    if (!function_exists('get_db')) {
        return $html;
    }

    // Helper: peel off query + fragment and trailing .html, return the
    // candidate documents.path keys ("foo/bar.md" and "foo/bar/index.md").
    $candidates = [];
    if (!preg_match_all('#<a\b[^>]*?href="([^"]+)"#', $html, $matches)) {
        return $html;
    }
    foreach ($matches[1] as $href) {
        if ($href === '' || $href[0] !== '/') continue;
        if (preg_match('#^/(assets|api|files/\.data)/#', $href)) continue;
        $pathPart = $href;
        if (($pos = strpos($pathPart, '#')) !== false) $pathPart = substr($pathPart, 0, $pos);
        if (($pos = strpos($pathPart, '?')) !== false) $pathPart = substr($pathPart, 0, $pos);
        $pathPart = preg_replace('/\.html$/', '', rtrim($pathPart, '/'));
        $pathPart = ltrim($pathPart, '/');
        if ($pathPart === '' || $pathPart === 'index') continue;
        $candidates[] = $pathPart . '.md';
        $candidates[] = $pathPart . '/index.md';   // folder-link fallback
    }
    if (empty($candidates)) return $html;
    $candidates = array_values(array_unique($candidates));

    try {
        $pdo = get_db();
        $ph = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = $pdo->prepare("SELECT path, guid FROM documents WHERE path IN ($ph) AND deleted_at IS NULL");
        $stmt->execute($candidates);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['path']] = $row['guid'];
        }
    } catch (Throwable $e) {
        return $html;
    }
    if (empty($map)) return $html;

    return preg_replace_callback(
        '#(<a\b[^>]*?href=")([^"]+)(")#',
        function ($m) use ($map) {
            $href = $m[2];
            if ($href === '' || $href[0] !== '/') return $m[0];
            if (preg_match('#^/(assets|api|files/\.data)/#', $href)) return $m[0];
            // Split path | query | fragment
            $path = $href; $tail = '';
            if (($pos = strpos($path, '#')) !== false) { $tail = substr($path, $pos) . $tail; $path = substr($path, 0, $pos); }
            if (($pos = strpos($path, '?')) !== false) { $tail = substr($path, $pos) . $tail; $path = substr($path, 0, $pos); }
            $clean = preg_replace('/\.html$/', '', rtrim($path, '/'));
            $key = ltrim($clean, '/');
            if ($key === '' || $key === 'index') return $m[0];
            $candidate = $key . '.md';
            $folderCandidate = $key . '/index.md';
            if (isset($map[$candidate])) {
                return $m[1] . '/' . $map[$candidate] . $tail . $m[3];
            }
            if (isset($map[$folderCandidate])) {
                return $m[1] . '/' . $map[$folderCandidate] . $tail . $m[3];
            }
            return $m[0];
        },
        $html
    );
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
