<?php
require __DIR__ . '/../app/lib.php';
$hash = hash('sha256', (string)($_GET['token'] ?? ''));
$db = db();
$st = $db->prepare('SELECT * FROM login_tokens WHERE token_hash = ? AND expires_at > ?');
$st->execute([$hash, time()]);
$row = $st->fetch();
if ($row) {
    $db->prepare('DELETE FROM login_tokens WHERE token_hash = ?')->execute([$hash]);
    $db->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([time(), $row['user_id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['user_id'];
    header('Location: /');
    exit;
}
http_response_code(400);
page_start('Link ungültig');
echo '<p class="msg">Der Link ist ungültig oder abgelaufen. <a href="/login.php">Neuen Link anfordern</a></p>';
page_end();
