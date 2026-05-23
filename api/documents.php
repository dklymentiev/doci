<?php
/**
 * Documents API
 *
 * POST   /api/documents.php - Create new document (file + DB registration + git commit)
 * GET    /api/documents.php?guid={guid} - Get document info
 * GET    /api/documents.php?path={path} - Get document info by path
 * PUT    /api/documents.php - Update document content (+ git commit)
 * DELETE /api/documents.php - Soft delete document
 *
 * All document operations should go through this API.
 * Changes are committed to git automatically.
 *
 * Authentication: Traefik/Authum (Remote-User header) OR API key (X-API-Key header)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../documents.php';
require_once __DIR__ . '/../lib/git.php';
require_once __DIR__ . '/../lib/validation.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/mesh.php';

header('Content-Type: application/json');

// Require authentication using centralized function
$auth = require_api_auth();
$authUser = $auth['user'];

// CSRF protection for state-changing requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    require_csrf_token();
}

$method = $_SERVER['REQUEST_METHOD'];

doci_log('documents.api.request', [
    'method' => $method,
    'query' => $_GET,
    'user' => $authUser,
]);

try {
    $pdo = get_db();

    switch ($method) {
        case 'POST':
            handleCreate($pdo);
            break;
        case 'GET':
            handleGet($pdo);
            break;
        case 'PUT':
            handleUpdate($pdo);
            break;
        case 'DELETE':
            handleDelete($pdo);
            break;
        default:
            throw new Exception('Method not allowed');
    }

} catch (Exception $e) {
    doci_log('documents.api.error', ['error' => $e->getMessage()], 'ERROR');
    json_error($e->getMessage());
}

/**
 * POST - Create new document
 * Body: {path, content, title?, summary?, tags?, created_by?}
 */
function handleCreate($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $path = $input['path'] ?? null;
    $content = $input['content'] ?? null;
    $title = $input['title'] ?? null;
    $summary = $input['summary'] ?? null;
    $tags = $input['tags'] ?? null;
    $createdBy = $input['created_by'] ?? get_current_username() ?? 'system';

    doci_log('documents.create.start', [
        'path' => $path,
        'title' => $title,
        'content_length' => strlen($content ?? ''),
        'created_by' => $createdBy
    ]);

    if (!$path) {
        throw new Exception('path is required');
    }
    if ($content === null) {
        throw new Exception('content is required');
    }

    // Sanitize and validate path
    $path = sanitize_path($path);
    if (empty($path)) {
        throw new Exception('Invalid path');
    }
    if (!str_ends_with($path, '.md')) {
        $path .= '.md';
    }

    // Check if already exists
    $existing = get_document_by_path($path);
    if ($existing) {
        throw new Exception('Document already exists: ' . $path);
    }

    // Extract title from content if not provided
    if (!$title) {
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            $title = trim($matches[1]);
        } else {
            $title = basename($path, '.md');
            $title = ucwords(str_replace(['-', '_'], ' ', $title));
        }
    }

    // Create file
    $filePath = __DIR__ . '/../files/' . $path;
    $dirPath = dirname($filePath);

    // Create directory if needed
    if (!is_dir($dirPath)) {
        if (!mkdir($dirPath, 0775, true)) {
            throw new Exception('Failed to create directory');
        }
        doci_log('documents.create.mkdir', ['dir' => $dirPath]);
    }

    // Normalise internal path links to GUID URLs before persisting.
    require_once __DIR__ . '/../lib/markdown.php';
    $content = rewrite_md_links_to_guids($content);

    // Write file
    if (file_put_contents($filePath, $content) === false) {
        throw new Exception('Failed to write file');
    }

    doci_log('documents.create.file_written', [
        'path' => $filePath,
        'size' => strlen($content)
    ]);

    // Commit and push to git
    $commitResult = git_commit_and_push($path, "Create $path", $createdBy);
    if (isset($commitResult['error'])) {
        doci_log('documents.create.git_error', [
            'path' => $path,
            'error' => $commitResult['error']
        ], 'WARN');
    } else {
        doci_log('documents.create.git_commit', [
            'path' => $path,
            'hash' => $commitResult['hash'] ?? ''
        ]);
    }

    // Register in database
    $guid = register_document($path, [
        'title' => $title,
        'summary' => $summary,
        'tags' => $tags,
        'doc_type' => 'document',
        'created_by' => $createdBy
    ]);

    if (!$guid) {
        // Rollback - delete file
        @unlink($filePath);
        throw new Exception('Failed to register document');
    }

    doci_log('documents.create.success', [
        'guid' => $guid,
        'path' => $path,
        'title' => $title
    ]);

    // Sync to Mesh for semantic search (non-blocking, errors logged)
    syncDocumentToMesh($path, $content);

    json_success([
        'guid' => $guid,
        'path' => $path,
        'title' => $title,
        'url' => '/' . $guid
    ]);
}

