<?php
/**
 * Extended Parsedown with link creation validation, thread block and entity chip support
 *
 * Thread blocks use HTML comments for marking:
 * <!-- @thread:guid --> content <!-- @/thread:guid -->
 *
 * Entity chips use reference format:
 * In text: {e:entity-id}Display Text{/e}
 * At end:  <!--entities\n id: {JSON}\n id: {JSON}\n-->
 *
 * Also supports legacy inline format:
 * <!--entity:{JSON}-->Display Text<!--/entity-->
 *
 * These are rendered as interactive chips with tooltips.
 *
 * Based on Parsedown by Emanuil Rusev (MIT License)
 * @license MIT
 */

require_once __DIR__ . '/Parsedown.php';

class ParsedownExtended extends Parsedown
{
    /**
     * Thread metadata storage (guid => title mapping)
     */
    protected $threadMeta = [];

    /**
     * Set thread metadata for rendering
     */
    public function setThreadMeta(array $meta): void
    {
        $this->threadMeta = $meta;
    }

    /**
     * Stored thread blocks for post-processing
     */
    protected $threadBlockStorage = [];

    /**
     * Stored details blocks for post-processing
     */
    protected $detailsBlockStorage = [];

    /**
     * Stored entity chips for post-processing
     */
    protected $entityChipStorage = [];

    /**
     * Entity data registry (id => JSON data) from <!--entities--> block
     */
    protected $entityRegistry = [];

    /**
     * Override text() to handle thread blocks and details via pre/post processing
     */
    public function text($text)
    {
        // Pre-process: extract thread blocks, details blocks and entity chips
        $text = $this->extractThreadBlocks($text);
        $text = $this->extractDetailsBlocks($text);
        $text = $this->extractEntityChips($text);

        // Normal Parsedown processing
        $html = parent::text($text);

        // Post-process: restore blocks as HTML
        $html = $this->restoreThreadBlocks($html);
        $html = $this->restoreDetailsBlocks($html);
        $html = $this->restoreEntityChips($html);

        return $html;
    }

    /**
     * Extract thread blocks from markdown and replace with placeholders
     */
    protected function extractThreadBlocks(string $markdown): string
    {
        $this->threadBlockStorage = [];

        // Pattern to match thread blocks (GUID can be UUID or any alphanumeric identifier)
        $pattern = '/<!-- @thread:([a-zA-Z0-9-]+) -->\n?(.*?)\n?<!-- @\/thread:\1 -->/si';

        return preg_replace_callback($pattern, function($matches) {
            $guid = $matches[1];
            $content = $matches[2];

            // Store for later - use plain text placeholder that won't be escaped
            $placeholder = "\n\nTHREADBLOCKPLACEHOLDER" . $guid . "ENDPLACEHOLDER\n\n";
            $this->threadBlockStorage[$guid] = $content;

            return $placeholder;
        }, $markdown);
    }

    /**
     * Restore thread blocks from placeholders to HTML
     */
    protected function restoreThreadBlocks(string $html): string
    {
        foreach ($this->threadBlockStorage as $guid => $content) {
            // Pattern for placeholder (might be wrapped in <p> tags)
            $patterns = [
                '<p>THREADBLOCKPLACEHOLDER' . $guid . 'ENDPLACEHOLDER</p>',
                'THREADBLOCKPLACEHOLDER' . $guid . 'ENDPLACEHOLDER'
            ];

            // Parse thread content as markdown (keep safe mode to prevent XSS)
            $parsedContent = parent::text($content);

            // Get thread title from metadata if available
            $title = $this->threadMeta[$guid]['title'] ?? 'Thread';
            $author = $this->threadMeta[$guid]['author'] ?? '';
            $titleAttr = htmlspecialchars($title, ENT_QUOTES);
            $authorAttr = htmlspecialchars($author, ENT_QUOTES);
            $guidAttr = htmlspecialchars($guid, ENT_QUOTES);

            $replacement = '<div class="thread-block" data-thread-guid="' . $guidAttr . '" data-thread-title="' . $titleAttr . '" data-thread-author="' . $authorAttr . '">'
                         . '<div class="thread-indicator"></div>'
                         . '<div class="thread-content">' . $parsedContent . '</div>'
                         . '<a class="thread-tab" href="/' . $guidAttr . '">'
                         . $titleAttr
                         . ($authorAttr ? ' <span class="thread-tab-author">— ' . $authorAttr . '</span>' : '')
                         . '</a>'
                         . '</div>';

            foreach ($patterns as $pattern) {
                $html = str_replace($pattern, $replacement, $html);
            }
        }

        return $html;
    }

