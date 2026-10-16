# Meta setup for Ads & Attribution

This file grows with each phase. Phases 0–1 are live; the sections for Phases 2–4 are filled in when those phases are built.

## Phase 1 — Click-to-WhatsApp lead attribution (live)

**No Meta App Review or new permission is needed.** The `referral` object arrives on the WhatsApp Cloud API webhook that WAAMINI already receives.

### Server checklist (7Digit)
1. Deploy the code. The database tables and columns create themselves on first use (`includes/ads/schema.php`). To apply them by hand instead, run `migrations/034_ads_foundation.sql` and `migrations/035_ads_attribution.sql`. Each has a matching `*_down.sql`.
2. Keep the every-minute cron running, because it downloads ad thumbnails before Meta's links expire:
   `* * * * * php /home/USER/public_html/waamini/workers/automation_queue_worker.php`
   Without it, thumbnails still download whenever the company opens **Ad Leads** or **WhatsApp Chat**.
3. Optional `.env` settings:
   - `META_GRAPH_VERSION=v23.0`: the single Graph API version for all ads calls. **VERIFY** it is still supported, then bump it as Meta retires versions.
   - `META_APP_ID` / `META_APP_SECRET`: 7Digit's Meta app. The app secret verifies WhatsApp webhook signatures and is also used for `appsecret_proof`. Existing installs using `FB_APP_SECRET` or `WHATSAPP_APP_SECRET` remain supported.
4. Turn the add-on on:
   - **Admin → Plans → Edit → Ads & Attribution add-on → Ad Lead Attribution**, for a whole plan, or
   - **Admin → Companies → Edit → Add-ons**, for one company (Follow plan / On / Off).
5. Monitor it in **Admin → Ads & Attribution**: which companies have it, their ad leads, sales and thumbnail health.

### What the client must do
1. Run a **Click-to-WhatsApp** ad on Facebook or Instagram, with the destination set to WhatsApp using the same business number that is connected to WAAMINI.
   - The Facebook Page must be linked to that WhatsApp number in Meta Business Suite (Settings → Linked accounts → WhatsApp).
2. That's all. When someone taps the ad and sends a message:
   - the chat's **Contact info → Lead source** shows the ad (thumbnail, headline, ad ID)
   - **Ads → Ad Leads** counts the lead under that ad
   - marking the chat **Converted** with a **Sale value** shows as Won and ₹ revenue for that ad
3. Optional: in **Flow Builder**, set a trigger to **"Lead came from a Click-to-WhatsApp ad"** to send something automatically to ad leads. It can match any ad, specific ad IDs, or a headline that contains a word, for example sending the saree catalogue only to saree-ad leads.

### Known limits (explain these to clients)
- **Only leads who tap the ad are counted.** Someone who types the number by hand, or messages from an old chat, comes in as organic.
- **Only the first message counts.** The referral comes only on the first message after the ad tap. WAAMINI stores it immediately and records every ad a customer taps over time.
- **Meta's numbers won't match exactly.** Meta's "conversations started" (Phase 3) and WAAMINI's ad leads differ, because of attribution windows and people who opened the chat without sending anything.
- **Old thumbnails can't be recovered.** Meta's links expire, so thumbnails that couldn't be downloaded in time show a placeholder icon. The ad itself is still recorded.

### Testing without spending on ads
Post `tests/fixtures/ctwa_referral.json` to the webhook. Replace `PHONE_NUMBER_ID` with your number's ID, `919876543210` with a test phone, and `wamid.XXXX` with any unique ID. On production, sign the request with `X-Hub-Signature-256`, or test on a local or staging copy.
Then run:
- `php tests/ads_unit_test.php`: no database needed
- `php tests/ads_attribution_test.php`: uses the database with fake companies 99001/99002 and removes everything it creates

## Phase 2 — Conversions API for Business Messaging (live)

**Usually no App Review is needed.** Events are sent with the company's own WhatsApp Cloud API token (a system-user token from their Business Manager). If that token can't reach the dataset, the company can paste a separate Conversions API token, which is stored encrypted.

