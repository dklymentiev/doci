<?php
/**
 * DOCI - Mesh API Integration
 *
 * Helpers for syncing documents to Mesh and running semantic search.
 * Optional integration. Set MESH_API_URL to enable; if unreachable, Mesh
 * sync becomes a no-op (errors are logged, not raised). See mesh-memory:
 * https://github.com/dklymentiev/mesh-memory
 *
 * Endpoints used:
 *   PUT  /doc/{guid}  - Upsert document (content + tags)
 *   POST /search      - Semantic search (query + limit)
 */

define('MESH_API_URL', getenv('MESH_API_URL') ?: 'http://mesh-api:8000');

/**
 * Generate deterministic Mesh GUID from DOCI file path.
 *
 * Format: doc_ + first 8 hex chars of md5(relativePath)
 * This ensures idempotent upsert -- same file always gets same GUID.
 *
 * @param string $relativePath e.g. "projects/my-project.md"
 * @return string e.g. "doc_a1b2c3d4"
 */
function getDOCIMeshGuid(string $relativePath): string {
    return 'doc_' . substr(md5($relativePath), 0, 8);
}

/**
 * Sync a DOCI document to Mesh for indexing.
 *
 * Sends first 4000 chars of content with source:doci tag
 * so search results can be filtered to DOCI docs only.
 *
 * @param string   $relativePath Path relative to files/ (e.g. "projects/readme.md")
 * @param string   $content      Full markdown content
 * @param int|null $fileTime     File modification time (Unix timestamp). Used as created_at/updated_at.
 * @return bool True on success
 */
// Top-level DOCI folders excluded from Mesh indexing.
// Override via env DOCI_MESH_SKIP_FOLDERS="folder1,folder2".
const DOCI_MESH_SKIP_FOLDERS_DEFAULT = 'top-monitor';

function getMeshSkipFolders(): array {
    $env = getenv('DOCI_MESH_SKIP_FOLDERS');
    $raw = ($env !== false && $env !== '') ? $env : DOCI_MESH_SKIP_FOLDERS_DEFAULT;
    return array_filter(array_map('trim', explode(',', $raw)));
}

function syncDocumentToMesh(string $relativePath, string $content, ?int $fileTime = null): bool {
    $guid = getDOCIMeshGuid($relativePath);

    // Extract top-level folder for tag
    $parts = explode('/', $relativePath);
    $folder = count($parts) > 1 ? $parts[0] : 'root';

    if (in_array($folder, getMeshSkipFolders(), true)) {
        return true;
    }

    $tags = [
        'source:doci',
        'doci-path:' . $relativePath,
        'doci-folder:' . $folder,
    ];

    // Mesh uses first 4000 chars for embedding
    $truncatedContent = mb_substr($content, 0, 4000);

    $data = [
        'content' => $truncatedContent,
        'tags' => $tags,
        'source' => 'api',
    ];

    // Pass file modification time so Mesh stores real dates, not sync date
    if ($fileTime !== null) {
        $isoDate = gmdate('Y-m-d\TH:i:s\Z', $fileTime);
        $data['created_at'] = $isoDate;
        $data['updated_at'] = $isoDate;
    }

    $payload = json_encode($data, JSON_UNESCAPED_UNICODE);

    $ch = curl_init(MESH_API_URL . '/doc/' . $guid);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[DOCI] Mesh sync error for $relativePath: $error");
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    error_log("[DOCI] Mesh sync failed for $relativePath: HTTP $httpCode - $response");
    return false;
}

/**
 * Search DOCI documents via Mesh semantic search.
 *
 * Uses Mesh server-side tags filter (source:doci) to return only DOCI documents.
 * Optionally filters by folder using doci-folder: tag.
 *
 * @param string      $query   Search query text
 * @param int         $limit   Max results to return
 * @param string|null $folder  Optional folder name to scope search (e.g. "operator")
 * @return array Array of search results with keys: guid, content, tags, similarity_score, created_at
 */
function searchMesh(string $query, int $limit = 20, ?string $folder = null): array {
    if (empty(trim($query))) {
        return [];
    }

    // Build tags filter -- always filter by source:doci
    $tags = ['source:doci'];
    if ($folder !== null && $folder !== '') {
        $tags[] = 'doci-folder:' . $folder;
    }

    $payload = json_encode([
        'query' => $query,
        'limit' => $limit,
        'tags' => $tags,
    ]);

    $ch = curl_init(MESH_API_URL . '/search');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[DOCI] Mesh search error: $error");
        return [];
    }

    if ($httpCode !== 200) {
        error_log("[DOCI] Mesh search failed: HTTP $httpCode - $response");
        return [];
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['results'])) {
        return [];
    }

    return $data['results'];
}
