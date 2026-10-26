<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

function cfg(string $key) { global $config; return $config[$key]; }

session_name('portal');
session_set_cookie_params([
    'lifetime' => cfg('session_ttl'),
    'path'     => '/',
    'secure'   => str_starts_with(cfg('base_url'), 'https'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . cfg('db_path'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, created_at INTEGER NOT NULL, last_login INTEGER
        );
        CREATE TABLE IF NOT EXISTS access (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, tool TEXT NOT NULL,
            PRIMARY KEY (user_id, tool)
        );
        CREATE TABLE IF NOT EXISTS login_tokens (
            token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL
        );
    ');
    return $pdo;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function is_admin_email(string $email): bool {
    return in_array($email, array_map('strtolower', cfg('admin_emails')), true);
}

function normalize_email(string $email): ?string {
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function find_user(string $email): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

function add_user(string $email): int {
    db()->prepare('INSERT OR IGNORE INTO users (email, created_at) VALUES (?, ?)')->execute([$email, time()]);
    return (int)find_user($email)['id'];
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u) { $_SESSION = []; return null; }
    $u['is_admin'] = is_admin_email($u['email']);
    return $u;
}

function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: /login.php'); exit; }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if (!$u['is_admin']) { http_response_code(403); exit('Kein Zugriff.'); }
    return $u;
}

function csrf_token(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400); exit('Ungültige Anfrage.');
    }
}

/** Alle Tools: Unterordner von tools/, optional mit tool.json {"title":..., "description":...} */
function all_tools(): array {
    $tools = [];
    foreach (glob(cfg('tools_dir') . '/*', GLOB_ONLYDIR) as $dir) {
        $slug = basename($dir);
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) continue;
        $meta = is_file("$dir/tool.json") ? (json_decode(file_get_contents("$dir/tool.json"), true) ?: []) : [];
        $tools[$slug] = ['slug' => $slug, 'title' => $meta['title'] ?? $slug, 'description' => $meta['description'] ?? ''];
    }
    ksort($tools);
    return $tools;
}

function user_tools(array $user): array {
    $tools = all_tools();
    if ($user['is_admin']) return $tools;
    $st = db()->prepare('SELECT tool FROM access WHERE user_id = ?');
    $st->execute([$user['id']]);
    return array_intersect_key($tools, array_flip($st->fetchAll(PDO::FETCH_COLUMN)));
}

function send_login_link(array $user): void {
    $db = db();
    $st = $db->prepare('SELECT COUNT(*) FROM login_tokens WHERE user_id = ? AND created_at > ?');
    $st->execute([$user['id'], time() - 600]);
    if ($st->fetchColumn() >= 3) return; // max. 3 Links pro 10 Minuten

    $token = bin2hex(random_bytes(32));
    $db->prepare('DELETE FROM login_tokens WHERE expires_at < ?')->execute([time()]);
    $db->prepare('INSERT INTO login_tokens (token_hash, user_id, expires_at, created_at) VALUES (?, ?, ?, ?)')
       ->execute([hash('sha256', $token), $user['id'], time() + cfg('link_ttl'), time()]);

    $link = cfg('base_url') . '/verify.php?token=' . $token;
    $minutes = (int)(cfg('link_ttl') / 60);
    $body = "Hallo,\n\nmit diesem Link meldest du dich bei " . cfg('site_name') . " an:\n\n$link\n\n"
          . "Der Link ist $minutes Minuten gültig und nur einmal verwendbar.\nWenn du ihn nicht angefordert hast, ignoriere diese E-Mail.\n";
    $headers = "From: " . cfg('site_name') . " <" . cfg('mail_from') . ">\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
    mail($user['email'], '=?UTF-8?B?' . base64_encode('Dein Login-Link') . '?=', $body, $headers, '-f' . cfg('mail_from'));
}

function page_start(string $title, ?array $user = null): void {
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>' . h($title) . ' – ' . h(cfg('site_name')) . '</title><style>'
       . 'body{font:16px/1.5 system-ui,sans-serif;max-width:720px;margin:0 auto;padding:24px 16px;color:#1a1a1a;background:#fafafa}'
       . 'header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}a{color:#0b57d0}'
       . '.card{display:block;background:#fff;border:1px solid #ddd;border-radius:10px;padding:16px;margin:12px 0;text-decoration:none;color:inherit}'
       . '.card:hover{border-color:#0b57d0}.card small{color:#666}'
       . 'input[type=email]{width:100%;padding:10px;font-size:16px;box-sizing:border-box;margin:8px 0}'
       . 'button{padding:10px 18px;font-size:16px;border:0;border-radius:8px;background:#0b57d0;color:#fff;cursor:pointer}'
       . 'table{border-collapse:collapse;width:100%;background:#fff}td,th{border:1px solid #ddd;padding:8px;text-align:left;vertical-align:top}'
       . '.msg{background:#e8f0fe;padding:12px;border-radius:8px}'
       . '</style></head><body><header><strong>' . h(cfg('site_name')) . '</strong><span>';
    if ($user) {
        echo h($user['email']) . ' · ' . ($user['is_admin'] ? '<a href="/admin.php">Verwaltung</a> · ' : '')
           . '<a href="/logout.php">Abmelden</a>';
    }
    echo '</span></header>';
}

function page_end(): void { echo '</body></html>'; }
