<?php
/**
 * 统一 API JSON 响应格式
 * 所有 API 使用 success, message, data 三个字段
 */

function api_success($data = null, $message = '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

function api_error($message, $httpCode = 400, $data = null) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    $out = [
        'success' => false,
        'message' => $message,
        'data' => $data,
        'error' => $message  // 向后兼容：前端可能读取 .error
    ];
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}