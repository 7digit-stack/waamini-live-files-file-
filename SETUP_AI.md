# WAAMINI AI Setup - Complete Guide

## ✅ What's Been Created

- [x] `waamini_ai_agent.php` — AI agent with 6 read-only tools
- [x] `process_ai_queue.php` — Async queue worker
- [x] `.env` — OpenAI key placeholder (NEEDS YOUR KEY)
- [x] `webhook.php` — Modified to queue messages
- [x] `setup_ai_tables.sql` — Database tables
- [x] `test_openai.php` — API connection test
- [x] `test_ai.php` — Agent test

---

## 📋 WHAT YOU NEED TO DO

### 1️⃣ Add Your OpenAI Key

**Get key from:** https://platform.openai.com/api-keys

**Important:** Create a permanent API key (not test key)

**Then add billing:** https://platform.openai.com/account/billing/overview (minimum $5)

**Edit `.env` line 79-80:**
```
OPENAI_API_KEY=sk-proj-YOUR_ACTUAL_KEY
OPENAI_MODEL=gpt-4o-mini
```

### 2️⃣ Run SQL Setup

**Open phpMyAdmin** → select `waamini` database → SQL tab → Copy+paste `setup_ai_tables.sql` → Execute

This creates:
- `ai_conversation_log` — Chat history (UTF8MB4)
- `ai_message_queue` — Async queue
- `ai_settings` — Enable/disable AI per company
- Converts existing tables to UTF8MB4 (Tamil support)

### 3️⃣ Test OpenAI Connection

Visit: `http://localhost:8888/waamini/test_openai.php`

**Should show:**
```
✅ OpenAI API WORKING
Response: ✅ API WORKING
```

If it shows "insufficient_quota" → add billing (step 1)

### 4️⃣ Test AI Agent

Visit: `http://localhost:8888/waamini/test_ai.php`

**Should show:**
```
Test: AI Agent
Company: 2
Phone: +919003876516
Message: saree price
---
✅ Reply:
[AI response about saree pricing from your database]
```

### 5️⃣ Test End-to-End

**Via WhatsApp to your business:**
1. Send message: "saree price"
2. Check if message appears in `ai_message_queue` table (status='pending')
3. Run worker: `php C:\xampp\htdocs\waamini\process_ai_queue.php`
4. Check if queue status changes to 'done'
5. Check if reply arrived on WhatsApp

---

## 🔧 Running the Queue Worker

### Option A: Manual (Testing)
```bash
cd C:\xampp\htdocs\waamini
php process_ai_queue.php
```

### Option B: Cron (Production)

**On Hostinger cPanel:**
- Go to Cron Jobs
- Add: `* * * * * /usr/bin/php /home/uXXXXXX/public_html/waamini/process_ai_queue.php`
- (Replace `uXXXXXX` with your Hostinger username)

**On Windows (local):**
- Task Scheduler → Create Task
- Name: WaaminiAIQueue
- Trigger: Every 1 minute
- Action: `C:\xampp\php\php.exe C:\xampp\htdocs\waamini\process_ai_queue.php`

---

## 🎯 How It Works

```
Customer sends WhatsApp message
         ↓
webhook.php receives, saves to DB
         ↓
Queues message to ai_message_queue (status='pending')
         ↓
process_ai_queue.php (runs via cron every minute)
         ↓
WaaminiAiAgent processes message with OpenAI
         ↓
Saves reply to messages table
         ↓
Sends reply via WhatsApp
         ↓
Updates queue (status='done')
```

**All in < 60 seconds for ₹0.08**

---

## 📊 Monitor AI Activity

**Check queue status:**
```sql
SELECT status, COUNT(*) FROM ai_message_queue GROUP BY status;
```

**View conversation:**
```sql
SELECT * FROM ai_conversation_log WHERE company_id=2 ORDER BY id DESC LIMIT 20;
```

**Check errors:**
```bash
tail -50 C:\xampp\apache\logs\error.log | grep "AI QUEUE"
```

---

## 🚨 Troubleshooting

| Problem | Fix |
|---|---|
| "OPENAI_API_KEY not set" | Add key to `.env` line 79 |
| OpenAI returns 401 | Wrong key, check platform.openai.com |
| OpenAI returns "insufficient_quota" | Add $5 billing first |
| Queue stays 'pending' | Worker not running; run process_ai_queue.php manually |
| Queue shows 'failed' | Check error_log table AND apache error.log |
| No reply on WhatsApp | WhatsApp token expired; check `whatsapp_config` table |
| Tamil shows ???? | SQL tables not UTF8MB4; run setup_ai_tables.sql again |

---

## ✨ Next Steps (After Testing)

Once tests pass:

1. **Read-only tools working:** contacts_search, products_search, orders_track, company_profile, faq_search
2. **Add write tools:** contacts_create (optional, risky)
3. **Add payment tool:** payment_createLink (requires Razorpay keys)
4. **Monitor for 3 days** before enabling payment

---

## 📞 Support

Check these logs in order:
1. `apache error.log` → `[AI QUEUE]` errors
2. `ai_message_queue.error_msg` column → specific errors per message
3. `ai_conversation_log` → see what AI received vs. responded
