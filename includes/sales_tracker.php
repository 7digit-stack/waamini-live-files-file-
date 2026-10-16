<?php
/**
 * WAAMINI - WhatsApp Sales Tracker
 * Turns WhatsApp into a real-time POS by intercepting messages before AI Bot.
 *
 * Features:
 *   - Authorization: Only company team members can log sales
 *   - Regex Parsing: Item Name + Quantity + Optional Price
 *   - Dual Storage: Local MySQL + Google Sheets sync via Apps Script
 *   - Conversational Reporting: "Report" / "Sales Report" for daily summary
 *   - AI Bot Suppression: Returns reply string to block further bot processing
 *
 * Supported input formats:
 *   "Saree 5"           → Item=Saree, Qty=5
 *   "Saree 5 1500"      → Item=Saree, Qty=5, Price=1500
 *   "Saree, 5, 1500"    → Item=Saree, Qty=5, Price=1500
 *   "5 Saree"           → Item=Saree, Qty=5
 *   "5 Saree 1500"      → Item=Saree, Qty=5, Price=1500
 */
class SalesTracker {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
        $this->ensureTable();
    }

    /**
     * Main entry point — called from webhook.php before AI bot.
     * @return string|null  Reply message if handled, null = not a sales command
     */
    public function process(int $companyId, int $contactId, string $text, string $phone): ?string {
        // 0. Check if Sales Tracker is enabled for this company
        if (!$this->isEnabled($companyId)) {
            return null;
        }

        // 1. Authorization: Only team members can use commands
        if (!$this->isAuthorized($companyId, $phone)) {
            return null; // Silently ignore for regular customers
        }

        $cleanText = strtolower(trim($text));

        // 2. Report commands
        if (in_array($cleanText, ['report', 'sales report', 'total sales today', 'sales today', 'today sales'])) {
            return $this->generateDailyReport($companyId);
        }

        // 3. Delete last entry
        if ($cleanText === 'undo' || $cleanText === 'delete last' || $cleanText === 'cancel last') {
            return $this->undoLastSale($companyId, $phone);
        }

        // 4. Try multi-product first (comma-separated: "Saree 5 1500, Kurta 3 800")
        $multiParsed = $this->parseMultiProduct($text);
        if ($multiParsed && count($multiParsed) > 1) {
            $userId = $this->resolveUserId($companyId, $phone);
            foreach ($multiParsed as $sale) {
                $this->logSale($companyId, $contactId, $userId, $sale['item'], $sale['qty'], $sale['price'], $phone);
            }
            return $this->formatMultiConfirmation($multiParsed);
        }

        // 5. Try single product parse
        $parsed = $this->parseMessage($text);
        if ($parsed) {
            $userId = $this->resolveUserId($companyId, $phone);
            $this->logSale($companyId, $contactId, $userId, $parsed['item'], $parsed['qty'], $parsed['price'], $phone);
            return $this->formatConfirmation($parsed['item'], $parsed['qty'], $parsed['price']);
        }

        return null; // Not a sales command — let other bots handle it
    }

    /**
     * Parse multi-product comma-separated message.
     * Format: "Saree 5 1500, Kurta 3 800, Dupatta 10 200"
     * Also:   "Saree 5, Kurta 3, Dupatta 10" (no price)
     * Also:   "Saree,5,1500, Kurta,3,800" (fully comma-separated)
     *
     * @return array|null  Array of ['item'=>string, 'qty'=>int, 'price'=>float|null] or null
     */
    private function parseMultiProduct(string $text): ?array {
        $text = trim($text);
        if ($text === '' || strlen($text) > 1000) return null;

        // Skip non-sale messages
        if (preg_match('/^(hi|hello|hey|good|thanks|thank|ok|yes|no|hii|helo|help)\b/i', $text)) return null;
        if (preg_match('/^https?:\/\//i', $text)) return null;
        if (str_contains($text, '?')) return null;

        // Split by comma — but we need to be smart about it
        // "Saree 5 1500, Kurta 3 800" → each chunk is a product
        // "Saree, 5, 1500" → this is a SINGLE product (3 comma-separated fields)
        // Strategy: split by comma, then try to group chunks into products

        $results = [];

        // First try: split into product groups by detecting "word number [number]" pattern
        // Match groups like: "Saree 5 1500" or "Kurta 3" separated by commas
        // Pattern: item_name qty [price], item_name qty [price], ...
        if (preg_match_all('/([a-z][a-z0-9 .\-]+?)\s+(\d+)\s+(\d+(?:\.\d{1,2})?)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $item = trim($m[1]);
                $qty = (int)$m[2];
                $price = (float)$m[3];
                if ($qty > 0 && $qty <= 9999 && strlen($item) >= 2) {
                    $results[] = [
                        'item'  => ucfirst(strtolower($item)),
                        'qty'   => $qty,
                        'price' => $price,
                    ];
                }
            }
            if (count($results) > 1) return $results;
        }

        // Second try: "Item qty, Item qty" (no prices)
        $results = [];
        if (preg_match_all('/([a-z][a-z0-9 .\-]+?)\s+(\d+)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $item = trim($m[1]);
                $qty = (int)$m[2];
                if ($qty > 0 && $qty <= 9999 && strlen($item) >= 2) {
                    $results[] = [
                        'item'  => ucfirst(strtolower($item)),
                        'qty'   => $qty,
                        'price' => null,
                    ];
                }
            }
            if (count($results) > 1) return $results;
        }

        // Third try: fully comma-separated "Item,Qty,Price, Item,Qty,Price"
        // Split all commas, group in sets of 3 (or 2)
        $parts = array_map('trim', explode(',', $text));
        $results = [];
        $i = 0;
        while ($i < count($parts)) {
            // Try 3-field group: Item, Qty, Price
            if ($i + 2 < count($parts)
                && preg_match('/^[a-z]/i', $parts[$i])
                && preg_match('/^\d+$/', $parts[$i+1])
                && preg_match('/^\d+(\.\d{1,2})?$/', $parts[$i+2])) {
                $item = trim($parts[$i]);
                $qty = (int)$parts[$i+1];
                $price = (float)$parts[$i+2];
                if ($qty > 0 && $qty <= 9999 && strlen($item) >= 2) {
                    $results[] = [
                        'item'  => ucfirst(strtolower($item)),
                        'qty'   => $qty,
                        'price' => $price,
                    ];
                }
                $i += 3;
                continue;
            }
            // Try 2-field group: Item, Qty
            if ($i + 1 < count($parts)
                && preg_match('/^[a-z]/i', $parts[$i])
                && preg_match('/^\d+$/', $parts[$i+1])) {
                $item = trim($parts[$i]);
                $qty = (int)$parts[$i+1];
                if ($qty > 0 && $qty <= 9999 && strlen($item) >= 2) {
                    $results[] = [
                        'item'  => ucfirst(strtolower($item)),
                        'qty'   => $qty,
                        'price' => null,
                    ];
                }
                $i += 2;
                continue;
            }
            $i++; // skip unrecognized part
        }
        if (count($results) > 1) return $results;

        return null; // Not a multi-product message
    }

    /**
     * Parse incoming message for item/qty/price patterns.
     * @return array|null  ['item'=>string, 'qty'=>int, 'price'=>float|null]
     */
    private function parseMessage(string $text): ?array {
        $text = trim($text);
        if ($text === '' || strlen($text) > 500) return null;

        // Skip obvious non-sale messages (URLs, greetings, questions)
        if (preg_match('/^(hi|hello|hey|good|thanks|thank|ok|yes|no|hii|helo|help)\b/i', $text)) return null;
        if (preg_match('/^https?:\/\//i', $text)) return null;
        if (str_contains($text, '?')) return null;

        $item = '';
        $qty = 0;
        $price = null;

        // Pattern 1: "Item Name, Qty, Price" (comma separated)
        if (preg_match('/^([a-z][a-z0-9 .\-]+?)\s*,\s*(\d+)\s*,\s*(\d+(?:\.\d{1,2})?)\s*$/i', $text, $m)) {
            $item = trim($m[1]);
            $qty = (int)$m[2];
            $price = (float)$m[3];
        }
        // Pattern 2: "Item Name Qty Price" (space separated, 3 parts)
        elseif (preg_match('/^([a-z][a-z0-9 .\-]+?)\s+(\d+)\s+(\d+(?:\.\d{1,2})?)\s*$/i', $text, $m)) {
            $item = trim($m[1]);
            $qty = (int)$m[2];
            $price = (float)$m[3];
        }
        // Pattern 3: "Item Name Qty" (no price)
        elseif (preg_match('/^([a-z][a-z0-9 .\-]+?)\s+(\d+)\s*$/i', $text, $m)) {
            $item = trim($m[1]);
            $qty = (int)$m[2];
        }
        // Pattern 4: "Qty Item Name Price" (quantity first)
        elseif (preg_match('/^(\d+)\s+([a-z][a-z0-9 .\-]+?)\s+(\d+(?:\.\d{1,2})?)\s*$/i', $text, $m)) {
            $qty = (int)$m[1];
            $item = trim($m[2]);
            $price = (float)$m[3];
        }
        // Pattern 5: "Qty Item Name" (quantity first, no price)
        elseif (preg_match('/^(\d+)\s+([a-z][a-z0-9 .\-]+?)\s*$/i', $text, $m)) {
            $qty = (int)$m[1];
            $item = trim($m[2]);
        }

        // Validate: qty must be reasonable (1-9999), item must be > 1 char
        if ($qty > 0 && $qty <= 9999 && strlen($item) >= 2) {
            return [
                'item'  => ucfirst(strtolower($item)),
                'qty'   => $qty,
                'price' => $price,
            ];
        }

        return null;
    }

    /**
     * Insert sale into sales_log table.
     */
    private function logSale(int $companyId, int $contactId, ?int $userId, string $item, int $qty, ?float $price, string $phone): void {
        $st = $this->conn->prepare(
            "INSERT INTO sales_log (company_id, user_id, contact_id, item_name, quantity, price, phone, synced_to_google, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())"
        );
        if (!$st) {
            error_log('[SALES TRACKER] Insert prepare failed: ' . $this->conn->error);
            return;
        }
        $st->bind_param("iiisids", $companyId, $userId, $contactId, $item, $qty, $price, $phone);
        $st->execute();
        $saleId = (int)$this->conn->insert_id;

        // Async Google Sheets sync (non-blocking, 5s timeout)
        $synced = $this->syncToGoogleSheet($companyId, $item, $qty, $price, $phone);
        if ($synced && $saleId > 0) {
            $upd = $this->conn->prepare("UPDATE sales_log SET synced_to_google=1 WHERE id=?");
            if ($upd) {
                $upd->bind_param("i", $saleId);
                $upd->execute();
            }
        }
    }

    /**
     * Sync sale to Google Sheets via Apps Script Web App.
     */
    private function syncToGoogleSheet(int $companyId, string $item, int $qty, ?float $price, string $phone): bool {
        // First check per-company sales_tracker_settings
        $sheetUrl = '';
        $st = $this->conn->prepare("SELECT google_sheet_url FROM sales_tracker_settings WHERE company_id=? AND is_active=1 LIMIT 1");
        if ($st) {
            $st->bind_param("i", $companyId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $sheetUrl = trim((string)($row['google_sheet_url'] ?? ''));
        }

        // Fallback to companies.google_sheet_webhook
        if ($sheetUrl === '') {
            $st2 = $this->conn->prepare("SELECT google_sheet_webhook FROM companies WHERE id=? LIMIT 1");
            if ($st2) {
                $st2->bind_param("i", $companyId);
                $st2->execute();
                $row2 = $st2->get_result()->fetch_assoc();
                $sheetUrl = trim((string)($row2['google_sheet_webhook'] ?? ''));
            }
        }

        if ($sheetUrl === '' || !filter_var($sheetUrl, FILTER_VALIDATE_URL)) {
            return false;
        }

        $payload = json_encode([
            [
                'type'      => 'instant_sale',
                'timestamp' => date('Y-m-d H:i:s'),
                'item'      => $item,
                'quantity'  => $qty,
                'price'     => $price ?? 0,
                'phone'     => $phone,
            ]
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($sheetUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 400) {
            return true;
        }

        error_log("[SALES TRACKER] Google Sheets sync failed: HTTP $httpCode for company_id=$companyId");
        return false;
    }

    /**
     * Generate a daily sales report with item breakdown and revenue.
     */
    private function generateDailyReport(int $companyId): string {
        $st = $this->conn->prepare(
            "SELECT item_name, SUM(quantity) AS total_qty, SUM(IFNULL(price,0) * quantity) AS total_revenue, COUNT(*) AS entries
             FROM sales_log
             WHERE company_id=? AND DATE(created_at) = CURDATE()
             GROUP BY item_name
             ORDER BY total_qty DESC"
        );
        $st->bind_param("i", $companyId);
        $st->execute();
        $res = $st->get_result();

        if ($res->num_rows === 0) {
            return "📊 No sales recorded today yet.";
        }

        $report = "📊 *Today's Sales Report*\n━━━━━━━━━━━━━━━━\n\n";
        $grandQty = 0;
        $grandRevenue = 0;

        while ($row = $res->fetch_assoc()) {
            $itemName = ucfirst($row['item_name']);
            $totalQty = (int)$row['total_qty'];
            $totalRev = (float)$row['total_revenue'];
            $grandQty += $totalQty;
            $grandRevenue += $totalRev;

            $line = "• *{$itemName}*: {$totalQty} units";
            if ($totalRev > 0) {
                $line .= " — ₹" . number_format($totalRev, 0);
            }
            $report .= $line . "\n";
        }

        $report .= "\n━━━━━━━━━━━━━━━━\n";
        $report .= "📦 *Total Units:* {$grandQty}\n";
        if ($grandRevenue > 0) {
            $report .= "💰 *Total Revenue:* ₹" . number_format($grandRevenue, 0) . "\n";
        }
        $report .= "\n_Generated at " . date('h:i A') . "_";

        return $report;
    }

    /**
     * Undo the last sale recorded by this phone today.
     */
    private function undoLastSale(int $companyId, string $phone): string {
        $cleanPhone = preg_replace('/\D+/', '', $phone);
        $match = "%" . substr($cleanPhone, -10);

        $st = $this->conn->prepare(
            "SELECT id, item_name, quantity, price FROM sales_log
             WHERE company_id=? AND phone LIKE ? AND DATE(created_at) = CURDATE()
             ORDER BY id DESC LIMIT 1"
        );
        $st->bind_param("is", $companyId, $match);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();

        if (!$row) {
            return "❌ No sales to undo today.";
        }

        $del = $this->conn->prepare("DELETE FROM sales_log WHERE id=?");
        $del->bind_param("i", $row['id']);
        $del->execute();

        $item = ucfirst($row['item_name']);
        $qty = $row['quantity'];
        return "🗑️ Deleted last entry: *{$item}* x {$qty}";
    }

    /**
     * Format confirmation reply.
     */
    private function formatConfirmation(string $item, int $qty, ?float $price): string {
        $msg = "✅ Recorded: *{$item}* x *{$qty}*";
        if ($price !== null && $price > 0) {
            $total = $price * $qty;
            $msg .= " (₹" . number_format($price, 0) . " each = ₹" . number_format($total, 0) . ")";
        }
        return $msg;
    }

    /**
     * Format combined confirmation for multiple products.
     * @param array $items Array of ['item'=>string, 'qty'=>int, 'price'=>float|null]
     */
    private function formatMultiConfirmation(array $items): string {
        $count = count($items);
        $msg = "✅ *{$count} items recorded:*\n";
        $grandTotal = 0;
        $hasAnyPrice = false;

        foreach ($items as $sale) {
            $line = "• *{$sale['item']}* x {$sale['qty']}";
            if ($sale['price'] !== null && $sale['price'] > 0) {
                $lineTotal = $sale['price'] * $sale['qty'];
                $line .= " = ₹" . number_format($lineTotal, 0);
                $grandTotal += $lineTotal;
                $hasAnyPrice = true;
            }
            $msg .= $line . "\n";
        }

        if ($hasAnyPrice && $grandTotal > 0) {
            $msg .= "\n💰 *Total: ₹" . number_format($grandTotal, 0) . "*";
        }

        return $msg;
    }

    /**
     * Check if Sales Tracker is enabled for this company.
     * Checks sales_tracker_settings first, falls back to checking if google_sheet_webhook exists.
     */
    private function isEnabled(int $companyId): bool {
        // Check dedicated settings table
        $st = $this->conn->prepare("SELECT is_active FROM sales_tracker_settings WHERE company_id=? LIMIT 1");
        if ($st) {
            $st->bind_param("i", $companyId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            if ($row) {
                return (int)$row['is_active'] === 1;
            }
        }

        // No settings row = feature not configured, default to enabled
        // (so existing companies with google_sheet_webhook still work)
        return true;
    }

    /**
     * Authorization: Only company team members can log sales.
     * Matches phone number against users table.
     */
    private function isAuthorized(int $companyId, string $phone): bool {
        $cleanPhone = preg_replace('/\D+/', '', $phone);
        if (strlen($cleanPhone) < 10) return false;

        // Match last 10 digits for robustness (with/without country code)
        $last10 = substr($cleanPhone, -10);
        $match1 = "%" . $last10;
        $match2 = $cleanPhone;

        $stmt = $this->conn->prepare(
            "SELECT id FROM users WHERE company_id=? AND (mobile LIKE ? OR mobile LIKE ?) AND status='active' LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param("iss", $companyId, $match1, $match2);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    /**
     * Resolve the user_id for this phone number.
     */
    private function resolveUserId(int $companyId, string $phone): ?int {
        $cleanPhone = preg_replace('/\D+/', '', $phone);
        $last10 = substr($cleanPhone, -10);
        $match = "%" . $last10;

        $stmt = $this->conn->prepare("SELECT id FROM users WHERE company_id=? AND mobile LIKE ? AND status='active' LIMIT 1");
        if (!$stmt) return null;

        $stmt->bind_param("is", $companyId, $match);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Auto-create tables if they don't exist.
     */
    private function ensureTable(): void {
        // Create sales_log if missing
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS `sales_log` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `company_id` INT(11) NOT NULL,
                `user_id` INT(11) DEFAULT NULL,
                `contact_id` INT(11) DEFAULT NULL,
                `item_name` VARCHAR(255) NOT NULL,
                `quantity` INT(11) NOT NULL DEFAULT 1,
                `price` DECIMAL(10,2) DEFAULT NULL,
                `phone` VARCHAR(20) DEFAULT NULL,
                `synced_to_google` TINYINT(1) DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_company_date` (`company_id`, `created_at`),
                KEY `idx_company_item` (`company_id`, `item_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Create settings table if missing
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS `sales_tracker_settings` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `company_id` INT(11) NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `google_sheet_url` VARCHAR(500) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_company` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
