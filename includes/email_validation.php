<?php

function sanitize_email_input($value): string
{
    $value = (string) $value;
    $value = preg_replace('/[\x{4e00}-\x{9fa5}]/u', '', $value) ?? '';
    $value = preg_replace('/\s+/', '', $value) ?? '';
    return strtolower($value);
}

function normalize_email($value): string
{
    return sanitize_email_input(trim((string) $value));
}

function email_has_dangerous_content(string $email): bool
{
    return (bool) preg_match('/[<>\"\'`;\\\\]|(\/\/)|javascript:|data:|\x00/i', $email);
}

function is_valid_email_local_part(string $local): bool
{
    if ($local === '' || strlen($local) > 64) {
        return false;
    }
    if ($local[0] === '.' || substr($local, -1) === '.') {
        return false;
    }
    if (strpos($local, '..') !== false) {
        return false;
    }
    return (bool) preg_match('/^[a-z0-9.+_-]+$/', $local);
}

function is_valid_email_domain_label(string $label): bool
{
    if ($label === '' || strlen($label) > 63) {
        return false;
    }
    if ($label[0] === '-' || substr($label, -1) === '-') {
        return false;
    }
    return (bool) preg_match('/^[a-z0-9-]+$/', $label);
}

function is_valid_email_domain_part(string $domain): bool
{
    if ($domain === '' || strlen($domain) > 253) {
        return false;
    }
    if ($domain[0] === '.' || substr($domain, -1) === '.') {
        return false;
    }
    if (strpos($domain, '..') !== false) {
        return false;
    }

    $labels = explode('.', $domain);
    if (count($labels) < 2) {
        return false;
    }

    $tld = $labels[count($labels) - 1];
    if (strlen($tld) < 2 || !preg_match('/^[a-z]+$/', $tld)) {
        return false;
    }

    foreach ($labels as $label) {
        if (!is_valid_email_domain_label($label)) {
            return false;
        }
    }

    return true;
}

function is_valid_email($value): bool
{
    $email = normalize_email($value);
    if ($email === '') {
        return false;
    }
    if (email_has_dangerous_content($email)) {
        return false;
    }
    if (substr_count($email, '@') !== 1) {
        return false;
    }

    [$local, $domain] = explode('@', $email, 2);
    if ($local === '' || $domain === '') {
        return false;
    }

    return is_valid_email_local_part($local) && is_valid_email_domain_part($domain);
}

/** @return array{ok: bool, normalized: string, error: ?string} */
function validate_email($value): array
{
    $normalized = normalize_email($value);
    if ($normalized === '') {
        return ['ok' => false, 'normalized' => '', 'error' => 'empty'];
    }
    if (!is_valid_email($normalized)) {
        return ['ok' => false, 'normalized' => $normalized, 'error' => 'invalid'];
    }
    return ['ok' => true, 'normalized' => $normalized, 'error' => null];
}
