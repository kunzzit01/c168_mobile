<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/password_hashing.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'owner') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_SESSION['secondary_password_verified']) && $_SESSION['secondary_password_verified'] === true) {
    echo json_encode(['success' => true, 'message' => 'Already verified'], JSON_UNESCAPED_UNICODE);
    exit;
}

$secondaryPassword = trim((string)($_POST['secondary_password'] ?? ''));
if ($secondaryPassword === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter secondary password'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!preg_match('/^\d{6}$/', $secondaryPassword)) {
    echo json_encode(['success' => false, 'message' => 'Secondary password must be exactly 6 digits'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $ownerId = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT secondary_password FROM owner WHERE id = ?");
    $stmt->execute([$ownerId]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($owner && !empty($owner['secondary_password'])) {
        $storedSecondary = (string) $owner['secondary_password'];
        if (!verify_secure_password($secondaryPassword, $storedSecondary)) {
            echo json_encode(['success' => false, 'message' => 'Secondary password is incorrect'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rehashed = maybe_rehash_password($secondaryPassword, $storedSecondary);
        if ($rehashed !== null) {
            $pdo->prepare('UPDATE owner SET secondary_password = ? WHERE id = ?')->execute([$rehashed, $ownerId]);
        }
    }

    $_SESSION['secondary_password_verified'] = true;
    echo json_encode(['success' => true, 'message' => 'Verified'], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('Owner secondary password verify error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.'], JSON_UNESCAPED_UNICODE);
}
