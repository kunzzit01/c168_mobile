<?php
/**
 * User 货币显示顺序 API（按账号 + 公司维度永久化）
 * GET: ?company_id= 可选；未传时使用 session 中的 company_id（Member 等场景）
 * POST: JSON { "company_id": int, "order": ["USD","MYR",...] }；company_id 可省略时用 session company_id
 *
 * 存储格式（currency_order 列）：
 * - 旧版：JSON 数组 ["MYR","USD"] — 对所有公司返回同一顺序，直至某公司通过 POST 写入后迁移为对象
 * - 新版：JSON 对象 { "12": ["USD","MYR"], "34": ["MYR"] } — 键为公司 ID 字符串，仅该公司使用该顺序
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../api_response.php';

/**
 * @param mixed $decoded
 */
function currency_order_is_flat_list($decoded) {
    if (!is_array($decoded) || $decoded === []) {
        return false;
    }
    $i = 0;
    foreach ($decoded as $k => $_) {
        if ($k !== $i) {
            return false;
        }
        $i++;
    }
    return true;
}

/**
 * @param array<int|string, mixed> $order
 * @return list<string>
 */
function normalize_currency_order_codes(array $order) {
    $out = [];
    foreach ($order as $c) {
        $u = strtoupper(trim((string) $c));
        if ($u === '' || $u === 'ALL') {
            continue;
        }
        if (!in_array($u, $out, true)) {
            $out[] = $u;
        }
    }
    return $out;
}

/**
 * 从 DB 行解析出「按公司」的 map；若为旧版平铺数组则返回空 map（由 GET 单独返回 legacy）
 *
 * @return array<string, list<string>>
 */
function currency_order_decode_to_company_map(?string $json, ?array &$legacyFlat) {
    $legacyFlat = null;
    if ($json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    if (currency_order_is_flat_list($decoded)) {
        $norm = normalize_currency_order_codes($decoded);
        $legacyFlat = $norm !== [] ? $norm : null;
        return [];
    }
    $map = [];
    foreach ($decoded as $k => $v) {
        if (!is_array($v)) {
            continue;
        }
        $kid = (int) $k;
        if ($kid <= 0) {
            continue;
        }
        $norm = normalize_currency_order_codes($v);
        if ($norm !== []) {
            $map[(string) $kid] = $norm;
        }
    }
    return $map;
}

/**
 * Resolve view_group for company access checks (GET query or group login).
 */
function currency_order_view_group(): ?string
{
    if (isset($_GET['view_group']) && trim((string) $_GET['view_group']) !== '') {
        return gc_normalize_view_group((string) $_GET['view_group']);
    }
    if (isset($_GET['group_id']) && trim((string) $_GET['group_id']) !== '') {
        return gc_normalize_view_group((string) $_GET['group_id']);
    }
    if (gc_is_group_login()) {
        return gc_session_login_identifier();
    }

    return null;
}

function assert_currency_order_company_access(PDO $pdo, int $companyId): void
{
    if ($companyId <= 0) {
        return;
    }
    gc_assert_api_company_access($pdo, $companyId, currency_order_view_group());
}

try {
    if (!isset($_SESSION['user_id'])) {
        api_error('未登录', 401);
        exit;
    }

    $userType = strtolower($_SESSION['user_type'] ?? '');
    $baseId = (int) $_SESSION['user_id'];

    $accountId = ($userType === 'member') ? $baseId : -$baseId;

    $method = $_SERVER['REQUEST_METHOD'] ?? '';

    if ($method === 'GET') {
        $companyId = isset($_GET['company_id']) ? (int) $_GET['company_id'] : (int) ($_SESSION['company_id'] ?? 0);
        try {
            assert_currency_order_company_access($pdo, $companyId);
        } catch (RuntimeException $e) {
            api_error('无权访问该公司', 403);
            exit;
        }

        $stmt = $pdo->prepare('SELECT currency_order FROM account_currency_display_order WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $order = null;
        if ($row && !empty($row['currency_order'])) {
            $legacyFlat = null;
            $map = currency_order_decode_to_company_map((string) $row['currency_order'], $legacyFlat);
            if ($legacyFlat !== null) {
                $order = $legacyFlat;
            } elseif ($companyId > 0 && isset($map[(string) $companyId])) {
                $order = $map[(string) $companyId];
            }
        }
        api_success(['order' => $order, 'company_id' => $companyId > 0 ? $companyId : null]);
        exit;
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = [];
        }

        $companyId = isset($body['company_id']) ? (int) $body['company_id'] : 0;
        if ($companyId <= 0) {
            $companyId = (int) ($_SESSION['company_id'] ?? 0);
        }
        if ($companyId <= 0) {
            api_error('缺少 company_id（且 session 中无公司）', 400);
            exit;
        }
        try {
            assert_currency_order_company_access($pdo, $companyId);
        } catch (RuntimeException $e) {
            api_error('无权访问该公司', 403);
            exit;
        }

        $order = isset($body['order']) && is_array($body['order']) ? $body['order'] : [];
        $order = normalize_currency_order_codes($order);

        $stmt = $pdo->prepare('SELECT currency_order FROM account_currency_display_order WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $legacyFlat = null;
        $existingJson = $row && !empty($row['currency_order']) ? (string) $row['currency_order'] : '';
        $map = currency_order_decode_to_company_map($existingJson, $legacyFlat);

        // 若整表仍是旧版平铺数组，首次按公司写入时转为 map，不再保留「一条全局顺序」
        $map[(string) $companyId] = $order;

        $json = json_encode($map, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare('
            INSERT INTO account_currency_display_order (account_id, currency_order)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE currency_order = VALUES(currency_order), updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$accountId, $json]);
        api_success(['order' => $order, 'company_id' => $companyId], '已保存');
        exit;
    }

    api_error('方法不允许', 405);
} catch (Exception $e) {
    error_log('user_currency_order_api: ' . $e->getMessage());
    api_error($e->getMessage(), 500);
}
