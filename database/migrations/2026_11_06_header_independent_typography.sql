-- Independent typography and rich text for each public header text component.
ALTER TABLE site_header_settings
  ADD COLUMN title_html LONGTEXT NULL,
  ADD COLUMN hidden_left_html LONGTEXT NULL,
  ADD COLUMN hidden_right_html LONGTEXT NULL,
  ADD COLUMN typography_json LONGTEXT NULL;
