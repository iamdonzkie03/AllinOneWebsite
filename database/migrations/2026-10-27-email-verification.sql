-- Run once against an existing allinoneweb database.
ALTER TABLE users
 ADD COLUMN lastname VARCHAR(100) NULL AFTER full_name,
 ADD COLUMN firstname VARCHAR(100) NULL AFTER lastname,
 ADD COLUMN middle_initial VARCHAR(5) NULL AFTER firstname,
 ADD COLUMN nickname VARCHAR(80) NULL AFTER middle_initial,
 ADD COLUMN birthdate DATE NULL AFTER nickname,
 ADD COLUMN birthplace VARCHAR(180) NULL AFTER birthdate,
 ADD COLUMN email_verified_at DATETIME NULL AFTER birthplace;

CREATE TABLE email_verification_codes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 code_hash VARCHAR(255) NOT NULL,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_email_verification_user (user_id, expires_at, used_at)
) ENGINE=InnoDB;
