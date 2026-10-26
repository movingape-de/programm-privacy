<?php
require __DIR__ . '/../app/lib.php';
$_SESSION = [];
session_destroy();
header('Location: /login.php');
