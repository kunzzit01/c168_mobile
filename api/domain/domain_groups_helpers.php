<?php
/**
 * Domain API — groups table (separate from company group_id labels).
 */

function domainApiHasGroupsTable(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'groups'");
        return $stmt && $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * @param mixed $groups
 * @return array<int, array<string, mixe>>
 */
function domainApiNormalizeGroupsPayload($groups): array
{
    if (is_string($groups)) {
        $decoded = json_decode($groups, true);
        $groups = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
    }
    if (!is_array($groups)) {
        return [];
    }
    $out = [];
    foreach ($groups as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = strtoupper(trim((string) ($row['group_code'] ?? $row['group_id'] ?? '')));
        if ($code === '') {
            continue;
        }
        $entry = [
            'group_code' => $code,
            'expiration_date' => !empty($row['expiration_date']) ? $row['expiration_date'] : null,
            'permissions' => (isset($row['permissions']) && is_array($row['permissions'])) ? $row['permissions'] : [],
            'fee_share_allocations' => $row['fee_share_allocations'] ?? null,
            'apply_commission_payments_on_domain_save' => !empty($row['apply_commission_payments_on_domain_save']),
        ];
        $prev = strtoupper(trim((string) ($row['previous_group_code'] ?? '')));
        if ($prev !== '' && $prev !== $code) {
            $entry['previous_group_code'] = $prev;
        }
        $out[] = $entry;
    }
    return $out;
}

/**
 * @return string[]
 */
function domainApiCollectGroupCodesFromGroupsPayload(array $groups): array
{
    $set = [];
    foreach ($groups as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = strtoupper(trim((string) ($row['group_code'] ?? '')));
        if ($code !== '') {
            $set[$code] = true;
        }
    }
    return array_keys($set);
}

/**
 * @return string[]
 */
function domainApiLoadOwnerGroupCodes(PDO $pdo, int $ownerId): array
{
    if ($ownerId <= 0 || !domainApiHasGroupsTable($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT UPPER(TRIM(group_code)) FROM `groups` WHERE owner_id = ?');
    $stmt->execute([$ownerId]);
    $codes = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $c = strtoupper(trim((string) ($row[0] ?? '')));
        if ($c !== '') {
            $codes[] = $c;
        }
    }
    return $codes;
}

/**
 * Groups payload vs companies payload: group_code must not equal any company_id.
 */
function domainApiValidateGroupsAndCompaniesExclusivity(array $groups, array $companies): ?string
{
    $groupCodes = domainApiCollectGroupCodesFromGroupsPayload($groups);
    $groupSet = array_flip($groupCodes);
    foreach ($companies as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = strtoupper(trim((string) ($row['company_id'] ?? '')));
        if ($cid !== '' && isset($groupSet[$cid])) {
            return 'Group ID and Company ID cannot use the same code: ' . $cid;
        }
    }
    return null;
}

function domainApiValidateGroupCodesUniqueWithinPayload(array $groups): ?string
{
    $seen = [];
    foreach ($groups as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = strtoupper(trim((string) ($row['group_code'] ?? '')));
        if ($code === '') {
            continue;
        }
        if (isset($seen[$code])) {
            return 'Duplicate Group ID in this form: "' . $code . '".';
        }
        $seen[$code] = true;
    }
    return null;
}

/**
 * update: only newly added group codes for cross-owner check.
 *
 * @return array<int, array<string, mixed>>
 */
function domainApiFilterGroupsToNewCodes(PDO $pdo, int $ownerId, array $groups): array
{
    $existing = array_flip(domainApiLoadOwnerGroupCodes($pdo, $ownerId));
    $filtered = [];
    foreach ($groups as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = strtoupper(trim((string) ($row['group_code'] ?? '')));
        if ($code !== '' && !isset($existing[$code])) {
            $filtered[] = $row;
        }
    }
    return $filtered;
}

/**
 * Global uniqueness: codes in groups payload + company rows vs company table + groups table.
 *
 * @param array<int, array<string, mixed>> $groups
 * @param array<int, array<string, mixed>> $companies company rows (real companies only)
 */
function domainApiValidateCrossOwnerCodesIncludingGroups(
    PDO $pdo,
    array $groups,
    array $companies,
    ?int $excludeOwnerId
): ?string {
    $codesSet = [];
    foreach (domainApiCollectGroupCodesFromGroupsPayload($groups) as $c) {
        $codesSet[$c] = true;
    }
    foreach (domainApiCollectCompanyGroupCodesFromRows($companies) as $c) {
        $codesSet[$c] = true;
    }
    $codes = array_keys($codesSet);
    if ($codes === []) {
        return null;
    }

    $in = implode(',', array_fill(0, count($codes), '?'));
    $excludeBranchClause = '';
    $excludeRepeatParams = [];
    if ($excludeOwnerId !== null && (int) $excludeOwnerId > 0) {
        $excludeBranchClause = ' id NOT IN (SELECT id FROM company WHERE owner_id = ?) AND ';
        $excludeRepeatParams[] = (int) $excludeOwnerId;
    }

    $parts = [
        'SELECT z.v FROM ('
        . ' SELECT UPPER(TRIM(CAST(company_id AS CHAR))) AS v FROM company WHERE ' . $excludeBranchClause
        . " company_id IS NOT NULL AND TRIM(CAST(company_id AS CHAR)) <> ''"
        . " AND UPPER(TRIM(CAST(company_id AS CHAR))) IN ($in)"
        . ' UNION'
        . ' SELECT UPPER(TRIM(CAST(group_id AS CHAR))) AS v FROM company WHERE ' . $excludeBranchClause
        . " group_id IS NOT NULL AND TRIM(CAST(group_id AS CHAR)) <> ''"
        . " AND UPPER(TRIM(CAST(group_id AS CHAR))) IN ($in)"
    ];

    $execParams = [];
    if ($excludeOwnerId !== null && (int) $excludeOwnerId > 0) {
        $execParams = array_merge($excludeRepeatParams, $codes, $excludeRepeatParams, $codes);
    } else {
        $execParams = array_merge($codes, $codes);
    }

    if (domainApiHasGroupsTable($pdo)) {
        $excludeGroupClause = '';
        $groupExcludeParams = [];
        if ($excludeOwnerId !== null && (int) $excludeOwnerId > 0) {
            $excludeGroupClause = ' id NOT IN (SELECT id FROM `groups` WHERE owner_id = ?) AND ';
            $groupExcludeParams[] = (int) $excludeOwnerId;
        }
        $parts[] = ' UNION SELECT UPPER(TRIM(CAST(group_code AS CHAR))) AS v FROM `groups` WHERE ' . $excludeGroupClause
            . " group_code IS NOT NULL AND TRIM(CAST(group_code AS CHAR)) <> ''"
            . " AND UPPER(TRIM(CAST(group_code AS CHAR))) IN ($in)";
        if ($excludeOwnerId !== null && (int) $excludeOwnerId > 0) {
            $execParams = array_merge($execParams, $groupExcludeParams, $codes);
        } else {
            $execParams = array_merge($execParams, $codes);
        }
    }

    $parts[] = ' ) AS z WHERE z.v <> \'\' LIMIT 1';
    $sql = implode('', $parts);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($execParams);
    } catch (PDOException $e) {
        error_log('[domain_api] domainApiValidateCrossOwnerCodesIncludingGroups: ' . $e->getMessage());
        return 'Could not verify company/group code availability. Please try again.';
    }

    $hit = $stmt->fetchColumn();
    if ($hit === false || $hit === null || trim((string) $hit) === '') {
        return null;
    }
    $code = strtoupper(trim((string) $hit));

    return 'This ID "' . $code . '" is already in use by another domain (not allowed). Choose a different Company ID or Group ID.';
}

/**
 * @return array<int, array<string, mixed>>
 */
function domainApiFetchOwnerGroupsFormatted(PDO $pdo, int $ownerId): array
{
    if ($ownerId <= 0 || !domainApiHasGroupsTable($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT group_code, expiration_date, permissions, fee_share_allocations FROM `groups` WHERE owner_id = ? ORDER BY group_code'
    );
    $stmt->execute([$ownerId]);
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $perms = $row['permissions'];
        if ($perms !== null && $perms !== '') {
            $decoded = json_decode($perms, true);
            $row['permissions'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        } else {
            $row['permissions'] = [];
        }
        $row['fee_share_allocations'] = normalizeFeeShareAllocationsInput($row['fee_share_allocations'] ?? null);
        $groups[] = $row;
    }
    return $groups;
}

/**
 * Upsert groups for owner; delete groups removed from payload.
 *
 * @param array<int, array<string, mixed>> $groupsData
 */
function domainApiSaveOwnerGroups(PDO $pdo, int $ownerId, array $groupsData, string $createdBy): void
{
    if (!domainApiHasGroupsTable($pdo) || $ownerId <= 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT id, UPPER(TRIM(group_code)) AS gc FROM `groups` WHERE owner_id = ?');
    $stmt->execute([$ownerId]);
    $existingByCode = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existingByCode[(string) $row['gc']] = (int) $row['id'];
    }

    $newCodes = [];
    $insert = $pdo->prepare(
        'INSERT INTO `groups` (group_code, owner_id, expiration_date, permissions, fee_share_allocations, created_by) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $update = $pdo->prepare(
        'UPDATE `groups` SET expiration_date = ?, permissions = ?, fee_share_allocations = ? WHERE id = ?'
    );

    $renameGroupCode = $pdo->prepare(
        'UPDATE `groups` SET group_code = ?, expiration_date = ?, permissions = ?, fee_share_allocations = ? WHERE id = ?'
    );
    $renameCompanyGroupId = $pdo->prepare(
        "UPDATE company SET group_id = ? WHERE owner_id = ? AND UPPER(TRIM(group_id)) = ?"
    );

    foreach ($groupsData as $g) {
        $code = strtoupper(trim((string) ($g['group_code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $prevCode = strtoupper(trim((string) ($g['previous_group_code'] ?? '')));
        $newCodes[$code] = true;
        $permsJson = !empty($g['permissions']) && is_array($g['permissions']) ? json_encode($g['permissions']) : null;
        $feeJson = feeShareAllocationsToJson(normalizeFeeShareAllocationsInput($g['fee_share_allocations'] ?? null));
        $exp = !empty($g['expiration_date']) ? $g['expiration_date'] : null;

        if ($prevCode !== '' && $prevCode !== $code && isset($existingByCode[$prevCode])) {
            $groupPk = $existingByCode[$prevCode];
            $renameGroupCode->execute([$code, $exp, $permsJson, $feeJson, $groupPk]);
            unset($existingByCode[$prevCode]);
            $existingByCode[$code] = $groupPk;
            $renameCompanyGroupId->execute([$code, $ownerId, $prevCode]);
            if (function_exists('domainApiRenameC168MemberAccountCode')) {
                if (function_exists('domainApiRenameC168MemberAccountCode')) {
                domainApiRenameC168MemberAccountCode($pdo, $prevCode, $code);
            }
            }
            continue;
        }

        if (isset($existingByCode[$code])) {
            $update->execute([$exp, $permsJson, $feeJson, $existingByCode[$code]]);
        } else {
            $insert->execute([$code, $ownerId, $exp, $permsJson, $feeJson, $createdBy]);
            $existingByCode[$code] = (int) $pdo->lastInsertId();
        }
    }

    $toDelete = [];
    foreach ($existingByCode as $code => $id) {
        if (!isset($newCodes[$code])) {
            $toDelete[] = $id;
        }
    }
    if ($toDelete !== []) {
        deleteByIds($pdo, 'groups', 'id', $toDelete);
    }
}

/**
 * Sync group_company_map from company.group_id (string code) → groups.id + company.id.
 */
function domainApiSyncGroupCompanyMap(PDO $pdo, int $ownerId): void
{
    if ($ownerId <= 0 || !domainApiHasGroupsTable($pdo)) {
        return;
    }
    try {
        $pdo->query('SELECT 1 FROM group_company_map LIMIT 1');
    } catch (PDOException $e) {
        return;
    }

    $pdo->prepare('DELETE gcm FROM group_company_map gcm
        INNER JOIN `groups` g ON g.id = gcm.group_id
        WHERE g.owner_id = ?')->execute([$ownerId]);

    $stmt = $pdo->prepare("
        SELECT c.id AS company_pk, UPPER(TRIM(c.group_id)) AS gid
        FROM company c
        WHERE c.owner_id = ?
          AND c.group_id IS NOT NULL AND TRIM(c.group_id) <> ''
          AND c.company_id IS NOT NULL AND TRIM(c.company_id) <> ''
    ");
    $stmt->execute([$ownerId]);
    $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($pairs === []) {
        return;
    }

    $gStmt = $pdo->prepare('SELECT id FROM `groups` WHERE owner_id = ? AND UPPER(TRIM(group_code)) = ? LIMIT 1');
    $ins = $pdo->prepare('INSERT IGNORE INTO group_company_map (group_id, company_id) VALUES (?, ?)');
    foreach ($pairs as $p) {
        $gid = (string) ($p['gid'] ?? '');
        if ($gid === '') {
            continue;
        }
        $gStmt->execute([$ownerId, $gid]);
        $groupPk = $gStmt->fetchColumn();
        if ($groupPk === false || $groupPk === null) {
            continue;
        }
        $ins->execute([(int) $groupPk, (int) $p['company_pk']]);
    }
}

/**
 * Remove legacy group-only company rows (empty company_id) for this owner after groups table save.
 */
function domainApiDeleteGroupOnlyCompanyRows(PDO $pdo, int $ownerId): void
{
    if ($ownerId <= 0) {
        return;
    }
    $stmt = $pdo->prepare("
        SELECT id FROM company
        WHERE owner_id = ?
          AND (company_id IS NULL OR TRIM(company_id) = '')
    ");
    $stmt->execute([$ownerId]);
    $ids = normalizeIds($stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($ids !== []) {
        deleteByIds($pdo, 'company', 'id', $ids);
    }
}

/**
 * Companies payload for save: only real company rows (non-empty company_id).
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function domainApiFilterRealCompaniesPayload(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = strtoupper(trim((string) ($row['company_id'] ?? '')));
        if ($cid === '') {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * @return string[]
 */
function domainApiGroupExistsByCode(PDO $pdo, string $groupCode): bool
{
    $code = strtoupper(trim($groupCode));
    if ($code === '' || !domainApiHasGroupsTable($pdo)) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT 1 FROM `groups` WHERE UPPER(TRIM(group_code)) = ? LIMIT 1');
        $st->execute([$code]);
        return $st->fetchColumn() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function domainApiGetGroupOwnerCodeByGroupCode(PDO $pdo, string $groupCode): string
{
    $code = strtoupper(trim($groupCode));
    if ($code === '' || !domainApiHasGroupsTable($pdo)) {
        return '';
    }
    try {
        $st = $pdo->prepare("
            SELECT UPPER(TRIM(COALESCE(o.owner_code, ''))) AS oc
            FROM `groups` g
            INNER JOIN owner o ON o.id = g.owner_id
            WHERE UPPER(TRIM(g.group_code)) = ?
            ORDER BY g.id ASC
            LIMIT 1
        ");
        $st->execute([$code]);
        return strtoupper(trim((string) ($st->fetchColumn() ?: '')));
    } catch (PDOException $e) {
        return '';
    }
}

/**
 * @param mixed $groups
 * @return string[]
 */
function domainApiExtractProvisionGroupIds($groups): array
{
    $ids = [];
    foreach (domainApiNormalizeGroupsPayload($groups) as $row) {
        $c = strtoupper(trim((string) ($row['group_code'] ?? '')));
        if ($c !== '' && $c !== 'C168') {
            $ids[] = $c;
        }
    }
    return array_values(array_unique($ids));
}

function domainApiOwnerGroupIdsForList(PDO $pdo, int $ownerId): array
{
    $codes = domainApiLoadOwnerGroupCodes($pdo, $ownerId);
    if ($codes !== []) {
        return $codes;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT UPPER(TRIM(group_id)) AS g
            FROM company
            WHERE owner_id = ? AND group_id IS NOT NULL AND TRIM(group_id) <> ''
        ");
        $stmt->execute([$ownerId]);
        $legacy = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $c = strtoupper(trim((string) ($row[0] ?? '')));
            if ($c !== '') {
                $legacy[] = $c;
            }
        }
        sort($legacy);
        return $legacy;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Group code + expiration for Domain list / expiration status modal.
 *
 * @return array<int, array{group_code: string, expiration_date: string|null}>
 */
function domainApiOwnerGroupsFullForList(PDO $pdo, int $ownerId): array
{
    if ($ownerId <= 0) {
        return [];
    }

    $byCode = [];
    if (domainApiHasGroupsTable($pdo)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT group_code, expiration_date FROM `groups` WHERE owner_id = ? ORDER BY group_code'
            );
            $stmt->execute([$ownerId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = strtoupper(trim((string) ($row['group_code'] ?? '')));
                if ($code === '') {
                    continue;
                }
                $byCode[$code] = [
                    'group_code' => $code,
                    'expiration_date' => $row['expiration_date'] ?? null,
                ];
            }
        } catch (PDOException $e) {
            // fall through to legacy lookup
        }
    }

    foreach (domainApiOwnerGroupIdsForList($pdo, $ownerId) as $code) {
        if (isset($byCode[$code])) {
            continue;
        }
        $exp = null;
        try {
            $stmt = $pdo->prepare("
                SELECT expiration_date
                FROM company
                WHERE owner_id = ?
                  AND UPPER(TRIM(company_id)) = ?
                LIMIT 1
            ");
            $stmt->execute([$ownerId, $code]);
            $exp = $stmt->fetchColumn();
            $exp = $exp !== false && $exp !== null && trim((string) $exp) !== ''
                ? (string) $exp
                : null;
        } catch (PDOException $e) {
            $exp = null;
        }
        $byCode[$code] = ['group_code' => $code, 'expiration_date' => $exp];
    }

    $out = array_values($byCode);
    usort($out, static function ($a, $b) {
        return strcmp((string) $a['group_code'], (string) $b['group_code']);
    });
    return $out;
}
