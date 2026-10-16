<?php
include '../includes/client_auth_check.php';

if (!has_feature('feat_ocr_scanner')) {
    header("Location: dashboard.php");
    exit();
}

// Bug #1 fixed: was raw SVG HTML inside $pageTitle → renders as literal text in browser <title>
$pageTitle   = 'AI Image Lead Scanner';
$currentPage = 'premium_ocr';

$stmt = $conn->prepare("SELECT openai_api_key FROM ai_bot_settings WHERE company_id = ? LIMIT 1");
$botSettings = null;
if ($stmt) {
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $botSettings = $stmt->get_result()->fetch_assoc();
} else {
    error_log('[WAAMINI] premium_ocr.php: prepare() failed for ai_bot_settings lookup');
}
$hasOpenAiKey = !empty($botSettings['openai_api_key']);

include '../includes/client_header.php';
?>

<style>
/* ── AI Image Lead Scanner ── */

.ocr-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    align-items: flex-start;
}

/* Bug #10 fixed: inline grid override was bypassing .dashboard-grid responsive breakpoints */
@media (max-width: 860px) {
    .ocr-layout { grid-template-columns: 1fr; }
}

.ocr-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 22px;
    box-shadow: var(--shadow);
}

.ocr-card-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ocr-card-sub {
    font-size: 13px;
    color: var(--text-secondary);
    margin: 0 0 22px;
    line-height: 1.55;
}

/* Bug #7 fixed: replace all inline styles with classes */
/* Bug #2 fixed: was var(--bg-dark) which doesn't exist in client.css → transparent bg */
/* Bug #6 fixed: was hardcoded #8e44ad (admin purple) → var(--accent) */
.ocr-drop-zone {
    border: 2px dashed var(--border);
    border-radius: 12px;
    padding: 44px 20px;
    text-align: center;
    cursor: pointer;
    background: var(--bg-input);
    transition: border-color 0.2s ease, background 0.2s ease;
    outline: none;
}

.ocr-drop-zone:hover,
.ocr-drop-zone:focus,
.ocr-drop-zone.dragover {
    border-color: var(--accent);
    background: var(--accent-light, #eaf3ff);
}

.ocr-drop-icon {
    width: 56px;
    height: 56px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
    color: var(--accent);
}

.ocr-drop-title { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 6px; }
.ocr-drop-hint  { font-size: 11.5px; color: var(--text-muted); }

/* Loading */
.ocr-loading {
    display: none;
    text-align: center;
    padding: 32px 0;
}

/* Bug #6 fixed: spinner was rgba(142,68,173,.3) / #8e44ad (admin purple) */
/* Bug #13 fixed: @keyframes now at page scope, not inside PHP conditional else block */
.ocr-spinner {
    width: 28px;
    height: 28px;
    border: 3px solid var(--accent-light, #eaf3ff);
    border-top-color: var(--accent);
    border-radius: 50%;
    margin: 0 auto 14px;
    animation: ocrSpin 0.8s linear infinite;
}

@keyframes ocrSpin { to { transform: rotate(360deg); } }

.ocr-loading-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
}

/* Results */
.ocr-results-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
}

