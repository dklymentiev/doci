<?php
/**
 * Scan documents for broken internal links
 * Run: php scan-broken-links.php
 * Or via cron: 0 3 * * * php /path/to/scan-broken-links.php
 *
 * Checks:
 * - Markdown links: [text](path)
 * - Relative paths: ./file.md, ../folder/file.md
 * - Absolute paths: /folder/file.md
 *
 * Logs broken links to broken_links table
 */

require_once __DIR__ . '/../config.php';

$filesDir = __DIR__ . '/../files';
$pdo = get_db();

// Stats
$filesScanned = 0;
$linksChecked = 0;
$brokenFound = 0;
$newBroken = 0;

// Load all known paths from DB
$knownPaths = [];
$stmt = $pdo->query("SELECT path, guid FROM documents WHERE deleted_at IS NULL");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Store with and without extension
    $knownPaths[$row['path']] = $row['guid'];
    $pathNoExt = preg_replace('/\.(md|html)$/', '', $row['path']);
    $knownPaths[$pathNoExt] = $row['guid'];
}
echo "Loaded " . count($knownPaths) . " known paths\n\n";

// Prepare insert statement
$insertStmt = $pdo->prepare("
    INSERT INTO broken_links (source_path, source_guid, link_text, link_target, link_type, error_type)
    VALUES (:source_path, :source_guid, :link_text, :link_target, :link_type, :error_type)
    ON CONFLICT (source_path, link_target) DO UPDATE SET
        found_at = NOW(),
        link_text = :link_text,
        error_type = :error_type,
        fixed_at = NULL
");

// Clear old fixed links that are still broken
$pdo->exec("UPDATE broken_links SET fixed_at = NULL WHERE fixed_at IS NOT NULL");

// Scan all markdown files
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($filesDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if (!$file->isFile()) continue;
    if ($file->getExtension() !== 'md') continue;

    $fullPath = $file->getPathname();
    $relativePath = str_replace($filesDir . '/', '', $fullPath);

    // Skip hidden
    if (strpos($relativePath, '.') === 0) continue;
    if (strpos($relativePath, '/.') !== false) continue;

    $filesScanned++;
    $content = file_get_contents($fullPath);
    $sourceGuid = $knownPaths[$relativePath] ?? null;
    $sourceDir = dirname($relativePath);

    // Find all markdown links: [text](url)
    preg_match_all('/\[([^\]]*)\]\(([^)]+)\)/', $content, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $linkText = $match[1];
        $linkTarget = $match[2];

        // Skip external links
        if (preg_match('/^https?:\/\//', $linkTarget)) {
            continue;
        }

        // Skip anchors only
        if (strpos($linkTarget, '#') === 0) {
            continue;
        }

        // Skip mailto, tel, etc
        if (preg_match('/^[a-z]+:/', $linkTarget)) {
            continue;
        }

        $linksChecked++;

        // Remove anchor from link
        $linkPath = preg_replace('/#.*$/', '', $linkTarget);

        // Resolve relative path
        $resolvedPath = resolvePath($linkPath, $sourceDir);

        // Check if target exists
        $exists = checkLinkExists($resolvedPath, $knownPaths, $filesDir);

        if (!$exists) {
            $brokenFound++;

            try {
                $insertStmt->execute([
                    ':source_path' => $relativePath,
                    ':source_guid' => $sourceGuid,
                    ':link_text' => mb_substr($linkText, 0, 200),
                    ':link_target' => $linkTarget,
                    ':link_type' => 'internal',
                    ':error_type' => 'not_found'
                ]);

                if ($insertStmt->rowCount() > 0) {
                    $newBroken++;
                    echo "[!] $relativePath -> $linkTarget\n";
                }
            } catch (Exception $e) {
                echo "[E] DB error: " . $e->getMessage() . "\n";
            }
        }
    }
}

// Mark links as fixed if they now exist
$pdo->exec("
    UPDATE broken_links SET fixed_at = NOW()
    WHERE fixed_at IS NULL
    AND found_at < NOW() - INTERVAL '1 minute'
");

echo "\n=== Scan complete ===\n";
echo "Files scanned: $filesScanned\n";
echo "Links checked: $linksChecked\n";
echo "Broken found: $brokenFound\n";
echo "New broken: $newBroken\n";

// Show summary
$stmt = $pdo->query("SELECT count(*) FROM broken_links WHERE fixed_at IS NULL");
echo "\nTotal unfixed broken links: " . $stmt->fetchColumn() . "\n";

/**
 * Resolve relative path to absolute
 */
function resolvePath($linkPath, $sourceDir) {
    // Absolute path (starts with /)
    if (strpos($linkPath, '/') === 0) {
        return ltrim($linkPath, '/');
    }

    // Relative path
    if ($sourceDir === '.' || $sourceDir === '') {
        $resolved = $linkPath;
    } else {
        $resolved = $sourceDir . '/' . $linkPath;
    }

    // Normalize ../
    $parts = explode('/', $resolved);
    $normalized = [];
    foreach ($parts as $part) {
        if ($part === '..') {
            array_pop($normalized);
        } elseif ($part !== '.' && $part !== '') {
            $normalized[] = $part;
        }
    }

    return implode('/', $normalized);
}

/**
 * Check if link target exists
 */
function checkLinkExists($path, $knownPaths, $filesDir) {
    // Remove .html extension if present (we use .md internally)
    $path = preg_replace('/\.html$/', '', $path);

    // Check in known paths (with and without .md)
    if (isset($knownPaths[$path])) {
        return true;
    }
    if (isset($knownPaths[$path . '.md'])) {
        return true;
    }

    // Check if file exists on disk
    $fullPath = $filesDir . '/' . $path;
    if (file_exists($fullPath)) {
        return true;
    }
    if (file_exists($fullPath . '.md')) {
        return true;
    }
    if (file_exists($fullPath . '.html')) {
        return true;
    }

    // Check if it's a directory
    if (is_dir($fullPath)) {
        return true;
    }

    return false;
}
