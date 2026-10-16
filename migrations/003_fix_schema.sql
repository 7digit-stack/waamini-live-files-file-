ALTER TABLE campaign_queue ADD COLUMN company_id INT(11) AFTER id;
ALTER TABLE campaign_queue ADD COLUMN message_payload TEXT AFTER phone;