/* Bug #2 fixed: was var(--bg-dark) → transparent */
.ocr-results-box {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 8px;
    min-height: 220px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* Bug #4 fixed: was font-size:32px div wrapping width="16" height="16" SVG → 16px dot */
.ocr-empty {
    margin: auto;
    text-align: center;
    padding: 40px 24px;
}

.ocr-empty-icon {
    width: 52px;
    height: 52px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 12px;
    opacity: 0.55;
    color: var(--text-muted);
}

.ocr-empty-text { font-size: 13px; color: var(--text-muted); }

/* Inline notification replaces alert() popups */
.ocr-notif {
    display: none;
    align-items: center;
    gap: 8px;
    padding: 11px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 16px;
}

.ocr-notif.show    { display: flex; }
.ocr-notif.success { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); }
.ocr-notif.error   { background: var(--danger-bg);  color: var(--danger);  border: 1px solid var(--danger-border, #fecaca); }

/* Bug #11 fixed: lead inputs had no border-radius or box-sizing → overflowed cells */
.ocr-lead-input {
    width: 100%;
    padding: 5px 8px;
    font-size: 12.5px;
    color: var(--text-primary);
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 6px;
    box-sizing: border-box;
    font-family: inherit;
    transition: border-color 0.15s ease;
}

.ocr-lead-input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px rgba(45,124,227,0.1);
}
</style>

<div class="page-header">
    <h2><?php echo cn_svg('ocr'); ?> AI Image Lead Scanner</h2>
</div>
<p style="font-size:13px;color:var(--text-secondary);margin:-6px 0 20px;line-height:1.55;">
    Extract names and phone numbers from photos &mdash; business cards, handwritten lists, or Excel screenshots &mdash; using OpenAI Vision.
</p>

<!-- Bug #9 fixed: replaces all 7 alert() popup calls -->
<div id="ocrNotif" class="ocr-notif" role="alert"></div>

<div class="ocr-layout">

    <!-- Upload column -->
    <div class="ocr-card">
        <?php if (!$hasOpenAiKey): ?>
            <!-- Bug #5 fixed: was stroke="#f59e0b" (hardcoded yellow) on red alert-danger → cn_svg uses currentColor -->
            <div class="alert alert-danger">
                <?php echo cn_svg('support'); ?>
                You need to provide an OpenAI API Key to use the Vision Scanner.
                <a href="support.php" style="font-weight:600;">Contact support</a> to set it up.
            </div>
        <?php else: ?>
            <h3 class="ocr-card-title"><?php echo cn_svg('ocr'); ?> Upload Image</h3>
            <p class="ocr-card-sub">Drop a photo containing contact details. Our AI will automatically scan and extract them for you.</p>

            <!-- Bug #8 fixed: was a paper-plane/send icon — wrong for image upload context -->
            <div id="dropZone" class="ocr-drop-zone" role="button" tabindex="0" aria-label="Click or drag an image here to upload">
                <div class="ocr-drop-icon">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
                </div>
                <div class="ocr-drop-title">Click or drag image here</div>
                <div class="ocr-drop-hint">Supports JPG, PNG, WEBP &mdash; max 5 MB</div>
                <input type="file" id="fileInput" accept="image/*" style="display:none;">
            </div>

            <div id="loadingIndicator" class="ocr-loading">
                <div class="ocr-spinner"></div>
                <div class="ocr-loading-label">
                    <?php echo cn_svg('ai'); ?> AI is scanning the image&hellip;
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Results column -->
    <div class="ocr-card">
        <div class="ocr-results-head">
            <h3 class="ocr-card-title" style="margin:0;"><?php echo cn_svg('contacts'); ?> Extracted Leads</h3>
            <!-- Bug #12 fixed: <span> for label text; JS updates .textContent only, never touches SVG child -->
            <button id="saveBtn" class="btn btn-sm btn-primary" style="display:none;" onclick="saveSelectedLeads()">
                <?php echo cn_svg('contacts'); ?>
                <span id="saveBtnLabel">Save to CRM</span>
            </button>
        </div>

        <div id="resultsContainer" class="ocr-results-box">
            <!-- Bug #4 fixed: proper icon container — previously font-size:32px div holding a 16×16 SVG dot -->
            <div id="emptyState" class="ocr-empty">
                <div class="ocr-empty-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>
                </div>
                <div class="ocr-empty-text">Scan results will appear here</div>
            </div>

            <div id="leadsTable" style="display:none;">
                <table class="data-table" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="selectAll" checked></th>
                            <th>Name</th>
                            <th>Phone Number</th>
                        </tr>
                    </thead>
                    <tbody id="leadsBody"></tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
<?php if ($hasOpenAiKey): ?>
var dropZone  = document.getElementById('dropZone');
var fileInput = document.getElementById('fileInput');
var loading   = document.getElementById('loadingIndicator');
var notif     = document.getElementById('ocrNotif');

function showNotif(type, msg) {
    notif.className = 'ocr-notif show ' + type;
    notif.textContent = msg;
    notif.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function hideNotif() {
    notif.className = 'ocr-notif';
    notif.textContent = '';
}

// Drop zone
dropZone.addEventListener('click', function() { fileInput.click(); });
dropZone.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
});

