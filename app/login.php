<?php
if (!defined('EZYPUBLIC')) { http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__ . '/utils.php';

$env = load_env();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    if (!isset($env['APP_USER']) || !isset($env['APP_PASS_HASH'])) {
        $message = 'No application account is configured. Run setup first.';
    } else {
        if ($user === $env['APP_USER'] && password_verify($pass, $env['APP_PASS_HASH'])) {
            $_SESSION['logged_in'] = true;
            $_SESSION['user'] = $user;
            log_message("User $user logged in");
            header('Location: ./');
            exit;
        } else {
            $message = 'Invalid credentials';
            log_message("Failed login attempt for user $user");
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Login - EzyGitSync</title>
    <link rel="stylesheet" href="?asset=styles.css">
</head>
<body>
<div class="container">
    <h1>Login</h1>
    <?php if ($message): ?>
        <div class="alert"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="post" action="./?action=login">
        <label>Username
            <input name="user" required>
        </label>
        <label>Password
            <input name="pass" type="password" required>
        </label>
        <button type="submit">Login</button>
    </form>

    <p><a href="./?action=setup">Setup / Edit Configuration</a></p>
</div>
</body>
</html>
