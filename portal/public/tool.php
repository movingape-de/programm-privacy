<?php
// Liefert Dateien aus tools/<slug>/ nur an berechtigte Nutzer aus.
require __DIR__ . '/../app/lib.php';
$user = require_login();
$slug = (string)($_GET['slug'] ?? '');
if (!isset(user_tools($user)[$slug])) { http_response_code(403); exit('Kein Zugriff auf dieses Tool.'); }

$base = realpath(cfg('tools_dir') . '/' . $slug);
$path = trim((string)($_GET['path'] ?? ''), '/');
$file = realpath($base . '/' . ($path === '' ? 'index.html' : $path));
if ($file && is_dir($file)) $file = realpath($file . '/index.html');
if (!$file || !str_starts_with($file, $base . '/') || !is_file($file) || basename($file) === 'tool.json' || str_ends_with($file, '.php')) {
    http_response_code(404); exit('Nicht gefunden.');
}
$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json',
          'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
          'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8'];
header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache');
readfile($file);
