<?php
/**
 * Company expiration rules shared by login + session company switch.
 *
 * - AP group (company_id=AP or group_id=AP): empty expiration_date is allowed.
 * - C168: legacy always-valid (incl. expired dates).
 * - All other companies/groups: expiration_date required; past dates block access.
 */
declare(strict_types=1);

function gc_company_allows_empty_expiration(?string $companyCode, ?string $groupId): bool
{
    $code = strtoupper(trim((string) $companyCode));
    $group = strtoupper(trim((string) $groupId));
    if ($code === 'C168') {
        return true;
    }

    return $code === 'AP' || $group === 'AP';
}

/** @return 'valid'|'no_set'|'expired' */
function gc_get_company_expiration_state($expirationDate, ?string $companyCode = null, ?string $groupId = null): string
{
    $code = strtoupper(trim((string) $companyCode));
    if ($code === 'C168') {
        return 'valid';
    }

    $empty = $expirationDate === null || trim((string) $expirationDate) === '';
    if ($empty) {
        return gc_company_allows_empty_expiration($companyCode, $groupId) ? 'valid' : 'no_set';
    }

    $expTs = strtotime((string) $expirationDate);
    if ($expTs === false) {
        return gc_company_allows_empty_expiration($companyCode, $groupId) ? 'valid' : 'no_set';
    }

    if ($expTs < strtotime(date('Y-m-d'))) {
        return 'expired';
    }

    return 'valid';
}

function gc_is_company_expiration_blocking($expirationDate, ?string $companyCode = null, ?string $groupId = null): bool
{
    return gc_get_company_expiration_state($expirationDate, $companyCode, $groupId) !== 'valid';
}
