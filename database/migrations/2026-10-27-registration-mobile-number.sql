-- Run after 2026-10-27-email-verification.sql on the allinoneweb database.
ALTER TABLE users
 ADD COLUMN mobile_no VARCHAR(25) NULL AFTER credential_notes;
