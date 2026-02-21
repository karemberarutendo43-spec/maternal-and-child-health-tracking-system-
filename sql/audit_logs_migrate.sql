-- Audit Logs migration for existing installations.
-- Uses information_schema checks so it can run on MySQL/MariaDB safely.

-- Expand description to TEXT (prevents truncation for detailed audit entries).
SET @has_desc := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audit_logs'
    AND COLUMN_NAME = 'description'
);
SET @sql := IF(@has_desc > 0, 'ALTER TABLE audit_logs MODIFY COLUMN description TEXT NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Add optional request context columns.
SET @has_ip := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audit_logs'
    AND COLUMN_NAME = 'ip_address'
);
SET @sql := IF(@has_ip = 0, 'ALTER TABLE audit_logs ADD COLUMN ip_address VARCHAR(45) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_ua := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audit_logs'
    AND COLUMN_NAME = 'user_agent'
);
SET @sql := IF(@has_ua = 0, 'ALTER TABLE audit_logs ADD COLUMN user_agent VARCHAR(255) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_uri := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audit_logs'
    AND COLUMN_NAME = 'request_uri'
);
SET @sql := IF(@has_uri = 0, 'ALTER TABLE audit_logs ADD COLUMN request_uri VARCHAR(255) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Add helpful index for filtering by action.
SET @has_idx := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audit_logs'
    AND INDEX_NAME = 'idx_audit_action'
);
SET @sql := IF(@has_idx = 0, 'ALTER TABLE audit_logs ADD KEY idx_audit_action (action)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
