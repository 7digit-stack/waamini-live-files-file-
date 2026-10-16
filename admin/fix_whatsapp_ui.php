<?php
/**
 * Fix UI on settings_whatsapp.php
 */

// Read the existing file and fix inline styles
$content = file_get_contents(__DIR__ . '/settings_whatsapp.php');

// Fix: Replace inline-styled info boxes with CSS class
$content = str_replace(
    'background:rgba(108, 92, 231, 0.06); border:1px solid rgba(108, 92, 231, 0.12);',
    'background:var(--info-bg); border:1px solid var(--info-border);',
    $content
);

// Fix: Replace inline-styled alerts with proper alert classes
$content = str_replace(
    '<div class="alert alert-success"><svg',
    '<div class="alert alert-success"><svg',
    $content
);

// Fix: Replace inline page header h2 styles
$content = str_replace(
    'style="font-size:14px;font-weight:700;margin:0 0 10px;color:var(--text-primary);"',
    '',
    $content
);

// Fix: Replace inline section titles with class
$content = str_replace(
    'style="font-size:14px;font-weight:700;margin:0 0 10px;color:var(--text-primary);"',
    'class="settings-section-title"',
    $content
);

// Fix: Replace inline-styled labels
$content = str_replace(
    'style="font-size:13px;font-weight:500;color:var(--text-secondary);margin-bottom:6px;"',
    'class="form-label"',
    $content
);

// Fix: Replace inline-styled inputs
$content = str_replace(
    'style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-input);color:var(--text-primary);font-size:13px;"',
    'class="form-control"',
    $content
);

// Fix: Replace inline-styled select
$content = str_replace(
    'style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-input);color:var(--text-primary);font-size:13px;"',
    'class="form-control"',
    $content
);

// Fix: Replace inline-styled buttons
$content = str_replace(
    'style="padding:10px 20px;background:var(--accent);color:white;border:none;border-radius:var(--radius);font-size:13px;font-weight:600;cursor:pointer;"',
    'class="btn btn-primary"',
    $content
);

// Fix: Replace inline-styled edit buttons
$content = str_replace(
    'style="padding:10px 20px;background:rgba(108,92,231,0.1);color:var(--accent);border:none;border-radius:var(--radius);font-size:13px;font-weight:600;cursor:pointer;"',
    'class="btn btn-edit"',
    $content
);

// Fix: Replace inline-styled form-card
$content = str_replace(
    'style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:24px;"',
    'class="form-card"',
    $content
);

// Fix: Replace inline-styled page-header
$content = str_replace(
    'style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding-bottom:16px;border-bottom:1px solid var(--border);"',
    'class="page-header"',
    $content
);

// Fix: Replace inline-styled info-box
$content = str_replace(
    'style="display:flex;gap:10px;padding:14px;background:rgba(108,92,231,0.06);border:1px solid rgba(108,92,231,0.12);border-radius:10px;font-size:13px;color:var(--text-secondary);line-height:1.5;"',
    'class="info-box"',
    $content
);

file_put_contents(__DIR__ . '/settings_whatsapp.php', $content);
echo "Fixed settings_whatsapp.php inline styles\n";