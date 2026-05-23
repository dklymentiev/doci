<?php
/**
 * AI Response API
 *
 * Called via AJAX to process AI requests for threads.
 * POST /api/ai-response.php { threadGuid }
 *
 * Reads AI context from .ai-pending.json file created during thread creation.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../documents.php';
require_once __DIR__ . '/../ai.php';

header('Content-Type: application/json');

// Require authentication
$auth = require_api_auth();
$authUser = $auth['user'];

// CSRF protection
require_csrf_token();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $threadGuid = $input['threadGuid'] ?? null;

    if (!$threadGuid) {
        throw new Exception('threadGuid is required');
    }

    // Get thread document to find context file
    $thread = get_document_by_guid($threadGuid);
    if (!$thread) {
        throw new Exception('Thread not found');
    }

    // Check for context files (initial or reply)
    $threadDir = dirname(dirname(__DIR__) . '/files/' . $thread['path']);
    $initialContextPath = $threadDir . '/.ai-pending.json';
    $replyContextPath = $threadDir . '/.ai-reply-pending.json';

    $contextPath = null;
    $isReply = false;

    if (file_exists($replyContextPath)) {
        $contextPath = $replyContextPath;
        $isReply = true;
    } elseif (file_exists($initialContextPath)) {
        $contextPath = $initialContextPath;
    } else {
        throw new Exception('No pending AI request for this thread');
    }

    $context = json_decode(file_get_contents($contextPath), true);
    if (!$context) {
        throw new Exception('Invalid AI context file');
    }

    doci_log('ai-response.start', [
        'thread_guid' => $threadGuid,
        'original_guid' => $context['originalGuid'],
        'model' => $context['model'],
        'is_reply' => $isReply
    ]);

    // Call appropriate AI function
    if ($isReply) {
        // Continue conversation
        $result = continue_thread_conversation(
            $context['threadGuid'],
            $context['threadContent'],
            $context['message'],
            $context['originalGuid'],
            $context['username'],
            $context['model']
        );
    } else {
        // Initial response
        $result = request_ai_response(
            $context['originalGuid'],
            $context['quote'],
            $context['comment'],
            $context['threadGuid'],
            $context['username'],
            $context['model']
        );
    }

    // Delete context file after processing
    @unlink($contextPath);

    doci_log('ai-response.complete', [
        'thread_guid' => $threadGuid,
        'success' => $result['success'],
        'error' => $result['error'] ?? null
    ]);

    echo json_encode([
        'success' => $result['success'],
        'content' => $result['content'] ?? null,
        'error' => $result['error'] ?? null
    ]);

} catch (Exception $e) {
    doci_log('ai-response.error', ['error' => $e->getMessage()], 'ERROR');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
