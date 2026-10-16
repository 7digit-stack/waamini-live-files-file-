<?php
include '../db.php';
$res = $conn->query("DESCRIBE chatbot_nodes");
$rows = [];
while($row = $res->fetch_assoc()) $rows[] = $row;
file_put_contents('schema_nodes.json', json_encode($rows, JSON_PRETTY_PRINT));
echo "Done";
