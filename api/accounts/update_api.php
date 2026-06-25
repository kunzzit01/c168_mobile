<?php
/**
 * 更新账户信息 API
 * 路径: api/accounts/update_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/password_hashing.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
require_once __DIR__ . '/../includes/money_decimal.php';

header('Content-Type: application/json');

function jsonResponse($success, $message, $data = null, $httpCode = null) {
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode([
        'success' => (bool) $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

function normalizeAlertAmount(?string $value): ?string {
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return null;
    }
    if (!money_is_valid($value)) {
        throw new Exception('Alert amount must be a valid decimal amount');
    }
    return money_normalize($value);
}

function normalizeGroupId(?string $groupId): ?string {
    $g = strtoupper(trim((string)($groupId ?? '')));
    return $g !== '' ? $g : null;
}

function resolveAccountUpdateContext(PDO $pdo): array
{
    return tenant_resolve_currency_context_from_request($pdo, [
        'group_id' => $_POST['group_id'] ?? null,
        'view_group' => $_POST['view_group'] ?? null,
        'company_id' => $_POST['company_id'] ?? null,
        'group_only' => $_POST['group_only'] ?? null,
        'session_company_id' => $_SESSION['company_id'] ?? null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
    exit;
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录或缺少公司信息');
    }
    if (is_partnership_audit_read_only_active($pdo)) {
        throw new Exception('只读账号无法修改账户');
    }
    try {
        $accountCtx = resolveAccountUpdateContext($pdo);
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }
    $company_id = (int) ($accountCtx['company_id'] ?? 0);
    if ($company_id <= 0) {
        throw new Exception('用户未登录或缺少公司信息');
    }
    $isGroupScope = (($accountCtx['mode'] ?? '') === 'group');
    $groupCode = (string) ($accountCtx['group_code'] ?? '');
    if ($groupCode !== '' && gc_is_group_login()) {
        gc_assert_company_id_allowed_for_login_scope($pdo, $company_id, $groupCode);
    }

    $id = (int) $_POST['id'];
    $name = trim($_POST['name']);
    $role = trim($_POST['role']);
    $password = trim($_POST['password']);
    $payment_alert = isset($_POST['payment_alert']) ? (int) $_POST['payment_alert'] : 0;

    $alert_type = !empty($_POST['alert_type']) ? trim($_POST['alert_type']) : null;
    $alert_start_date = !empty($_POST['alert_start_date']) ? trim($_POST['alert_start_date']) : null;
    if ($alert_type === null && !empty($_POST['alert_day'])) {
        $alert_type = trim($_POST['alert_day']);
    }
    if ($alert_start_date === null && !empty($_POST['alert_specific_date'])) {
        $alert_start_date = trim($_POST['alert_specific_date']);
    }

    if ($alert_type !== null) {
        $alert_type_lower = strtolower($alert_type);
        if ($alert_type_lower !== 'weekly' && $alert_type_lower !== 'monthly') {
            $alert_type_int = (int) $alert_type;
            if ($alert_type_int < 1 || $alert_type_int > 31) {
                throw new Exception('Alert Type must be "weekly", "monthly", or a number between 1 and 31');
            }
            $alert_type = (string) $alert_type_int;
        } else {
            $alert_type = $alert_type_lower;
        }
    }

    if ($alert_start_date !== null) {
        $date_parts = explode('-', $alert_start_date);
        if (count($date_parts) !== 3 || !checkdate((int) $date_parts[1], (int) $date_parts[2], (int) $date_parts[0])) {
            throw new Exception('Alert Start Date must be a valid date (YYYY-MM-DD)');
        }
    }

    if ($payment_alert == 1 && ($alert_type === null || $alert_start_date === null)) {
        throw new Exception('When Payment Alert is enabled, both Alert Type and Start Date must be provided');
    }

    if ($payment_alert == 0) {
        $alert_type = null;
        $alert_start_date = null;
        $alert_amount = null;
    } else {
        $alert_amount = normalizeAlertAmount($_POST['alert_amount'] ?? null);
    }

    $alert_day = $alert_type;
    $alert_specific_date = $alert_start_date;
    $remark = !empty($_POST['remark']) ? trim($_POST['remark']) : null;

    $submitted_company_ids = null;
    if (isset($_POST['company_ids']) && $_POST['company_ids'] !== '') {
        $decoded = json_decode($_POST['company_ids'], true);
        if (is_array($decoded)) {
            $submitted_company_ids = array_values(array_unique(array_filter(array_map('intval', $decoded), function ($id) {
                return $id > 0;
            })));
        }
    }

    $submitted_linked_account_ids = null;
    if (isset($_POST['linked_account_ids']) && $_POST['linked_account_ids'] !== '') {
        $decoded = json_decode($_POST['linked_account_ids'], true);
        if (is_array($decoded)) {
            $submitted_linked_account_ids = array_values(array_unique(array_filter(array_map('intval', $decoded), function ($linked_id) use ($id) {
                return $linked_id > 0 && $linked_id != $id;
            })));
        }
    }

    if (empty($name) || empty($role)) {
        throw new Exception('请填写所有必填字段');
    }

    $current_user_id = $_SESSION['user_id'] ?? null;
    $current_user_role = $_SESSION['role'] ?? '';
    if (!$current_user_id) {
        throw new Exception('用户未登录');
    }

    if (!tenant_account_belongs_to_context($pdo, $id, $accountCtx)) {
        throw new Exception('无权限操作此账户');
    }
    $stmt = $pdo->prepare('SELECT status FROM account WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $currentAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentAccount) {
        throw new Exception('无权限操作此账户');
    }

    $status = isset($_POST['status']) && trim($_POST['status']) !== '' ? trim($_POST['status']) : $currentAccount['status'];

    // 容错：角色 code 可能存在大小写差异，按不区分大小写匹配
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM role WHERE LOWER(code) = LOWER(?)");
    $stmt->execute([$role]);
    $roleExists = ((int) $stmt->fetchColumn()) > 0;
    if (!$roleExists) {
        // 容错：部分环境 role 表可能缺少核心角色，但前端仍会展示（如 PARTNER/STAFF/DEBTOR）
        $core = strtoupper(trim($role));
        if (!in_array($core, ['PARTNER', 'STAFF', 'DEBTOR'], true)) {
            throw new Exception('选择的角色无效');
        }
    }

    if (!$isGroupScope && is_array($submitted_company_ids) && !empty($submitted_company_ids)) {
        $stmt = $pdo->prepare("SELECT company_id FROM account_company WHERE account_id = ?");
        $stmt->execute([$id]);
        $current_company_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $new_ids = $submitted_company_ids;
        $added_company_ids = array_values(array_diff($new_ids, $current_company_ids));

        if (!empty($current_company_ids)) {
            $placeholders = implode(',', array_fill(0, count($new_ids), '?'));
            $deleteParams = array_merge([$id], $new_ids);
            $deleteStmt = $pdo->prepare("DELETE FROM account_company WHERE account_id = ? AND company_id NOT IN ($placeholders)");
            $deleteStmt->execute($deleteParams);
        }

        $insertStmt = $pdo->prepare("INSERT INTO account_company (account_id, company_id) VALUES (?, ?)");
        foreach ($new_ids as $cid) {
            try {
                $insertStmt->execute([$id, $cid]);
            } catch (PDOException $e) {
                if ($e->getCode() != 23000) throw $e;
            }
        }

        if (!empty($added_company_ids)) {
            $currencyStmt = $pdo->prepare("
                SELECT DISTINCT c.code FROM account_currency ac INNER JOIN currency c ON ac.currency_id = c.id WHERE ac.account_id = ?
            ");
            $currencyStmt->execute([$id]);
            $existingCurrencies = $currencyStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($existingCurrencies)) {
                $findCurrencyStmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
                $insertCurrencyStmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                $linkCurrencyStmt = $pdo->prepare("INSERT INTO account_currency (account_id, currency_id) VALUES (?, ?)");
                $checkLinkedStmt = $pdo->prepare("SELECT id FROM account_currency WHERE account_id = ? AND currency_id = ?");
                foreach ($added_company_ids as $targetCompanyId) {
                    foreach ($existingCurrencies as $code) {
                        if ($code === null || $code === '') continue;
                        $normalizedCode = strtoupper(trim($code));
                        if ($normalizedCode === '') continue;
                        $findCurrencyStmt->execute([$normalizedCode, $targetCompanyId]);
                        $currencyId = $findCurrencyStmt->fetchColumn();
                        if (!$currencyId) {
                            $insertCurrencyStmt->execute([$normalizedCode, $targetCompanyId]);
                            $currencyId = $pdo->lastInsertId();
                            tenant_sync_company_currency_to_parent_groups($pdo, (int) $targetCompanyId, $normalizedCode);
                        }
                        $checkLinkedStmt->execute([$id, $currencyId]);
                        if (!$checkLinkedStmt->fetchColumn()) {
                            try {
                                $linkCurrencyStmt->execute([$id, $currencyId]);
                            } catch (PDOException $e) {
                                if ($e->getCode() != 23000) throw $e;
                            }
                        }
                    }
                }
            }
        }
    }

    if (isset($_POST['linked_account_ids'])) {
        $has_account_link_table = false;
        try {
            $has_account_link_table = $pdo->query("SHOW TABLES LIKE 'account_link'")->rowCount() > 0;
        } catch (PDOException $e) {}
        if ($has_account_link_table) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT CASE WHEN account_id_1 = ? THEN account_id_2 ELSE account_id_1 END AS linked_account_id
                FROM account_link WHERE (account_id_1 = ? OR account_id_2 = ?) AND company_id = ?
            ");
            $stmt->execute([$id, $id, $id, $company_id]);
            $current_linked_account_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $new_ids = (is_array($submitted_linked_account_ids)) ? array_map('intval', $submitted_linked_account_ids) : [];
            $to_add = array_diff($new_ids, $current_linked_account_ids);
            $to_remove = array_diff($current_linked_account_ids, $new_ids);
            if (!empty($to_add)) {
                $placeholders = str_repeat('?,', count($to_add) - 1) . '?';
                $stmt = $pdo->prepare("
                    SELECT DISTINCT a.id FROM account a INNER JOIN account_company ac ON a.id = ac.account_id
                    WHERE a.id IN ($placeholders) AND ac.company_id = ?
                ");
                $stmt->execute(array_merge($to_add, [$company_id]));
                if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) != count($to_add)) {
                    throw new Exception('部分关联账户不属于当前公司');
                }
            }
            foreach ($to_remove as $linked_id) {
                $a1 = min($id, $linked_id);
                $a2 = max($id, $linked_id);
                $pdo->prepare("DELETE FROM account_link WHERE account_id_1 = ? AND account_id_2 = ? AND company_id = ?")->execute([$a1, $a2, $company_id]);
            }
            foreach ($to_add as $linked_id) {
                $a1 = min($id, $linked_id);
                $a2 = max($id, $linked_id);
                try {
                    $pdo->prepare("INSERT INTO account_link (account_id_1, account_id_2, company_id) VALUES (?, ?, ?)")->execute([$a1, $a2, $company_id]);
                } catch (PDOException $e) {
                    if ($e->getCode() != 23000) throw $e;
                }
            }
        }
    }

    $updateFields = ['name = ?', 'role = ?', 'payment_alert = ?', 'alert_day = ?', 'alert_specific_date = ?', 'alert_amount = ?', 'remark = ?', 'status = ?'];
    $updateValues = [$name, $role, $payment_alert, $alert_day, $alert_specific_date, $alert_amount, $remark, $status];
    if (!empty($password)) {
        $updateFields[] = 'password = ?';
        $updateValues[] = secure_hash_password($password);
    }
    $updateValues[] = $id;
    $sql = "UPDATE account SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($updateValues);
    $err = $stmt->errorInfo();
    if ($err[0] !== '00000' && $err[0] !== null) {
        throw new Exception('数据库更新错误: ' . ($err[2] ?? '未知错误'));
    }

    jsonResponse(true, 'Account updated successfully', null);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}