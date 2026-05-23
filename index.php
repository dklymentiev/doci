<?php
/**
 * DOCI - Main Router
 *
 * Routes HTTP requests to markdown files and renders them as HTML pages.
 * Supports nested directory structures like /sub/sub/sub/file.md
 */

// Load configuration and require authentication
require_once __DIR__ . '/config.php';

// Load shared libraries
require_once __DIR__ . '/lib/validation.php';
require_once __DIR__ . '/lib/git.php';

// Require authentication before any content is served
require_authentication();

// Load Parsedown markdown parser
require_once __DIR__ . '/Parsedown.php';
require_once __DIR__ . '/ParsedownExtended.php';

// Load document functions for GUID routing
require_once __DIR__ . '/documents.php';

// Load render and markdown functions
require_once __DIR__ . '/lib/markdown.php';
require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/mesh.php';

// All helper/render functions moved to lib/markdown.php and lib/render.php
// Only routing logic remains below.

// ========================================================================
// MAIN ROUTING LOGIC
// ========================================================================

// Check if this is an AJAX content request
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

// Get requested path from URL (default to 'index' for homepage)
$requestPath = $_GET['path'] ?? 'index';

// Log page request
doci_log('page.request', [
    'path' => $requestPath,
    'ajax' => $isAjax,
    'referer' => $_SERVER['HTTP_REFERER'] ?? null,
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100)
]);

// Search route -- before GUID/file routing
// Strip .html suffix early (form action is /search.html)
$searchPath = preg_replace('/\.html$/', '', $requestPath);
if ($searchPath === 'search') {
    $searchQuery = $_GET['q'] ?? '';
    $searchFolder = $_GET['folder'] ?? '';
    $pageTitle = 'Search' . ($searchQuery ? ': ' . $searchQuery : '');
    if ($searchFolder) {
        $pageTitle .= ' (in ' . $searchFolder . ')';
    }
    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'title' => $pageTitle,
            'content' => render_search_page($searchQuery, $searchFolder),
            'breadcrumbs' => render_breadcrumbs('search'),
            'path' => 'search',
            'meta' => ['isSearch' => true],
        ]);
        exit;
    }
    render_page($pageTitle, render_search_page($searchQuery, $searchFolder), 'search');
    exit;
}

// Variables for GUID-based routing
$documentRecord = null;
$documentHierarchy = [];

// Check if path is a UUID (GUID-based routing)
if (is_valid_guid($requestPath)) {
    $documentRecord = get_document_by_guid($requestPath);

    if ($documentRecord) {
        // Get hierarchy for breadcrumbs - special handling for versions and threads
        $docType = $documentRecord['doc_type'] ?? 'document';

        if ($docType === 'version' && !empty($documentRecord['original_guid'])) {
            // For versions: use original document's hierarchy + version info
            $documentHierarchy = get_document_hierarchy($documentRecord['original_guid']);
            // Find version number
            $allVersions = get_document_versions($documentRecord['original_guid']);
            $versionNum = 1;
            foreach ($allVersions as $i => $v) {
                if ($v['guid'] === $documentRecord['guid']) {
                    $versionNum = $i + 1;
                    break;
                }
            }
            $initials = strtoupper(substr($documentRecord['created_by'] ?? 'UN', 0, 2));
            // Add version as last item
            $documentHierarchy[] = [
                'guid' => $documentRecord['guid'],
                'title' => $initials . '-' . $versionNum,
                'doc_type' => 'version',
                'depth' => count($documentHierarchy)
            ];
        } elseif ($docType === 'thread' && !empty($documentRecord['parent_guid'])) {
            // For threads: check if parent is a version
            $parentDoc = get_document_by_guid($documentRecord['parent_guid']);
            if ($parentDoc && $parentDoc['doc_type'] === 'version' && !empty($parentDoc['original_guid'])) {
                // Thread on a version - use original's hierarchy + version + thread
                $documentHierarchy = get_document_hierarchy($parentDoc['original_guid']);
                // Find version number
                $allVersions = get_document_versions($parentDoc['original_guid']);
                $versionNum = 1;
                foreach ($allVersions as $i => $v) {
                    if ($v['guid'] === $parentDoc['guid']) {
                        $versionNum = $i + 1;
                        break;
                    }
                }
                $initials = strtoupper(substr($parentDoc['created_by'] ?? 'UN', 0, 2));
                $documentHierarchy[] = [
                    'guid' => $parentDoc['guid'],
                    'title' => $initials . '-' . $versionNum,
                    'doc_type' => 'version',
                    'depth' => count($documentHierarchy)
                ];
                $documentHierarchy[] = [
                    'guid' => $documentRecord['guid'],
                    'title' => $documentRecord['title'] ?? 'Thread',
                    'doc_type' => 'thread',
                    'depth' => count($documentHierarchy)
                ];
            } else {
                // Thread on regular document (legacy)
                $documentHierarchy = get_document_hierarchy($requestPath);
            }
        } else {
            // Regular document
            $documentHierarchy = get_document_hierarchy($requestPath);
        }

        // Use the file path from database
        $requestPath = $documentRecord['path'];

        // Remove .md extension if present (will be added back later)
        $requestPath = preg_replace('/\.md$/', '', $requestPath);
    } else {
        // UUID not found in database
        http_response_code(404);
        render_404();
        exit;
    }
} else {
    // Sanitize path to prevent directory traversal
    $requestPath = sanitize_path($requestPath);

    // Strip .html suffix (URLs use .html but files are .md)
    $requestPath = preg_replace('/\.html$/', '', $requestPath);

    // Default to index if path is empty after sanitization
    if (empty($requestPath)) {
        $requestPath = 'index';
    }

    // Try to find document in database by path
    $docPath = $requestPath . '.md';
    $documentRecord = get_document_by_path($docPath);
    if ($documentRecord) {
        $documentHierarchy = get_document_hierarchy($documentRecord['guid']);
    }
}

