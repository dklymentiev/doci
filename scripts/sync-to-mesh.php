<?php
/**
 * DOCI - Sync documents to Mesh for semantic search
 *
 * Scans files/ directory for .md files and pushes them to Mesh API.
 * Idempotent: uses deterministic GUIDs so re-runs update existing entries.
 *
 * Usage:
 *   docker exec doci php scripts/sync-to-mesh.php [--verbose] [--dry-run]
 *
 * Options:
 *   --verbose   Show each file being synced
 *   --dry-run   Show what would be synced without sending to Mesh
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/mesh.php';

// Parse CLI args
$verbose = in_array('--verbose', $argv ?? []);
$dryRun = in_array('--dry-run', $argv ?? []);

// Directories to skip
$skipPrefixes = [
    '.data/',
    '.git/',
];
foreach (getMeshSkipFolders() as $folder) {
    $skipPrefixes[] = $folder . '/';
}

$filesDir = DOCI_ROOT . '/files';

if (!is_dir($filesDir)) {
    fwrite(STDERR, "ERROR: Files directory not found: $filesDir\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($filesDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$synced = 0;
$skipped = 0;
$errors = 0;
$total = 0;

if ($dryRun) {
    echo "[DRY RUN] No documents will be sent to Mesh\n\n";
}

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'md') {
        continue;
    }

    $total++;
    $fullPath = $file->getPathname();
    $relativePath = substr($fullPath, strlen($filesDir) + 1);

    // Check skip prefixes
    $skip = false;
    foreach ($skipPrefixes as $prefix) {
        if (strncmp($relativePath, $prefix, strlen($prefix)) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        $skipped++;
        continue;
    }

    $content = file_get_contents($fullPath);
    if ($content === false || strlen(trim($content)) < 10) {
        $skipped++;
        if ($verbose) {
            echo "SKIP (empty/short): $relativePath\n";
        }
        continue;
    }

    $guid = getDOCIMeshGuid($relativePath);

    if ($dryRun) {
        if ($verbose) {
            echo "WOULD SYNC: $relativePath -> $guid (" . strlen($content) . " bytes)\n";
        }
        $synced++;
        continue;
    }

    $ok = syncDocumentToMesh($relativePath, $content, $file->getMTime());
    if ($ok) {
        $synced++;
        if ($verbose) {
            echo "OK: $relativePath -> $guid\n";
        }
    } else {
        $errors++;
        echo "ERROR: $relativePath\n";
    }

    // Small delay to avoid overwhelming Mesh embedding queue
    if ($synced % 50 === 0) {
        usleep(500000); // 0.5s every 50 docs
    }
}

echo "\n";
echo "Total .md files found: $total\n";
echo "Synced: $synced\n";
echo "Skipped: $skipped\n";
echo "Errors: $errors\n";

if ($dryRun) {
    echo "\n[DRY RUN] No changes were made.\n";
}

exit($errors > 0 ? 1 : 0);
