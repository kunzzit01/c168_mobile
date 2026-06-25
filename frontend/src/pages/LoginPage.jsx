import { useEffect, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { fetchCurrentUser, loginWithCredentials } from "../lib/api.js";

export default function LoginPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const [role, setRole] = useState("admin");
  const [companyId, setCompanyId] = useState("");
  const [loginId, setLoginId] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const data = await fetchCurrentUser();
      if (!cancelled && data) {
        const redirect = location.state?.from || "/home";
        navigate(redirect, { replace: true });
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [location.state, navigate]);

  const onSubmit = async (e) => {
    e.preventDefault();
    if (submitting) return;
    setError("");
    setSubmitting(true);
    try {
      const data = await loginWithCredentials({
        companyId,
        loginId,
        password,
        role,
      });
      if (data.status === "success") {
        navigate(location.state?.from || "/home", { replace: true });
        return;
      }
      setError(data.message || "登录失败，请检查账号信息");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="m-app justify-center px-5 py-8">
      <div className="mb-8 text-center">
        <p className="text-sky-400 text-sm font-semibold tracking-widest uppercase">C168</p>
        <h1 className="text-2xl font-bold mt-2">手机特别版</h1>
        <p className="text-slate-400 text-sm mt-2">专为手机操作设计，与桌面版独立</p>
      </div>

      <div className="m-card space-y-4">
        <div className="grid grid-cols-2 gap-2 p-1 rounded-xl bg-slate-900/80">
          {[
            { id: "admin", label: "管理员" },
            { id: "member", label: "会员" },
          ].map((item) => (
            <button
              key={item.id}
              type="button"
              onClick={() => setRole(item.id)}
              className={`rounded-lg py-2 text-sm font-medium transition ${
                role === item.id ? "bg-sky-500 text-white" : "text-slate-400"
              }`}
            >
              {item.label}
            </button>
          ))}
        </div>

        <form onSubmit={onSubmit} className="space-y-4">
          <div>
            <label className="m-label" htmlFor="companyId">
              公司 ID
            </label>
            <input
              id="companyId"
              className="m-input"
              value={companyId}
              onChange={(e) => setCompanyId(e.target.value)}
              autoComplete="organization"
              required
            />
          </div>
          <div>
            <label className="m-label" htmlFor="loginId">
              {role === "member" ? "账号 ID" : "登录 ID"}
            </label>
            <input
              id="loginId"
              className="m-input"
              value={loginId}
              onChange={(e) => setLoginId(e.target.value)}
              autoComplete="username"
              required
            />
          </div>
          <div>
            <label className="m-label" htmlFor="password">
              密码
            </label>
            <input
              id="password"
              type="password"
              className="m-input"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="current-password"
              required
            />
          </div>
          {error ? <p className="m-error">{error}</p> : null}
          <button type="submit" className="m-btn m-btn-primary" disabled={submitting}>
            {submitting ? "登录中…" : "登录"}
          </button>
        </form>
      </div>
    </div>
  );
}
