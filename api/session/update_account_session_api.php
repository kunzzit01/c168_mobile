<?php

/**

 * Member Win/Loss：仅切换「查看」账号（写入 member_winloss_view_account_id），

 * 不改变登录身份 $_SESSION['user_id']（避免全站看起来像突然换了登录账号）。

 * 路径: api/session/update_account_session_api.php

 */



// 此 API 需要写入 session（切换账户），不能让 session_check.php 提前关闭锁

define('SESSION_KEEP_OPEN', true);



require_once __DIR__ . '/../../includes/session_check.php';

require_once __DIR__ . '/../includes/member_linked_closure.php';



header('Content-Type: application/json');



function jsonResponse($success, $message, $data = null, $httpCode = null) {

    if ($httpCode !== null) {

        http_response_code($httpCode);

    }

    echo json_encode([

        'success' => (bool) $success,

        'message' => $message,

        'data' => $data

    ], JSON_UNESCAPED_UNICODE);

}



function hasAccountLinkTable(PDO $pdo) {

    try {

        $stmt = $pdo->query("SHOW TABLES LIKE 'account_link'");

        return $stmt->rowCount() > 0;

    } catch (PDOException $e) {

        return false;

    }

}



function getAccountByCompany(PDO $pdo, $account_id, $company_id) {

    $stmt = $pdo->prepare("

        SELECT a.id, a.account_id, a.name, a.status

        FROM account a

        INNER JOIN account_company ac ON a.id = ac.account_id

        WHERE a.id = ? AND ac.company_id = ? AND a.status = 'active'

    ");

    $stmt->execute([$account_id, $company_id]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}



function getLinkedAccountIds(PDO $pdo, $start_account_id, $company_id) {

    $linked = [];

    $visited = [];

    $queue = [$start_account_id];

    while (!empty($queue)) {

        $current_id = array_shift($queue);

        if (isset($visited[$current_id])) continue;

        $visited[$current_id] = true;

        $linked[] = $current_id;

        $stmt = $pdo->prepare("

            SELECT account_id_2 AS linked_id FROM account_link WHERE account_id_1 = ? AND company_id = ?

            UNION

            SELECT account_id_1 AS linked_id FROM account_link WHERE account_id_2 = ? AND company_id = ?

        ");

        $stmt->execute([$current_id, $company_id, $current_id, $company_id]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $linked_id) {

            if (!isset($visited[$linked_id])) $queue[] = $linked_id;

        }

    }

    return $linked;

}



try {

    if (!isset($_SESSION['user_id'])) {

        jsonResponse(false, '用户未登录', null, 401);

        exit;

    }

    $current_user_type = strtolower($_SESSION['user_type'] ?? '');

    if ($current_user_type !== 'member') {

        jsonResponse(false, '只有 member 用户可以使用此功能', null, 403);

        exit;

    }



    $requested_account_id = null;

    if (isset($_GET['account_id']) && $_GET['account_id'] !== '') {

        $requested_account_id = (int) $_GET['account_id'];

    } elseif (isset($_POST['account_id']) && $_POST['account_id'] !== '') {

        $requested_account_id = (int) $_POST['account_id'];

    }

    if (!$requested_account_id) {

        jsonResponse(false, '缺少 account_id 参数', null, 400);

        exit;

    }



    $login_account_id = member_session_canonical_account_id();

    if ($login_account_id <= 0) {

        jsonResponse(false, '无法识别登录账号', null, 403);

        exit;

    }



    if (empty($_SESSION['member_login_account_id'])) {

        $_SESSION['member_login_account_id'] = $login_account_id;

    }



    $current_company_id = $_SESSION['company_id'] ?? null;

    if (!$current_company_id) {

        jsonResponse(false, '缺少公司信息', null, 400);

        exit;

    }



    if (!hasAccountLinkTable($pdo)) {

        jsonResponse(false, '账户关联功能未启用', null, 500);

        exit;

    }



    $login_row = getAccountByCompany($pdo, $login_account_id, $current_company_id);

    if (!$login_row) {

        jsonResponse(false, '登录账号不存在、不属于当前公司或已停用', null, 403);

        exit;

    }



    // 恒定登录身份（与会话一致性）

    $_SESSION['user_id'] = $login_account_id;

    $_SESSION['login_id'] = $login_row['account_id'];

    $_SESSION['name'] = $login_row['name'];

    $_SESSION['account_id'] = $login_row['account_id'];



    $target_account = getAccountByCompany($pdo, $requested_account_id, $current_company_id);

    if (!$target_account) {

        jsonResponse(false, '账户不存在、不属于当前公司或已停用', null, 403);

        exit;

    }



    $view_now_id = member_session_winloss_view_account_id();

    if ($requested_account_id === $view_now_id) {

        session_write_close();

        jsonResponse(true, '已经是当前账户', [

            'account_id'   => $view_now_id,

            'account_code' => ($view_now_id === $login_account_id)

                ? $login_row['account_id']

                : $target_account['account_id'],

            'account_name' => ($view_now_id === $login_account_id)

                ? $login_row['name']

                : $target_account['name'],

        ]);

        exit;

    }



    $linked_account_ids = getLinkedAccountIds($pdo, $login_account_id, $current_company_id);

    if (!in_array($requested_account_id, $linked_account_ids)) {

        jsonResponse(false, '该账户与当前账户未关联，无法切换', null, 403);

        exit;

    }



    if ($requested_account_id === $login_account_id) {

        unset($_SESSION['member_winloss_view_account_id']);

    } else {

        $_SESSION['member_winloss_view_account_id'] = $requested_account_id;

    }



    $view_after_id = member_session_winloss_view_account_id();

    $view_row = ($view_after_id === $login_account_id)

        ? $login_row

        : getAccountByCompany($pdo, $view_after_id, $current_company_id);

    $view_code = $view_row ? (string) ($view_row['account_id'] ?? '') : '';

    $view_name = $view_row ? (string) ($view_row['name'] ?? '') : '';



    session_write_close();



    jsonResponse(true, '账户已切换', [

        'account_id'   => $view_after_id,

        'account_code' => $view_code,

        'account_name' => $view_name,

    ]);

} catch (Exception $e) {

    session_write_close();

    jsonResponse(false, $e->getMessage(), null, 500);

}

