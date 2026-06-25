-- =============================================================================
-- Fix: JK Admin cannot see company 95 (company_id=123) data after DB import
-- =============================================================================
--
-- Symptom:
--   Owner can see accounts/process/transactions for company "95", but admin JK cannot.
--   accountlistapi returns success with accounts=[] and user_permissions_count > 0.
--
-- Root cause:
--   user_company_permissions.account_permissions for JK listed group-level domain
--   accounts (e.g. id 4924 code "95" on company_id=5), not subsidiary accounts
--   linked in account_company for company_id=123. Admin whitelist IN (...) filter
--   intersects to zero rows. Owner role bypasses this filter.
--
-- This script rebuilds JK's whitelist for company 95 from a working admin row
-- (BEE / user_id=255) that already has correct subsidiary account/process IDs.
--
-- Target DB: easycount (local) or u857194726_c168site (production) — same data.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 0) Optional: inspect before fix
-- -----------------------------------------------------------------------------
-- SELECT u.login_id, ucp.company_id, c.company_id AS company_code,
--        JSON_LENGTH(ucp.account_permissions) AS whitelist_accounts,
--        (SELECT COUNT(*) FROM account_company ac
--         WHERE ac.company_id = ucp.company_id
--           AND (COALESCE(ac.scope_type, '') IN ('', 'company'))) AS linked_accounts
-- FROM user_company_permissions ucp
-- JOIN user u ON u.id = ucp.user_id
-- JOIN company c ON c.id = ucp.company_id
-- WHERE u.login_id = 'JK' AND c.company_id = '95';

-- -----------------------------------------------------------------------------
-- 1) Fix JK (user_id=218) permissions for company 95 (company_id=123)
--    Copy from BEE (user_id=255) who has correct subsidiary whitelist.
-- -----------------------------------------------------------------------------
UPDATE user_company_permissions AS jk
INNER JOIN user_company_permissions AS src
  ON src.user_id = 255
 AND src.company_id = 123
SET
  jk.account_permissions = src.account_permissions,
  jk.process_permissions = src.process_permissions
WHERE jk.user_id = 218
  AND jk.company_id = 123;

-- -----------------------------------------------------------------------------
-- 2) Verify: whitelist should overlap all subsidiary accounts for company 95
-- -----------------------------------------------------------------------------
SELECT
  u.login_id,
  ucp.company_id,
  c.company_id AS company_code,
  JSON_LENGTH(ucp.account_permissions) AS whitelist_accounts,
  JSON_LENGTH(ucp.process_permissions) AS whitelist_processes,
  (
    SELECT COUNT(*)
    FROM account_company ac
    WHERE ac.company_id = ucp.company_id
      AND (COALESCE(ac.scope_type, '') IN ('', 'company'))
  ) AS linked_accounts,
  (
    SELECT COUNT(*)
    FROM account_company ac
    WHERE ac.company_id = ucp.company_id
      AND (COALESCE(ac.scope_type, '') IN ('', 'company'))
      AND JSON_CONTAINS(
        ucp.account_permissions,
        JSON_OBJECT('id', ac.account_id),
        '$'
      )
  ) AS whitelist_hits_linked_accounts
FROM user_company_permissions ucp
JOIN user u ON u.id = ucp.user_id
JOIN company c ON c.id = ucp.company_id
WHERE u.login_id = 'JK'
  AND c.company_id = '95';

-- Expected after fix:
--   whitelist_accounts  ~ 280+
--   whitelist_hits_linked_accounts = linked_accounts (277 on current dump)

COMMIT;

-- =============================================================================
-- Alternative A: grant JK full visibility (no account/process whitelist)
-- =============================================================================
-- UPDATE user_company_permissions
-- SET account_permissions = NULL,
--     process_permissions = NULL
-- WHERE user_id = 218
--   AND company_id = 123;

-- =============================================================================
-- Alternative B: rebuild whitelist from subsidiary tables (MariaDB 10.5+ JSON_ARRAYAGG)
-- =============================================================================
-- SET SESSION group_concat_max_len = 4194304;
-- UPDATE user_company_permissions
-- SET account_permissions = (
--   SELECT CONCAT('[', IFNULL(GROUP_CONCAT(
--     CONCAT('{"id":', a.id, ',"account_id":', JSON_QUOTE(a.account_id), '}')
--     ORDER BY a.account_id SEPARATOR ','
--   ), ''), ']')
--   FROM account_company ac
--   INNER JOIN account a ON a.id = ac.account_id
--   WHERE ac.company_id = 123
--     AND (COALESCE(ac.scope_type, '') IN ('', 'company'))
-- ),
-- process_permissions = (
--   SELECT CONCAT('[', IFNULL(GROUP_CONCAT(
--     CONCAT(
--       '{"id":', p.id,
--       ',"process_id":', JSON_QUOTE(p.process_id),
--       ',"process_description":', JSON_QUOTE(COALESCE(d.name, '')),
--       '}'
--     )
--     ORDER BY p.process_id SEPARATOR ','
--   ), ''), ']')
--   FROM process p
--   LEFT JOIN description d ON d.id = p.description_id
--   WHERE p.company_id = 123
-- )
-- WHERE user_id = 218
--   AND company_id = 123;
