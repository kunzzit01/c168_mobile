<?php
/**
 * Summary server state API — get/save row order and formula edits.
 * Path: api/datacapture_summary/summary_state_api.php
 *
 * Actions: get_summary_state (GET), save_summary_state (POST)
 */
require_once __DIR__ . '/summary_bootstrap.php';
require_once __DIR__ . '/summary_state_handler.php';

dcSummaryApiStartSession();
require_once __DIR__ . '/summary_api_lib.php';

dcSummaryApiInitScope();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (!in_array($action, dcSummaryStateApiActions(), true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action', 'data' => null]);
    exit;
}

dcDispatchSummaryStateApi($action);
