<?php
require __DIR__ . '/../app/lib.php';
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = normalize_email($_POST['email'] ?? '');
    if ($email) {
        $user = find_user($email);
        if (!$user && is_admin_email($email)) { add_user($email); $user = find_user($email); }
        if ($user) send_login_link($user);
    }
    $sent = true; // immer gleiche Antwort, damit keine Adressen erraten werden können
}
page_start('Anmelden');
if ($sent) {
    echo '<p class="msg">Falls die Adresse freigeschaltet ist, wurde ein Login-Link gesendet. Bitte prüfe dein Postfach.</p>';
} else {
    echo '<h1>Anmelden</h1><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
       . '<label>E-Mail-Adresse<input type="email" name="email" required autofocus autocomplete="email"></label>'
       . '<button>Login-Link senden</button></form>';
}
page_end();
