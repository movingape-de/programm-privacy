<?php
// Kopieren nach config.php und anpassen. config.php wird nicht ins Git eingecheckt.
return [
    'base_url'     => 'https://tools.example.de',        // Subdomain, ohne Slash am Ende
    'mail_from'    => 'portal@example.de',               // Absender, am besten Adresse der eigenen Domain
    'admin_emails' => ['du@example.de'],                 // diese Adressen sind Admins und dürfen sich immer anmelden
    'db_path'      => __DIR__ . '/../data/portal.sqlite',
    'tools_dir'    => __DIR__ . '/../tools',
    'site_name'    => 'Meine Tools',
    'link_ttl'     => 900,                               // Gültigkeit des Login-Links in Sekunden
    'session_ttl'  => 60 * 60 * 24 * 30,                 // Angemeldet bleiben: 30 Tage
];
