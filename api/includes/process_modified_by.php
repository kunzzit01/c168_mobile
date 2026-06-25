<?php
/**
 * Resolve process.modified_by* audit fields from the current session.
 */

function getProcessAuditUserId(PDO $pdo): int
{
    $isOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner';
    $currentCompanyId = $_SESSION['company_id'] ?? null;

    if (!$isOwner && isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        $userId = (int) $_SESSION['user_id'];
        $stmt = $pdo->prepare('SELECT id FROM user WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn()) {
            return $userId;
        }
    }

    if (!$isOwner && !empty($_SESSION['login_id'])) {
        $stmt = $pdo->prepare('SELECT id FROM user WHERE login_id = ? LIMIT 1');
        $stmt->execute([$_SESSION['login_id']]);
        $userId = $stmt->fetchColumn();
        if ($userId) {
            return (int) $userId;
        }
    }

    if ($currentCompanyId) {
        $stmt = $pdo->prepare('
            SELECT u.id
            FROM user u
            INNER JOIN user_company_map ucm ON u.id = ucm.user_id
            WHERE ucm.company_id = ? AND u.status = \'active\'
            ORDER BY u.id ASC
            LIMIT 1
        ');
        $stmt->execute([$currentCompanyId]);
        $fallbackId = $stmt->fetchColumn();
        if ($fallbackId) {
            return (int) $fallbackId;
        }
    }

    $stmt = $pdo->query('SELECT id FROM user WHERE status = \'active\' ORDER BY id ASC LIMIT 1');
    $fallbackId = $stmt->fetchColumn();
    if ($fallbackId) {
        return (int) $fallbackId;
    }

    throw new RuntimeException('Unable to resolve audit user id from session');
}

/**
 * @return array{modified_by: ?int, modified_by_type: string, modified_by_owner_id: ?int}
 */
function resolveProcessModifierFromSession(PDO $pdo, bool $strict = false): array
{
    $isOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner';
    if ($isOwner) {
        $ownerId = isset($_SESSION['owner_id']) ? (int) $_SESSION['owner_id'] : 0;

        return [
            'modified_by' => null,
            'modified_by_type' => 'owner',
            'modified_by_owner_id' => $ownerId > 0 ? $ownerId : null,
        ];
    }

    try {
        return [
            'modified_by' => getProcessAuditUserId($pdo),
            'modified_by_type' => 'user',
            'modified_by_owner_id' => null,
        ];
    } catch (Throwable $e) {
        if ($strict) {
            throw $e;
        }

        return [
            'modified_by' => null,
            'modified_by_type' => 'user',
            'modified_by_owner_id' => null,
        ];
    }
}

function processModifiedBySqlSuffix(): string
{
    return ', dts_modified = NOW(), modified_by = ?, modified_by_type = ?, modified_by_owner_id = ?';
}

/**
 * @return array<int, mixed>
 */
function processModifiedByBindParams(array $modifier): array
{
    return [
        $modifier['modified_by'],
        $modifier['modified_by_type'],
        $modifier['modified_by_owner_id'],
    ];
}

/** SQL expression: modified-by display name with legacy fallback to creator when audit fields are missing. */
function processModifiedByLoginSql(): string
{
    return "COALESCE(
        u_modified.login_id,
        o_modified.owner_code,
        CASE
            WHEN p.dts_modified <> p.dts_created
            THEN COALESCE(u_created.login_id, o_created.owner_code)
        END
    )";
}

/** Same as processModifiedByLoginSql() but for bank_process alias `bp`. */
function bankProcessModifiedByLoginSql(): string
{
    return "COALESCE(
        u_modified.login_id,
        o_modified.owner_code,
        CASE
            WHEN bp.dts_modified <> bp.dts_created
            THEN COALESCE(u_created.login_id, o_created.owner_code)
        END
    )";
}