### Server checklist (7Digit)
1. Deploy the code. `capi_settings` and `capi_events` create themselves on first use. They're also in `migrations/036_ads_capi.sql`, with `036_down.sql` to reverse.
2. Keep the every-minute cron running, because it sends queued events. While a company has **WhatsApp Chat** or **Ad Leads** open, the panel also sends them every 2 minutes.
3. Turn it on: **Admin → Plans → Conversions API**, or **Admin → Companies → Edit → Add-ons → Conversions API**.
4. Monitor it in **Admin → Ads & Attribution**: events sent and failed per company, and the failure rate.

### What the client does (Ads → Conversion Events)
1. Click **Find my dataset**. WAAMINI finds or creates the dataset linked to their WhatsApp Business Account.
   - Or in Events Manager: **Connect data sources → WhatsApp**, select the WhatsApp number, then copy the **Dataset ID** into the form.
2. Choose which stages are sent. The defaults:
   - **Follow-up** → *Lead submitted*
   - **Converted** → *Purchase*, with the **Sale value ₹** from WhatsApp Chat
   - **Lost** → not sent
3. Optional: paste a **test event code** from Events Manager → Test events, click **Send a test event**, and confirm it appears there. Remove the code after testing.
4. Tick **Send events to Meta** and save.
5. From then on, pressing **Follow-up** or **Converted** on an ad lead in WhatsApp Chat (or the AI applying that outcome) sends the event. Ads Manager shows the results per ad within a day.

### Rules built in
- **Only ad leads are sent.** An event needs the ad click id (`ctwa_clid`). Organic leads are logged as **Skipped** with the reason.
- **Only recent taps are sent.** Stage changes more than the attribution window after the ad tap (default **7 days**, adjustable) are skipped. **VERIFY** Meta's current window.
- **Never sent twice.** Each customer + event pair is sent once (`event_id = waamini_{company}_{contact}_{event}`).
- **Purchase waits for the value.** It waits up to an hour for the sale value, then sends with what's there; Meta requires a value on Purchase.
- **Failures retry, then stop.** Temporary Meta errors retry up to 5 times with growing gaps. Permanent errors stop with Meta's reason shown in the log, and **Retry** or **Retry all failed** re-queues them.

### VERIFY before going live
The field names and endpoint follow Meta's "Conversions API for Business Messaging" docs as of this build:
- `action_source=business_messaging`, `messaging_channel=whatsapp`
- `user_data.whatsapp_business_account_id` + `user_data.ctwa_clid`
- `POST /{dataset_id}/events`
- `GET/POST /{waba_id}/dataset`, used by **Find my dataset**

Send one test event and one real Purchase, then confirm both in Events Manager.

### Common errors
| What the log shows | Cause | Fix |
|---|---|---|
| "permission needed" / code 10 or 200 | The WhatsApp token can't reach the dataset | In Business Settings, give the system user access to the dataset, or paste a separate token under Advanced |
| "Reconnect your Facebook account" / code 190 | The token expired or was revoked | Create a new system-user token, then update WhatsApp settings or the separate token |
| "Invalid ctwa_clid" / code 100 | The click is too old or not from this WABA's ad | Normal for very old leads; no action needed |
| Many **Skipped: not from an ad** | The lead came organically | Expected: only ad leads can be sent |

## Phase 3 — Ad account connection and insights (planned)
_Business Verification for 7Digit, Facebook Login for Business configuration, App Review for `ads_read` + `business_management` (use-case text and screencast script). Written when Phase 3 is built._

Note for planning: tenants use **their own Meta apps** for WhatsApp today (`whatsapp_config.fb_app_id`). Ad-account access in Phase 3 must go through **7Digit's** app via Facebook Login for Business, which gives one token type to manage and lets `appsecret_proof` be used.

## Phase 4 — Ad Builder (planned)
_App Review for `ads_management`, Ads Management Standard Access, Page ↔ WhatsApp linking checks, and the special ad categories (housing) for real-estate clients. Written when Phase 4 is built._

## Common errors
| What you see | Cause | Fix |
|---|---|---|
| A lead shows "Organic" although it came from an ad | The customer typed the number or used an old chat, or the ad isn't Click-to-WhatsApp | Check the ad's destination in Ads Manager |
| Thumbnail shows a placeholder icon | The download failed or the link expired | Make sure the every-minute cron is running |
| Ad Leads menu is locked | The add-on is off for the company's plan | Admin → Companies → Edit → Add-ons |