// Map to markdown file in /files/ directory
$markdownFile = __DIR__ . '/files/' . $requestPath . '.md';
$dirPath = __DIR__ . '/files/' . $requestPath;
$htmlFile = __DIR__ . '/files/' . $requestPath . '.html';

// Check if it's an HTML file. Wrap it in the DOCI shell and render the
// HTML inside a sandboxed iframe -- same mechanism used for .md files
// whose content is a full HTML document. Pass ?raw=1 in the URL if you
// need the file served untouched (e.g. to embed it from another tool).
if (file_exists($htmlFile) && is_file($htmlFile)) {
    $filesDir = realpath(__DIR__ . '/files');
    $realHtmlPath = realpath($htmlFile);

    // Security check: ensure file is within /files/ directory
    if ($realHtmlPath !== false && strpos($realHtmlPath, $filesDir) === 0) {
        if (!empty($_GET['raw'])) {
            header('Content-Type: text/html; charset=UTF-8');
            readfile($htmlFile);
            exit;
        }

        require_once __DIR__ . '/lib/markdown.php';
        $htmlSource = file_get_contents($htmlFile);
        $title = extract_title($htmlSource, basename($requestPath));
        $htmlContent = render_html_document($htmlSource);

        render_page($title, $htmlContent, $requestPath, false, null,
            $documentHierarchy, $documentRecord);
        exit;
    }
}

// Check if it's a directory
if (is_dir($dirPath)) {
    // Generate directory listing
    $filesDir = realpath(__DIR__ . '/files');
    $realDirPath = realpath($dirPath);

    // Security check: ensure directory is within /files/
    if ($realDirPath === false || strpos($realDirPath, $filesDir) !== 0) {
        error_log('SECURITY: Directory traversal attempt detected: ' . $requestPath);
        http_response_code(404);
        render_404();
        exit;
    }

    $dirTitle = ucwords(str_replace(['-', '_'], ' ', basename($requestPath)));
    if ($requestPath === 'index') {
        $dirTitle = 'Root';
    }

    // Use card-based layout for directories
    $urlPrefix = $requestPath === 'index' ? '' : $requestPath;
    $folderCards = render_folder_cards($dirPath, false, $urlPrefix);
    $fileCards = render_file_cards($dirPath, false, $urlPrefix);

    if (empty($folderCards) && empty($fileCards)) {
        http_response_code(404);
        render_404();
        exit;
    }

    // Add folder search bar for non-root directories
    $htmlContent = '';
    if ($requestPath !== 'index') {
        // Get top-level folder for tag-based search
        $folderParts = explode('/', $requestPath);
        $topFolder = $folderParts[0];
        $folderTitle = ucwords(str_replace(['-', '_'], ' ', $topFolder));
        $htmlContent .= '<form method="GET" action="/search.html" class="folder-search-form">' . "\n";
        $htmlContent .= '  <input type="hidden" name="folder" value="' . htmlspecialchars($topFolder) . '">' . "\n";
        $htmlContent .= '  <input type="text" name="q" class="folder-search-input" placeholder="Search in ' . htmlspecialchars($folderTitle) . '...">' . "\n";
        $htmlContent .= '  <button type="submit" class="folder-search-btn">Search</button>' . "\n";
        $htmlContent .= '</form>' . "\n";
    }

    $htmlContent .= '<div class="folder-cards">' . "\n";
    $htmlContent .= $folderCards;
    $htmlContent .= $fileCards;
    $htmlContent .= '</div>' . "\n";

    $displayPath = $requestPath === 'index' ? '' : $requestPath;

    // Handle AJAX request for directory
    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'title' => $dirTitle,
            'content' => $htmlContent,
            'breadcrumbs' => render_breadcrumbs($displayPath),
            'path' => $displayPath,
            'meta' => [
                'isDirectory' => true,
            ],
        ]);
        exit;
    }

    render_page($dirTitle, $htmlContent, $displayPath);
    exit;
}

