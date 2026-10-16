<?php
/**
 * The separate "Multi-Agent Chat" page was retired: assignment (All / Mine / Unassigned),
 * payment links and quotations now live in the main WhatsApp Chat (client/whatsapp.php).
 * Old links and bookmarks land on the same conversation there.
 */
include '../includes/client_auth_check.php';

$c = (int)($_GET['c'] ?? 0);
header('Location: whatsapp.php' . ($c > 0 ? '?c=' . $c : ''), true, 301);
exit();
