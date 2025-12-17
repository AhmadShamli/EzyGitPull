<?php
require_once __DIR__ . '/utils.php';
if (is_authenticated()) {
    $u = $_SESSION['user'] ?? '';
    session_unset();
    session_destroy();
    log_message("User $u logged out");
}
header('Location: ./login.php');
exit;
?>