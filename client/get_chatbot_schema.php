<?php
include '../db.php';
$tables = ['chatbot_flows', 'chatbot_nodes', 'chatbot_sessions', 'chatbot_node_connections'];
$info = [];
foreach ($tables as $t) {
    $res = $conn->query("DESCRIBE $t");
    if ($res) {
        while($row = $res->fetch_assoc()) $info[$t][] = $row;
    }
}
file_put_contents('chatbot_schema.json', json_encode($info, JSON_PRETTY_PRINT));
echo "Done";
