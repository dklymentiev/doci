<?php
/**
 * DOCI - API Response Library
 *
 * Consistent JSON response formatting for all API endpoints.
 */

/**
 * Send successful JSON response
 *
 * @param array $data Response data (will include success: true)
 * @param int $code HTTP status code (default 200)
 */
function json_success(array $data = [], int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Send error JSON response
 *
 * @param string $message Error message
 * @param int $code HTTP status code (default 400)
 * @param array $extra Additional data to include
 */
function json_error(string $message, int $code = 400, array $extra = []): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => false,
        'error' => $message
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Send error and exit
 *
 * @param string $message Error message
 * @param int $code HTTP status code
 * @param array $extra Additional data
 */
function json_error_exit(string $message, int $code = 400, array $extra = []): never {
    json_error($message, $code, $extra);
    exit;
}

/**
 * Send 404 Not Found response
 *
 * @param string $message Error message (default "Not found")
 */
function json_not_found(string $message = 'Not found'): never {
    json_error_exit($message, 404);
}

/**
 * Send 401 Unauthorized response
 *
 * @param string $message Error message
 * @param string $realm WWW-Authenticate realm
 */
function json_unauthorized(string $message = 'Authentication required', string $realm = 'DOCI API'): never {
    header('WWW-Authenticate: Bearer realm="' . $realm . '"');
    json_error_exit($message, 401, ['hint' => 'Provide X-API-Key header or authenticate via Traefik']);
}

/**
 * Send 403 Forbidden response
 *
 * @param string $message Error message
 */
function json_forbidden(string $message = 'Access denied'): never {
    json_error_exit($message, 403);
}

/**
 * Send 405 Method Not Allowed response
 *
 * @param array $allowed Allowed methods
 */
function json_method_not_allowed(array $allowed = ['GET', 'POST']): never {
    header('Allow: ' . implode(', ', $allowed));
    json_error_exit('Method not allowed', 405);
}

/**
 * Send 500 Internal Server Error response
 *
 * @param string $message Error message (keep generic for security)
 */
function json_server_error(string $message = 'Internal server error'): never {
    json_error_exit($message, 500);
}
