-- Users table cleanup helpers (run manually).
-- Goal: make mother logins phone-only and keep data consistent.
--
-- IMPORTANT: Review results before running UPDATE/ALTER statements.
-- Recommended: take a DB backup first.

-- 1) Find mothers with missing/empty phone
SELECT id, full_name, phone, username, role, status
FROM users
WHERE role = 'mother' AND (phone IS NULL OR TRIM(phone) = '');

-- 2) Find duplicate phone numbers (all roles)
SELECT phone, COUNT(*) AS cnt
FROM users
WHERE phone IS NOT NULL AND TRIM(phone) <> ''
GROUP BY phone
HAVING COUNT(*) > 1
ORDER BY cnt DESC, phone;

-- 3) Preview phone values that look like local formats (should be stored as +2637XXXXXXXX)
SELECT id, full_name, phone
FROM users
WHERE role = 'mother'
  AND phone IS NOT NULL
  AND (
    phone REGEXP '^7[0-9]{8}$'
    OR phone REGEXP '^2637[0-9]{8}$'
  );

-- 4) Normalize mothers' phone numbers into +2637XXXXXXXX (ONLY if you confirmed the formats above are correct)
-- UPDATE users
-- SET phone = CONCAT('+263', phone)
-- WHERE role = 'mother' AND phone REGEXP '^7[0-9]{8}$';
--
-- UPDATE users
-- SET phone = CONCAT('+', phone)
-- WHERE role = 'mother' AND phone REGEXP '^2637[0-9]{8}$';

-- 5) Optional: ensure mothers don't use usernames (phone-only login)
-- UPDATE users
-- SET username = NULL
-- WHERE role = 'mother';

-- 6) Optional (only after duplicates are resolved): enforce unique phone numbers
-- ALTER TABLE users ADD UNIQUE KEY uniq_users_phone (phone);

