<?php
require_once '../../includes/session_check.php';
require_once '../../includes/config.php';
require_once '../includes/money_decimal.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$company_id = $_POST['company_id'] ?? null;
$account_id = $_POST['account_id'] ?? null;
$percentage = $_POST['percentage'] ?? null;

if (!$company_id || !$account_id || $percentage === null) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit();
}

$percentage = money_normalize($percentage, 2);

if (money_cmp($percentage, '0', 2) <= 0 || money_cmp($percentage, '100', 2) > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Percentage must be between 0 and 100']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Sum existing percentages for this company, EXCEPT the account we are updating (if it already exists)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(percentage), 0) as total 
        FROM company_ownership 
        WHERE company_id = ? AND account_id != ?
    ");
    $stmt->execute([$company_id, $account_id]);
    $currentTotal = money_normalize($stmt->fetchColumn(), 2);

    $newTotal = money_add($currentTotal, $percentage, 2);
    if (money_cmp($newTotal, '100', 2) > 0) {
        $allowed = money_sub('100', $currentTotal, 2);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Cannot assign percentage. Total would exceed 100%. Maximum allowed for this account is ' . money_out($allowed, 2) . '%.'
        ]);
        $pdo->rollBack();
        exit();
    }

    // Insert or update
    $stmt = $pdo->prepare("
        INSERT INTO company_ownership (company_id, account_id, percentage)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE percentage = ?
    ");
    $stmt->execute([$company_id, $account_id, $percentage, $percentage]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Ownership saved successfully'
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
