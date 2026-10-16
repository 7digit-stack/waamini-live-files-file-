<?php
/**
 * Quick Blast was retired. Sending a template to a contact group is done in Campaigns → Create
 * (now or scheduled, with variables and header media). Past quick blasts are listed under Campaigns.
 * Old links and bookmarks land there.
 */
include '../includes/client_auth_check.php';

header('Location: ' . (has_feature('campaigns') ? 'campaign_create.php' : 'dashboard.php'), true, 301);
exit();
