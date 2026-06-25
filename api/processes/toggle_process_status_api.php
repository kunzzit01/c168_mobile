<?php
session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
require_once __DIR__ . '/../includes/process_modified_by.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Invalid request method', 405);
    exit;
}

/**
 * 判断角色是否为 manager 及以上（manager / admin / owner）
 */
function isManagerOrAboveRole(string $role): bool {
    $role = strtolower(trim($role));
    return in_array($role, ['manager', 'admin', 'owner'], true);
}

function getBankProcessCurrent(PDO $pdo, int $id, int $companyId): ?array {
    // 读取当前状态与 dts_modified，便于在从 inactive 切回 active 时重置本轮 manual_inactive 记录
    $stmt = $pdo->prepare("SELECT status, dts_modified FROM bank_process WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateBankProcessStatus(PDO $pdo, string $newStatus, ?string $newDayEnd, int $id, int $companyId): void {
    if ($newDayEnd !== null) {
        $stmt = $pdo->prepare("UPDATE bank_process SET status = ?, day_end = ? WHERE id = ? AND company_id = ?");
        $stmt->execute([$newStatus, $newDayEnd, $id, $companyId]);
    } else {
        $stmt = $pdo->prepare("UPDATE bank_process SET status = ? WHERE id = ? AND company_id = ?");
        $stmt->execute([$newStatus, $id, $companyId]);
    }
}

function getProcessCurrent(PDO $pdo, int $id, int $companyId): ?array {
    $stmt = $pdo->prepare("SELECT status FROM process WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateProcessStatus(PDO $pdo, string $newStatus, int $id, int $companyId): void {
    $modifier = resolveProcessModifierFromSession($pdo);
    $stmt = $pdo->prepare(
        'UPDATE process SET status = ?'
        . processModifiedBySqlSuffix()
        . ' WHERE id = ? AND company_id = ?'
    );
    $stmt->execute(array_merge(
        [$newStatus],
        processModifiedByBindParams($modifier),
        [$id, $companyId]
    ));
    if ($stmt->rowCount() == 0) throw new Exception('状态更新失败');
}

try {
    if (!isset($_SESSION['company_id'])) {
        api_error('用户未登录或缺少公司信息', 401);
        exit;
    }
    $companyId = (int)$_SESSION['company_id'];
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        api_error('无效的流程ID', 400);
        exit;
    }
    if (is_partnership_audit_read_only_active($pdo)) {
        api_error('只读账号无法执行此操作', 403);
        exit;
    }
    $permission = trim($_POST['permission'] ?? '');

    if ($permission === 'Bank') {
        $current = getBankProcessCurrent($pdo, $id, $companyId);
        if (!$current) {
            api_error('无权限操作此流程', 403);
            exit;
        }
        $status = $current['status'];
        // Bank：不再限制 INACTIVE → ACTIVE 的切换，也不依赖 Transaction 记录
        if ($status === 'inactive') {
            // 只有 manager 及以上（manager / admin / owner）可以将 inactive 切回 active
            $sessionRole = isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';
            if (!isManagerOrAboveRole($sessionRole)) {
                api_error('Only manager or above can change Bank Process from INACTIVE to ACTIVE', 403);
                exit;
            }
            $newStatus = 'active';
        } else {
            $newStatus = ($status === 'active') ? 'inactive' : (($status === 'waiting') ? 'active' : 'active');
        }

        // day_end 逻辑保持由其他流程（如 Accounting Due Transaction）控制，这里只更新状态本身
        updateBankProcessStatus($pdo, $newStatus, null, $id, $companyId);

        // 修复 1+1 / 1+2 / 1+3：从 inactive 切回 active 之后，下次再改为 inactive 仍然可以产生 manual_inactive Transaction。
        // 方式：当本次是 inactive → active 时，清掉该流程在本轮产生的 manual_inactive 记录，
        // 让 accounting_inbox_api 的 NOT EXISTS 条件重新为真。
        if ($status === 'inactive' && $newStatus === 'active') {
            try {
                $del = $pdo->prepare("DELETE FROM process_accounting_posted WHERE company_id = ? AND process_id = ? AND period_type = 'manual_inactive'");
                $del->execute([$companyId, $id]);
            } catch (Throwable $e) {
                // 删除失败不影响状态切换本身
            }
        }

        api_success(['newStatus' => $newStatus], '状态更新成功');
        exit;
    }

    $current = getProcessCurrent($pdo, $id, $companyId);
    if (!$current) {
        api_error('无权限操作此流程', 403);
        exit;
    }
    $newStatus = $current['status'] === 'active' ? 'inactive' : 'active';
    updateProcessStatus($pdo, $newStatus, $id, $companyId);
    api_success(['newStatus' => $newStatus], '状态更新成功');
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}