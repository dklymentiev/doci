<?php
/**
 * DOCI - AI Integration
 *
 * Provides AI response functionality for threads using AI Gateway CLI mode.
 * Creates a CLI session, sends message with rich context, gets response, closes session.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/documents.php';

// AI Gateway configuration (CLI mode)
// AI_GATEWAY_URL is defined in config.php from environment variable
define('AI_GATEWAY_BASE_URL', AI_GATEWAY_URL);
define('AI_GATEWAY_TIMEOUT', (int)(getenv('AI_GATEWAY_TIMEOUT') ?: 120));

// SSL verification - ALWAYS enabled in production
// Can only be disabled with BOTH conditions: DOCI_ENV=development AND AI_GATEWAY_SSL_VERIFY=false
$isDev = getenv('DOCI_ENV') === 'development';
$sslDisableRequested = getenv('AI_GATEWAY_SSL_VERIFY') === 'false';
define('AI_GATEWAY_SSL_VERIFY', !($isDev && $sslDisableRequested));

if (!AI_GATEWAY_SSL_VERIFY) {
    error_log('[DOCI] WARNING: SSL verification disabled for AI Gateway - DEVELOPMENT ONLY');
}

/**
 * Request AI response for a thread
 *
 * @param string $documentGuid GUID of the source document
 * @param string $quote Selected/quoted text from document
 * @param string $comment User's question or comment
 * @param string $threadGuid GUID of the created thread
 * @param string $username User who created the thread
 * @param string $model AI model to use: haiku, sonnet, opus
 * @return array ['success' => bool, 'content' => string, 'error' => string|null]
 */
