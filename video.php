<?php
/**
 * DOCI - Video streaming proxy
 * Serves video files from the protected /files directory
 */

require_once __DIR__ . '/src/config.php';

// Require authentication
require_authentication();

// Get requested file
$file = $_GET['file'] ?? '';

if (empty($file)) {
    http_response_code(400);
    die('Missing file parameter');
}

// Sanitize path - prevent directory traversal
$file = str_replace(['..', "\0"], '', $file);
$file = ltrim($file, '/');

// Build full path
$basePath = __DIR__ . '/files/shared/';
$fullPath = $basePath . $file;

// Resolve real path and verify it's within allowed directory
$realPath = realpath($fullPath);
$realBasePath = realpath($basePath);

if ($realPath === false || strpos($realPath, $realBasePath) !== 0) {
    http_response_code(404);
    die('File not found');
}

// Check file exists
if (!is_file($realPath)) {
    http_response_code(404);
    die('File not found');
}

// Get file info
$fileSize = filesize($realPath);
$extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

// Determine content type
$mimeTypes = [
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'ogv' => 'video/ogg',
    'mov' => 'video/quicktime',
];

$contentType = $mimeTypes[$extension] ?? 'application/octet-stream';

// Handle range requests for video seeking
$start = 0;
$end = $fileSize - 1;
$length = $fileSize;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
        $start = $matches[1] !== '' ? intval($matches[1]) : 0;
        $end = $matches[2] !== '' ? intval($matches[2]) : $fileSize - 1;

        if ($start > $end || $start >= $fileSize) {
            http_response_code(416);
            header("Content-Range: bytes */$fileSize");
            exit;
        }

        $length = $end - $start + 1;

        http_response_code(206);
        header("Content-Range: bytes $start-$end/$fileSize");
    }
} else {
    http_response_code(200);
}

// Send headers
header("Content-Type: $contentType");
header("Content-Length: $length");
header("Accept-Ranges: bytes");
header("Cache-Control: public, max-age=86400");

// Stream file
$handle = fopen($realPath, 'rb');
if ($handle === false) {
    http_response_code(500);
    die('Cannot open file');
}

fseek($handle, $start);

$bufferSize = 8192;
$bytesRemaining = $length;

while ($bytesRemaining > 0 && !feof($handle)) {
    $readSize = min($bufferSize, $bytesRemaining);
    $data = fread($handle, $readSize);
    if ($data === false) break;
    echo $data;
    $bytesRemaining -= strlen($data);
    flush();
}

fclose($handle);
