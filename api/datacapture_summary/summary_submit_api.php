<?php
/**
 * Summary submit API — POST JSON batches into data_captures.
 * Path: api/datacapture_summary/summary_submit_api.php
 */
require_once __DIR__ . '/summary_bootstrap.php';
require_once __DIR__ . '/summary_submit_handler.php';

dcSummaryApiStartSession();
require_once __DIR__ . '/summary_api_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed', 'data' => null]);
    exit;
}

dcSummaryApiInitScope();
dcSummaryApiHandleSubmit();
