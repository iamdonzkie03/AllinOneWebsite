-- Run once on an existing allinoneweb database to add editable public header settings.
CREATE TABLE IF NOT EXISTS site_header_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  header_title VARCHAR(160) NOT NULL DEFAULT 'Z.C. commonplace',
  logo_path VARCHAR(500) NULL,
  header_links LONGTEXT NULL,
  hidden_left VARCHAR(180) NULL,
  hidden_right VARCHAR(180) NULL,
  font_family VARCHAR(80) NOT NULL DEFAULT 'Ubuntu',
  font_style VARCHAR(20) NOT NULL DEFAULT 'normal',
  font_size TINYINT UNSIGNED NOT NULL DEFAULT 20,
  font_weight SMALLINT UNSIGNED NOT NULL DEFAULT 700,
  logo_position_x TINYINT UNSIGNED NOT NULL DEFAULT 50,
  logo_position_y TINYINT UNSIGNED NOT NULL DEFAULT 50,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO site_header_settings (id, header_title, header_links, hidden_left, hidden_right)
VALUES (1, 'Z.C. commonplace', '[]', 'ZAMBOANGA CITY · STORIES, CULTURE & COMMUNITY', 'Local perspectives. Shared with care.');
