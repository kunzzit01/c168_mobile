<?php
/**
 * 添加账户 API
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/password_hashing.php';
require_once __DIR__ . '/../includes/money_decimal.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
}

function jsonResponse(bool $success, string $message, $data = null): void {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
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

function validateCompanyAccess(PDO $pdo, int $company_id): void {
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';
    if ($current_user_role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $owner_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$current_user_id, $company_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    }
}

function hasAccountCompanyTable(PDO $pdo): bool {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function accountExistsInCompany(PDO $pdo, string $account_id, int $company_id): bool {
    if (hasAccountCompanyTable($pdo)) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE a.account_id = ? AND ac.company_id = ?
        ");
        $stmt->execute([$account_id, $company_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM account WHERE account_id = ? AND company_id = ?");
        $stmt->execute([$account_id, $company_id]);
    }
    return $stmt->fetchColumn() > 0;
}

function roleExists(PDO $pdo, string $role): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM role WHERE code = ?");
    $stmt->execute([$role]);
    return $stmt->fetchColumn() > 0;
}

function insertAccount(PDO $pdo, array $row): int {
    $stmt = $pdo->prepare("
        INSERT INTO account (account_id, name, role, password, payment_alert, alert_day, alert_specific_date, alert_amount, remark, status, last_login)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NULL)
    ");
    $stmt->execute([
        $row['account_id'], $row['name'], $row['role'], $row['password'],
        $row['payment_alert'], $row['alert_day'], $row['alert_specific_date'], $row['alert_amount'], $row['remark']
    ]);
    return (int) $pdo->lastInsertId();
}

function linkAccountToCompanies(PDO $pdo, int $accountId, array $companyIds): void {
    $stmt = $pdo->prepare("INSERT INTO account_company (account_id, company_id) VALUES (?, ?)");
    foreach ($companyIds as $comp_id) {
        try {
            $stmt->execute([$accountId, $comp_id]);
        } catch (PDOException $e) {
            if ($e->getCode() != 23000) {
                error_log("Error linking company to account: " . $e->getMessage());
                throw $e;
            }
        }
    }
}

function userCanAccessCompany(PDO $pdo, int $userId, int $companyId, string $role): bool {
    if ($role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $userId;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$companyId, $owner_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$userId, $companyId]);
    }
    return $stmt->fetchColumn() > 0;
}

function getUsersWithCompanyAccess(PDO $pdo, array $companyIds): array {
    $placeholders = str_repeat('?,', count($companyIds) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, ucp.account_permissions
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        LEFT JOIN user_company_permissions ucp ON u.id = ucp.user_id AND ucm.company_id = ucp.company_id
        WHERE ucm.company_id IN ($placeholders)
    ");
    $stmt->execute($companyIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updateUserAccountPermissionsForNewAccount(PDO $pdo, array $users, array $companyIdsToLink, int $newAccountId, string $account_id): void {
    $updateStmt = $pdo->prepare("
        INSERT INTO user_company_permissions (user_id, company_id, account_permissions, process_permissions)
        VALUES (?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE account_permissions = VALUES(account_permissions)
    ");
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';

    foreach ($users as $user) {
        $currentPermissions = [];
        $hasPermissionsSet = false;
        if (isset($user['account_permissions']) && $user['account_permissions'] !== null && $user['account_permissions'] !== '') {
            if (strtolower(trim($user['account_permissions'])) === 'null') {
                $hasPermissionsSet = false;
            } else {
                $decoded = json_decode($user['account_permissions'], true);
                if (is_array($decoded)) {
                    $hasPermissionsSet = true;
                    if (!empty($decoded)) {
                        $currentPermissions = $decoded;
                    }
                }
            }
        }

        if ($hasPermissionsSet) {
            $accountExists = false;
            foreach ($currentPermissions as $permission) {
                if (isset($permission['id']) && (int)$permission['id'] == (int)$newAccountId) {
                    $accountExists = true;
                    break;
                }
            }
            if (!$accountExists) {
                $currentPermissions[] = ['id' => (int)$newAccountId, 'account_id' => $account_id];
                foreach ($companyIdsToLink as $comp_id) {
                    $updateStmt->execute([$user['id'], $comp_id, json_encode($currentPermissions)]);
                }
            }
        }
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    if (is_partnership_audit_read_only_active($pdo)) {
        jsonResponse(false, '只读账号无法添加账户', null);
        exit;
    }

    $company_id = null;
    if (isset($_POST['company_id']) && $_POST['company_id'] !== '') {
        $company_id = (int)$_POST['company_id'];
    } elseif (isset($_SESSION['company_id'])) {
        $company_id = (int)$_SESSION['company_id'];
    }
    if (!$company_id) {
        throw new Exception('缺少公司信息');
    }

    validateCompanyAccess($pdo, $company_id);

    $account_id = trim($_POST['account_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $payment_alert = isset($_POST['payment_alert']) ? (int)$_POST['payment_alert'] : 0;
    $alert_type = !empty($_POST['alert_type']) ? trim($_POST['alert_type']) : null;
    $alert_start_date = !empty($_POST['alert_start_date']) ? trim($_POST['alert_start_date']) : null;
    if ($alert_type === null && !empty($_POST['alert_day'])) {
        $alert_type = trim($_POST['alert_day']);
    }
    if ($alert_start_date === null && !empty($_POST['alert_specific_date'])) {
        $alert_start_date = trim($_POST['alert_specific_date']);
    }

    if (empty($account_id) || empty($name) || empty($role) || empty($password)) {
        throw new Exception('请填写所有必填字段');
    }

    if ($alert_type !== null) {
        $alert_type_lower = strtolower($alert_type);
        if ($alert_type_lower !== 'weekly' && $alert_type_lower !== 'monthly') {
            $alert_type_int = (int)$alert_type;
            if ($alert_type_int < 1 || $alert_type_int > 31) {
                throw new Exception('Alert Type must be "weekly", "monthly", or a number between 1 and 31');
            }
            $alert_type = (string)$alert_type_int;
        } else {
            $alert_type = $alert_type_lower;
        }
    }

    if ($alert_start_date !== null) {
        $date_parts = explode('-', $alert_start_date);
        if (count($date_parts) !== 3 || !checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
            throw new Exception('Alert Start Date must be a valid date (YYYY-MM-DD)');
        }
    }

    if ($payment_alert == 1 && ($alert_type === null || $alert_start_date === null)) {
        throw new Exception('当支付提醒为是时，必须填写提醒类型和开始日期');
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

    if (accountExistsInCompany($pdo, $account_id, $company_id)) {
        throw new Exception('账户ID已存在');
    }
    if (!roleExists($pdo, $role)) {
        throw new Exception('选择的角色无效');
    }

    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';

    $pdo->beginTransaction();
    try {
        $newAccountId = insertAccount($pdo, [
            'account_id' => $account_id,
            'name' => $name,
            'role' => $role,
            'password' => secure_hash_password($password),
            'payment_alert' => $payment_alert,
            'alert_day' => $alert_day,
            'alert_specific_date' => $alert_specific_date,
            'alert_amount' => $alert_amount,
            'remark' => $remark,
        ]);

        $company_ids_to_link = [];
        if (isset($_POST['company_ids']) && $_POST['company_ids'] !== '') {
            $company_ids = json_decode($_POST['company_ids'], true);
            if (is_array($company_ids) && !empty($company_ids)) {
                foreach ($company_ids as $comp_id) {
                    $comp_id = (int)$comp_id;
                    if ($comp_id > 0 && userCanAccessCompany($pdo, $current_user_id, $comp_id, $current_user_role)) {
                        $company_ids_to_link[] = $comp_id;
                    }
                }
            }
        }
        if (empty($company_ids_to_link)) {
            $company_ids_to_link[] = $company_id;
        }

        linkAccountToCompanies($pdo, $newAccountId, $company_ids_to_link);
        $users = getUsersWithCompanyAccess($pdo, $company_ids_to_link);
        updateUserAccountPermissionsForNewAccount($pdo, $users, $company_ids_to_link, $newAccountId, $account_id);

        $pdo->commit();

        jsonResponse(true, '账户创建成功！', [
            'id' => $newAccountId,
            'account_id' => $account_id,
            'name' => $name,
            'role' => $role,
            'status' => 'active',
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (PDOException $e) {
    http_response_code(500);
    jsonResponse(false, '数据库错误: ' . $e->getMessage(), null);
} catch (Exception $e) {
    http_response_code(400);
    jsonResponse(false, $e->getMessage(), null);
}