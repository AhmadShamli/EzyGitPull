<?php
require_once __DIR__ . '/utils.php';
$env = load_env();
if (empty($env)) {
    header('Location: ./setup.php');
    exit;
}
if (!is_authenticated()) {
    header('Location: ./login.php');
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
    <title>EzyGitSync</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <h1>EzyGitSync</h1>
    <p>Logged in as <strong><?php echo htmlspecialchars($_SESSION['user']); ?></strong> — <a href="logout.php">Logout</a></p>

    <div class="card">
        <h2>Repository</h2>
        <p>URL: <code><?php echo htmlspecialchars($env['GIT_URL'] ?? ''); ?></code></p>
        <p>Local path: <code><?php echo htmlspecialchars($repo); ?></code></p>
        <div style="margin-bottom:8px">
            <label style="display:inline-block; margin-right:12px"><input type="checkbox" id="clearPath"> Clear existing files before deploy (replace instead of merge)</label>
            <button id="pullBtn">Pull / Sync</button>
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
    xhr = new XMLHttpRequest();
    const form = new FormData();
    form.append('clear', document.getElementById('clearPath').checked ? '1' : '0');
    xhr.open('POST', 'pull.php');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            // Done
            const resp = xhr.responseText;
            if (resp.indexOf('===DONE===') !== -1 || resp.indexOf('===DONE (exit') !== -1) {
                // Remove DONE marker
                progressLog.textContent = resp.replace(/===DONE===/g, '').replace(/===DONE \(exit .*\)===/g, '');
            }
            pullBtn.disabled = false;
            // hide after a short delay
            setTimeout(() => { progressArea.style.display = 'none'; }, 1500);
            // refresh page to show latest commits
            setTimeout(() => { location.reload(); }, 1200);
        }
    };
    xhr.onprogress = function() {
        // Show incremental data
        progressLog.textContent = xhr.responseText;
        progressLog.scrollTop = progressLog.scrollHeight;
    };
    xhr.send(form);
});

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