<?php
require __DIR__ . '/../app/lib.php';
$user = require_login();
$tools = user_tools($user);
page_start('Übersicht', $user);
echo '<h1>Deine Tools</h1>';
if (!$tools) echo '<p>Dir wurden noch keine Tools freigeschaltet.</p>';
foreach ($tools as $t) {
    echo '<a class="card" href="/t/' . h($t['slug']) . '/"><strong>' . h($t['title']) . '</strong>'
       . ($t['description'] ? '<br><small>' . h($t['description']) . '</small>' : '') . '</a>';
}
page_end();