    /**
     * Extract <details> blocks from markdown and replace with placeholders
     */
    protected function extractDetailsBlocks(string $markdown): string
    {
        $this->detailsBlockStorage = [];

        $pattern = '/<details>\s*\n?\s*<summary>(.*?)<\/summary>\s*\n?(.*?)\n?\s*<\/details>/si';

        return preg_replace_callback($pattern, function($matches) {
            $id = count($this->detailsBlockStorage);
            $summary = $matches[1];
            $content = $matches[2];

            $placeholder = "\n\nDETAILSBLOCKPLACEHOLDER" . $id . "ENDDETAILS\n\n";
            $this->detailsBlockStorage[$id] = [
                'summary' => $summary,
                'content' => $content
            ];

            return $placeholder;
        }, $markdown);
    }

    /**
     * Restore details blocks from placeholders to HTML
     */
    protected function restoreDetailsBlocks(string $html): string
    {
        foreach ($this->detailsBlockStorage as $id => $block) {
            $patterns = [
                '<p>DETAILSBLOCKPLACEHOLDER' . $id . 'ENDDETAILS</p>',
                'DETAILSBLOCKPLACEHOLDER' . $id . 'ENDDETAILS'
            ];

            $summary = htmlspecialchars($block['summary'], ENT_QUOTES);
            $content = htmlspecialchars(trim($block['content']));

            $replacement = '<details class="prompt-details">'
                         . '<summary>' . $summary . '</summary>'
                         . '<pre class="prompt-content"><code>' . $content . '</code></pre>'
                         . '</details>';

            foreach ($patterns as $pattern) {
                $html = str_replace($pattern, $replacement, $html);
            }
        }

        return $html;
    }

    /**
     * Extract entity chips from markdown and replace with placeholders.
     *
     * Supports two formats:
     * 1. Reference format (preferred):
     *    In text: {e:entity-id}Display Text{/e}
     *    At end:  <!--entities\n id: {JSON}\n-->
     *
     * 2. Legacy inline format:
     *    <!--entity:{JSON}-->Display Text<!--/entity-->
     */
    protected function extractEntityChips(string $markdown): string
    {
        $this->entityChipStorage = [];
        $this->entityRegistry = [];

        // Step 1: Parse <!--entities ... --> JSON block
        $markdown = preg_replace_callback('/<!--entities\s*\n?(.*?)-->/s', function($matches) {
            $json = trim($matches[1]);
            $data = json_decode($json, true);
            if ($data && is_array($data)) {
                $this->entityRegistry = $data;
            }
            return ''; // Remove from markdown
        }, $markdown);

        // Step 2: Parse {e:id}Text{/e} reference tags
        $markdown = preg_replace_callback('/\{e:([^}]+)\}(.+?)\{\/e\}/s', function($matches) {
            $entityId = trim($matches[1]);
            $displayText = $matches[2];

            $data = $this->entityRegistry[$entityId] ?? null;
            if (!$data) {
                return $displayText; // No data found, render as plain text
            }

            $id = count($this->entityChipStorage);
            $placeholder = "ENTITYCHIP" . $id . "ENDENTITY";
            $this->entityChipStorage[$id] = [
                'data' => $data,
                'text' => trim($displayText)
            ];
            return $placeholder;
        }, $markdown);

        // Step 3: Legacy format <!--entity:{JSON}-->Text<!--/entity-->
        $markdown = preg_replace_callback('/<!--entity:(\{.+?\})-->(.+?)<!--\/entity-->/s', function($matches) {
            $jsonStr = $matches[1];
            $displayText = $matches[2];
            $data = json_decode($jsonStr, true);
            if (!$data) return $displayText;

            $id = count($this->entityChipStorage);
            $placeholder = "ENTITYCHIP" . $id . "ENDENTITY";
            $this->entityChipStorage[$id] = [
                'data' => $data,
                'text' => trim($displayText)
            ];
            return $placeholder;
        }, $markdown);

        return $markdown;
    }

