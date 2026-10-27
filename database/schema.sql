CREATE DATABASE IF NOT EXISTS allinoneweb
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE allinoneweb;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('viewer','creator','system_admin') NOT NULL DEFAULT 'viewer',
  account_status ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
  credential_notes TEXT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  slug VARCHAR(140) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO categories(name, slug, description) VALUES
('Creative Arts and Photography','creative-arts-photography','Photography, visual art, design and creative work'),
('News Connect','news-connect','Community updates, civic stories and current affairs'),
('Marketplace','marketplace','Products, makers, services and listings'),
('Entertainment & Politics','entertainment-politics','Entertainment, public figures, political discussion and analysis'),
('History & Culture','history-culture','Zamboanga heritage, Chavacano identity and living traditions');

CREATE TABLE content (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  content_type ENUM('article','product','property','video','news') NOT NULL DEFAULT 'article',
  title VARCHAR(220) NOT NULL,
  slug VARCHAR(240) NOT NULL,
  excerpt VARCHAR(500) NULL,
  body MEDIUMTEXT NULL,
  cover_image VARCHAR(500) NULL,
  price DECIMAL(14,2) NULL,
  location VARCHAR(220) NULL,
  status ENUM('draft','pending_review','published','rejected','archived') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_content_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_category FOREIGN KEY (category_id) REFERENCES categories(id),
  INDEX idx_content_public (status, published_at),
  INDEX idx_content_owner (owner_id, status),
  FULLTEXT INDEX ft_content_search (title, excerpt, body)
) ENGINE=InnoDB;

CREATE TABLE moderation_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  administrator_id BIGINT UNSIGNED NOT NULL,
  target_user_id BIGINT UNSIGNED NULL,
  content_id BIGINT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mod_admin FOREIGN KEY (administrator_id) REFERENCES users(id),
  CONSTRAINT fk_mod_user FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_mod_content FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE SET NULL
) ENGINE=InnoDB;
