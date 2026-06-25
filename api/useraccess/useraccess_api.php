<?php
/**
 * 用户权限（User Access）API - 复制权限、获取权限等
 * 路径: api/useraccess/useraccess_api.php
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/permissions.php';

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行

if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    sendResponse(false, 'Unauthorized access', null);
}

$current_company_id = $_SESSION['company_id'];

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$input = json_decode(file_get_contents('php://input'), true);

function sendResponse($success, $message = '', $data = null) {
    echo json_encode([
        'success' => (bool) $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getCurrentUser() {
    return $_SESSION['login_id'] ?? 'admin001';
}

function validatePermissions($permissions) {
    if (!is_array($permissions)) return false;
    $valid = ['home', 'admin', 'account', 'ownership', 'process', 'datacapture', 'payment', 'report', 'maintenance'];
    foreach ($permissions as $p) {
        if (!in_array($p, $valid)) return false;
    }
    return true;
}

function logPermissionChange($pdo, $templateUserId, $affectedUserIds, $permissions) {
    try {
        $logData = [
            'template_user_id' => $templateUserId,
            'source_type' => $templateUserId ? 'template' : 'manual',
            'affected_user_ids' => $affectedUserIds,
            'permissions_copied' => $permissions,
            'performed_by' => getCurrentUser(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        error_log("Permission Copy Log: " . json_encode($logData));
        return true;
    } catch (Exception $e) {
        error_log("Failed to log permission change: " . $e->getMessage());
        return false;
    }
}

// ---------- 数据库层 ----------
function dbValidateAccountIdsInCompany($pdo, $accountIds, $company_id) {
    if (empty($accountIds)) return true;
    $placeholders = str_repeat('?,', count($accountIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM account WHERE id IN ($placeholders) AND company_id = ?");
    $stmt->execute(array_merge($accountIds, [$company_id]));
    return $stmt->fetchColumn() === count($accountIds);
}

function dbValidateProcessIdsInCompany($pdo, $processIds, $company_id) {
    if (empty($processIds)) return true;
    $placeholders = str_repeat('?,', count($processIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM process WHERE id IN ($placeholders) AND company_id = ?");
    $stmt->execute(array_merge($processIds, [$company_id]));
    return $stmt->fetchColumn() === count($processIds);
}

function dbGetTemplateUser($pdo, $userId, $company_id) {
    $stmt = $pdo->prepare("SELECT name, login_id FROM user WHERE id = ? AND company_id = ?");
    $stmt->execute([$userId, $company_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function dbCountAffectedUsersInCompany($pdo, $userIds, $company_id) {
    $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user WHERE id IN ($placeholders) AND company_id = ?");
    $stmt->execute(array_merge($userIds, [$company_id]));
    return (int) $stmt->fetchColumn();
}

function dbUpdateUserPermissionsBatch($pdo, $userIds, $permissionsJson, $accountPermissionsJson, $processPermissionsJson, $company_id) {
    $roleStmt = $pdo->prepare("SELECT id, role FROM user WHERE id = ? AND company_id = ?");
    $updateStmt = $pdo->prepare("UPDATE user SET permissions = ?, account_permissions = ?, process_permissions = ? WHERE id = ? AND company_id = ?");
    $successCount = 0;
    foreach ($userIds as $userId) {
        $roleStmt->execute([$userId, $company_id]);
        $userRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            continue;
        }
        $perms = json_decode($permissionsJson, true);
        $sanitized = sanitize_sidebar_permissions_for_role($userRow['role'] ?? '', is_array($perms) ? $perms : []);
        $sanitizedJson = json_encode($sanitized);
        if ($updateStmt->execute([$sanitizedJson, $accountPermissionsJson, $processPermissionsJson, $userId, $company_id])) {
            $successCount++;
        }
    }
    return $successCount;
}

function dbGetUserNameLogin($pdo, $userId, $company_id) {
    $stmt = $pdo->prepare("SELECT name, login_id FROM user WHERE id = ? AND company_id = ?");
    $stmt->execute([$userId, $company_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function dbGetUserPermissionsById($pdo, $userId, $company_id) {
    $stmt = $pdo->prepare("SELECT id, login_id, name, email, role, permissions, account_permissions, process_permissions FROM user WHERE id = ? AND company_id = ?");
    $stmt->execute([$userId, $company_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function dbGetAllUsersByCompany($pdo, $company_id) {
    $stmt = $pdo->prepare("SELECT id, login_id, name, email, role, permissions, account_permissions, process_permissions FROM user WHERE company_id = ? ORDER BY name ASC");
    $stmt->execute([$company_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    if (!$input || !isset($input['action'])) {
        sendResponse(false, 'Invalid request', null);
    }

    $action = $input['action'];

    switch ($action) {
        case 'copy_permissions':
            if (!isset($input['affected_user_ids']) || !isset($input['permissions']) || !isset($input['source_type'])) {
                sendResponse(false, 'Missing required parameters', null);
            }
            if ($input['source_type'] === 'template' && !isset($input['template_user_id'])) {
                sendResponse(false, 'Template user ID is required for template mode', null);
            }

            $templateUserId = $input['template_user_id'] ?? null;
            $affectedUserIds = $input['affected_user_ids'];
            $permissions = $input['permissions'];

            if ($input['source_type'] === 'template' && (!is_numeric($templateUserId) || $templateUserId <= 0)) {
                sendResponse(false, 'Invalid template user ID', null);
            }
            if (!is_array($affectedUserIds) || empty($affectedUserIds)) {
                sendResponse(false, 'No affected users specified', null);
            }
            foreach ($affectedUserIds as $uid) {
                if (!is_numeric($uid) || $uid <= 0) {
                    sendResponse(false, 'Invalid affected user ID: ' . $uid, null);
                }
            }
            if (!validatePermissions($permissions)) {
                sendResponse(false, 'Invalid permissions data', null);
            }

            $accountPermissions = $input['account_permissions'] ?? [];
            if (!is_array($accountPermissions)) {
                sendResponse(false, 'Invalid account permissions data', null);
            }
            if (!empty($accountPermissions)) {
                foreach ($accountPermissions as &$perm) {
                    if (isset($perm['id'])) $perm['id'] = (int)$perm['id'];
                }
                unset($perm);
                $accountIds = array_unique(array_column($accountPermissions, 'id'));
                $accountIds = array_values(array_filter($accountIds));
                if (!empty($accountIds) && !dbValidateAccountIdsInCompany($pdo, $accountIds, $current_company_id)) {
                    sendResponse(false, 'One or more selected accounts not found or access denied', null);
                }
            }

            $processPermissions = $input['process_permissions'] ?? [];
            if (!is_array($processPermissions)) {
                sendResponse(false, 'Invalid process permissions data', null);
            }
            if (!empty($processPermissions)) {
                foreach ($processPermissions as &$perm) {
                    if (isset($perm['id'])) $perm['id'] = (int)$perm['id'];
                }
                unset($perm);
                $processIds = array_values(array_unique(array_column($processPermissions, 'id')));
                if (!empty($processIds) && !dbValidateProcessIdsInCompany($pdo, $processIds, $current_company_id)) {
                    sendResponse(false, 'One or more selected processes not found or access denied', null);
                }
            }

            $templateUser = null;
            if ($input['source_type'] === 'template') {
                $templateUser = dbGetTemplateUser($pdo, $templateUserId, $current_company_id);
                if (!$templateUser) {
                    sendResponse(false, 'Template user not found or access denied', null);
                }
            }

            if (dbCountAffectedUsersInCompany($pdo, $affectedUserIds, $current_company_id) !== count($affectedUserIds)) {
                sendResponse(false, 'One or more affected users not found or access denied', null);
            }

            $pdo->beginTransaction();
            try {
                $permissionsJson = json_encode($permissions);
                $accountPermissionsJson = json_encode($accountPermissions);
                $processPermissionsJson = json_encode($processPermissions);
                $successCount = dbUpdateUserPermissionsBatch($pdo, $affectedUserIds, $permissionsJson, $accountPermissionsJson, $processPermissionsJson, $current_company_id);
                $failedUsers = [];
                if ($successCount < count($affectedUserIds)) {
                    foreach ($affectedUserIds as $uid) {
                        $info = dbGetUserNameLogin($pdo, $uid, $current_company_id);
                        $failedUsers[] = $info ? $info['name'] . ' (' . $info['login_id'] . ')' : "User ID: $uid";
                    }
                }
                logPermissionChange($pdo, $templateUserId, $affectedUserIds, $permissions);
                $pdo->commit();

                if ($successCount === count($affectedUserIds)) {
                    $msg = $input['source_type'] === 'template'
                        ? "Successfully updated permissions for $successCount user(s) based on template: {$templateUser['name']} ({$templateUser['login_id']})"
                        : "Successfully updated permissions for $successCount user(s) with manually selected permissions";
                    sendResponse(true, $msg, [
                        'success_count' => $successCount,
                        'total_count' => count($affectedUserIds),
                        'template_user' => $templateUser
                    ]);
                }
                $failCount = count($affectedUserIds) - $successCount;
                $msg = "Partially completed: $successCount succeeded, $failCount failed.";
                if (!empty($failedUsers)) $msg .= " Failed users: " . implode(', ', $failedUsers);
                sendResponse(false, $msg, [
                    'success_count' => $successCount,
                    'failed_count' => $failCount,
                    'failed_users' => $failedUsers
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'get_user_permissions':
            if (!isset($input['user_id']) || !is_numeric($input['user_id']) || $input['user_id'] <= 0) {
                sendResponse(false, $input['user_id'] ? 'Invalid user ID' : 'User ID is required', null);
            }
            $user = dbGetUserPermissionsById($pdo, $input['user_id'], $current_company_id);
            if (!$user) {
                sendResponse(false, 'User not found or access denied', null);
            }
            $user['permissions'] = $user['permissions'] ? (json_decode($user['permissions'], true) ?? []) : [];
            sendResponse(true, 'User permissions retrieved successfully', $user);
            break;

        case 'get_all_users':
            $users = dbGetAllUsersByCompany($pdo, $current_company_id);
            foreach ($users as &$u) {
                $u['permissions'] = $u['permissions'] ? (json_decode($u['permissions'], true) ?? []) : [];
            }
            sendResponse(true, 'Users retrieved successfully', $users);
            break;

        case 'validate_permissions':
            if (!isset($input['permissions'])) {
                sendResponse(false, 'Permissions data is required', null);
            }
            $perms = $input['permissions'];
            if (validatePermissions($perms)) {
                sendResponse(true, 'Permissions are valid', ['permissions' => $perms, 'count' => count($perms)]);
            }
            sendResponse(false, 'Invalid permissions data', null);
            break;

        default:
            sendResponse(false, 'Invalid action', null);
    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Database error in useraccess_api: " . $e->getMessage());
    sendResponse(false, 'Database error occurred. Please try again later.', null);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("General error in useraccess_api: " . $e->getMessage());
    sendResponse(false, 'An unexpected error occurred. Please try again later.', null);
}

if (function_exists('ob_clean')) {
    @ob_clean();
}