['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function(evt) {
    dropZone.addEventListener(evt, function(e) { e.preventDefault(); e.stopPropagation(); });
});
['dragenter', 'dragover'].forEach(function(evt) {
    dropZone.addEventListener(evt, function() { dropZone.classList.add('dragover'); });
});
['dragleave', 'drop'].forEach(function(evt) {
    dropZone.addEventListener(evt, function() { dropZone.classList.remove('dragover'); });
});

dropZone.addEventListener('drop', function(e) { handleFiles(e.dataTransfer.files); });
fileInput.addEventListener('change', function() { handleFiles(this.files); });

function handleFiles(files) {
    if (!files || files.length === 0) return;
    uploadFile(files[0]);
}

var extractedData = [];

function uploadFile(file) {
    // Bug #9 fixed: alert() replaced with showNotif() throughout
    if (file.type && file.type.indexOf('image/') !== 0) {
        showNotif('error', 'Please choose an image file (JPG, PNG, or WEBP).');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        showNotif('error', 'File must be 5 MB or smaller.');
        return;
    }
    if (file.size === 0) {
        showNotif('error', 'This file appears to be empty.');
        return;
    }

    hideNotif();
    dropZone.style.display = 'none';
    loading.style.display  = 'block';

    var fd = new FormData();
    fd.append('image', file);

    fetch('../api/ocr_process.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
    })
    .then(function(response) {
        return response.text().then(function(text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('OCR API non-JSON:', text.slice(0, 400));
                return {
                    status: 'error',
                    message: 'Server error (' + response.status + '). Check PHP upload limits or OpenAI configuration.'
                };
            }
        });
    })
    .then(function(data) {
        loading.style.display = 'none';
        dropZone.style.display = '';

        if (data.status === 'success') {
            var raw = Array.isArray(data.leads) ? data.leads : [];
            extractedData = raw.map(function(item) {
                if (typeof item === 'string') {
                    try {
                        var o = JSON.parse(item);
                        return (typeof o === 'object' && o !== null) ? o : { name: '', phone: item };
                    } catch (e) {
                        return { name: '', phone: item };
                    }
                }
                return item;
            });
            renderLeads();
        } else {
            showNotif('error', 'Error: ' + (data.message || 'Unknown error from AI scanner.'));
        }
    })
    .catch(function() {
        loading.style.display = 'none';
        dropZone.style.display = '';
        showNotif('error', 'Network error during upload. Check your connection and try again.');
    });
}

function pickLeadFields(lead) {
    if (!lead || typeof lead !== 'object') return { name: '', phone: '' };
    function nonEmpty(val) {
        if (val == null) return '';
        return String(val).trim();
    }
    var nameKeys  = ['name', 'Name', 'contact_name', 'contact', 'Contact', 'label', 'fullname', 'full_name', 'person', 'title'];
    var phoneKeys = ['phone', 'Phone', 'mobile', 'Mobile', 'tel', 'telephone', 'phone_number', 'phonenumber', 'whatsapp', 'number'];
    var name = '', phone = '', i;

    for (i = 0; i < nameKeys.length; i++) {
        name = nonEmpty(lead[nameKeys[i]]);
        if (name) break;
    }
    for (i = 0; i < phoneKeys.length; i++) {
        phone = nonEmpty(lead[phoneKeys[i]]);
        if (phone) break;
    }
    if (!phone) {
        var keys = Object.keys(lead);
        for (i = 0; i < keys.length; i++) {
            var digits = String(lead[keys[i]] == null ? '' : lead[keys[i]]).replace(/\D/g, '');
            if (digits.length >= 7 && digits.length <= 15) {
                phone = String(lead[keys[i]]).trim();
                break;
            }
        }
    }
    return { name: name, phone: phone };
}

