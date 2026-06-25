<?php
/**
 * Password hashing: prefer Argon2id, fallback to Bcrypt (cost 12).
 */

if (!function_exists('secure_password_hash_options')) {
    /**
     * @return array{algo: int, options: array<string, int>}
     */
    function secure_password_hash_options(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return [
                'algo' => PASSWORD_ARGON2ID,
                'options' => [
                    'memory_cost' => 65536,
                    'time_cost' => 4,
                    'threads' => 1,
                ],
            ];
        }

        return [
            'algo' => PASSWORD_BCRYPT,
            'options' => ['cost' => 12],
        ];
    }
}

if (!function_exists('secure_hash_password')) {
    function secure_hash_password(string $password): string
    {
        $cfg = secure_password_hash_options();
        $hash = password_hash($password, $cfg['algo'], $cfg['options']);
        if ($hash === false) {
            throw new RuntimeException('Failed to hash password');
        }

        return $hash;
    }
}

if (!function_exists('verify_secure_password')) {
    function verify_secure_password(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }
}

if (!function_exists('maybe_rehash_password')) {
    /**
     * After successful login/verify: return new hash if algorithm params need upgrade.
     *
     * @return string|null New hash to persist, or null if no change needed
     */
    function maybe_rehash_password(string $password, string $storedHash): ?string
    {
        if ($storedHash === '' || !password_verify($password, $storedHash)) {
            return null;
        }

        $cfg = secure_password_hash_options();
        if (!password_needs_rehash($storedHash, $cfg['algo'], $cfg['options'])) {
            return null;
        }

        return secure_hash_password($password);
    }
}
