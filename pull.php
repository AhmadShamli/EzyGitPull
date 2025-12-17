<?php
require_once __DIR__ . '/utils.php';
require_auth();

$env = load_env();
$repo = get_repo_local_path();
$git_url = $env['GIT_URL'] ?? '';
$git_user = $env['GIT_USERNAME'] ?? '';
$git_pass = $env['GIT_PASSWORD'] ?? '';
$pull_method = $env['PULL_METHOD'] ?? 'git';
$curl_available = function_exists('curl_version');

// Build auth-embedded URL if credentials provided and url is https
$useUrl = $git_url;
if ($git_user && $git_pass && preg_match('#^https?://#', $git_url)) {
    $parts = parse_url($git_url);
    if ($parts && isset($parts['host'])) {
        $userInfo = rawurlencode($git_user) . ':' . rawurlencode($git_pass);
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $path = $parts['path'] ?? '';
        $useUrl = "$scheme://$userInfo@$host$path";
    }
}

// Ensure repo dir exists
if (!is_dir(dirname($repo))) @mkdir(dirname($repo), 0777, true);

$clear = isset($_POST['clear']) && in_array(strval($_POST['clear']), ['1','on','true','yes'], true);

// Decide method: if configured to HTTP, use HTTP; if configured to git but git isn't available, fall back to HTTP.
if ($pull_method === 'http' || !git_is_available()) {
    header('Content-Type: text/plain');
    $writer = function($m) { echo htmlspecialchars($m) . "\n"; log_message($m); @flush(); @ob_flush(); };

    if ($pull_method === 'http') {
        $writer("Pull method set to HTTP: attempting HTTP download for $git_url");
    } else {
        $writer("Git not available: attempting HTTP download fallback for $git_url");
    }

    if ($clear) {
        $writer('Clear requested — attempting to remove existing content');
        if (!safe_rmdir($repo)) {
            $writer('Warning: clear refused for safety or did not exist');
        } else {
            $writer('Cleared target path');
        }
    }

    $ok = try_download_archive_from_git_url($git_url, $repo, $git_user, $git_pass, $writer);
    if ($ok) {
        echo "\n===DONE===\n";
        log_message("Download fallback completed successfully");
    } else {
        echo "\n===DONE (failed)===\n";
        log_message("Download fallback failed");
    }
    exit;
}

$logPrefix = "Pull by " . ($_SESSION['user'] ?? 'unknown');
log_message("$logPrefix started for repo: $git_url -> $repo");

if ($clear) {
    // Attempt a safe removal of the repo path
    if (safe_rmdir($repo)) {
        log_message("Cleared existing repo path before deploy: $repo");
    } else {
        log_message("Clear requested but refused for safety: $repo");
    }
}

// Prepare commands: clone if missing, otherwise pull
if (!is_dir($repo . '/.git')) {
    $cmd = "git clone " . escapeshellarg($useUrl) . " " . escapeshellarg($repo);
    $action = 'clone';
} else {
    $cmd = "git -C " . escapeshellarg($repo) . " pull";
    $action = 'pull';
}

// Stream output to client while running
header('Content-Type: text/plain');
header('Cache-Control: no-cache');

$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
];

$process = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($process)) {
    log_message("Failed to start git $action");
    echo "Failed to start git $action\n";
    exit;
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$done = false;
while (true) {
    $stdout = fgets($pipes[1]);
    $stderr = fgets($pipes[2]);
    if ($stdout !== false) {
        echo htmlspecialchars($stdout);
        log_message(trim($stdout));
        @flush(); @ob_flush();
    }
    if ($stderr !== false) {
        echo htmlspecialchars($stderr);
        log_message(trim($stderr));
        @flush(); @ob_flush();
    }
    $status = proc_get_status($process);
    if (!$status['running']) break;
    usleep(100000);
}

// Read any remaining buffers
while (($line = fgets($pipes[1])) !== false) {
    echo htmlspecialchars($line);
    log_message(trim($line));
}
while (($line = fgets($pipes[2])) !== false) {
    echo htmlspecialchars($line);
    log_message(trim($line));
}

fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);

if ($exit === 0) {
    echo "\n===DONE===\n";
    log_message("Git $action completed successfully");
} else {
    echo "\n===DONE (exit $exit)===\n";
    log_message("Git $action failed with exit code $exit");
}

?>