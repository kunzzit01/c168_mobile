<?php
/**
 * Dashboard bootstrap: one HTTP request returns current KPI, previous period, and multi-currency earnings.
 * Reuses dashboard_api.php in-process via dashboard_api_capture() — same logic as
 * GET /api/transactions/dashboard_api.php (not a separate calculation path).
 */

session_start();
session_write_close();
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';

if (!$pdo instanceof PDO) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
        'data' => null,
        'error' => 'Database connection failed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => '用户未登录',
        'data' => null,
        'error' => '用户未登录',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

define('DASHBOARD_API_SKIP_MAIN', true);
require_once __DIR__ . '/dashboard_api.php';

/**
 * Mirror frontend previousMonthEquivalentRange() — same calendar days one month earlier.
 *
 * @return array{from:string,to:string}
 */
function dashboard_bootstrap_shift_ymd_by_months(string $ymd, int $monthDelta): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    if (!$dt) {
        return $ymd;
    }
    $day = (int) $dt->format('j');
    $anchor = $dt->modify('first day of this month')->modify(
        ($monthDelta >= 0 ? '+' : '') . $monthDelta . ' months'
    );
    $lastDay = (int) $anchor->modify('last day of this month')->format('j');
    return $anchor->setDate(
        (int) $anchor->format('Y'),
        (int) $anchor->format('m'),
        min($day, $lastDay)
    )->format('Y-m-d');
}

function dashboard_bootstrap_previous_period(string $fromYmd, string $toYmd): array
{
    return [
        'from' => dashboard_bootstrap_shift_ymd_by_months($fromYmd, -1),
        'to' => dashboard_bootstrap_shift_ymd_by_months($toYmd, -1),
    ];
}

/**
 * @return array<string, string>
 */
function dashboard_bootstrap_base_params(): array
{
    $params = [];
    $dateFrom = isset($_GET['date_from']) ? trim((string) $_GET['date_from']) : '';
    $dateTo = isset($_GET['date_to']) ? trim((string) $_GET['date_to']) : '';
    if ($dateFrom !== '') {
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $params['date_to'] = $dateTo;
    }

    $companyId = isset($_GET['company_id']) && $_GET['company_id'] !== ''
        ? (string) $_GET['company_id']
        : '';
    $viewGroup = isset($_GET['view_group']) ? trim((string) $_GET['view_group']) : '';

    $subsidiaryOnly = isset($_GET['subsidiary_accounts_only'])
        && (string) $_GET['subsidiary_accounts_only'] === '1';

    if ($companyId !== '') {
        $params['company_id'] = $companyId;
        if ($viewGroup !== '' && !$subsidiaryOnly) {
            $params['view_group'] = $viewGroup;
        }
        if ($subsidiaryOnly) {
            $params['subsidiary_accounts_only'] = '1';
        }
    } elseif ($viewGroup !== '') {
        $params['view_group'] = $viewGroup;
        $params['group_id'] = $viewGroup;
    }

    return $params;
}

/**
 * Strip heavy chart series from earnings-only payloads.
 *
 * @param array<string, mixed>|null $data
 * @return array<string, mixed>|null
 */
function dashboard_bootstrap_slim_payload(?array $data): ?array
{
    if (!is_array($data)) {
        return null;
    }
    unset($data['daily_data']);
    return $data;
}

/**
 * Memoize identical in-process captures (e.g. earnings scope re-fetch).
 *
 * @param array<string, string|null> $params
 * @return array{success:bool,message?:string,data?:mixed,error?:string}
 */
function dashboard_bootstrap_capture(array $params): array
{
    static $cache = [];
    ksort($params);
    $key = http_build_query($params);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $cache[$key] = dashboard_api_capture($params);

    return $cache[$key];
}

/** Attach kpi_only for fast KPI paths (skip daily GROUP BY on server). */
function dashboard_bootstrap_capture_scoped(array $params, string $bootstrapScope): array
{
    if (in_array($bootstrapScope, ['kpi', 'previous', 'earnings'], true)) {
        $params['kpi_only'] = '1';
    }
    if ($bootstrapScope === 'earnings') {
        $params['earnings_only'] = '1';
    }

    return dashboard_bootstrap_capture($params);
}

