/** Sidebar / maintenance access rules for authenticated staff (non-member). */

import { canAccessC168AutoRenew, canAccessC168DomainPages } from "../company/loginScope.js";
import { spaPath } from "../routing/pageRoutes.js";

export function normRole(role) {
  return String(role || "").trim().toLowerCase();
}

export function isOwnerUser(me) {
  return normRole(me?.role) === "owner";
}

export function getUserPermissions(me) {
  return Array.isArray(me?.permissions) ? me.permissions : [];
}

/** Empty permissions array = unrestricted (owner / legacy). */
export function hasFullPermissions(me) {
  return getUserPermissions(me).length === 0;
}

export function roleSupportsOwnershipPermission(role) {
  const r = normRole(role);
  return r === "owner" || r === "partnership";
}

export function canAccessPermission(me, key) {
  if (key === "ownership" && !roleSupportsOwnershipPermission(me?.role)) return false;
  if (hasFullPermissions(me)) return true;
  return getUserPermissions(me).includes(key);
}

export function canAccessFullMaintenance(me) {
  if (isOwnerUser(me) || hasFullPermissions(me)) return true;
  return canAccessPermission(me, "maintenance");
}

/**
 * Non-owner without Maintenance permission: sidebar still shows Transaction + Formula under Maintenance.
 */
export function canAccessLimitedMaintenance(me) {
  if (isOwnerUser(me) || hasFullPermissions(me)) return false;
  if (canAccessFullMaintenance(me)) return false;
  return !!(me?.company_has_gambling || me?.company_has_bank);
}

export function showMaintenanceInSidebar(me) {
  return canAccessFullMaintenance(me) || canAccessLimitedMaintenance(me);
}

/** Transaction / Formula maintenance pages (limited path for non-owner). */
export function canAccessTransactionFormulaMaintenance(me) {
  return canAccessFullMaintenance(me) || canAccessLimitedMaintenance(me);
}

/** Capture maintenance: full Maintenance, or limited path when session company has Bank. */
export function canAccessCaptureMaintenance(me) {
  if (canAccessFullMaintenance(me)) return true;
  return canAccessLimitedMaintenance(me) && Boolean(me?.company_has_bank);
}

export function canAccessDashboard(me) {
  return canAccessPermission(me, "home");
}

/**
 * First SPA route after login — mirrors sidebar order in AuthenticatedLayout.
 * @returns {string|null} spaPath result, or null when no staff page is accessible
 */
export function resolveDefaultLandingPath(me) {
  if (!me) return spaPath("login");

  const userType = String(me.user_type || "").toLowerCase();
  if (userType === "member") return spaPath("member");

  if (canAccessDashboard(me)) return spaPath("dashboard");
  if (canAccessC168DomainPages(me)) return spaPath("domain");
  if (canAccessC168AutoRenew(me)) return spaPath("auto-renew");
  if (canAccessPermission(me, "admin")) return spaPath("userlist");
  if (canAccessPermission(me, "account")) return spaPath("account-list");
  if (canAccessPermission(me, "ownership")) return spaPath("ownership");
  if (canAccessPermission(me, "process")) {
    return me?.company_has_bank && !me?.company_has_gambling
      ? spaPath("bank-process-list")
      : spaPath("process-list");
  }
  if (canAccessPermission(me, "datacapture") && (me?.company_has_gambling || me?.company_has_bank)) {
    return spaPath("datacapture");
  }
  if (canAccessPermission(me, "payment")) return spaPath("transaction");
  if (canAccessPermission(me, "report") && me?.company_has_gambling) {
    return spaPath("customer-report");
  }
  if (canAccessFullMaintenance(me)) return spaPath("payment-maintenance");
  if (canAccessLimitedMaintenance(me)) return spaPath("transaction-maintenance");

  return null;
}
