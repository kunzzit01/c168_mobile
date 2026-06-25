import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useEffect, useState } from "react";
import { fetchCurrentUser } from "../lib/api.js";
import BottomNav from "./BottomNav.jsx";

export default function MobileShell({ requireAuth = true }) {
  const location = useLocation();
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(requireAuth);

  useEffect(() => {
    if (!requireAuth) {
      setLoading(false);
      return;
    }
    let cancelled = false;
    (async () => {
      const data = await fetchCurrentUser();
      if (!cancelled) {
        setUser(data?.user || data);
        setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [requireAuth, location.pathname]);

  if (loading) {
    return (
      <div className="m-app items-center justify-center">
        <p className="text-slate-400 text-sm">加载中…</p>
      </div>
    );
  }

  if (requireAuth && !user) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }

  return (
    <div className="m-app">
      <main className="m-scroll">
        <Outlet context={{ user, setUser }} />
      </main>
      {requireAuth ? <BottomNav /> : null}
    </div>
  );
}
