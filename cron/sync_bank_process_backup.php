<?php
/**
 * 将 bank_process 全量同步到 bank_process_backup。
 * company_name 来自 company.company_id；各 account 名来自 account.name；
 * modified_by_name / created_by_name 与 process 列表一致：user.login_id 与 owner.owner_code 的 COALESCE。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_bank_process_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM bank_process_backup';
$sqlInsert = <<<'SQL'
INSERT INTO bank_process_backup (
  id, company_id, company_name, country, bank, `type`, `name`,
  card_merchant_id, card_merchant_name, customer_id, customer_name,
  profit_account_id, profit_account_name, contract, insurance, sop, remark,
  cost, price, profit, profit_sharing, day_start, day_start_frequency, day_end,
  `status`, issue_flag, dts_modified, modified_by, modified_by_name,
  modified_by_type, modified_by_owner_id, dts_created, created_by, created_by_name,
  created_by_type, created_by_owner_id,
  accounting_resend_relax_created_floor, accounting_resend_schedule_day_start,
  accounting_resend_schedule_day_end, accounting_resend_schedule_frequency
)
SELECT
  bp.id,
  bp.company_id,
  COALESCE(co.company_id, '') AS company_name,
  bp.country,
  bp.bank,
  bp.type,
  bp.name,
  bp.card_merchant_id,
  COALESCE(acm.name, '') AS card_merchant_name,
  bp.customer_id,
  COALESCE(acu.name, '') AS customer_name,
  bp.profit_account_id,
  COALESCE(apf.name, '') AS profit_account_name,
  bp.contract,
  bp.insurance,
  bp.sop,
  bp.remark,
  bp.cost,
  bp.price,
  bp.profit,
  bp.profit_sharing,
  bp.day_start,
  bp.day_start_frequency,
  bp.day_end,
  bp.status,
  bp.issue_flag,
  bp.dts_modified,
  bp.modified_by,
  COALESCE(um.login_id, om.owner_code, '') AS modified_by_name,
  bp.modified_by_type,
  bp.modified_by_owner_id,
  bp.dts_created,
  bp.created_by,
  COALESCE(uc.login_id, oc.owner_code, '') AS created_by_name,
  bp.created_by_type,
  bp.created_by_owner_id,
  bp.accounting_resend_relax_created_floor,
  bp.accounting_resend_schedule_day_start,
  bp.accounting_resend_schedule_day_end,
  bp.accounting_resend_schedule_frequency
FROM bank_process bp
LEFT JOIN company co ON co.id = bp.company_id
LEFT JOIN account acm ON acm.id = bp.card_merchant_id
LEFT JOIN account acu ON acu.id = bp.customer_id
LEFT JOIN account apf ON apf.id = bp.profit_account_id
LEFT JOIN `user` um ON bp.modified_by = um.id
  AND (bp.modified_by_type IS NULL OR bp.modified_by_type = 'user')
LEFT JOIN owner om ON bp.modified_by_owner_id = om.id AND bp.modified_by_type = 'owner'
LEFT JOIN `user` uc ON bp.created_by = uc.id
LEFT JOIN owner oc ON bp.created_by_owner_id = oc.id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_bank_process_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_bank_process_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
