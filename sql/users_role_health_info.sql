-- Add health_info role support (safe migration).
-- Use only if your users.role column is an ENUM and rejects new role values.
SET @col_type := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
    LIMIT 1
  );
SET @is_enum := (@col_type LIKE 'enum(%');
SET @has_role := (LOCATE("'health_info'", @col_type) > 0);
SET @default := (
    SELECT COLUMN_DEFAULT
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
    LIMIT 1
  );
SET @null := (
    SELECT IS_NULLABLE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
    LIMIT 1
  );
SET @null_sql := IF(@null = 'YES', 'NULL', 'NOT NULL');
-- Preserve DEFAULT only if it is a valid enum value; otherwise omit it to avoid:
--   #1067 - Invalid default value for 'role'
SET @default_escaped := IF(
    @default IS NULL,
    NULL,
    REPLACE(@default, '''', '''''')
  );
SET @default_in_enum := IF(
    @default IS NULL,
    0,
    (
      LOCATE(CONCAT('''', @default_escaped, ''''), @col_type) > 0
    )
  );
SET @default_sql := IF(
    @default_in_enum = 1,
    CONCAT(' DEFAULT ', QUOTE(@default)),
    ''
  );
-- Add 'health_info' to the end of the enum list.
-- We must escape single quotes inside a single-quoted string by doubling them.
SET @new_enum := REPLACE(@col_type, ')', ',''health_info'')');
SET @sql := IF(
    @is_enum
    AND NOT @has_role,
    CONCAT(
      'ALTER TABLE users MODIFY COLUMN role ',
      @new_enum,
      ' ',
      @null_sql,
      @default_sql
    ),
    'SELECT 1'
  );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;