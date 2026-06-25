<?php
/**
 * Legacy Summary API router — forwards to split endpoints (Strangler).
 * Path: api/datacapture_summary/summary_api.php
 */
require_once __DIR__ . '/summary_bootstrap.php';

dcSummaryApiStartSession();
require_once __DIR__ . '/summary_api_lib.php';

dcSummaryApiInitScope();

$action = isset($_GET['action']) ? $_GET['action'] : 'load';

$templateActions = ['save_template', 'delete_template', 'templates'];
if (in_array($action, $templateActions, true)) {
    require __DIR__ . '/summary_templates_api.php';
    exit;
}

$stateActions = ['get_summary_state', 'save_summary_state'];
if (in_array($action, $stateActions, true)) {
    require __DIR__ . '/summary_state_api.php';
    exit;
}

if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/summary_submit_api.php';
    exit;
}

require __DIR__ . '/summary_catalog_api.php';
