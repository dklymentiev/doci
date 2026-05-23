<?php
/**
 * DOCI - Git Operations Library
 *
 * Secure git commit and push operations with author validation.
 */

require_once __DIR__ . '/../config.php';

// Git configuration
// GIT_REPO_ROOT is the git repository root (where .git is)
// GIT_FILES_PATH is where content files are stored
if (!defined('GIT_REPO_ROOT')) {
    define('GIT_REPO_ROOT', __DIR__ . '/..');
}
if (!defined('GIT_REPO_PATH')) {
    define('GIT_REPO_PATH', __DIR__ . '/../files');
}
// Prefix for file paths in git (relative to repo root)
if (!defined('GIT_FILES_PREFIX')) {
    define('GIT_FILES_PREFIX', 'files/');
}

/**
 * Validate author name - must be alphanumeric with underscores/hyphens only
 * This prevents command injection via git --author
 *
 * @param string $author Author name to validate
 * @return bool True if valid, false otherwise
 */
function validate_git_author(string $author): bool {
    return preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $author) === 1;
}

/**
 * Commit and push to git (secure version)
 *
 * Uses environment variables for author instead of --author flag to prevent
 * command injection. Author name is strictly validated.
 *
 * @param string $path File path relative to repo
 * @param string $message Commit message
 * @param string $author Author name (will be validated)
 * @return array Result with 'success' or 'error' key
 */
function git_commit_and_push(string $path, string $message, string $author): array {
    $repoPath = GIT_REPO_PATH;

    // Strict validation of author to prevent command injection
    if (!validate_git_author($author)) {
        doci_log('git.invalid_author', ['author' => substr($author, 0, 20)], 'WARN');
        $author = 'unknown';
    }

    // Construct safe email
    $domain = getenv('DOCI_DOMAIN') ?: 'doci.local';
    $email = $author . '@' . preg_replace('/[^a-zA-Z0-9.-]/', '', $domain);

    // Change to repo directory
    $oldCwd = getcwd();
    if (!chdir($repoPath)) {
        return ['error' => 'Cannot access repository directory'];
    }

    try {
        // Stage the file
        $output = [];
        $code = 0;
        exec("git add " . escapeshellarg($path) . " 2>&1", $output, $code);
        if ($code !== 0) {
            doci_log('git.add_failed', ['path' => $path, 'output' => implode("\n", $output)], 'ERROR');
            return ['error' => 'git add failed'];
        }

        // Check if there are changes to commit
        exec("git diff --cached --quiet 2>&1", $output, $code);
        if ($code === 0) {
            return ['success' => true, 'message' => 'No changes to commit'];
        }

        // Commit using environment variables for author (safer than --author flag)
        $output = [];
        $env = [
            'GIT_AUTHOR_NAME' => $author,
            'GIT_AUTHOR_EMAIL' => $email,
            'GIT_COMMITTER_NAME' => $author,
            'GIT_COMMITTER_EMAIL' => $email
        ];
        $envString = '';
        foreach ($env as $key => $value) {
            $envString .= escapeshellarg($key) . '=' . escapeshellarg($value) . ' ';
        }
        $commitCmd = $envString . 'git commit -m ' . escapeshellarg($message) . ' 2>&1';
        exec($commitCmd, $output, $code);

        if ($code !== 0) {
            doci_log('git.commit_failed', ['output' => implode("\n", $output)], 'ERROR');
            return ['error' => 'git commit failed'];
        }

        // Get commit hash
        $hash = trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?? '');

        // Log the commit
        doci_log('git.commit', ['hash' => $hash, 'author' => $author, 'path' => $path]);

        // Push to origin (async, log result to file)
        $pushLogFile = DOCI_LOG_FILE ?? '/var/log/doci/app.log';
        $pushCmd = sprintf(
            '(git push origin main 2>&1 || echo "[DOCI] git push FAILED") >> %s &',
            escapeshellarg($pushLogFile)
        );
        exec($pushCmd);

        return [
            'success' => true,
            'hash' => $hash,
            'message' => $message
        ];

    } finally {
        chdir($oldCwd);
    }
}

/**
 * Get git commit history for a file
 *
 * @param string $path File path relative to files dir (e.g., "docs/api.md")
 * @param int $limit Maximum number of commits to return
 * @return array List of commits with hash, message, author, date
 */
function git_get_file_history(string $path, int $limit = 3): array {
    $repoRoot = GIT_REPO_ROOT;

    // Sanitize path
    $path = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $path);
    if (empty($path)) {
        return [];
    }

    // Add files prefix for git path
    $gitPath = GIT_FILES_PREFIX . $path;

    $oldCwd = getcwd();
    if (!chdir($repoRoot)) {
        return [];
    }

    // Set HOME for www-data to find git config
    $oldHome = getenv('HOME');
    putenv('HOME=/var/www');

    try {
        $output = [];
        $cmd = sprintf(
            'git log --oneline -n %d --format="%%h|%%s|%%an|%%ar" -- %s 2>/dev/null',
            min($limit, 10),
            escapeshellarg($gitPath)
        );
        exec($cmd, $output);

        $commits = [];
        foreach ($output as $line) {
            $parts = explode('|', $line, 4);
            if (count($parts) >= 2) {
                $commits[] = [
                    'hash' => $parts[0],
                    'message' => $parts[1],
                    'author' => $parts[2] ?? '',
                    'date' => $parts[3] ?? '',
                ];
            }
        }

        return $commits;
    } finally {
        chdir($oldCwd);
        // Restore HOME
        if ($oldHome !== false) {
            putenv('HOME=' . $oldHome);
        } else {
            putenv('HOME');
        }
    }
}

/**
 * Get Gitea commit URL base
 *
 * @return string|null Base URL for commits or null if not configured
 */
function git_get_commit_url_base(): ?string {
    // Parse from git remote
    $repoRoot = GIT_REPO_ROOT;
    $oldCwd = getcwd();

    if (!chdir($repoRoot)) {
        return null;
    }

    // Set HOME for www-data to find git config
    $oldHome = getenv('HOME');
    putenv('HOME=/var/www');

    try {
        $remote = trim(shell_exec('git remote get-url origin 2>/dev/null') ?? '');

        // Parse: https://user:token@git.example.com/owner/repo.git
        if (preg_match('#https?://[^@]+@([^/]+)/([^/]+)/([^/]+?)(?:\.git)?$#', $remote, $m)) {
            return "https://{$m[1]}/{$m[2]}/{$m[3]}/commit";
        }

        // Parse: https://git.example.com/owner/repo.git
        if (preg_match('#https?://([^/]+)/([^/]+)/([^/]+?)(?:\.git)?$#', $remote, $m)) {
            return "https://{$m[1]}/{$m[2]}/{$m[3]}/commit";
        }

        return null;
    } finally {
        chdir($oldCwd);
        // Restore HOME
        if ($oldHome !== false) {
            putenv('HOME=' . $oldHome);
        } else {
            putenv('HOME');
        }
    }
}