// Check if file exists
if (!file_exists($markdownFile) || !is_file($markdownFile)) {
    http_response_code(404);
    render_404();
    exit;
}

// Security check: ensure resolved path is within /files/ directory
$realFilePath = realpath($markdownFile);
$filesDir = realpath(__DIR__ . '/files');

if ($realFilePath === false || strpos($realFilePath, $filesDir) !== 0) {
    // Attempted path traversal attack
    error_log('SECURITY: Path traversal attempt detected: ' . $requestPath);
    http_response_code(404);
    render_404();
    exit;
}

// Read markdown content
$markdownContent = file_get_contents($markdownFile);

if ($markdownContent === false) {
    error_log('ERROR: Failed to read file: ' . $markdownFile);
    http_response_code(500);
    die('Error reading file.');
}

// Store raw content for editor and set path variables
$rawContent = $markdownContent;
$mdFilePath = $markdownFile;
$requestedPath = $requestPath;

// Extract title from markdown or use filename
$title = extract_title($markdownContent, basename($requestPath));

// Parse content to HTML. HTML documents render in a sandboxed iframe
// (see render_html_document); everything else goes through Markdown,
// which escapes raw HTML via Parsedown SafeMode.
if (doci_is_html_document($markdownContent)) {
    $htmlContent = render_html_document($markdownContent);
} else {
    $htmlContent = parse_markdown($markdownContent);
}

// File path indicator removed - metadata shown at bottom instead

// Add folder and file cards at the top on homepage
if ($requestPath === 'index') {
    $folderCards = render_folder_cards(__DIR__ . '/files', false);
    $fileCards = render_file_cards(__DIR__ . '/files', false);

    if (!empty($folderCards) || !empty($fileCards)) {
        $cardsSection = '<h2>Browse Documents</h2>' . "\n";
        $cardsSection .= '<div class="folder-cards">' . "\n";
        $cardsSection .= $folderCards;
        $cardsSection .= $fileCards;
        $cardsSection .= '</div>' . "\n";
        $cardsSection .= '<hr>' . "\n";

        $htmlContent = $cardsSection . $htmlContent;
    }
}

// Handle AJAX request - return JSON with content and metadata
if ($isAjax) {
    doci_log('page.ajax_response', [
        'path' => $requestPath,
        'title' => $title,
        'content_length' => strlen($htmlContent)
    ]);
    header('Content-Type: application/json; charset=UTF-8');

    // Build metadata for response
    $mdFilePath = __DIR__ . '/files/' . $requestPath . '.md';
    $isEditable = $requestPath !== 'index' && !empty($requestPath) && file_exists($mdFilePath);
    $meta = [
        'path' => $requestPath . '.md',
        'guid' => $documentRecord['guid'] ?? null,
        'isThread' => ($documentRecord['doc_type'] ?? null) === 'thread',
        'isDirectory' => false,
        'isEditable' => $isEditable,
        'editPath' => $isEditable ? $requestPath : '',
        'rawContent' => $isEditable ? file_get_contents($mdFilePath) : '',
    ];

    // Add thread-specific info
    if ($meta['isThread'] && !empty($documentRecord['parent_guid'])) {
        $parentDoc = get_document_by_guid($documentRecord['parent_guid']);
        $meta['thread'] = [
            'author' => $documentRecord['created_by'] ?? 'unknown',
            'created' => $documentRecord['created_at'] ?? '',
            'parentGuid' => $parentDoc['guid'] ?? null,
            'parentTitle' => $parentDoc['title'] ?? null,
        ];
    }

    // Add git history
    $meta['commits'] = git_get_file_history($requestPath . '.md', 3);
    $meta['commitUrlBase'] = git_get_commit_url_base();

    echo json_encode([
        'title' => $title,
        'content' => $htmlContent,
        'breadcrumbs' => render_breadcrumbs($requestPath),
        'path' => $requestPath,
        'meta' => $meta,
    ]);
    exit;
}

// Log page render
doci_log('page.render', [
    'path' => $requestPath,
    'title' => $title,
    'guid' => $documentRecord['guid'] ?? null,
    'doc_type' => $documentRecord['doc_type'] ?? null,
    'content_length' => strlen($htmlContent)
]);

// Render the page (show recent sidebar on index page)
render_page($title, $htmlContent, $requestPath, true, $rawContent, $documentHierarchy, $documentRecord);
