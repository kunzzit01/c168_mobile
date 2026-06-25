import { buildApiUrl } from "../../utils/core/apiUrl.js";
import { isApiSuccess } from "./shared/ownershipHelpers.js";
import {
  getOwnershipCurrentMonthKey,
  isOwnershipHistoricalMonth,
} from "./shared/ownershipMonthHelpers.js";

const cache = new Map();
const inflight = new Map();

function cacheKey(monthKey) {
  return monthKey || getOwnershipCurrentMonthKey();
}

function companiesApiUrl(monthKey) {
  const monthQs = isOwnershipHistoricalMonth(monthKey)
    ? `&month=${encodeURIComponent(monthKey)}`
    : "";
  return buildApiUrl(`api/ownership/get_companies_api.php?all=1${monthQs}`);
}

/** Read warm company list from sidebar hover prefetch (same shape as get_companies_api JSON). */
export function peekOwnershipCompaniesCache(monthKey = getOwnershipCurrentMonthKey()) {
  return cache.get(cacheKey(monthKey)) ?? null;
}

export async function prefetchOwnershipCompanies(monthKey = getOwnershipCurrentMonthKey()) {
  const key = cacheKey(monthKey);
  const hit = cache.get(key);
  if (hit) return hit;

  const pending = inflight.get(key);
  if (pending) return pending;

  const promise = fetch(companiesApiUrl(monthKey), { credentials: "include" })
    .then((res) => res.json())
    .then((json) => {
      if (isApiSuccess(json)) {
        cache.set(key, json);
        return json;
      }
      throw new Error(json?.message || "Failed to load ownership companies");
    })
    .finally(() => {
      inflight.delete(key);
    });

  inflight.set(key, promise);
  return promise;
}
