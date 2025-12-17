<?php
if (!defined('EZYPUBLIC')) { http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__ . '/utils.php';

$env = load_env();
$hasEnv = !empty($env);
$git_available = git_is_available();
$curl_available = function_exists('curl_version');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $repo_url = trim($_POST['repo_url'] ?? '');
    $git_user = trim($_POST['git_user'] ?? '');
    $git_pass = trim($_POST['git_pass'] ?? '');
    $repo_path = trim($_POST['repo_path'] ?? 'repos/myrepo');
    $app_user = trim($_POST['app_user'] ?? 'admin');
    $app_pass = trim($_POST['app_pass'] ?? 'password');
    $protect_paths = trim($_POST['protect_paths'] ?? '');
    $pull_method = trim($_POST['pull_method'] ?? 'git');

    $hash = password_hash($app_pass, PASSWORD_DEFAULT);

    $data = [
        'GIT_URL' => $repo_url,
        'GIT_USERNAME' => $git_user,
        'GIT_PASSWORD' => $git_pass,
        'REPO_PATH' => $repo_path,
        'APP_USER' => $app_user,
        'APP_PASS_HASH' => $hash,
        'LOG_FILE' => 'logs/app.log',
        'PROTECT_PATHS' => $protect_paths,
        'PULL_METHOD' => $pull_method
    ];

    save_env($data);
    log_message("Setup completed. Config saved to .env");
    header('Location: ./');
    exit;
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Setup - EzyGitSync</title>
    <link rel="stylesheet" href="?asset=styles.css">
</head>
<body>
<div class="container">
    <h1>Setup EzyGitSync</h1>
    <?php if ($hasEnv): ?>
        <p>Configuration file already exists. Edit <code>.env</code> manually if needed or continue to the app.</p>
        <p><a href="./">Go to app</a></p>
    <?php else: ?>
    <form method="post" id="setupForm" action="./?action=setup">
        <label>Pull Method
            <select name="pull_method" id="pull_method">
                <option value="git" <?php echo (($env['PULL_METHOD'] ?? 'git') === 'git') ? 'selected' : ''; ?> <?php echo $git_available ? '' : 'disabled'; ?>>Git Pull <?php echo $git_available ? '' : '(not available on server)'; ?></option>
                <option value="http" <?php echo (($env['PULL_METHOD'] ?? '') === 'http') ? 'selected' : ''; ?>>HTTP Download <?php echo $curl_available ? '(curl available)' : '(using file_get_contents)'; ?></option>
            </select>
        </label>

        <label>Repository URL
            <input type="text" name="repo_url" id="repo_url" placeholder="https://github.com/owner/repo.git or archive URL" value="<?php echo htmlspecialchars($env['GIT_URL'] ?? ''); ?>" required>
        </label>

        <div id="git_fields" style="margin-top:10px;">
            <label>Git Username (optional)
                <input type="text" name="git_user" id="git_user" placeholder="username or token" value="<?php echo htmlspecialchars($env['GIT_USERNAME'] ?? ''); ?>">
            </label>
            <label>Git Password/Token (optional)
                <input type="password" name="git_pass" id="git_pass" placeholder="password or token" value="<?php echo htmlspecialchars($env['GIT_PASSWORD'] ?? ''); ?>">
            </label>
        </div>

        <div id="http_note" style="margin-top:10px; display:none; color:#444;">
            <em>HTTP download will fetch an archive (zip) of the repository. Server will use <strong><?php echo $curl_available ? 'curl' : 'file_get_contents'; ?></strong> for requests.</em>
        </div>

        <label>Local Repo Path
            <input type="text" name="repo_path" value="<?php echo htmlspecialchars($env['REPO_PATH'] ?? 'repos/myrepo'); ?>">
        </label>
        <label>Protected paths (comma-separated, relative to project or absolute)
            <input type="text" name="protect_paths" placeholder="logs, .env, other/path" value="<?php echo htmlspecialchars($env['PROTECT_PATHS'] ?? ''); ?>">
        </label>
        <label>App Username
            <input type="text" name="app_user" value="<?php echo htmlspecialchars($env['APP_USER'] ?? 'admin'); ?>" required>
        </label>
        <label>App Password
            <input type="password" name="app_pass" value="" required>
        </label>
        <button type="submit">Save Configuration</button>
    </form>

    <script>
    (function(){
        var select = document.getElementById('pull_method');
        var gitFields = document.getElementById('git_fields');
        var httpNote = document.getElementById('http_note');
        var repoUrl = document.getElementById('repo_url');

        function updateUI(){
            var v = select.value;
            if (v === 'git'){
                gitFields.style.display = '';
                httpNote.style.display = 'none';
                repoUrl.placeholder = 'https://github.com/owner/repo.git';
                repoUrl.required = true;
            } else {
                gitFields.style.display = 'none';
                httpNote.style.display = '';
                repoUrl.placeholder = 'https://github.com/owner/repo/archive/refs/heads/main.zip';
                repoUrl.required = true;
            }
            var gitOption = select.querySelector('option[value="git"]');
            if (gitOption && gitOption.disabled && select.value === 'git') {
                select.value = 'http';
                updateUI();
            }
        }
        select.addEventListener('change', updateUI);
        updateUI();
    })();
    </script>
    <?php endif; ?>

    <hr>
    <h3>Example .env</h3>
    <pre><?php echo htmlspecialchars(file_get_contents(__DIR__ . '/.example.env')); ?></pre>
</div>
</body>
</html>