/**
 * GET - Get document info
 * Query: ?guid={guid} or ?path={path}
 */
function handleGet($pdo) {
    $guid = $_GET['guid'] ?? null;
    $path = $_GET['path'] ?? null;

    if (!$guid && !$path) {
        throw new Exception('guid or path parameter is required');
    }

    $doc = null;
    if ($guid) {
        $doc = get_document_by_guid($guid);
    } else {
        // Normalize path
        if (!str_ends_with($path, '.md')) {
            $path .= '.md';
        }
        $doc = get_document_by_path($path);
    }

    if (!$doc) {
        http_response_code(404);
        throw new Exception('Document not found');
    }

    doci_log('documents.get.success', [
        'guid' => $doc['guid'],
        'path' => $doc['path']
    ]);

    // Optionally include content
    $includeContent = isset($_GET['content']) && $_GET['content'] === '1';
    $content = null;
    if ($includeContent) {
        $filePath = __DIR__ . '/../files/' . $doc['path'];
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
        }
    }

    $response = [
        'success' => true,
        'document' => [
            'guid' => $doc['guid'],
            'path' => $doc['path'],
            'title' => $doc['title'],
            'summary' => $doc['summary'],
            'tags' => $doc['tags'],
            'doc_type' => $doc['doc_type'],
            'created_by' => $doc['created_by'],
            'created_at' => $doc['created_at'],
            'updated_by' => $doc['updated_by'],
            'updated_at' => $doc['updated_at'],
            'url' => '/' . $doc['guid']
        ]
    ];

    if ($includeContent) {
        $response['document']['content'] = $content;
    }

    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * PUT - Update document
 * Body: {guid, content?, title?, summary?, tags?}
 */
function handleUpdate($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $guid = $input['guid'] ?? null;
    $content = $input['content'] ?? null;
    $title = $input['title'] ?? null;
    $summary = $input['summary'] ?? null;
    $tags = $input['tags'] ?? null;
    $updatedBy = $input['updated_by'] ?? get_current_username() ?? 'system';

    if (!$guid) {
        throw new Exception('guid is required');
    }

    doci_log('documents.update.start', [
        'guid' => $guid,
        'has_content' => $content !== null,
        'updated_by' => $updatedBy
    ]);

    $doc = get_document_by_guid($guid);
    if (!$doc) {
        http_response_code(404);
        throw new Exception('Document not found');
    }

    // Update file content if provided
    if ($content !== null) {
        $filePath = __DIR__ . '/../files/' . $doc['path'];
        require_once __DIR__ . '/../lib/markdown.php';
        $content = rewrite_md_links_to_guids($content);
        if (file_put_contents($filePath, $content) === false) {
            throw new Exception('Failed to write file');
        }
        doci_log('documents.update.file_written', [
            'path' => $doc['path'],
            'size' => strlen($content)
        ]);

        // Commit and push to git
        $commitResult = git_commit_and_push($doc['path'], "Update " . $doc['path'], $updatedBy);
        if (isset($commitResult['error'])) {
            doci_log('documents.update.git_error', [
                'path' => $doc['path'],
                'error' => $commitResult['error']
            ], 'WARN');
        } else {
            doci_log('documents.update.git_commit', [
                'path' => $doc['path'],
                'hash' => $commitResult['hash'] ?? ''
            ]);
        }
    }

    // Sync to Mesh for semantic search (if content changed)
    if ($content !== null) {
        syncDocumentToMesh($doc['path'], $content);
    }

    // Update database record
    $updates = [];
    $params = ['guid' => $guid];

    if ($title !== null) {
        $updates[] = 'title = :title';
        $params['title'] = $title;
    }
    if ($summary !== null) {
        $updates[] = 'summary = :summary';
        $params['summary'] = $summary;
    }
    if ($tags !== null) {
        $updates[] = 'tags = :tags';
        $params['tags'] = is_array($tags) ? '{' . implode(',', $tags) . '}' : $tags;
    }

    $updates[] = 'updated_at = NOW()';
    $updates[] = 'updated_by = :updated_by';
    $params['updated_by'] = $updatedBy;

    $sql = 'UPDATE documents SET ' . implode(', ', $updates) . ' WHERE guid = :guid';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    doci_log('documents.update.success', [
        'guid' => $guid,
        'path' => $doc['path']
    ]);

    json_success([
        'guid' => $guid,
        'path' => $doc['path'],
        'message' => 'Document updated'
    ]);
}

