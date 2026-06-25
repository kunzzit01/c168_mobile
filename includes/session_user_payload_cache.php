<?php
/**
 * Short-lived cache for api/session/current_user_api.php payload (stored in PHP session).
 * Cleared on login, logout, and company session switch.
 */

function session_user_payload_cache_key(): string
{
    return implode('|', [
        (string) (int) ($_SESSION['user_id'] ?? 0),
        (string) (int) ($_SESSION['company_id'] ?? 0),
        (string) (int) ($_SESSION['read_only'] ?? 0),
        (string) (int) ($_SESSION['secondary_password_verified'] ?? 0),
        (string) ($_SESSION['login_scope'] ?? ''),
        (string) ($_SESSION['login_identifier'] ?? ''),
        (string) (int) ($_SESSION['login_group_scope_id'] ?? 0),
        (string) ($_SESSION['user_type'] ?? ''),
        (string) ($_SESSION['role'] ?? ''),
    ]);
}

/** @return array<string, mixed>|null */
function session_user_payload_cache_get(int $ttlSeconds = 45): ?array
{
    $cached = $_SESSION['_spa_user_payload_cache'] ?? null;
    if (!is_array($cached)) {
        return null;
    }
    if (($cached['key'] ?? '') !== session_user_payload_cache_key()) {
        return null;
    }
    if (time() - (int) ($cached['at'] ?? 0) > $ttlSeconds) {
        return null;
    }
    $data = $cached['data'] ?? null;

    return is_array($data) ? $data : null;
}

/** @param array<string, mixed> $data */
function session_user_payload_cache_set(array $data): void
{
    $_SESSION['_spa_user_payload_cache'] = [
        'key' => session_user_payload_cache_key(),
        'at' => time(),
        'data' => $data,
    ];
}

function session_user_payload_cache_clear(): void
{
    unset($_SESSION['_spa_user_payload_cache']);
}
