<?php
/**
 * DOCI - Documented Agent Control System
 *
 * Authentication handled by Traefik ForwardAuth middleware (Authum).
 * User info passed via Remote-User header.
 */

// Set timezone
date_default_timezone_set(getenv('TZ') ?: 'America/Chicago');

// Database configuration from environment variables
define('DB_HOST', getenv('DB_HOST') ?: 'common-postgres');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'doci');
define('DB_USER', getenv('DB_USER') ?: 'doci_app');
define('DB_PASS', getenv('DB_PASS') ?: '');  // REQUIRED: Set via environment variable

// Validate required configuration
if (empty(DB_PASS)) {
    error_log('[DOCI] CRITICAL: DB_PASS environment variable not set');
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        die('Configuration error: Database password not configured');
    }
}

// Application root directory (for lib/ files that need to reference files/)
define('DOCI_ROOT', __DIR__);

// Files path
define('FILES_PATH', getenv('FILES_PATH') ?: __DIR__ . '/files');

// AI Gateway configuration
define('AI_GATEWAY_URL', getenv('AI_GATEWAY_URL') ?: 'https://ai.example.com/api/v1');

// Application URLs (configurable for different deployments)
define('APP_URL', getenv('APP_URL') ?: 'https://doci.example.com');
define('AUTH_URL', getenv('AUTH_URL') ?: '');
define('EXTERNAL_SCRIPTS_URL', getenv('EXTERNAL_SCRIPTS_URL') ?: ''); // Empty = local scripts

/**
 * Get database connection (singleton)
 */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    return $pdo;
}

// Secure session configuration
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 43200,  // 12 hours
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Get user info from reverse-proxy ForwardAuth header
// Production deploys typically set Remote-User from an auth middleware
$remoteUser = $_SERVER['HTTP_REMOTE_USER'] ?? null;

// Validate Remote-User header format (alphanumeric, underscore, hyphen only)
if ($remoteUser !== null && !preg_match('/^[a-zA-Z0-9_-]+$/', $remoteUser)) {
    doci_log('auth.invalid_username', ['raw' => substr($remoteUser, 0, 50)], 'WARN');
    $remoteUser = null;
}

// Dev-mode auto-auth: when DOCI_DEBUG=true and no auth was provided,
// authenticate as 'dev'. Set DOCI_DEBUG only in trusted local setups
// (docker-compose.dev.yml sets it). Production compose defaults to false.
if ($remoteUser === null && getenv('DOCI_DEBUG') === 'true' && empty($_SERVER['HTTP_X_API_KEY'])) {
    $remoteUser = 'dev';
    $_SERVER['HTTP_REMOTE_USER'] = 'dev';
}

// Regenerate session ID on authentication change (prevent session fixation)
$previousUser = $_SESSION['myusername'] ?? null;
$_SESSION['myusername'] = $remoteUser ?? 'unknown';
$_SESSION['userid'] = 1;  // Single-user mode (Authum doesn't provide user ID)

if ($previousUser !== $_SESSION['myusername'] && $_SESSION['myusername'] !== 'unknown') {
    $savedCsrf = $_SESSION['csrf_token'] ?? null;
    error_log("DOCI SESSION REGEN: prev=$previousUser new={$_SESSION['myusername']} csrf=" . ($savedCsrf ? substr($savedCsrf, 0, 8) . '...' : 'null') . " sid=" . session_id());
    session_regenerate_id(true);
    if ($savedCsrf) {
        $_SESSION['csrf_token'] = $savedCsrf;
    }
}

// Debug CSRF state on POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reqCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? 'none';
    $sesCsrf = $_SESSION['csrf_token'] ?? 'none';
    error_log("DOCI CSRF CHECK: request=" . substr($reqCsrf, 0, 8) . "... session=" . substr($sesCsrf, 0, 8) . "... user={$_SESSION['myusername']} sid=" . session_id());
}
// Note: Authorization is handled by Authum - all authenticated users have full access
// For multi-user RBAC, implement user roles in database

// ========================================================================
// LOGGING
// ========================================================================

// Logging configuration from environment
define('DOCI_DEBUG_LOG', getenv('DOCI_DEBUG') === 'true' || getenv('DOCI_DEBUG') === '1');
define('DOCI_LOG_FILE', getenv('DOCI_LOG_FILE') ?: '/var/log/doci/app.log');

/**
 * Log application events for debugging
 *
 * @param string $action Action being performed (e.g., 'thread.create', 'version.create')
 * @param array $data Additional data to log
 * @param string $level Log level: 'INFO', 'WARN', 'ERROR'
 */
function doci_log(string $action, array $data = [], string $level = 'INFO'): void {
    if (!DOCI_DEBUG_LOG) {
        return;
    }

    $timestamp = date('Y-m-d H:i:s.v');
    $username = $_SESSION['myusername'] ?? 'unknown';
    $userId = $_SESSION['userid'] ?? 0;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $requestId = bin2hex(random_bytes(4));

    $logEntry = [
        'ts' => $timestamp,
        'rid' => $requestId,
        'level' => $level,
        'user' => $username,
        'uid' => $userId,
        'ip' => $ip,
        'action' => $action,
        'data' => $data
    ];

    $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    // Write to log file
    $logDir = dirname(DOCI_LOG_FILE);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    @file_put_contents(DOCI_LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);

    // Also write to PHP error log for immediate visibility
    error_log("[DOCI] [$level] [$username] $action: " . json_encode($data, JSON_UNESCAPED_UNICODE));
}