function request_ai_response(
    string $documentGuid,
    string $quote,
    string $comment,
    string $threadGuid,
    string $username,
    string $model = 'sonnet'
): array {
    doci_log('ai.request.start', [
        'document_guid' => $documentGuid,
        'thread_guid' => $threadGuid,
        'model' => $model,
        'quote_length' => strlen($quote),
        'comment_length' => strlen($comment)
    ]);

    try {
        // Get document metadata
        $document = get_document_by_guid($documentGuid);
        if (!$document) {
            throw new Exception('Document not found');
        }

        // Get document content
        $documentContent = get_document_content($documentGuid);
        if ($documentContent === null) {
            throw new Exception('Could not read document content');
        }

        // Get document hierarchy
        $hierarchy = get_document_hierarchy($documentGuid);

        // Get parent document content (1 level up) for additional context
        $parentContent = null;
        if (!empty($document['parent_guid'])) {
            $parentContent = get_document_content($document['parent_guid']);
            doci_log('ai.request.parent_context', [
                'parent_guid' => $document['parent_guid'],
                'parent_content_length' => $parentContent !== null ? strlen($parentContent) : 0
            ]);
        }

        // Build context
        $context = build_ai_context($document, $hierarchy, $documentContent, $parentContent);

        // Build messages
        $systemPrompt = build_system_prompt($context);
        $userMessage = build_user_message($quote, $comment);

        doci_log('ai.request.context', [
            'hierarchy_depth' => count($hierarchy),
            'document_length' => strlen($documentContent),
            'system_prompt_length' => strlen($systemPrompt),
            'user_message_length' => strlen($userMessage),
            'model' => $model
        ]);

        // Build prompt record for inclusion in thread file
        $promptRecord = "SYSTEM:\n" . $systemPrompt . "\n\nUSER:\n" . $userMessage;

        // Validate input size (prevent excessive AI costs)
        $totalInputLength = strlen($systemPrompt) + strlen($userMessage);
        if ($totalInputLength > 200000) {
            throw new Exception('Input too large for AI processing (' . round($totalInputLength / 1000) . 'KB)');
        }

        // Call AI Gateway (CLI mode - uses short model names: haiku, sonnet, opus)
        $response = call_ai_gateway($systemPrompt, $userMessage, $model);

        if (!$response['success']) {
            throw new Exception($response['error'] ?? 'AI Gateway error');
        }

        doci_log('ai.request.success', [
            'thread_guid' => $threadGuid,
            'response_length' => strlen($response['content'])
        ]);

        // Append AI response to thread file
        $appendResult = append_ai_response_to_thread($threadGuid, $response['content'], $model, $promptRecord);
        if (!$appendResult) {
            doci_log('ai.request.append_failed', ['thread_guid' => $threadGuid], 'WARN');
        }

        return [
            'success' => true,
            'content' => $response['content'],
            'model' => $model
        ];

    } catch (Exception $e) {
        doci_log('ai.request.error', [
            'thread_guid' => $threadGuid,
            'error' => $e->getMessage()
        ], 'ERROR');

        // Append error notice to thread
        append_ai_error_to_thread($threadGuid, $e->getMessage());

        return [
            'success' => false,
            'content' => null,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get document content by GUID
 */
function get_document_content(string $guid): ?string {
    $document = get_document_by_guid($guid);
    if (!$document || empty($document['path'])) {
        return null;
    }

    $filePath = __DIR__ . '/files/' . $document['path'];
    if (!file_exists($filePath)) {
        return null;
    }

    return file_get_contents($filePath);
}

/**
 * Build AI context from document data
 */
function build_ai_context(array $document, array $hierarchy, string $content, ?string $parentContent = null): array {
    // Build hierarchy path
    $hierarchyPath = array_map(function($h) {
        return $h['title'] ?? 'Untitled';
    }, $hierarchy);

    // Build hierarchy links
    $hierarchyLinks = array_map(function($h) {
        return '- /' . $h['guid'] . ' - ' . ($h['title'] ?? 'Untitled');
    }, $hierarchy);

    return [
        'title' => $document['title'] ?? 'Untitled',
        'path' => implode(' > ', $hierarchyPath),
        'links' => implode("\n", $hierarchyLinks),
        'content' => $content,
        'parentContent' => $parentContent,
        'doc_type' => $document['doc_type'] ?? 'document'
    ];
}

/**
 * Build system prompt with document context
 */
function build_system_prompt(array $context): string {
    $prompt = "You are a helpful assistant in the DOCI documentation system.\n\n";

    $prompt .= "DOCUMENT HIERARCHY:\n";
    $prompt .= $context['path'] . "\n\n";

    if (!empty($context['links'])) {
        $prompt .= "LINKS TO PARENT DOCUMENTS:\n";
        $prompt .= $context['links'] . "\n\n";
    }

    $prompt .= "CURRENT DOCUMENT: " . $context['title'] . "\n";
    $prompt .= "TYPE: " . $context['doc_type'] . "\n\n";

    if (!empty($context['parentContent'])) {
        $prompt .= "PARENT DOCUMENT CONTENT:\n";
        $prompt .= "---\n";
        $prompt .= $context['parentContent'] . "\n";
        $prompt .= "---\n\n";
    }

    $prompt .= "CURRENT DOCUMENT CONTENT:\n";
    $prompt .= "---\n";
    $prompt .= $context['content'] . "\n";
    $prompt .= "---\n\n";

    $prompt .= "INSTRUCTIONS:\n";
    $prompt .= "- Answer questions based on the document content above\n";
    $prompt .= "- Use markdown formatting in your response\n";
    $prompt .= "- Be concise but thorough\n";
    $prompt .= "- If the answer is not in the document, say so\n";
    $prompt .= "- You can reference parent documents by their links if relevant\n";

    return $prompt;
}

/**
 * Build user message with quote and question
 */
function build_user_message(string $quote, string $comment): string {
    $message = "";

    if (!empty($quote)) {
        $message .= "SELECTED TEXT FROM DOCUMENT:\n";
        $message .= "> " . str_replace("\n", "\n> ", $quote) . "\n\n";
    }

    $message .= "QUESTION:\n";
    $message .= $comment;

    return $message;
}

/**
 * Call AI Gateway using CLI session
 *
 * 1. Create session with model
 * 2. Send message (system prompt + user message combined)
 * 3. Get response
 * 4. Close session
 */
function call_ai_gateway(string $systemPrompt, string $userMessage, string $model): array {
    $sessionId = null;

    try {
        // Step 1: Create CLI session
        doci_log('ai.gateway.session.create', ['model' => $model]);

        $createPayload = [
            'service' => 'doci',
            'model' => $model,
            'system_prompt' => $systemPrompt
        ];

        $createResult = ai_gateway_request('POST', '/sessions', $createPayload);

        if (!$createResult['success']) {
            return $createResult;
        }

        $sessionId = $createResult['data']['session_id'] ?? null;
        if (!$sessionId) {
            return ['success' => false, 'error' => 'No session_id in response'];
        }

        doci_log('ai.gateway.session.created', ['session_id' => $sessionId]);

        // Step 2: Send chat message
        doci_log('ai.gateway.chat.send', [
            'session_id' => $sessionId,
            'message_length' => strlen($userMessage)
        ]);

        $chatPayload = ['content' => $userMessage];
        $chatResult = ai_gateway_request('POST', "/sessions/{$sessionId}/chat", $chatPayload);

        // Step 3: Close session (even if chat failed)
        doci_log('ai.gateway.session.close', ['session_id' => $sessionId]);
        ai_gateway_request('DELETE', "/sessions/{$sessionId}");
        $sessionId = null;

        if (!$chatResult['success']) {
            return $chatResult;
        }

        $content = $chatResult['data']['content'] ?? '';
        if (empty($content)) {
            doci_log('ai.gateway.empty_response', [], 'WARN');
            return ['success' => false, 'error' => 'Empty response from AI'];
        }

        doci_log('ai.gateway.success', [
            'response_length' => strlen($content),
            'usage' => $chatResult['data']['usage'] ?? null
        ]);

        return ['success' => true, 'content' => $content];

    } catch (Exception $e) {
        // Cleanup session on error
        if ($sessionId) {
            doci_log('ai.gateway.session.cleanup', ['session_id' => $sessionId]);
            ai_gateway_request('DELETE', "/sessions/{$sessionId}");
        }

        doci_log('ai.gateway.exception', ['error' => $e->getMessage()], 'ERROR');
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Make request to AI Gateway
 */
function ai_gateway_request(string $method, string $endpoint, ?array $payload = null): array {
    $url = AI_GATEWAY_BASE_URL . $endpoint;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => AI_GATEWAY_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => AI_GATEWAY_SSL_VERIFY,
        CURLOPT_SSL_VERIFYHOST => AI_GATEWAY_SSL_VERIFY ? 2 : 0
    ]);

    if ($payload !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        doci_log('ai.gateway.curl_error', [
            'endpoint' => $endpoint,
            'error' => $error
        ], 'ERROR');
        return ['success' => false, 'error' => 'Connection error: ' . $error];
    }

    if ($httpCode >= 400) {
        doci_log('ai.gateway.http_error', [
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'response' => substr($response, 0, 500)
        ], 'ERROR');
        return ['success' => false, 'error' => "HTTP error: {$httpCode}"];
    }

    $data = json_decode($response, true);
    if ($response && !$data) {
        doci_log('ai.gateway.json_error', [
            'endpoint' => $endpoint,
            'response' => substr($response, 0, 500)
        ], 'ERROR');
        return ['success' => false, 'error' => 'Invalid JSON response'];
    }

    if (isset($data['error'])) {
        doci_log('ai.gateway.api_error', [
            'endpoint' => $endpoint,
            'error' => $data['error']
        ], 'ERROR');
        return ['success' => false, 'error' => $data['error']];
    }

    return ['success' => true, 'data' => $data];
}

/**
 * Replace AI placeholder with response in thread file
 */
function append_ai_response_to_thread(string $threadGuid, string $content, string $model, string $promptRecord = ''): bool {
    $thread = get_document_by_guid($threadGuid);
    if (!$thread || empty($thread['path'])) {
        return false;
    }

    $filePath = __DIR__ . '/files/' . $thread['path'];
    if (!file_exists($filePath)) {
        return false;
    }

    $fileContent = file_get_contents($filePath);
    $modelLabel = ucfirst($model);
    $timestamp = date('Y-m-d H:i');

    // Build prompt details block (collapsible)
    $promptDetails = "";
    if (!empty($promptRecord)) {
        $promptDetails = "<details>\n<summary>Prompt sent to AI</summary>\n" . $promptRecord . "\n</details>\n\n";
    }

    $aiSection = $promptDetails;
    $aiSection .= "**AI Response** ({$modelLabel}, {$timestamp})\n\n";
    $aiSection .= $content . "\n";

    // Replace placeholder with actual response
    // Note: Use preg_replace_callback to avoid $-escaping issues in AI content
    $placeholder = '/<!-- ai-pending:[a-z]+ -->\n?/';
    if (preg_match($placeholder, $fileContent)) {
        $newContent = preg_replace_callback($placeholder, function() use ($aiSection) {
            return $aiSection;
        }, $fileContent);
        return file_put_contents($filePath, $newContent) !== false;
    }

    // Fallback: append if no placeholder found
    $aiSection = "\n\n---\n\n" . $aiSection;
    return file_put_contents($filePath, $aiSection, FILE_APPEND) !== false;
}

/**
 * Replace AI placeholder with error notice in thread file
 */
function append_ai_error_to_thread(string $threadGuid, string $error): bool {
    $thread = get_document_by_guid($threadGuid);
    if (!$thread || empty($thread['path'])) {
        return false;
    }

    $filePath = __DIR__ . '/files/' . $thread['path'];
    if (!file_exists($filePath)) {
        return false;
    }

    $fileContent = file_get_contents($filePath);
    $timestamp = date('Y-m-d H:i');

    $errorSection = "_AI was unable to respond ({$timestamp}): {$error}_\n";

    // Replace placeholder with error
    $placeholder = '/<!-- ai-pending:[a-z]+ -->\n?/';
    if (preg_match($placeholder, $fileContent)) {
        $newContent = preg_replace($placeholder, $errorSection, $fileContent);
        return file_put_contents($filePath, $newContent) !== false;
    }

    // Fallback: append if no placeholder found
    $errorSection = "\n\n---\n\n" . $errorSection;
    return file_put_contents($filePath, $errorSection, FILE_APPEND) !== false;
}

/**
 * Continue conversation in a thread
 *
 * @param string $threadGuid GUID of the thread
 * @param string $threadContent Current thread content (before user's new message)
 * @param string $userMessage User's new message
 * @param string|null $originalGuid GUID of the original document (for context)
 * @param string $username User who sent the message
 * @param string $model AI model to use
 * @return array ['success' => bool, 'error' => string|null]
 */
function continue_thread_conversation(
    string $threadGuid,
    string $threadContent,
    string $userMessage,
    ?string $originalGuid,
    string $username,
    string $model = 'sonnet'
): array {
    doci_log('ai.continue.start', [
        'thread_guid' => $threadGuid,
        'original_guid' => $originalGuid,
        'message_length' => strlen($userMessage),
        'model' => $model
    ]);

    try {
        // Build system prompt with thread context
        $systemPrompt = "You are a helpful assistant continuing a conversation in a documentation thread.\n\n";
        $systemPrompt .= "THREAD CONTENT (previous conversation):\n";
        $systemPrompt .= "---\n";
        $systemPrompt .= $threadContent . "\n";
        $systemPrompt .= "---\n\n";

        // Add original document context if available
        if ($originalGuid) {
            $document = get_document_by_guid($originalGuid);
            if ($document) {
                $documentContent = get_document_content($originalGuid);
                if ($documentContent) {
                    $systemPrompt .= "ORIGINAL DOCUMENT: " . ($document['title'] ?? 'Untitled') . "\n";
                    $systemPrompt .= "---\n";
                    $systemPrompt .= $documentContent . "\n";
                    $systemPrompt .= "---\n\n";
                }
            }
        }

        $systemPrompt .= "INSTRUCTIONS:\n";
        $systemPrompt .= "- Continue the conversation naturally\n";
        $systemPrompt .= "- Reference previous messages when relevant\n";
        $systemPrompt .= "- Use markdown formatting\n";
        $systemPrompt .= "- Be concise but thorough\n";

        doci_log('ai.continue.context', [
            'system_prompt_length' => strlen($systemPrompt),
            'model' => $model
        ]);

        // Build prompt record for inclusion in thread file
        $promptRecord = "SYSTEM:\n" . $systemPrompt . "\n\nUSER:\n" . $userMessage;

        // Validate input size
        $totalInputLength = strlen($systemPrompt) + strlen($userMessage);
        if ($totalInputLength > 200000) {
            throw new Exception('Input too large for AI processing (' . round($totalInputLength / 1000) . 'KB)');
        }

        // Call AI Gateway
        $response = call_ai_gateway($systemPrompt, $userMessage, $model);

        if (!$response['success']) {
            throw new Exception($response['error'] ?? 'AI Gateway error');
        }

        doci_log('ai.continue.success', [
            'thread_guid' => $threadGuid,
            'response_length' => strlen($response['content'])
        ]);

        // Append AI response to thread file
        $appendResult = append_ai_response_to_thread($threadGuid, $response['content'], $model, $promptRecord);
        if (!$appendResult) {
            doci_log('ai.continue.append_failed', ['thread_guid' => $threadGuid], 'WARN');
        }

        return [
            'success' => true,
            'content' => $response['content']
        ];

    } catch (Exception $e) {
        doci_log('ai.continue.error', [
            'thread_guid' => $threadGuid,
            'error' => $e->getMessage()
        ], 'ERROR');

        // Append error notice to thread
        append_ai_error_to_thread($threadGuid, $e->getMessage());

        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
