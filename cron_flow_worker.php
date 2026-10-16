<?php
/**
 * Manual entry point for the Flow Builder tick:   php cron_flow_worker.php
 * You normally do NOT need to schedule this: cron_flow_tick.php is chained into process_ai_queue.php (the scheduled
 * WaaminiWorker task, every minute) and the client panel also pings the queue every minute while it is open.
 * Over HTTP it needs ?key=CRON_SECRET_KEY like the other cron files.
 */
require_once __DIR__ . '/cron_flow_tick.php';
