import { NavLink } from "react-router-dom";

const NAV_ITEMS = [
  { to: "/home", label: "首页", icon: "⌂" },
  { to: "/capture", label: "采集", icon: "⊞" },
  { to: "/transaction", label: "交易", icon: "↔" },
  { to: "/profile", label: "我的", icon: "◎" },
];

export default function BottomNav() {
  return (
    <nav
      className="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-[480px] border-t border-slate-700/60 bg-slate-950/95 backdrop-blur-md"
      style={{ paddingBottom: "max(8px, env(safe-area-inset-bottom))" }}
    >
      <div className="grid grid-cols-4">
        {NAV_ITEMS.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            className={({ isActive }) =>
              `flex flex-col items-center gap-1 py-2 text-xs ${
                isActive ? "text-sky-400" : "text-slate-400"
              }`
            }
          >
            <span className="text-lg leading-none" aria-hidden="true">
              {item.icon}
            </span>
            <span>{item.label}</span>
          </NavLink>
        ))}
      </div>
    </nav>
  );
}
