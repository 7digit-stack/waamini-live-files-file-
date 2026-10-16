<?php
include 'db.php';
// Create connections table for branching
$conn->query("CREATE TABLE IF NOT EXISTS chatbot_node_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    trigger_value VARCHAR(255) NULL, -- e.g. Button Text or Condition
    target_node_id INT NOT NULL,
    INDEX idx_node (node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Done";