/**
 * DELETE - Soft delete document with cascade
 * Body: {guid} or Query: ?guid={guid}
 *
 * Cascades: deletes all children (threads, versions) recursively
 * Returns: parent_guid for redirect after deletion
 */
function handleDelete($pdo) {
    $guid = $_GET['guid'] ?? null;

    if (!$guid) {
        $input = json_decode(file_get_contents('php://input'), true);
        $guid = $input['guid'] ?? null;
    }

    if (!$guid) {
        throw new Exception('guid is required');
    }

    $deletedBy = get_current_username() ?? 'system';

    doci_log('documents.delete.start', [
        'guid' => $guid,
        'deleted_by' => $deletedBy
    ]);

    $doc = get_document_by_guid($guid);
    if (!$doc) {
        http_response_code(404);
        throw new Exception('Document not found');
    }

    // Get parent for redirect
    $parentGuid = $doc['parent_guid'] ?? null;
    $originalGuid = $doc['original_guid'] ?? null;

    // Determine redirect target
    $redirectTo = null;
    if ($parentGuid) {
        // Thread or nested doc - go to parent
        $redirectTo = $parentGuid;
    } elseif ($originalGuid) {
        // Version - go to original document
        $redirectTo = $originalGuid;
    }

    // Cascade delete all children recursively
    $deletedCount = cascadeDelete($pdo, $guid, $deletedBy);

    doci_log('documents.delete.success', [
        'guid' => $guid,
        'path' => $doc['path'],
        'cascade_deleted' => $deletedCount,
        'redirect_to' => $redirectTo
    ]);

    json_success([
        'guid' => $guid,
        'path' => $doc['path'],
        'deleted_count' => $deletedCount + 1,
        'redirect_to' => $redirectTo,
        'message' => 'Document deleted'
    ]);
}

/**
 * Recursively soft delete document and all children
 */
function cascadeDelete($pdo, $guid, $deletedBy, int $depth = 0): int {
    if ($depth > 20) {
        doci_log('documents.delete.max_depth', ['guid' => $guid, 'depth' => $depth], 'WARN');
        return 0;
    }
    $count = 0;

    // Find all children (documents with parent_guid = this guid)
    $stmt = $pdo->prepare('
        SELECT guid FROM documents
        WHERE parent_guid = :guid AND deleted_at IS NULL
    ');
    $stmt->execute(['guid' => $guid]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Find all versions (documents with original_guid = this guid)
    $stmt = $pdo->prepare('
        SELECT guid FROM documents
        WHERE original_guid = :guid AND deleted_at IS NULL
    ');
    $stmt->execute(['guid' => $guid]);
    $versions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Recursively delete children
    foreach ($children as $childGuid) {
        $count += cascadeDelete($pdo, $childGuid, $deletedBy, $depth + 1);
    }

    // Recursively delete versions (and their children)
    foreach ($versions as $versionGuid) {
        $count += cascadeDelete($pdo, $versionGuid, $deletedBy, $depth + 1);
    }

    // Delete this document
    $stmt = $pdo->prepare('
        UPDATE documents
        SET deleted_at = NOW(), updated_by = :deleted_by
        WHERE guid = :guid AND deleted_at IS NULL
    ');
    $stmt->execute([
        'guid' => $guid,
        'deleted_by' => $deletedBy
    ]);

    if ($stmt->rowCount() > 0) {
        $count++;
    }

    return $count;
}