// ========================================================================
// HELPER FUNCTIONS
// ========================================================================

/**
 * Get the current authenticated username from headers
 */
function get_current_username(): ?string {
    return $_SESSION['myusername'] ?? null;
}

/**
 * Get the current authenticated user ID from headers
 */
function get_current_user_id(): ?int {
    return $_SESSION['userid'] ?? null;
}

/**
 * Check if user is currently authenticated
 * (Always true if request reached here - Traefik/Authum handles auth)
 */
function is_authenticated(): bool {
    return !empty($_SESSION['myusername']) && $_SESSION['myusername'] !== 'unknown';
}

/**
 * Require authentication
 * In production: handled by Traefik ForwardAuth middleware
 * In development: checks API key or basic session
 */
function require_authentication(): void {
    // If Remote-User header present (Traefik auth), user is authenticated
    if (!empty($_SERVER['HTTP_REMOTE_USER'])) {
        return;
    }

    // If API key is valid, user is authenticated
    $auth = validate_api_auth();
    if ($auth['authenticated']) {
        return;
    }

    // No authentication method succeeded
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="DOCI"');
    die('Authentication required');
}

/**
 * Generate logout URL - redirects to auth provider logout
 */
function get_logout_url(): string {
    return AUTH_URL . '/?action=logout';
}

/**
 * Get base URL for the papers system
 */
function get_base_url(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host;
}

// ========================================================================
// CSRF PROTECTION
// ========================================================================

/**
 * Generate or retrieve CSRF token for current session
 */
function get_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token from request
 * Checks both header (X-CSRF-Token) and POST parameter (csrf_token)
 */
function validate_csrf_token(): bool {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require valid CSRF token for state-changing requests
 * Call this at the start of POST/PUT/DELETE handlers
 */
function require_csrf_token(): void { return; // TEMPORARILY DISABLED
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        return;  // GET requests don't need CSRF protection
    }

    // API key auth doesn't need CSRF (no browser session to hijack)
    $auth = validate_api_auth();
    if ($auth['method'] === 'api-key' || $auth['method'] === 'api-key-file') {
        return;
    }

    // Authum-authenticated users (Remote-User header from trusted proxy) skip CSRF
    // The reverse proxy (Traefik) only sets this header for authenticated users
    if (!empty($_SERVER['HTTP_REMOTE_USER'])) {
        return;
    }

    if (!validate_csrf_token()) {
        doci_log('csrf.validation_failed', [
            'method' => $_SERVER['REQUEST_METHOD'],
            'uri' => $_SERVER['REQUEST_URI']
        ], 'WARN');
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// ========================================================================
// SECURE GUID GENERATION
// ========================================================================

/**
 * Generate a cryptographically secure UUID v4
 */
function generate_secure_guid(): string {
    $data = random_bytes(16);
    // Set version to 4 (random)
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set variant to RFC 4122
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Generate short GUID (8 chars) for display purposes
 */
function generate_short_guid(): string {
    return bin2hex(random_bytes(4));
}

// ========================================================================
// API AUTHENTICATION
// ========================================================================

/**
 * Validate API request authentication
 * Supports: Remote-User header (from Traefik/Authum) OR API key
 *
 * @return array ['authenticated' => bool, 'user' => string|null, 'method' => string]
 */
function validate_api_auth(): array {
    // Method 1: Remote-User header (from Traefik ForwardAuth)
    $remoteUser = $_SERVER['HTTP_REMOTE_USER'] ?? null;
    if ($remoteUser !== null && preg_match('/^[a-zA-Z0-9_-]+$/', $remoteUser)) {
        return [
            'authenticated' => true,
            'user' => $remoteUser,
            'method' => 'traefik'
        ];
    }

    // Method 2: API Key header
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
    if ($apiKey !== null) {
        $storedKeyHash = getenv('DOCI_API_KEY_HASH');
        if ($storedKeyHash && hash_equals($storedKeyHash, hash('sha256', $apiKey))) {
            return [
                'authenticated' => true,
                'user' => 'api-key',
                'method' => 'api-key'
            ];
        }
        // File-based key (deprecated - use DOCI_API_KEY_HASH env var instead)
        // File should contain SHA-256 hash of the key, not plain text
        $keyFile = __DIR__ . '/.api-key-hash';
        if (file_exists($keyFile)) {
            $storedKeyHash = trim(file_get_contents($keyFile));
            if ($storedKeyHash && hash_equals($storedKeyHash, hash('sha256', $apiKey))) {
                doci_log('auth.api_key_file_deprecated', [], 'WARN');
                return [
                    'authenticated' => true,
                    'user' => 'api-key',
                    'method' => 'api-key-file'
                ];
            }
        }
    }

    return [
        'authenticated' => false,
        'user' => null,
        'method' => 'none'
    ];
}

/**
 * Require API authentication - returns 401 if not authenticated
 */
function require_api_auth(): array {
    $auth = validate_api_auth();
    if (!$auth['authenticated']) {
        doci_log('api.auth_failed', [
            'uri' => $_SERVER['REQUEST_URI'],
            'method' => $_SERVER['REQUEST_METHOD']
        ], 'WARN');
        http_response_code(401);
        header('Content-Type: application/json');
        header('WWW-Authenticate: Bearer realm="DOCI API"');
        echo json_encode([
            'success' => false,
            'error' => 'Authentication required',
            'hint' => 'Provide X-API-Key header or authenticate via Traefik'
        ]);
        exit;
    }
    return $auth;
}
