<?php
/**
 * Data Capture form catalog API — currencies, processes, descriptions for capture form.
 * Path: api/datacapture/catalog_api.php
 *
 * Actions: load (GET, default), add_description (POST), delete_description (POST)
 */
require_once __DIR__ . '/submissions_bootstrap.php';
require_once __DIR__ . '/catalog_handlers.php';

dcSubmissionsApiInit();

$action = $_GET['action'] ?? $_POST['action'] ?? 'load';
if (!in_array($action, dcCatalogApiActions(), true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action', 'data' => null]);
    exit;
}

try {
    dcDispatchCatalogApi($action);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null);
}
