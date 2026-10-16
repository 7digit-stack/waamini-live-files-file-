<?php
/**
 * Flow Builder migration. Safe to run any number of times.
 *   php migrations/migrate_flow_builder.php
 * Creates flow_nodes / flow_edges / flow_sessions / flow_jobs / flow_logs, adds the new chatbot_flows columns, and converts
 * every old flow (chatbot_nodes + chatbot_node_connections) into the canvas format. Old rows are never deleted.
 * (The app also runs this automatically the first time the Flow Builder is opened.)
 */
chdir(dirname(__DIR__));
if (!defined('WAAMINI_LOADED')) define('WAAMINI_LOADED', true);
require_once 'db.php';
require_once 'includes/flow/schema.php';

flow_ensure_schema($conn);
$r = flow_migrate_legacy($conn);
echo "Flow Builder tables ready.\n";
echo "Converted from the old format: {$r['flows']} flow(s), {$r['nodes']} node(s), {$r['edges']} connection(s).\n";
$q = $conn->query("SELECT status, COUNT(*) c FROM chatbot_flows GROUP BY status");
while ($q && ($x = $q->fetch_assoc())) echo "  {$x['status']}: {$x['c']}\n";
