<?php
/**
 * Validate if selected text can become a thread link
 *
 * POST /api/validate-selection.php
 * Body: {documentGuid, selectedText, contextBefore, contextAfter}
 * Returns: {valid, reason, markdownFragment}
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ParsedownExtended.php';

header('Content-Type: application/json');

// Require authentication
$auth = require_api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    doci_log('validate.error', ['error' => 'Method not allowed'], 'ERROR');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        doci_log('validate.error', ['error' => 'Invalid JSON input'], 'ERROR');
        throw new Exception('Invalid JSON input');
    }

    $documentGuid = $input['documentGuid'] ?? null;
    $selectedText = $input['selectedText'] ?? '';
    $contextBefore = $input['contextBefore'] ?? '';
    $contextAfter = $input['contextAfter'] ?? '';

    doci_log('validate.start', [
        'documentGuid' => $documentGuid,
        'selectedText' => substr($selectedText, 0, 50) . (strlen($selectedText) > 50 ? '...' : ''),
        'selectedLength' => strlen($selectedText),
        'hasContext' => !empty($contextBefore) || !empty($contextAfter)
    ]);

    if (!$documentGuid) {
        doci_log('validate.error', ['error' => 'documentGuid is required'], 'ERROR');
        throw new Exception('documentGuid is required');
    }
    if (!$selectedText) {
        doci_log('validate.error', ['error' => 'selectedText is required'], 'ERROR');
        throw new Exception('selectedText is required');
    }

    // Get document from database
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT path, title FROM documents WHERE guid = ?");
    $stmt->execute([$documentGuid]);
    $doc = $stmt->fetch();

    if (!$doc) {
        doci_log('validate.error', ['error' => 'Document not found', 'guid' => $documentGuid], 'ERROR');
        throw new Exception('Document not found');
    }

    doci_log('validate.document', [
        'guid' => $documentGuid,
        'title' => $doc['title'],
        'path' => $doc['path']
    ]);

    // Read markdown content
    $filePath = __DIR__ . '/../files/' . $doc['path'];
    if (!file_exists($filePath)) {
        doci_log('validate.error', ['error' => 'File not found', 'path' => $filePath], 'ERROR');
        throw new Exception('Document file not found');
    }

    $markdown = file_get_contents($filePath);

    // Validate using ParsedownExtended - supports both inline and block threads
    $parsedown = new ParsedownExtended();
    $result = $parsedown->canCreateThreadBlock($markdown, $selectedText, $contextBefore, $contextAfter);

    doci_log('validate.result', [
        'valid' => $result['valid'],
        'reason' => $result['reason'] ?? null,
        'isBlock' => $result['isBlock'] ?? false
    ]);

    echo json_encode($result);

} catch (Exception $e) {
    doci_log('validate.exception', ['error' => $e->getMessage()], 'ERROR');
    http_response_code(400);
    echo json_encode([
        'valid' => false,
        'reason' => $e->getMessage(),
        'markdownFragment' => ''
    ]);
}
