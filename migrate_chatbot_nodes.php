<?php
include 'db.php';
// Update chatbot_nodes table
$conn->query("ALTER TABLE chatbot_nodes MODIFY COLUMN node_type ENUM('message','question','condition','end','commerce','button','list') DEFAULT 'message'");
echo "Done";
