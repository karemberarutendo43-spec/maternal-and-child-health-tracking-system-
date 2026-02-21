CREATE TABLE IF NOT EXISTS auth_remember_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  selector CHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  user_agent VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  UNIQUE KEY uq_auth_remember_selector (selector),
  INDEX idx_auth_remember_user (user_id),
  INDEX idx_auth_remember_expires (expires_at),
  CONSTRAINT fk_auth_remember_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
);

