<?php
/**
 * Deleted Log：按「前台/接口删除入口」分组（与 deleted_logs.page 写入值一致）
 */
function deleted_log_entry_source_definitions(): array
{
    return [
        '' => [
            'label' => 'All · 全部',
            'hint' => '所有删除记录',
            'pages' => [],
        ],
        'account' => [
            'label' => 'Account · 账号',
            'hint' => '账号列表、币种、账号币种等',
            'pages' => [
                'account-list.php',
                '/api/accounts/delete_accounts_api.php',
                '/api/accounts/delete_currency_api.php',
                '/api/accounts/account_currency_api.php',
                '/api/accounts/bulk_account_currency_api.php',
                '/api/accounts/account_company_api.php',
                '/api/accounts/account_link_api.php',
            ],
        ],
        'txn_maint' => [
            'label' => 'Txn Maint · 交易维护',
            'hint' => 'Transaction Maintenance 批量删流水',
            'pages' => [
                '/api/transactions/maintenance_delete_api.php',
            ],
        ],
        'payment' => [
            'label' => 'Payment · 收付款',
            'hint' => 'Payment Maintenance',
            'pages' => [
                '/api/payment_maintenance/delete_api.php',
            ],
        ],
        'capture' => [
            'label' => 'Capture · 抓数维护',
            'hint' => 'Data Capture Maintenance',
            'pages' => [
                '/api/capture_maintenance/delete_api.php',
            ],
        ],
        'formula' => [
            'label' => 'Formula · 公式',
            'hint' => 'Formula Maintenance / 模板',
            'pages' => [
                '/api/formula_maintenance/delete_api.php',
            ],
        ],
        'process' => [
            'label' => 'Process · 流程',
            'hint' => 'Process List 删 Bank/Games 流程',
            'pages' => [
                '/api/processes/delete_processes_api.php',
                'processlist.php',
            ],
        ],
        'ownership' => [
            'label' => 'Ownership · 股权',
            'hint' => '移除 ownership 行',
            'pages' => [
                '/api/ownership/remove_owner_api.php',
                'remove_owner_api.php',
            ],
        ],
        'marquee' => [
            'label' => 'Marquee · 跑马灯',
            'hint' => '系统维护区跑马灯',
            'pages' => [
                '/api/maintenance/delete_api.php',
            ],
        ],
    ];
}

/**
 * @return array{label:string,hint:string,pages:array<int,string>}|null
 */
function deleted_log_entry_source_for_key(string $key): ?array
{
    $all = deleted_log_entry_source_definitions();
    return array_key_exists($key, $all) ? $all[$key] : null;
}
