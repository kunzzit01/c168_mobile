<?php
/**
 * Automated Monthly Accounting Script
 * Reference: User Request for automation on 4th of every month.
 */

// Load configuration
require_once dirname(__DIR__) . '/includes/config.php';

// Logging setup
function logMessage($msg)
{
    echo $msg . "\n"; // For console output
    $file = __DIR__ . '/logs/auto_accounting_log.txt';
    $time = date('Y-m-d H:i:s');
    file_put_contents($file, "[$time] $msg" . PHP_EOL, FILE_APPEND);
}

// Helper: Check if table has column
function hasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    if (isset($cache["$table.$column"]))
        return $cache["$table.$column"];

    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    $exists = $stmt->rowCount() > 0;
    $cache["$table.$column"] = $exists;
    return $exists;
}

// Helper: Insert transaction
function insertTransaction(PDO $pdo, array $data)
{
    // Filter data to only include columns that exist in the table
    $validData = [];
    foreach ($data as $key => $value) {
        if (hasColumn($pdo, 'transactions', $key)) {
            $validData[$key] = $value;
        }
    }

    if (empty($validData))
        return false;

    $columns = array_keys($validData);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO transactions (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($validData));
    return $pdo->lastInsertId();
}

logMessage("Starting automated monthly accounting process...");

try {
    // Fetch all active bank processes
    // We get company_id and profit/cost/price info.
    // We also join with 'company' table to get owner_id for created_by_owner field if needed.
    $sql = "SELECT 
                bp.id, 
                bp.name, 
                bp.bank, 
                bp.cost, 
                bp.price, 
                bp.profit, 
                bp.card_merchant_id, 
                bp.customer_id, 
                bp.profit_account_id, 
                bp.company_id, 
                c.owner_id
            FROM bank_process bp
            LEFT JOIN company c ON bp.company_id = c.id
            WHERE bp.status = 'active'";

    $stmt = $pdo->query($sql);
    $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    logMessage("Found " . count($processes) . " active bank processes.");

    $transactionDate = date('Y-m-d');
    $createdCount = 0;

    foreach ($processes as $p) {
        $processLabel = $p['name'] ?: ($p['bank'] . ' #' . $p['id']);
        $companyId = $p['company_id'];
        $ownerId = $p['owner_id'];

        // Base data for this process's transactions
        $baseTxn = [
            'company_id' => $companyId,
            'transaction_date' => $transactionDate,
            'transaction_type' => 'WIN',
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => null,     // System created
            'created_by_owner' => $ownerId,
            'approval_status' => 'APPROVED',
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by_owner' => $ownerId
        ];

        // 1. Supplier (Buy Price) -> Credit Supplier Account
        if (!empty($p['card_merchant_id']) && $p['cost'] > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = $p['card_merchant_id'];
            $txn['amount'] = $p['cost'];
            $txn['description'] = "Auto: Buy Price for $processLabel";

            if (insertTransaction($pdo, $txn)) {
                $createdCount++;
            }
        }

        // 2. Customer (Sell Price) -> Credit Customer Account
        if (!empty($p['customer_id']) && $p['price'] > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = $p['customer_id'];
            $txn['amount'] = $p['price'];
            $txn['description'] = "Auto: Sell Price for $processLabel";

            if (insertTransaction($pdo, $txn)) {
                $createdCount++;
            }
        }

        // 3. Company (Profit) -> Credit Profit Account
        if (!empty($p['profit_account_id']) && $p['profit'] > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = $p['profit_account_id'];
            $txn['amount'] = $p['profit'];
            $txn['description'] = "Auto: Profit for $processLabel";

            if (insertTransaction($pdo, $txn)) {
                $createdCount++;
            }
        }
    }

    logMessage("Completed. Generated $createdCount transactions.");

} catch (Exception $e) {
    logMessage("Error: " . $e->getMessage());
}
?>