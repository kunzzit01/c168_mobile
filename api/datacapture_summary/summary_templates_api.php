<?php
/**
 * Summary template API — save / delete / fetch maintenance formulas.
 * Path: api/datacapture_summary/summary_templates_api.php
 *
 * Actions: save_template (POST), delete_template (POST), templates (GET/POST)
 */
require_once __DIR__ . '/summary_bootstrap.php';
require_once __DIR__ . '/summary_templates_handler.php';

dcSummaryApiStartSession();
require_once __DIR__ . '/summary_api_lib.php';

dcSummaryApiInitScope();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (!in_array($action, dcSummaryTemplatesApiActions(), true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action', 'data' => null]);
    exit;
}

dcDispatchSummaryTemplatesApi($action);
