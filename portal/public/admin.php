<?php
require __DIR__ . '/../app/lib.php';
$admin = require_admin();
$db = db();
$tools = all_tools();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'add' && ($email = normalize_email($_POST['email'] ?? ''))) {
        add_user($email);
    } elseif ($action === 'delete') {
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([(int)$_POST['user_id']]);
    } elseif ($action === 'save') {
        $uid = (int)$_POST['user_id'];
        $db->prepare('DELETE FROM access WHERE user_id = ?')->execute([$uid]);
        $ins = $db->prepare('INSERT INTO access (user_id, tool) VALUES (?, ?)');
        foreach ((array)($_POST['tools'] ?? []) as $slug) if (isset($tools[$slug])) $ins->execute([$uid, $slug]);
    }
    header('Location: /admin.php');
    exit;
}

$users = $db->query('SELECT * FROM users ORDER BY email')->fetchAll();
page_start('Verwaltung', $admin);
echo '<p><a href="/">← Übersicht</a></p><h1>Nutzer &amp; Zugriff</h1>'
   . '<form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="add">'
   . '<input type="email" name="email" placeholder="E-Mail-Adresse neuer Nutzer" required><button>Nutzer anlegen</button></form><br>';
if (!$tools) echo '<p class="msg">Noch keine Tools vorhanden. Lege Ordner unter <code>tools/&lt;name&gt;/</code> mit einer <code>index.html</code> an.</p>';
foreach ($users as $u) {
    $st = $db->prepare('SELECT tool FROM access WHERE user_id = ?');
    $st->execute([$u['id']]);
    $has = $st->fetchAll(PDO::FETCH_COLUMN);
    $adminNote = is_admin_email($u['email']) ? ' <small>(Admin, hat immer Zugriff)</small>' : '';
    echo '<form method="post" class="card"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
       . '<input type="hidden" name="user_id" value="' . (int)$u['id'] . '"><strong>' . h($u['email']) . '</strong>' . $adminNote
       . '<br><small>Letzter Login: ' . ($u['last_login'] ? date('d.m.Y H:i', $u['last_login']) : 'nie') . '</small><br>';
    foreach ($tools as $t) {
        echo '<label><input type="checkbox" name="tools[]" value="' . h($t['slug']) . '"' . (in_array($t['slug'], $has, true) ? ' checked' : '') . '> ' . h($t['title']) . '</label><br>';
    }
    echo '<br><button name="action" value="save">Speichern</button> '
       . '<button name="action" value="delete" style="background:#b3261e" onclick="return confirm(\'Nutzer löschen?\')">Löschen</button></form>';
}
page_end();
