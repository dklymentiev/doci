<?php
/**
 * PHPUnit Bootstrap
 */

// Set up minimal environment for testing
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Define constants that would normally come from config
if (!defined('DOCI_DEBUG_LOG')) {
    define('DOCI_DEBUG_LOG', false);
}

// Load libraries with no side effects at top level
require_once __DIR__ . '/../lib/validation.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/mesh.php';
require_once __DIR__ . '/../lib/markdown.php';
