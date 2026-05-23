<?php
/**
 * Inbox API - Quick capture for DOCI
 *
 * Requires authentication via:
 * - Traefik/Authum (Remote-User header)
 * - API Key (X-API-Key header)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/validation.php';
require_once __DIR__ . '/../lib/response.php';

header('Content-Type: application/json');

// Require authentication for all API requests
$auth = require_api_auth();
$authUser = $auth['user'];

// CSRF protection for state-changing requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    require_csrf_token();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $pdo = get_db();
} catch (PDOException $e) {
    doci_log('api.inbox.db_error', ['error' => $e->getMessage()], 'ERROR');
    json_server_error('Database connection failed');
}

switch ($action) {
    case 'add':
        addToInbox($pdo);
        break;
    case 'list':
        listInbox($pdo);
        break;
    case 'get':
        getInboxItem($pdo);
        break;
    case 'promote':
        promoteItem($pdo);
        break;
    default:
        json_error('Unknown action');
}

function addToInbox($pdo) {
    $content = $_POST['content'] ?? '';
    $tags = $_POST['tags'] ?? '';

    if (empty($content)) {
        json_error('Content required');
        return;
    }

    // Use library function for tag validation
    $tagArray = validate_tags($tags);
    $tagsPg = to_pg_array($tagArray);

    // Generate filename
    $timestamp = date('Ymd-His');
    $filename = "inbox/{$timestamp}.md";
    $filepath = FILES_PATH . "/{$filename}";

    // Create markdown file with secure GUID
    $guid = generate_secure_guid();

    $frontmatter = "---\n";
    $frontmatter .= "guid: {$guid}\n";
    $frontmatter .= "created: " . date('c') . "\n";
    $frontmatter .= "tags: [" . implode(', ', $tagArray) . "]\n";
    $frontmatter .= "---\n\n";
    $frontmatter .= $content;

    file_put_contents($filepath, $frontmatter);

    // Insert into DB
    $title = mb_substr($content, 0, 100);
    $stmt = $pdo->prepare("
        INSERT INTO documents (guid, path, doc_type, title, tags, created_at)
        VALUES (:guid, :path, 'inbox', :title, :tags, NOW())
        RETURNING guid
    ");
    $stmt->execute([
        'guid' => $guid,
        'path' => $filename,
        'title' => $title,
        'tags' => $tagsPg
    ]);

    json_success([
        'guid' => $guid,
        'path' => $filename
    ]);
}

function listInbox($pdo) {
    $limit = min((int)($_GET['limit'] ?? 50), 100); // Cap at 100

    $stmt = $pdo->prepare("
        SELECT guid, path, title, tags, created_at
        FROM documents
        WHERE doc_type = 'inbox' AND deleted_at IS NULL
        ORDER BY created_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert PostgreSQL array to PHP array
    foreach ($items as &$item) {
        $item['tags'] = parse_pg_array($item['tags']);
    }

    json_success(['items' => $items]);
}

function getInboxItem($pdo) {
    $guid = $_GET['guid'] ?? '';

    if (empty($guid) || !is_valid_guid($guid)) {
        json_error('Valid GUID required');
        return;
    }

    $stmt = $pdo->prepare("
        SELECT guid, path, title, tags, created_at
        FROM documents
        WHERE guid = :guid AND deleted_at IS NULL
    ");
    $stmt->execute(['guid' => $guid]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        json_error('Not found', 404);
        return;
    }

    // Read file content
    $filepath = FILES_PATH . '/' . $item['path'];
    if (file_exists($filepath)) {
        $item['content'] = file_get_contents($filepath);
    }

    $item['tags'] = parse_pg_array($item['tags']);
    $item['success'] = true;

    header('Content-Type: application/json');
    echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function promoteItem($pdo) {
    $guid = $_POST['guid'] ?? '';
    $targetPath = $_POST['target'] ?? '';

    if (empty($guid) || !is_valid_guid($guid)) {
        json_error('Valid GUID required');
        return;
    }

    if (empty($targetPath)) {
        json_error('Target path required');
        return;
    }

    // Sanitize target path - prevent path traversal
    $targetPath = sanitize_path($targetPath);
    if (empty($targetPath)) {
        json_error('Invalid target path');
        return;
    }

    // Get current item
    $stmt = $pdo->prepare("SELECT path FROM documents WHERE guid = :guid");
    $stmt->execute(['guid' => $guid]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        json_error('Not found', 404);
        return;
    }

    $oldPath = FILES_PATH . '/' . $item['path'];
    $newPath = FILES_PATH . '/' . $targetPath;

    // Verify paths are within FILES_PATH (prevent symlink attacks)
    if (!validate_path_within($newPath, FILES_PATH)) {
        doci_log('inbox.path_traversal_attempt', ['target' => $targetPath], 'WARN');
        json_error('Invalid target path');
        return;
    }

    // Check if target file already exists
    if (file_exists($newPath)) {
        json_error('Target file already exists', 409);
        return;
    }

    // Create target directory
    $targetDir = dirname($newPath);
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            json_server_error('Failed to create directory');
            return;
        }
    }

    // Move file
    if (file_exists($oldPath)) {
        if (!rename($oldPath, $newPath)) {
            json_server_error('Failed to move file');
            return;
        }
    }

    // Update DB
    $stmt = $pdo->prepare("
        UPDATE documents
        SET path = :path, doc_type = 'document', updated_at = NOW()
        WHERE guid = :guid
    ");
    $stmt->execute(['path' => $targetPath, 'guid' => $guid]);

    json_success([
        'guid' => $guid,
        'new_path' => $targetPath
    ]);
}

