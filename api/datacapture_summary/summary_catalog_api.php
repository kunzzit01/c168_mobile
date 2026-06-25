<?php
/**
 * Summary form catalog API — currencies + accounts for formula editing.
 * Path: api/datacapture_summary/summary_catalog_api.php
 *
 * Default GET (optional ?action=load)
 */
require_once __DIR__ . '/summary_bootstrap.php';
require_once __DIR__ . '/summary_catalog_handler.php';

dcSummaryApiStartSession();
require_once __DIR__ . '/summary_api_lib.php';

dcSummaryApiInitScope();
dcSummaryApiHandleLoadCatalog();
