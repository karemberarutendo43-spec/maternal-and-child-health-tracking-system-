-- OAuth identity linking for social login providers (Google).
-- Run this on your MySQL/MariaDB database.

CREATE TABLE IF NOT EXISTS oauth_identities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(32) NOT NULL,
  provider_user_id VARCHAR(128) NOT NULL,
  user_id INT NOT NULL,
  email VARCHAR(191) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_provider_user (provider, provider_user_id),
  KEY idx_user (user_id)
);