try {
    dashboard_api_begin_bootstrap_batch();
    $baseParams = dashboard_bootstrap_base_params();
    if ($baseParams === []) {
        throw new Exception('Missing dashboard scope');
    }

    $primaryCurrency = isset($_GET['currency']) ? strtoupper(trim((string) $_GET['currency'])) : '';
    $currencyListRaw = isset($_GET['currencies']) ? trim((string) $_GET['currencies']) : '';
    $currencyCodes = [];
    if ($currencyListRaw !== '') {
        foreach (explode(',', $currencyListRaw) as $part) {
            $code = strtoupper(trim($part));
            if ($code !== '' && !in_array($code, $currencyCodes, true)) {
                $currencyCodes[] = $code;
            }
        }
    }
    if ($primaryCurrency !== '' && !in_array($primaryCurrency, $currencyCodes, true)) {
        array_unshift($currencyCodes, $primaryCurrency);
    }
    if ($primaryCurrency === '' && $currencyCodes !== []) {
        $primaryCurrency = $currencyCodes[0];
    }

    $dateFrom = $baseParams['date_from'] ?? date('Y-m-01');
    $dateTo = $baseParams['date_to'] ?? date('Y-m-t');
    $prevRange = dashboard_bootstrap_previous_period($dateFrom, $dateTo);

    $bootstrapScope = isset($_GET['bootstrap_scope']) ? strtolower(trim((string) $_GET['bootstrap_scope'])) : 'full';
    if (!in_array($bootstrapScope, ['full', 'kpi', 'earnings', 'previous', 'chart'], true)) {
        $bootstrapScope = 'full';
    }
    $isPrefetch = isset($_GET['prefetch']) && (string) $_GET['prefetch'] === '1';

    $currentJson = null;
    $previousData = null;

    if ($bootstrapScope === 'full' || $bootstrapScope === 'kpi' || $bootstrapScope === 'chart') {
        $currentParams = $baseParams;
        if ($primaryCurrency !== '') {
            $currentParams['currency'] = $primaryCurrency;
        }
        $currentJson = $bootstrapScope === 'chart'
            ? dashboard_bootstrap_capture($currentParams)
            : dashboard_bootstrap_capture_scoped($currentParams, $bootstrapScope);
        if (empty($currentJson['success']) || !is_array($currentJson['data'])) {
            $failMsg = $currentJson['message'] ?? $currentJson['error'] ?? 'Failed to load dashboard';
            if ($isPrefetch) {
                echo json_encode([
                    'success' => false,
                    'message' => $failMsg,
                    'data' => null,
                    'error' => $failMsg,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            throw new Exception($failMsg);
        }

        // kpi: current only — previous period loads in a follow-up request so first paint is ~2× faster.
        if ($bootstrapScope === 'full') {
            $prevParams = $baseParams;
            $prevParams['date_from'] = $prevRange['from'];
            $prevParams['date_to'] = $prevRange['to'];
            if ($primaryCurrency !== '') {
                $prevParams['currency'] = $primaryCurrency;
            }
            $previousJson = dashboard_bootstrap_capture_scoped($prevParams, 'previous');
            $previousData = (!empty($previousJson['success']) && is_array($previousJson['data']))
                ? $previousJson['data']
                : null;
        }
    } elseif ($bootstrapScope === 'previous') {
        $prevParams = $baseParams;
        $prevParams['date_from'] = $prevRange['from'];
        $prevParams['date_to'] = $prevRange['to'];
        if ($primaryCurrency !== '') {
            $prevParams['currency'] = $primaryCurrency;
        }
        $previousJson = dashboard_bootstrap_capture_scoped($prevParams, 'previous');
        $previousData = (!empty($previousJson['success']) && is_array($previousJson['data']))
            ? $previousJson['data']
            : null;
    }

    $earningsCurrent = [];
    $earningsPrevious = [];

    if ($bootstrapScope === 'full' || $bootstrapScope === 'earnings') {
        if ($bootstrapScope === 'earnings' && ($currentJson === null || !is_array($currentJson['data'] ?? null))) {
            $currentParams = $baseParams;
            if ($primaryCurrency !== '') {
                $currentParams['currency'] = $primaryCurrency;
            }
            $currentJson = dashboard_bootstrap_capture_scoped($currentParams, 'kpi');
        }

        $skipEarningsPrevious = ($bootstrapScope === 'earnings');

        foreach ($currencyCodes as $code) {
            if ($code === $primaryCurrency) {
                $primaryCurrent = is_array($currentJson['data'] ?? null) ? $currentJson['data'] : null;
                $earningsCurrent[] = [
                    'code' => $code,
                    'payload' => dashboard_bootstrap_slim_payload($primaryCurrent),
                ];
                if (!$skipEarningsPrevious) {
                    $earningsPrevious[] = [
                        'code' => $code,
                        'payload' => dashboard_bootstrap_slim_payload($previousData),
                    ];
                }
                continue;
            }

            $curParams = $baseParams;
            $curParams['currency'] = $code;
            $curJson = $bootstrapScope === 'earnings'
                ? dashboard_bootstrap_capture_scoped($curParams, 'earnings')
                : dashboard_bootstrap_capture($curParams);
            $curPayload = (!empty($curJson['success']) && is_array($curJson['data']))
                ? dashboard_bootstrap_slim_payload($curJson['data'])
                : null;

            $earningsCurrent[] = ['code' => $code, 'payload' => $curPayload];

            if ($skipEarningsPrevious) {
                continue;
            }

            $prevCurParams = $baseParams;
            $prevCurParams['date_from'] = $prevRange['from'];
            $prevCurParams['date_to'] = $prevRange['to'];
            $prevCurParams['currency'] = $code;
            $prevCurJson = dashboard_bootstrap_capture($prevCurParams);
            $prevCurPayload = (!empty($prevCurJson['success']) && is_array($prevCurJson['data']))
                ? dashboard_bootstrap_slim_payload($prevCurJson['data'])
                : null;

            $earningsPrevious[] = ['code' => $code, 'payload' => $prevCurPayload];
        }
    }

    $responseData = [
        'earnings' => [
            'current' => $earningsCurrent,
            'previous' => $earningsPrevious,
        ],
        'date_range' => [
            'from' => $dateFrom,
            'to' => $dateTo,
        ],
        'previous_date_range' => $prevRange,
        'bootstrap_scope' => $bootstrapScope,
    ];

    if ($bootstrapScope === 'full' || $bootstrapScope === 'kpi' || $bootstrapScope === 'chart') {
        $responseData['current'] = $currentJson['data'] ?? null;
        $responseData['previous'] = $bootstrapScope === 'full' ? $previousData : null;
    } elseif ($bootstrapScope === 'previous') {
        $responseData['previous'] = $previousData;
    }

    echo json_encode([
        'success' => true,
        'data' => $responseData,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('dashboard_bootstrap_api: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} finally {
    dashboard_api_end_bootstrap_batch();
}
