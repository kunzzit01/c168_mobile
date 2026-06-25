<?php
/**
 * Helper file to share company query logic between API and inline PHP pages
 * Prevents code duplication and ensures consistent filtering.
 */

require_once __DIR__ . '/../includes/group_scope_resolve.php';

if (!function_exists('getCompaniesByUser')) {
    /**
     * @param bool $includeGroupLinkVirtualRows
     *   When true, each company in a group S that has been pooled into group T via
     *   `group_ownership (owner_type='group', partner_group_id=T)` also appears with
     *   `group_id = T`. Only for dashboard-style views that should SEE S-companies
     *   under T — do NOT enable for ownership-management pages, where native groupings
     *   must be preserved.
     */
    function getCompaniesByUser(PDO $pdo, int $userId, bool $fetchAll = false, bool $includeGroupLinkVirtualRows = false): array {
        if ($fetchAll) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.company_id, c.group_id AS native_group_id, c.group_id, c.expiration_date, c.permissions
                FROM company c
                INNER JOIN user_company_map ucm ON c.id = ucm.company_id
                WHERE ucm.user_id = ? AND c.company_id != ''
                ORDER BY c.company_id ASC
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Domain "Selected Companies" (e.g. independent ABC) live under the same owner_id
            // as mapped group subsidiaries — include the full owner portfolio for dashboard pills.
            $ownerScopeStmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.company_id, c.group_id AS native_group_id, c.group_id, c.expiration_date, c.permissions
                FROM company c
                WHERE c.company_id != ''
                  AND c.owner_id IN (
                    SELECT DISTINCT c2.owner_id
                    FROM company c2
                    INNER JOIN user_company_map ucm ON c2.id = ucm.company_id
                    WHERE ucm.user_id = ? AND c2.owner_id IS NOT NULL
                  )
                ORDER BY c.company_id ASC
            ");
            $ownerScopeStmt->execute([$userId]);
            $ownerRows = $ownerScopeStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($ownerRows)) {
                $byId = [];
                foreach (array_merge($rows, $ownerRows) as $r) {
                    $byId[(int) ($r['id'] ?? 0)] = $r;
                }
                $rows = array_values($byId);
            }

            if ($includeGroupLinkVirtualRows) {
                // Derive the set of owner ids this admin is managing via user_company_map
                // — only group-links configured by those owners should surface as
                // virtual rows on this admin's dashboard.
                $scopeStmt = $pdo->prepare("
                    SELECT DISTINCT c.owner_id
                    FROM company c
                    INNER JOIN user_company_map ucm ON c.id = ucm.company_id
                    WHERE ucm.user_id = ? AND c.owner_id IS NOT NULL
                ");
                $scopeStmt->execute([$userId]);
                $viewerOwnerIds = array_map('intval', $scopeStmt->fetchAll(PDO::FETCH_COLUMN));
                $rows = _applyGroupLinkVirtualRows($pdo, $rows, $viewerOwnerIds);
            }

            return $rows;
        } else {
            $session_company_id = $_SESSION['company_id'] ?? null;
            $native_group  = null;
            if ($session_company_id) {
                $stmtGrp = $pdo->prepare("SELECT group_id FROM company WHERE id = ? LIMIT 1");
                $stmtGrp->execute([$session_company_id]);
                $grpRow = $stmtGrp->fetch(PDO::FETCH_ASSOC);
                if ($grpRow) {
                    $native_group  = $grpRow['group_id'] ?: null;
                }
            }

            $params = [];
            $whereParts = [];

            if ($native_group !== null && trim($native_group) !== '') {
                $whereParts[] = "(LOWER(c.group_id) = LOWER(?))";
                $params[] = trim($native_group);
            } else {
                $whereParts[] = "(c.group_id IS NULL OR c.group_id = '')";
            }

            $whereSQL = implode(" OR ", $whereParts);
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.company_id, c.group_id, c.expiration_date
                FROM company c
                INNER JOIN user_company_map ucm ON c.id = ucm.company_id
                WHERE ucm.user_id = ? AND c.company_id != '' AND ($whereSQL)
                ORDER BY c.company_id ASC
            ");
            array_unshift($params, $userId);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

if (!function_exists('_applyGroupLinkVirtualRows')) {
    /**
     * Given a set of base company rows (each with `id`, `group_id`, ...), append
     * "virtual" duplicates so companies in a source group S also appear under any
     * target group T where S has been pooled into T via group_ownership.
     *
     * Visibility scope: a group-link only affects the viewer's dashboard if the
     * viewer's owner-scope matches `group_ownership.owner_id` (i.e. the link
     * creator). Otherwise an unrelated owner would see the linker's extra group
     * show up in their own dashboard, which is wrong.
     *
     * @param array $viewerOwnerIds  owner ids that scope the virtual rows. Pass
     *   [$ownerId] for real-owner sessions, or the full list of company.owner_id
     *   the admin is mapped to.
     *
     * Keys preserved from input rows are copied verbatim, only `group_id` is overridden.
     */
    function _applyGroupLinkVirtualRows(PDO $pdo, array $rows, array $viewerOwnerIds = []): array {
        if (empty($rows)) return $rows;

        // Normalise & guard — missing scope means "no virtual rows" to avoid leaking
        // other owners' group-link configurations.
        $viewerOwnerIds = array_values(array_unique(array_filter(array_map('intval', $viewerOwnerIds))));
        if (empty($viewerOwnerIds)) return $rows;

        $hasGroupOwnership = false;
        try {
            $hasGroupOwnership = $pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() > 0;
        } catch (Exception $e) { /* ignore */ }
        if (!$hasGroupOwnership) return $rows;

        $in = str_repeat('?,', count($viewerOwnerIds) - 1) . '?';
        // Two ways a link becomes visible to the viewer:
        //  (a) SELF-GROUP link — owner_type='group', owner_id = viewer.
        //      e.g. JK set "IG → Group:AP 3%" in his own Group Earnings.
        //  (b) EXTERNAL-OWNER link — owner_type='owner', account_id = viewer,
        //      partner_group_id stored when the viewer was matched by Group ID.
        //      e.g. JK-Admin invited JK-Owner (AA) into IG at 2% → a row
        //           (group_id='IG', owner_type='owner', account_id=JK-Owner, partner_group_id='AA', percentage=2)
        //      From JK-Owner's viewpoint, IG-companies should surface under AA × 2%.
        // Links are tagged with `is_external_partner` so downstream can decide whether
        // the source group should be hidden entirely (external case) or kept visible
        // (self-group case — the viewer still needs the source group to exist).
        $params = array_merge($viewerOwnerIds, $viewerOwnerIds);
        $stmtLinks = $pdo->prepare("
            SELECT source_group, target_group,
                   MAX(link_percentage) as link_percentage,
                   MAX(is_external_partner) as is_external_partner
            FROM (
                SELECT
                    UPPER(TRIM(group_id))           COLLATE utf8mb4_unicode_ci as source_group,
                    UPPER(TRIM(partner_group_id))   COLLATE utf8mb4_unicode_ci as target_group,
                    percentage as link_percentage,
                    0 as is_external_partner
                FROM group_ownership
                WHERE owner_type = 'group'
                  AND percentage > 0
                  AND partner_group_id IS NOT NULL
                  AND TRIM(partner_group_id) <> ''
                  AND owner_id IN ($in)

                UNION ALL

                SELECT
                    UPPER(TRIM(group_id))           COLLATE utf8mb4_unicode_ci as source_group,
                    UPPER(TRIM(partner_group_id))   COLLATE utf8mb4_unicode_ci as target_group,
                    percentage as link_percentage,
                    1 as is_external_partner
                FROM group_ownership
                WHERE owner_type = 'owner'
                  AND percentage > 0
                  AND partner_group_id IS NOT NULL
                  AND TRIM(partner_group_id) <> ''
                  AND account_id IN ($in)
            ) u
            GROUP BY source_group, target_group
        ");
        $stmtLinks->execute($params);
        $links = $stmtLinks->fetchAll(PDO::FETCH_ASSOC);
        // Note: do NOT early-return when $links is empty — per-company
        // account-ownership level links (company_ownership) are queried below
        // and may still produce virtual rows even without group-level links.

        // For external-owner links, the viewer only has access to the source group
        // through the partner link — they shouldn't see the source group as a separate
        // tab in their dashboard. Collect these source groups so we can strip any
        // non-native rows matching them.
        $hideSourceGroups = [];
        foreach ($links as $ln) {
            if ((int) $ln['is_external_partner'] === 1) {
                $hideSourceGroups[$ln['source_group']] = true;
            }
        }

        // flowsTo[source_group][target_group] = link_percentage (float)
        $flowsTo = [];
        foreach ($links as $ln) {
            $flowsTo[$ln['source_group']][$ln['target_group']] = (float) $ln['link_percentage'];
        }

        // Per-company Account-Ownership level links (company_ownership.owner_type='group').
        // Each row means "this specific company pools P% into group T". Applies only
        // to the viewer's own companies — same-owner scope.
        $companyFlowsTo = []; // company_id -> target_group -> pct
        try {
            $hasCo = $pdo->query("SHOW TABLES LIKE 'company_ownership'")->rowCount() > 0;
        } catch (Exception $e) { $hasCo = false; }
        if ($hasCo) {
            $stmtCoLinks = $pdo->prepare("
                SELECT co.company_id,
                       UPPER(TRIM(co.partner_group_id)) COLLATE utf8mb4_unicode_ci as target_group,
                       co.percentage as link_percentage
                FROM company_ownership co
                INNER JOIN company c ON co.company_id = c.id
                WHERE co.owner_type = 'group'
                  AND co.percentage > 0
                  AND co.partner_group_id IS NOT NULL
                  AND TRIM(co.partner_group_id) <> ''
                  AND c.owner_id IN ($in)
            ");
            $stmtCoLinks->execute($viewerOwnerIds);
            foreach ($stmtCoLinks->fetchAll(PDO::FETCH_ASSOC) as $ln) {
                $companyFlowsTo[(int) $ln['company_id']][$ln['target_group']] = (float) $ln['link_percentage'];
            }
        }

        // Nothing to emit if neither source produced any links.
        if (empty($flowsTo) && empty($companyFlowsTo)) return $rows;

        // Build dedupe keys by (group_id, id) AND (group_id, company_id). If the
        // target group already contains a native company with the same `company_id`
        // (name), we skip the virtual row — otherwise two buttons with the same
        // label would share one filter and the first one clicked would dictate
        // which underlying c.id is used, which is very confusing for the user.
        $seenById   = [];
        $seenByName = [];
        foreach ($rows as $r) {
            $gKey = strtoupper((string) ($r['group_id'] ?? ''));
            $seenById[$r['id'] . '|' . $gKey] = true;
            $cname = strtoupper(trim((string) ($r['company_id'] ?? '')));
            if ($cname !== '') {
                $seenByName[$cname . '|' . $gKey] = true;
            }
        }

        $extra = [];
        foreach ($rows as $r) {
            $src  = strtoupper(trim((string) ($r['group_id'] ?? '')));
            $cid  = (int) ($r['id'] ?? 0);
            $cname = strtoupper(trim((string) ($r['company_id'] ?? '')));

            // Collect every target group either layer can reach.
            $allTargets = [];
            if ($src !== '' && isset($flowsTo[$src])) {
                foreach ($flowsTo[$src] as $tgt => $_) $allTargets[$tgt] = true;
            }
            if ($cid > 0 && isset($companyFlowsTo[$cid])) {
                foreach ($companyFlowsTo[$cid] as $tgt => $_) $allTargets[$tgt] = true;
            }
            if (empty($allTargets)) continue;

            foreach (array_keys($allTargets) as $tgt) {
                $companyPct = $companyFlowsTo[$cid][$tgt] ?? null; // Account Ownership company→group
                $groupPct   = ($src !== '') ? ($flowsTo[$src][$tgt] ?? null) : null; // Group Earnings group→group

                // Priority: a company's DIRECT Account-Ownership allocation beats the
                // broader "native group → target" rule. If TT already explicitly
                // allocates 10% to SS via company_ownership, the AA→SS 90% row in
                // group_ownership does NOT further multiply — AA's pool is just how
                // the AA-group-level residue is distributed, which doesn't apply
                // to TT's already-allocated slice.
                $linkPct = null;
                if ($companyPct !== null) {
                    $linkPct = $companyPct;
                } elseif ($groupPct !== null) {
                    $linkPct = $groupPct;
                } else {
                    continue;
                }

                if (isset($seenById[$cid . '|' . $tgt])) continue;
                if ($cname !== '' && isset($seenByName[$cname . '|' . $tgt])) continue;
                $seenById[$cid . '|' . $tgt] = true;
                if ($cname !== '') $seenByName[$cname . '|' . $tgt] = true;

                $virtual = $r;
                $virtual['group_id'] = $tgt;
                $virtual['link_source_group'] = $src;
                $virtual['link_percentage']   = $linkPct;
                $extra[] = $virtual;
            }
        }

        // Strip rows whose group_id is an external-owner partner's source group —
        // those companies should appear only under the partner's own group (target).
        // Keep native rows (is_external=0) so the viewer doesn't accidentally lose
        // access to their own companies in the unlikely case of group-name collision.
        if (!empty($hideSourceGroups)) {
            $rows = array_values(array_filter($rows, function ($r) use ($hideSourceGroups) {
                $g = strtoupper(trim((string) ($r['group_id'] ?? '')));
                if ($g === '' || !isset($hideSourceGroups[$g])) return true;
                if (isset($r['is_external']) && (int) $r['is_external'] === 0) return true;
                return false;
            }));
        }

        return empty($extra) ? $rows : array_merge($rows, $extra);
    }
}

if (!function_exists('getCompaniesByOwner')) {
    /**
     * @param bool $includeGroupLinkVirtualRows
     *   When true, each company in a group S that has been pooled into group T via
     *   `group_ownership (owner_type='group', partner_group_id=T, owner_id=ownerId)`
     *   also appears with `group_id = T`. Dashboard views only — do NOT enable for
     *   ownership-management pages.
     */
    function getCompaniesByOwner(PDO $pdo, int $ownerId, bool $fetchAll, bool $includeGroupLinkVirtualRows = false): array {
        // Check if group_ownership table exists (group-level partner linking)
        $hasGroupOwnership = false;
        try {
            $hasGroupOwnership = $pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() > 0;
        } catch (Exception $e) { /* ignore */ }

        // Subquery: companies visible to this owner via group_ownership (owner_type='owner').
        // When TEST is linked to group 'IG' (with percentage > 0), TEST should see
        // all companies whose c.group_id = 'IG'.
        $groupVisibleSQL = $hasGroupOwnership
            ? "OR EXISTS (
                    SELECT 1 FROM group_ownership go
                    WHERE go.owner_type = 'owner'
                      AND go.account_id = ?
                      AND go.percentage > 0
                      AND c.group_id IS NOT NULL
                      AND TRIM(c.group_id) <> ''
                      AND LOWER(TRIM(go.group_id)) COLLATE utf8mb4_unicode_ci
                          = LOWER(TRIM(c.group_id)) COLLATE utf8mb4_unicode_ci
                )"
            : "";

        if ($fetchAll) {
            $sql = "
                SELECT DISTINCT c.id, c.company_id, c.expiration_date,
                       c.group_id AS native_group_id,
                       COALESCE(co.partner_group_id, c.group_id) as group_id,
                       IF(c.owner_id = ?, 0, 1) as is_external
                FROM company c
                LEFT JOIN company_ownership co ON c.id = co.company_id AND co.owner_type = 'owner' AND co.account_id = ?
                WHERE (
                    c.owner_id = ?
                    OR (co.account_id = ? AND co.percentage > 0)
                    $groupVisibleSQL
                ) AND c.company_id != ''
                ORDER BY is_external ASC, c.company_id ASC
            ";
            $params = [$ownerId, $ownerId, $ownerId, $ownerId];
            if ($hasGroupOwnership) {
                $params[] = $ownerId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $rows = gc_enrich_owner_company_rows_with_group_map($pdo, $ownerId, $rows);

            if ($includeGroupLinkVirtualRows) {
                // Real-owner session — only their own group-links produce virtual rows.
                $rows = _applyGroupLinkVirtualRows($pdo, $rows, [$ownerId]);
            }

            return $rows;
        } else {
            $session_company_id = $_SESSION['company_id'] ?? null;
            $partner_group = null;
            $native_group  = null;
            if ($session_company_id) {
                $stmtGrp = $pdo->prepare("
                    SELECT co.partner_group_id, c.group_id
                    FROM company c
                    LEFT JOIN company_ownership co
                        ON c.id = co.company_id AND co.owner_type = 'owner' AND co.account_id = ?
                    WHERE c.id = ?
                    LIMIT 1
                ");
                $stmtGrp->execute([$ownerId, $session_company_id]);
                $grpRow = $stmtGrp->fetch(PDO::FETCH_ASSOC);
                if ($grpRow) {
                    $partner_group = $grpRow['partner_group_id'] ?: null;
                    $native_group  = $grpRow['group_id']         ?: null;
                }
            }

            // Check if this owner has group-level access to the native_group
            // (linked via group_ownership, e.g. TEST linked to JK's group 'IG')
            $hasGroupAccessToNative = false;
            if ($hasGroupOwnership && $native_group !== null && trim($native_group) !== '') {
                $stmtGo = $pdo->prepare("
                    SELECT 1 FROM group_ownership
                    WHERE owner_type = 'owner'
                      AND account_id = ?
                      AND percentage > 0
                      AND LOWER(TRIM(group_id)) COLLATE utf8mb4_unicode_ci
                          = LOWER(TRIM(?)) COLLATE utf8mb4_unicode_ci
                    LIMIT 1
                ");
                $stmtGo->execute([$ownerId, trim($native_group)]);
                $hasGroupAccessToNative = (bool) $stmtGo->fetchColumn();
            }

            $params = [];
            $whereParts = [];

            if ($partner_group !== null && trim($partner_group) !== '') {
                $whereParts[] = "(c.owner_id != ? AND co.account_id = ? AND LOWER(co.partner_group_id) = LOWER(?) AND co.percentage > 0)";
                $params = array_merge($params, [$ownerId, $ownerId, trim($partner_group)]);
            } elseif ($hasGroupAccessToNative) {
                // Group-level external access: show every company in this group regardless of owner
                $whereParts[] = "(LOWER(TRIM(c.group_id)) = LOWER(TRIM(?)))";
                $params[] = trim($native_group);
            } elseif ($native_group !== null && trim($native_group) !== '') {
                $whereParts[] = "(c.owner_id = ? AND LOWER(c.group_id) = LOWER(?))";
                $params = array_merge($params, [$ownerId, trim($native_group)]);
            } else {
                $whereParts[] = "(
                    (c.owner_id = ? AND (c.group_id IS NULL OR c.group_id = ''))
                    OR 
                    (c.owner_id != ? AND co.account_id = ? AND co.percentage > 0 AND (
                        co.partner_group_id = '' 
                        OR (co.partner_group_id IS NULL AND (c.group_id IS NULL OR c.group_id = ''))
                    ))
                )";
                $params = array_merge($params, [$ownerId, $ownerId, $ownerId]);
            }

            $whereSQL = implode(" OR ", $whereParts);
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.company_id, c.expiration_date,
                       COALESCE(co.partner_group_id, c.group_id) as group_id,
                       IF(c.owner_id = ?, 0, 1) as is_external
                FROM company c
                LEFT JOIN company_ownership co ON c.id = co.company_id AND co.owner_type = 'owner' AND co.account_id = ?
                WHERE ($whereSQL) AND c.company_id != ''
                ORDER BY is_external ASC, c.company_id ASC
            ");
            array_unshift($params, $ownerId, $ownerId);  // prepend for IF(c.owner_id) and LEFT JOIN condition
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}
