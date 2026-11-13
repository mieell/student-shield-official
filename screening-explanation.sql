-- Run once in phpMyAdmin before deploying the screening explanation update.
-- Existing entries remain readable and display their recorded score and source.
ALTER TABLE vent_logs ADD COLUMN analysis_details TEXT NULL;
