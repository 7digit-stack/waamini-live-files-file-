<?php
include 'db.php';
// Update bot_replies table
$conn->query("ALTER TABLE bot_replies MODIFY COLUMN reply_type ENUM('text','template','media','interactive') DEFAULT 'text'");
$conn->query("ALTER TABLE bot_replies ADD COLUMN IF NOT EXISTS media_url TEXT DEFAULT NULL");
$conn->query("ALTER TABLE bot_replies ADD COLUMN IF NOT EXISTS interactive_data JSON DEFAULT NULL");
echo "Done";
