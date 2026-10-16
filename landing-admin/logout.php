<?php
require_once __DIR__ . '/includes/auth.php';
session_destroy();
header('Location: /landing-admin/login.php');
exit;
