<?php
/**
 * Markdown File Server
 * Serves raw markdown content for JavaScript fetch requests
 */

require_once __DIR__ . '/src/config.php';

// Require authentication
require_authentication();

header('Content-Type: text/plain; charset=UTF-8');

// Get the file parameter
$file = $_GET['file'] ?? '';

if (empty($file)) {
    http_response_code(400);
    echo 'Error: No file specified';
    exit;
}

// Security: prevent directory traversal
$file = str_replace(['../', '..\\'], '', $file);

// Build full path - files are relative to /files/shared/
$basePath = __DIR__ . '/files/shared/';
$fullPath = $basePath . $file;

// Resolve real path and verify it's within allowed directory
$realPath = realpath($fullPath);
$realBase = realpath($basePath);

if ($realPath === false || strpos($realPath, $realBase) !== 0) {
    http_response_code(404);
    echo 'Error: File not found';
    exit;
}

// Check file exists and is readable
if (!is_file($realPath) || !is_readable($realPath)) {
    http_response_code(404);
    echo 'Error: File not found';
    exit;
}

// Only allow markdown files
if (pathinfo($realPath, PATHINFO_EXTENSION) !== 'md') {
    http_response_code(403);
    echo 'Error: Only markdown files are allowed';
    exit;
}

// Output the file content
echo file_get_contents($realPath);
