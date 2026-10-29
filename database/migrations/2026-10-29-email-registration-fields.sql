-- Run once against the allinoneweb database.
-- Adds the separate name fields used by the current email-verification registration form.
ALTER TABLE users
  ADD COLUMN middlename VARCHAR(80) NULL AFTER firstname,
  ADD COLUMN suffix VARCHAR(20) NULL AFTER middlename;
