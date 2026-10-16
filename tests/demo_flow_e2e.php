<?php
/**
 * E2E verification for the demo booking flow change.
 * Proves: (1) no-time -> bot ASKS, NO booking + NO Google Meet written.
 *         (2) with-time -> bot books + writes a meeting link + confirms.
 * Uses the REAL mysqli $conn (db.php) so the production
 * google_meet_helper (mysqli-only) runs unmodified.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../db.php';          // provides real mysqli $conn
require_once __DIR__ . '/../waamini_ai_agent.php';

$db = $conn;
$testPhone = '+919150802906';
$testCompany = 2;

class TestAgent extends WaaminiAiAgent
{
    private array $script;
    private int $i = 0;

    public function __construct($db, int $companyId, string $apiKey, array $scriptedToolCalls)
    {
        $this->db = $db;
        $this->companyId = $companyId;
        $this->apiKey = $apiKey;
        $this->script = $scriptedToolCalls;
    }

    protected function callOpenAI(array $messages): array
    {
        if (!isset($this->script[$this->i])) {
            return [
                'choices' => [
                    [
                        'message' => [
                            'content' => 'OK',
                            'tool_calls' => [],
                        ],
                    ],
                ],
            ];
        }
        $call = $this->script[$this->i++];
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call' . $this->i,
                                'function' => $call,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function loadConversationHistory(string $phone, int $limit): array { return []; }
    protected function saveConversation(string $phone, string $in, string $out): void {}
    protected function detectLanguage(string $text): string { return 'EN'; }
}

function runCase(string $label, array $toolCall, $db, int $companyId, string $phone): array
{
    $db->query("DELETE FROM demo_bookings WHERE customer_phone = '{$phone}' AND customer_name = 'E2E Test'");

    $agent = new TestAgent($db, $companyId, 'test-key', [$toolCall]);
    $reply = $agent->handleMessage($phone, 'book a demo');

    $rows = [];
    $r = $db->query("SELECT * FROM demo_bookings WHERE customer_phone = '{$phone}' AND customer_name = 'E2E Test'");
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;

    echo "--- $label ---\n";
    echo "reply: " . str_replace("\n", ' | ', $reply) . "\n";
    echo "bookings written: " . count($rows) . "\n";
    if ($rows) {
        $x = $rows[0];
        echo "  status=" . $x['status'] . " demo_datetime=" . ($x['demo_datetime'] ?? 'NULL')
            . " meet_link=" . ($x['meet_link'] ?? 'NULL') . "\n";
    }
    return ['reply' => $reply, 'rows' => $rows];
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? "  PASS " : "  FAIL ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

// CASE 1: customer gives NO time -> bot should ASK, write NOTHING.
$r1 = runCase('CASE 1: no time supplied', [
    'name' => 'demo_schedule',
    'arguments' => json_encode(['name' => 'E2E Test', 'preferred_time' => '', 'topic' => 'full Waamini demo']),
], $db, $testCompany, $testPhone);
check('CASE1: no booking written to DB', count($r1['rows']) === 0);
check('CASE1: reply asks for a time/date (has "?")', strpos($r1['reply'], '?') !== false);
check('CASE1: reply mentions time/date',
    stripos($r1['reply'], 'time') !== false || stripos($r1['reply'], 'date') !== false);

// CASE 2: customer gives a time -> bot books, writes meeting link, confirms.
$r2 = runCase('CASE 2: explicit time supplied', [
    'name' => 'demo_schedule',
    'arguments' => json_encode(['name' => 'E2E Test', 'preferred_time' => 'tomorrow 4pm', 'topic' => 'full Waamini demo']),
], $db, $testCompany, $testPhone);
check('CASE2: exactly 1 booking written', count($r2['rows']) === 1);
check('CASE2: booking has a demo_datetime', !empty($r2['rows'][0]['demo_datetime']));
check('CASE2: booking has a meeting link', !empty($r2['rows'][0]['meet_link']));
check('CASE2: reply is a confirmation (has "Demo" or "confirmed")',
    stripos($r2['reply'], 'demo') !== false || stripos($r2['reply'], 'confirmed') !== false);

// Cleanup the test booking.
$db->query("DELETE FROM demo_bookings WHERE customer_phone = '{$testPhone}' AND customer_name = 'E2E Test'");

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