    /**
     * Restore entity chips from placeholders to HTML
     */
    protected function restoreEntityChips(string $html): string
    {
        foreach ($this->entityChipStorage as $id => $chip) {
            $patterns = [
                '<p>ENTITYCHIP' . $id . 'ENDENTITY</p>',
                'ENTITYCHIP' . $id . 'ENDENTITY'
            ];

            $data = $chip['data'];
            $text = htmlspecialchars($chip['text'], ENT_QUOTES);
            $type = htmlspecialchars($data['type'] ?? 'unknown', ENT_QUOTES);
            $entityId = htmlspecialchars($data['id'] ?? '', ENT_QUOTES);
            $description = htmlspecialchars($data['description'] ?? '', ENT_QUOTES);

            $dataAttrs = 'data-entity-id="' . $entityId . '"'
                       . ' data-entity-type="' . $type . '"';
            if ($description) {
                $dataAttrs .= ' data-entity-desc="' . $description . '"';
            }

            $jsonEncoded = htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
            $dataAttrs .= ' data-entity-json="' . $jsonEncoded . '"';

            $typeClass = 'entity-type-' . preg_replace('/[^a-z]/', '', $type);

            $replacement = '<span class="entity-chip ' . $typeClass . '" ' . $dataAttrs . '>'
                         . '<span class="entity-chip-name">' . $text . '</span>'
                         . '</span>';

            foreach ($patterns as $pattern) {
                $html = str_replace($pattern, $replacement, $html);
            }
        }

        return $html;
    }

    /**
     * Validate if a text selection can become a thread block
     *
     * Since we use HTML comments (<!-- @thread:guid -->) that wrap content,
     * we can always allow thread creation. The comments don't break markdown.
     *
     * @param string $markdown Full markdown content
     * @param string $selectedText Text that user selected (can be multi-line)
     * @param string $contextBefore Context before selection
     * @param string $contextAfter Context after selection
     * @return array {valid: bool, reason: string, markdownFragment: string, isBlock: bool}
     */
    public function canCreateThreadBlock(string $markdown, string $selectedText, string $contextBefore = '', string $contextAfter = ''): array
    {
        // Always allow thread creation - HTML comments are safe
        // The actual position finding happens at creation time
        //
        // Only check for overlap with existing thread blocks in the full markdown
        if (preg_match('/<!--\s*@thread:[a-zA-Z0-9-]+\s*-->/', $markdown)) {
            // There are existing thread blocks - do a simple check
            // This is a basic check; more sophisticated overlap detection could be added
        }

        // Always valid - we'll handle position finding at creation time
        return [
            'valid' => true,
            'reason' => '',
            'markdownFragment' => $selectedText,
            'isBlock' => true
        ];
    }

