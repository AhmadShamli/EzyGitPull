<?php
define('EZYPUBLIC', true);
require_once __DIR__ . '/app/utils.php';

// Serve static assets from app/static when requested (keeps webroot minimal)
if (!empty($_GET['asset'])) {
    $asset = basename($_GET['asset']);
    $file = __DIR__ . '/app/static/' . $asset;
    if (is_file($file)) {
        $mime = 'text/plain';
        if (substr($asset, -4) === '.css') $mime = 'text/css';
        header('Content-Type: ' . $mime);
        readfile($file);
        exit;
    }
    http_response_code(404);
    echo 'Not found';
    exit;
}

// Simple front controller: route actions to guarded controllers in app/
$action = $_GET['action'] ?? null;
if ($action) {
    $allowed = ['setup','login','logout','pull'];
    if (in_array($action, $allowed, true)) {
        include __DIR__ . '/app/' . $action . '.php';
        exit;
    }
    http_response_code(404);
    echo 'Not found';
    exit;
}

$env = load_env();
if (empty($env)) {
    header('Location: ./?action=setup');
    exit;
}
if (!is_authenticated()) {
    header('Location: ./?action=login');
    exit;
}
$repo = get_repo_local_path();
$commits = git_latest_commits($repo, 5);
$git_ok = function_exists('git_is_available') ? git_is_available() : false;
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>EzyGitPull</title>
    <link rel="stylesheet" href="?asset=styles.css">
</head>
<body>
<div class="container">
    <h1>EzyGitPull</h1>
    <p>Logged in as <strong><?php echo htmlspecialchars($_SESSION['user']); ?></strong> — <a href="?action=logout">Logout</a></p>

    <div class="card">
        <h2>Repository</h2>
        <p>URL: <code><?php echo htmlspecialchars($env['GIT_URL'] ?? ''); ?></code></p>
        <p>Local path: <code><?php echo htmlspecialchars($repo); ?></code></p>
        <div style="margin-bottom:8px">
            <label style="display:inline-block; margin-right:12px"><input type="checkbox" id="clearPath"> Clear existing files before deploy (replace instead of merge)</label>
            <button id="pullBtn">Pull / Sync</button>
            <button id="refreshBtn" style="display:none; margin-left:8px;">Refresh</button>
        </div>
        <?php if (!$git_ok): ?>
            <div class="alert">Git is not available on the server. The app will attempt an HTTP download fallback (archive download) when you press Pull.</div>
        <?php endif; ?>
        <div id="progressArea" class="collapsible" style="display:none;">
            <pre id="progressLog"></pre>
        </div>
    </div>

    <div class="card">
        <h2>Protected paths <button id="toggleProtected" style="font-size:smaller; margin-left:8px;">Show</button></h2>
        <div id="protectedArea" style="display:none; margin-top:8px;"><pre><?php
            $protected = function_exists('get_protected_paths') ? get_protected_paths() : [];
            if (count($protected) === 0) {
                echo "(no protected paths)";
            } else {
                foreach ($protected as $p) {
                    echo htmlspecialchars($p) . "\n";
                }
            }
        ?></pre></div>
    </div>

    <div class="card">
        <h2></h2>Latest updates</h2>
        <pre><?php echo htmlspecialchars(implode("\n", $commits)); ?></pre>
    </div>

    <div class="card">
        <h2>Activity Log (last 50 lines)</h2>
        <pre><?php
            $logfile = isset($env['LOG_FILE']) && $env['LOG_FILE'] ? $env['LOG_FILE'] : __DIR__ . '/logs/app.log';
            if (file_exists($logfile)) {
                $lines = file($logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $tail = array_slice($lines, -50);
                echo htmlspecialchars(implode("\n", $tail));
            } else {
                echo "(no logs yet)";
            }
        ?></pre>
    </div>
</div>

<script>
const pullBtn = document.getElementById('pullBtn');
const progressArea = document.getElementById('progressArea');
const progressLog = document.getElementById('progressLog');
let xhr = null;

pullBtn.addEventListener('click', () => {
    progressArea.style.display = 'block';
    progressLog.textContent = 'Starting...\n';
    pullBtn.disabled = true;
    // Hide refresh button when starting a new pull (only keep details cleared for new run)
    const refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) refreshBtn.style.display = 'none';

    xhr = new XMLHttpRequest();
    const form = new FormData();
    form.append('clear', document.getElementById('clearPath').checked ? '1' : '0');
    xhr.open('POST', '?action=pull');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            // Done — keep the full progress log visible (do not auto-hide)
            const resp = xhr.responseText;
            if (resp.indexOf('===DONE===') !== -1 || resp.indexOf('===DONE (exit') !== -1) {
                // Remove DONE marker but keep the rest
                progressLog.textContent = resp.replace(/===DONE===/g, '').replace(/===DONE \(exit .*\)===/g, '');
            }
            pullBtn.disabled = false;
            // Keep progressArea visible so the user can review results. Show the Refresh button so they can reload to see commits.
            if (refreshBtn) refreshBtn.style.display = 'inline-block';
            progressLog.scrollTop = progressLog.scrollHeight;
        }
    };
    xhr.onprogress = function() {
        // Show incremental data
        progressLog.textContent = xhr.responseText;
        progressLog.scrollTop = progressLog.scrollHeight;
    };
    xhr.send(form);
});

// Refresh button behavior: reload page to update commits/logs
const refreshBtn = document.getElementById('refreshBtn');
if (refreshBtn) {
    refreshBtn.addEventListener('click', () => {
        location.reload();
    });
}

// Protected paths toggle
const toggleProtected = document.getElementById('toggleProtected');
if (toggleProtected) {
    const protectedArea = document.getElementById('protectedArea');
    toggleProtected.addEventListener('click', () => {
        if (protectedArea.style.display === 'none') {
            protectedArea.style.display = 'block';
            toggleProtected.textContent = 'Hide';
        } else {
            protectedArea.style.display = 'none';
            toggleProtected.textContent = 'Show';
        }
    });
}
</script>
</body>
</html>