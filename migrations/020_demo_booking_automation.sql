-- WAAMINI demo booking automation fields
-- Adds reminder and automatic rescheduling support to existing installations.

ALTER TABLE demo_bookings
    ADD COLUMN demo_datetime DATETIME DEFAULT NULL,
    ADD COLUMN reminder_1h_sent TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN reminder_5m_sent TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN rescheduled_from DATETIME DEFAULT NULL,
    ADD COLUMN rescheduled_to DATETIME DEFAULT NULL,
    ADD COLUMN reschedule_reason VARCHAR(255) DEFAULT NULL,
    ADD COLUMN reschedule_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN missed_at DATETIME DEFAULT NULL,
    ADD INDEX idx_demo_reminder (status, demo_datetime),
    ADD INDEX idx_demo_reschedule (status, demo_datetime, reschedule_count);

ALTER TABLE demo_bookings
    MODIFY COLUMN status ENUM('scheduled','confirmed','completed','cancelled','missed') NOT NULL DEFAULT 'scheduled';