function renderLeads() {
    document.getElementById('emptyState').style.display = 'none';
    var tbody = document.getElementById('leadsBody');
    tbody.replaceChildren();

    var rows = Array.isArray(extractedData) ? extractedData : [];

    if (rows.length === 0) {
        var tr = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = 3;
        td.style.cssText = 'text-align:center;padding:20px;color:var(--text-muted);';
        td.textContent = 'No leads found in this image.';
        tr.appendChild(td);
        tbody.appendChild(tr);
        document.getElementById('saveBtn').style.display = 'none';
    } else {
        rows.forEach(function(lead, index) {
            var fields = pickLeadFields(lead);
            var tr = document.createElement('tr');

            // Checkbox cell
            var tdCb = document.createElement('td');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'lead-cb';
            cb.dataset.index = String(index);
            cb.checked = true;
            tdCb.appendChild(cb);

            // Name cell
            // Bug #3 fixed: inName was created and styled but tdName.appendChild(inName) was
            // never called → cells were empty, saveSelectedLeads() crashed on querySelectorAll
            var tdName = document.createElement('td');
            var inName = document.createElement('input');
            inName.type = 'text';
            inName.className = 'ocr-lead-input';
            inName.value = fields.name;
            inName.placeholder = 'Name';
            tdName.appendChild(inName);

            // Phone cell — same fix applied
            var tdPhone = document.createElement('td');
            var inPhone = document.createElement('input');
            inPhone.type = 'text';
            inPhone.className = 'ocr-lead-input';
            inPhone.value = fields.phone;
            inPhone.placeholder = 'Phone';
            tdPhone.appendChild(inPhone);

            tr.appendChild(tdCb);
            tr.appendChild(tdName);
            tr.appendChild(tdPhone);
            tbody.appendChild(tr);
        });
        document.getElementById('saveBtn').style.display = '';
    }

    document.getElementById('leadsTable').style.display = 'block';
}

document.getElementById('selectAll').addEventListener('change', function() {
    var checked = this.checked;
    document.querySelectorAll('.lead-cb').forEach(function(cb) { cb.checked = checked; });
});

function saveSelectedLeads() {
    var rows = document.querySelectorAll('#leadsBody tr');
    var leadsToSave = [];

    rows.forEach(function(row) {
        var cb = row.querySelector('.lead-cb');
        if (cb && cb.checked) {
            var inputs = row.querySelectorAll('input[type="text"]');
            if (inputs.length >= 2) {
                leadsToSave.push({ name: inputs[0].value.trim(), phone: inputs[1].value.trim() });
            }
        }
    });

    if (leadsToSave.length === 0) {
        showNotif('error', 'No leads selected. Check at least one row to save.');
        return;
    }

    var btn   = document.getElementById('saveBtn');
    // Bug #12 fixed: was btn.innerText = 'Saving...' which would destroy the SVG child inside the button.
    // Now only the <span> text label is updated via .textContent.
    var label = document.getElementById('saveBtnLabel');
    var orig  = label.textContent;
    label.textContent = 'Saving…';
    btn.disabled = true;
    hideNotif();

    fetch('../api/ocr_save.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ leads: leadsToSave })
    })
    .then(function(r) {
        var ct = r.headers.get('content-type') || '';
        if (!ct.includes('application/json')) throw new Error('non_json');
        return r.json();
    })
    .then(function(data) {
        if (data.status === 'success') {
            var msg = 'Saved ' + data.saved + ' contact' + (data.saved !== 1 ? 's' : '') + ' to your CRM.';
            if (data.stopped_by_limit) {
                msg += ' Your plan contact limit was reached — some rows were skipped.';
            }
            showNotif('success', msg);
            setTimeout(function() { location.href = 'contacts.php'; }, 1500);
        } else {
            showNotif('error', data.message || 'Save failed. Please try again.');
            label.textContent = orig;
            btn.disabled = false;
        }
    })
    .catch(function() {
        showNotif('error', 'Could not save contacts. Check your connection and try again.');
        label.textContent = orig;
        btn.disabled = false;
    });
}
<?php endif; ?>
</script>

<?php include '../includes/client_footer.php'; ?>
