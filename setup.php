<?php
require_once __DIR__ . '/utils.php';

$env = load_env();
$hasEnv = !empty($env);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $git_url = trim($_POST['git_url'] ?? '');
    $git_user = trim($_POST['git_user'] ?? '');
    $git_pass = trim($_POST['git_pass'] ?? '');
    $repo_path = trim($_POST['repo_path'] ?? 'repos/myrepo');
    $app_user = trim($_POST['app_user'] ?? 'admin');
    $app_pass = trim($_POST['app_pass'] ?? 'password');
    $protect_paths = trim($_POST['protect_paths'] ?? '');

    $hash = password_hash($app_pass, PASSWORD_DEFAULT);

    $data = [
        'GIT_URL' => $git_url,
        'GIT_USERNAME' => $git_user,
        'GIT_PASSWORD' => $git_pass,
        'REPO_PATH' => $repo_path,
        'APP_USER' => $app_user,
        'APP_PASS_HASH' => $hash,
        'LOG_FILE' => 'logs/app.log',
        'PROTECT_PATHS' => $protect_paths
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
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <h1>Setup EzyGitSync</h1>
    <?php if ($hasEnv): ?>
        <p>Configuration file already exists. Edit <code>.env</code> manually if needed or continue to the app.</p>
        <p><a href="./">Go to app</a></p>
    <?php else: ?>
    <form method="post">
        <label>Git Repository URL
            <input type="text" name="git_url" placeholder="https://github.com/owner/repo.git" required>
        </label>
        <label>Git Username (optional)
            <input type="text" name="git_user" placeholder="username or token">
        </label>
        <label>Git Password/Token (optional)
            <input type="password" name="git_pass" placeholder="password or token">
        </label>
        <label>Local Repo Path
            <input type="text" name="repo_path" value="repos/myrepo">
        </label>
        <label>Protected paths (comma-separated, relative to project or absolute)
            <input type="text" name="protect_paths" placeholder="logs, .env, other/path">
        </label>
        <label>App Username
            <input type="text" name="app_user" value="admin" required>
        </label>
        <label>App Password
            <input type="password" name="app_pass" value="password" required>
        </label>
        <button type="submit">Save Configuration</button>
    </form>
    <?php endif; ?>

    <hr>
    <h3>Example .env</h3>
    <pre><?php echo htmlspecialchars(file_get_contents(__DIR__ . '/.example.env')); ?></pre>
</div>
</body>
</html>