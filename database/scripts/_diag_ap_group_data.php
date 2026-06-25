<?php
require dirname(__DIR__, 2) . '/includes/config.php';
require dirname(__DIR__, 2) . '/api/transactions/transaction_scope.php';
define('DASHBOARD_API_SKIP_MAIN', true);
require dirname(__DIR__, 2) . '/api/transactions/dashboard_api.php';

$g = 'AP';
$pk = gc_resolve_group_pk_by_code($pdo, $g);
echo "groups.id for AP: {$pk}\n";

echo "\n--- currency (group scope) ---\n";
try {
    $st = $pdo->prepare("SELECT id, code, company_id, scope_type, scope_id FROM currency WHERE scope_type = 'group' AND scope_id = ?");
    $st->execute([$pk]);
    print_r($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
}

echo "\n--- currency (company_id = group pk as legacy) ---\n";
$st = $pdo->prepare("SELECT id, code, company_id, scope_type, scope_id FROM currency WHERE company_id = ?");
$st->execute([$pk]);
print_r($st->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- account_company group scope ---\n";
try {
    $st = $pdo->prepare("SELECT ac.account_id, a.account_id AS code, a.role FROM account_company ac JOIN account a ON a.id = ac.account_id WHERE ac.scope_type = 'group' AND ac.scope_id = ? LIMIT 20");
    $st->execute([$pk]);
    print_r($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
}

echo "\n--- dashboardCollectGroupOnlyAccountIds ---\n";
$ids = dashboardCollectGroupOnlyAccountIds($pdo, $g);
echo 'count=' . count($ids) . ' ids=' . json_encode(array_slice($ids, 0, 20)) . "\n";

echo "\n--- dashboardResolveGroupScopeCurrencyMap ---\n";
$map = dashboardResolveGroupScopeCurrencyMap($pdo, $g);
print_r($map);

echo "\n--- tx_resolve_transaction_list_scope (group only params) ---\n";
$scope = tx_resolve_transaction_list_scope($pdo, ['group_id' => 'AP', 'view_group' => 'AP']);
print_r($scope);
