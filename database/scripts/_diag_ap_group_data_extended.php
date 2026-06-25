<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/config.php';

$g = 'AP';
$st = $pdo->prepare('SELECT id FROM `groups` WHERE UPPER(TRIM(group_code)) = ?');
$st->execute([$g]);
$pk = (int) ($st->fetchColumn() ?: 0);
echo "groups.id for AP: {$pk}\n";

echo "\n--- company row AP/IG ---\n";
$st = $pdo->query("SELECT id, company_id FROM company WHERE UPPER(company_id) IN ('AP','IG')");
print_r($st->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- transactions scope group AP ---\n";
$st = $pdo->prepare("SELECT COUNT(*) AS c FROM transactions WHERE scope_type = 'group' AND scope_id = ?");
$st->execute([$pk]);
echo 'count=' . $st->fetchColumn() . "\n";

echo "\n--- currency any (all scopes) mentioning AP group pk ---\n";
$st = $pdo->prepare("SELECT id, code, company_id, scope_type, scope_id FROM currency WHERE scope_id = ? OR company_id = ? LIMIT 30");
$st->execute([$pk, $pk]);
print_r($st->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- account_company rows with scope_id = group pk (any scope_type) ---\n";
try {
    $st = $pdo->prepare('SELECT scope_type, COUNT(*) AS c FROM account_company WHERE scope_id = ? GROUP BY scope_type');
    $st->execute([$pk]);
    print_r($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
}

foreach (['c168site_src', 'easycount'] as $dbName) {
    echo "\n=== optional DB {$dbName} ===\n";
    try {
        $admin = new PDO(
            "mysql:host={$host};dbname={$dbName};charset=utf8mb4",
            $dbuser,
            $dbpass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $st = $admin->query("SELECT id, company_id FROM company WHERE UPPER(company_id) = 'AP'");
        print_r($st->fetchAll(PDO::FETCH_ASSOC));
        $apId = (int) ($st->fetchColumn() ?: 0);
        if ($apId <= 0) {
            $st = $admin->query("SELECT id FROM company WHERE UPPER(company_id) = 'AP'");
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $apId = (int) ($row['id'] ?? 0);
        }
        if ($apId > 0) {
            $st = $admin->prepare('SELECT COUNT(*) FROM currency WHERE company_id = ?');
            $st->execute([$apId]);
            echo "currency on company_id={$apId}: " . $st->fetchColumn() . "\n";
            $st = $admin->prepare('SELECT COUNT(*) FROM account_company WHERE company_id = ?');
            $st->execute([$apId]);
            echo "account_company on company_id={$apId}: " . $st->fetchColumn() . "\n";
        }
        $st = $admin->prepare("SELECT COUNT(*) FROM currency WHERE scope_type = 'group' AND scope_id = 1");
        $st->execute();
        echo 'currency scope group id=1: ' . $st->fetchColumn() . "\n";
    } catch (Throwable $e) {
        echo $e->getMessage() . "\n";
    }
}
