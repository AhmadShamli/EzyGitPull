<?php
if (!defined('EZYPUBLIC')) { http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__ . '/utils.php';
if (is_authenticated()) {
    $u = $_SESSION['user'] ?? '';
    session_unset();
    session_destroy();
    log_message("User $u logged out");
}
header('Location: ./?action=login');
exit;
