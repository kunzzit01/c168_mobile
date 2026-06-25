<?php
/**
 * Company expiration urgency tiers for sidebar / session / auto-renew UI.
 *
 * 30–16 days → exp-yellow
 * 15–8 days  → exp-orange
 * 7–0 days   → exp-critical
 * < 0        → expired
 * > 30 days  → normal
 */
function company_expiration_status(?int $daysLeft): string
{
    if ($daysLeft === null) {
        return 'normal';
    }
    if ($daysLeft < 0) {
        return 'expired';
    }
    if ($daysLeft <= 7) {
        return 'exp-critical';
    }
    if ($daysLeft <= 15) {
        return 'exp-orange';
    }
    if ($daysLeft <= 30) {
        return 'exp-yellow';
    }
    return 'normal';
}
