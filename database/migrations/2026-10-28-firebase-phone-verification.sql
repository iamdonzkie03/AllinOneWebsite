-- Run once against an existing AllInOneWebsite database after backing it up.
-- This adds the phone-verification state used by Firebase SMS registration.
ALTER TABLE users
  ADD COLUMN phone_verified_at DATETIME NULL AFTER email_verified_at;

CREATE INDEX idx_users_mobile_no ON users (mobile_no);
