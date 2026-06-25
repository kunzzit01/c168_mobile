const SITE_BASE = "/c168_mobile";

export function apiUrl(path) {
  const clean = String(path || "").replace(/^\//, "");
  if (import.meta.env.DEV) {
    return `/${clean}`;
  }
  return `${SITE_BASE}/${clean}`;
}

export function spaPath(segment = "") {
  const clean = String(segment || "").replace(/^\//, "");
  if (import.meta.env.DEV) {
    return clean ? `/${clean}` : "/login";
  }
  return clean ? `${SITE_BASE}/${clean}` : `${SITE_BASE}/login`;
}

export async function fetchCurrentUser() {
  const res = await fetch(apiUrl("api/session/current_user_api.php"), {
    credentials: "include",
    cache: "no-store",
  });
  if (!res.ok) return null;
  const data = await res.json().catch(() => null);
  if (!data || data.status !== "success") return null;
  return data;
}

export async function loginWithCredentials({ companyId, loginId, password, role }) {
  const fd = new FormData();
  fd.append("action", "login");
  fd.append("company_id", companyId.toUpperCase().trim());
  fd.append("password", password);
  fd.append("login_role", role);
  if (role === "member") {
    fd.append("account_id", loginId.toUpperCase().trim());
  } else {
    fd.append("login_id", loginId.toUpperCase().trim());
  }

  const res = await fetch(apiUrl("api/session/login_api.php"), {
    method: "POST",
    body: fd,
    credentials: "include",
    cache: "no-store",
  });
  const raw = await res.text();
  try {
    return raw ? JSON.parse(raw) : {};
  } catch {
    return { status: "error", message: res.ok ? "响应格式无效" : `服务器错误 (${res.status})` };
  }
}

export async function logout() {
  await fetch(apiUrl("api/session/logout_api.php"), {
    method: "POST",
    credentials: "include",
    cache: "no-store",
  }).catch(() => {});
}
