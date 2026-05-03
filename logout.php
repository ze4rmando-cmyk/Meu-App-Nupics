<?php
session_start();
session_unset();
session_destroy();

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'];
$script   = $_SERVER['SCRIPT_NAME'];           // /nupics/logout.php
$base     = rtrim(dirname($script), '/');      // /nupics
$login    = $protocol . '://' . $host . $base . '/login.php';

header('Location: ' . $login);
exit;