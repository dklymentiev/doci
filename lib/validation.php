<?php
/**
 * DOCI - Validation Library
 *
 * Path sanitization, GUID validation, and other security checks.
 */

/**
 * Sanitize file path - prevent path traversal attacks
 *
 * Removes null bytes, normalizes separators, and filters unsafe segments.
 * Only allows alphanumeric characters, underscores, hyphens, and dots.
 *
 * @param string $path Raw path from user input
 * @return string Sanitized path (may be empty if all segments invalid)
 */
function sanitize_path(string $path): string {
    // Remove null bytes
    $path = str_replace("\0", '', $path);
    // Normalize path separators
    $path = str_replace('\\', '/', $path);
    // Remove leading/trailing slashes
    $path = trim($path, '/');

    $segments = explode('/', $path);
    $sanitized = [];

    foreach ($segments as $segment) {
        // Skip empty, current dir, and parent dir references.
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }
        // Reject any segment that starts with a dot. files/.git,
        // files/.data, files/.ai-pending.json and similar dot-prefixed
        // paths are runtime/internal state -- never targets the
        // request layer should be able to address.
        if ($segment[0] === '.') {
            continue;
        }
        // Only allow safe characters
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $segment)) {
            continue;
        }
        $sanitized[] = $segment;
    }

    return implode('/', $sanitized);
}

/**
 * Validate UUID/GUID format (RFC 4122)
 *
 * @param string $guid GUID to validate
 * @return bool True if valid UUID v4 format
 */
function is_valid_guid(string $guid): bool {
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $guid) === 1;
}

/**
 * Validate that a path is within allowed base directory
 *
 * Uses realpath() to resolve symlinks and prevent escaping.
 *
 * @param string $path Path to validate
 * @param string $basePath Allowed base directory
 * @return bool True if path is within base directory
 */
function validate_path_within(string $path, string $basePath): bool {
    $realBasePath = realpath($basePath);
    if ($realBasePath === false) {
        return false;
    }

    // For new files, check parent directory
    $realPath = realpath($path);
    if ($realPath === false) {
        $realPath = realpath(dirname($path));
        if ($realPath === false) {
            // Parent doesn't exist - reject (cannot verify without resolved path)
            return false;
        }
    }

    return str_starts_with($realPath, $realBasePath);
}

/**
 * Parse PostgreSQL array format to PHP array
 *
 * @param string|null $str PostgreSQL array string like '{a,b,c}'
 * @return array PHP array
 */
function parse_pg_array(?string $str): array {
    if (empty($str) || $str === '{}') {
        return [];
    }
    $str = trim($str, '{}');
    if (empty($str)) {
        return [];
    }
    return array_map(fn($s) => trim($s, '"'), explode(',', $str));
}

/**
 * Convert PHP array to PostgreSQL array format
 *
 * Tags must be pre-validated before calling this function.
 *
 * @param array $arr PHP array
 * @return string PostgreSQL array string
 */
function to_pg_array(array $arr): string {
    return '{' . implode(',', $arr) . '}';
}

/**
 * Validate and sanitize tags array
 *
 * Only allows alphanumeric, hyphen, underscore (max 50 chars each).
 *
 * @param string|array $tags Tags as comma-separated string or array
 * @return array Validated tags array
 */
function validate_tags($tags): array {
    if (is_string($tags)) {
        $tags = array_map('trim', explode(',', $tags));
    }
    if (!is_array($tags)) {
        return [];
    }
    return array_filter($tags, fn($t) => preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $t));
}
