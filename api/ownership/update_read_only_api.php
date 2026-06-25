<?php
require_once '../../includes/session_check.php';
require_once '../../includes/config.php';
require_once '../includes/ownership_history.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$read_only   = isset($data['read_only'])    ? (int)$data['read_only']    : null;
$user_id     = isset($data['user_id'])      ? (int)$data['user_id']      : null;
$ownership_id = isset($data['ownership_id']) ? (int)$data['ownership_id'] : null;

if ($read_only === null || (!$user_id && !$ownership_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    exit();
}

try {
    if ($user_id) {
        // Partnership / Audit user (user table) — verify role first
        $check = $pdo->prepare("SELECT id FROM user WHERE id = ? AND LOWER(role) IN ('partnership', 'audit')");
        $check->execute([$user_id]);
        if (!$check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Not a Partnership or Audit user']);
            exit();
        }
        $stmt = $pdo->prepare("UPDATE user SET read_only = ? WHERE id = ?");
        $stmt->execute([$read_only, $user_id]);
    } else {
        // External Partner (company_ownership row)
        $stmtLookup = $pdo->prepare('SELECT company_id FROM company_ownership WHERE id = ?');
        $stmtLookup->execute([$ownership_id]);
        $companyId = (int) $stmtLookup->fetchColumn();

        $stmt = $pdo->prepare("UPDATE company_ownership SET read_only = ? WHERE id = ?");
        $stmt->execute([$read_only, $ownership_id]);

        if ($companyId > 0) {
            $savedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            ownership_history_snapshot_company_from_live($pdo, $companyId, $savedBy);
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Read-only status updated']);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $e->getMessage()]);
}
?>
