<?php
/**
 * Data Capture submissions + process picker API.
 * Path: api/datacapture/submissions_api.php
 *
 * Actions:
 * - get_submissions_by_capture_date
 * - get_processes_by_day
 * - save_submission
 * - get_group_process_id
 */
require_once __DIR__ . '/submissions_bootstrap.php';
require_once __DIR__ . '/submissions_handlers.php';

$ctx = dcSubmissionsApiInit();
dcDispatchSubmissionsApi($ctx['action'], $ctx['user_id']);
