<?php

/**
 * data_capture_details.processed_amount：按 6 位小数量化（HALF_UP）的 SQL 片段。
 * 统计口径统一 6 位，展示口径仍由前端/调用方按 2 位处理。
 *
 * @param string $col 列全名，如 dcd.processed_amount
 */
function dcd_processed_amount_sql_quant2(string $col = 'dcd.processed_amount'): string
{
    return 'ROUND((' . $col . '), 6)';
}

/**
 * PHP 侧量化到 6 位并使用 HALF_UP，作为 SQL 口径的等价实现。
 */
function dcd_processed_amount_float_quant2(float $value): float
{
    if (!is_finite($value)) {
        return 0.0;
    }
    $out = round($value, 6, PHP_ROUND_HALF_UP);
    return ($out === 0.0 || $out === -0.0) ? 0.0 : $out;
}
