<?php
/**
 * WAAMINI - WhatsApp Webhook API Endpoint
 * Path: /api/webhook/whatsapp
 * This is a thin wrapper around webhook.php.
 * Company is resolved from metadata.phone_number_id in the webhook payload.
 */

require_once __DIR__ . '/../../webhook.php';