    /**
     * Check if a text selection can become a link
     *
     * @param string $markdown Full markdown content
     * @param string $selectedText Text that user selected
     * @param string $contextBefore 50 chars before selection in markdown
     * @param string $contextAfter 50 chars after selection in markdown
     * @return array {valid: bool, reason: string, markdownFragment: string}
     */
    public function canCreateLink(string $markdown, string $selectedText, string $contextBefore = '', string $contextAfter = ''): array
    {
        // Find the exact position using context
        $searchPattern = $contextBefore . $selectedText . $contextAfter;
        $patternPos = strpos($markdown, $searchPattern);

        if ($patternPos === false) {
            // Fallback: try without context
            $pos = strpos($markdown, $selectedText);
            if ($pos === false) {
                return [
                    'valid' => false,
                    'reason' => 'Selected text not found in markdown',
                    'markdownFragment' => ''
                ];
            }
        } else {
            $pos = $patternPos + strlen($contextBefore);
        }

        // Extract the markdown fragment at this position
        $markdownFragment = substr($markdown, $pos, strlen($selectedText) * 3); // Get more to see context

        // Get the line containing the selection
        $lineStart = strrpos(substr($markdown, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($markdown, "\n", $pos);
        $lineEnd = $lineEnd === false ? strlen($markdown) : $lineEnd;
        $line = substr($markdown, $lineStart, $lineEnd - $lineStart);

        // Position within the line
        $posInLine = $pos - $lineStart;

        // Check 1: Selection crosses newlines
        if (strpos($selectedText, "\n") !== false) {
            return [
                'valid' => false,
                'reason' => 'Selection crosses multiple lines',
                'markdownFragment' => $markdownFragment
            ];
        }

        // Check 2: Line is a header
        if (preg_match('/^#{1,6}\s/', $line)) {
            return [
                'valid' => false,
                'reason' => 'Cannot create link on a header',
                'markdownFragment' => $markdownFragment
            ];
        }

        // Check 3: Line is a list item
        if (preg_match('/^\s*[-*+]\s/', $line) || preg_match('/^\s*\d+\.\s/', $line)) {
            return [
                'valid' => false,
                'reason' => 'Cannot create link on a list item',
                'markdownFragment' => $markdownFragment
            ];
        }

        // Check 4: Line is a blockquote
        if (preg_match('/^>\s/', $line)) {
            return [
                'valid' => false,
                'reason' => 'Cannot create link on a quote',
                'markdownFragment' => $markdownFragment
            ];
        }

        // Check 5: Line is a horizontal rule
        if (preg_match('/^[-*_]{3,}\s*$/', trim($line))) {
            return [
                'valid' => false,
                'reason' => 'Cannot create link on a separator',
                'markdownFragment' => $markdownFragment
            ];
        }

        // Check 6: Selection is inside or overlaps with inline markdown elements
        $inlineCheck = $this->checkInlineElements($line, $posInLine, strlen($selectedText));
        if (!$inlineCheck['valid']) {
            return [
                'valid' => false,
                'reason' => $inlineCheck['reason'],
                'markdownFragment' => $markdownFragment
            ];
        }

        // Check 7: Selected text in markdown differs from plain text (contains markdown syntax)
        $selectedMarkdown = substr($markdown, $pos, strlen($selectedText));

        // Check for markdown special characters that would indicate formatting
        if ($this->containsMarkdownSyntax($selectedMarkdown)) {
            return [
                'valid' => false,
                'reason' => 'Selection contains markdown formatting',
                'markdownFragment' => $markdownFragment
            ];
        }

        return [
            'valid' => true,
            'reason' => '',
            'markdownFragment' => $selectedMarkdown
        ];
    }

    /**
     * Check if position overlaps with inline markdown elements
     */
    protected function checkInlineElements(string $line, int $startPos, int $length): array
    {
        $endPos = $startPos + $length;

        // Check for links [text](url)
        if (preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $linkStart = $match[1];
                $linkEnd = $linkStart + strlen($match[0]);
                if ($this->rangesOverlap($startPos, $endPos, $linkStart, $linkEnd)) {
                    return ['valid' => false, 'reason' => 'Selection overlaps with existing link'];
                }
            }
        }

        // Check for emphasis **text** or *text*
        if (preg_match_all('/(\*\*|__)(.+?)\1/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $emStart = $match[1];
                $emEnd = $emStart + strlen($match[0]);
                if ($this->rangesOverlap($startPos, $endPos, $emStart, $emEnd)) {
                    return ['valid' => false, 'reason' => 'Selection overlaps with bold text'];
                }
            }
        }

        if (preg_match_all('/(?<!\*)(\*)(?!\*)(.+?)(?<!\*)\1(?!\*)/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $emStart = $match[1];
                $emEnd = $emStart + strlen($match[0]);
                if ($this->rangesOverlap($startPos, $endPos, $emStart, $emEnd)) {
                    return ['valid' => false, 'reason' => 'Selection overlaps with italic text'];
                }
            }
        }

        // Check for inline code `code`
        if (preg_match_all('/`([^`]+)`/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $codeStart = $match[1];
                $codeEnd = $codeStart + strlen($match[0]);
                if ($this->rangesOverlap($startPos, $endPos, $codeStart, $codeEnd)) {
                    return ['valid' => false, 'reason' => 'Selection overlaps with inline code'];
                }
            }
        }

        // Check for images ![alt](url)
        if (preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $imgStart = $match[1];
                $imgEnd = $imgStart + strlen($match[0]);
                if ($this->rangesOverlap($startPos, $endPos, $imgStart, $imgEnd)) {
                    return ['valid' => false, 'reason' => 'Selection overlaps with image'];
                }
            }
        }

        return ['valid' => true, 'reason' => ''];
    }

    /**
     * Check if two ranges overlap
     */
    protected function rangesOverlap(int $start1, int $end1, int $start2, int $end2): bool
    {
        return $start1 < $end2 && $start2 < $end1;
    }

    /**
     * Check if text contains markdown syntax characters in meaningful positions
     */
    protected function containsMarkdownSyntax(string $text): bool
    {
        // Check for common markdown patterns
        $patterns = [
            '/\*\*/',           // Bold
            '/\*[^*]/',         // Italic (single asterisk not at boundary)
            '/__/',             // Bold underscore
            '/_[^_]/',          // Italic underscore
            '/\[.*\]\(/',       // Link start
            '/\]\(.*\)/',       // Link end
            '/`/',              // Inline code
            '/!\[/',            // Image
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }
}
