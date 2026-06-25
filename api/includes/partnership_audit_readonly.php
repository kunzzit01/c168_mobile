<?php
/**
 * Audit / Partnership 只读标志（与 session_check.php、submit_api.php 一致）。
 * 可在 session_write_close 之后调用：仅读库，不依赖 session 锁。
 */

/**
 * @return int 0 = 可写, 1 = 只读（仅对 audit/partnership 有意义；其他角色恒为 0）
 */
function get_partnership_audit_read_only_flag(PDO $pdo): int
{
    if (!isset($_SESSION['user_id'])) {
        return 0;
    }
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    if (!in_array($role, ['audit', 'partnership'], true)) {
        return 0;
    }
    $uid = (int) $_SESSION['user_id'];
    $stmt = $pdo->prepare('SELECT read_only FROM user WHERE id = ?');
    $stmt->execute([$uid]);
    $ro = $stmt->fetchColumn();
    $readOnly = (int) (($ro !== false && $ro !== null) ? $ro : 1);
    if ($role === 'partnership') {
        $cid = isset($_SESSION['company_id']) ? (int) $_SESSION['company_id'] : 0;
        if ($cid > 0) {
            $co = $pdo->prepare("SELECT read_only FROM company_ownership WHERE account_id = ? AND company_id = ? AND owner_type = 'user'");
            $co->execute([$uid, $cid]);
            $cr = $co->fetchColumn();
            if ($cr !== false && $cr !== null) {
                $readOnly = (int) $cr;
            }
        }
    }
    return $readOnly === 1 ? 1 : 0;
}

function is_partnership_audit_read_only_active(PDO $pdo): bool
{
    return get_partnership_audit_read_only_flag($pdo) === 1;
}

/**
 * User List 更新：只读 Partnership/Audit 仅禁止改自己；编辑下级用户（含权限）仍允许。
 */
function partnership_audit_read_only_blocks_userlist_self_edit(PDO $pdo, int $targetUserId): bool
{
    if (!is_partnership_audit_read_only_active($pdo)) {
        return false;
    }
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    return $targetUserId > 0 && $uid > 0 && $targetUserId === $uid;
}
