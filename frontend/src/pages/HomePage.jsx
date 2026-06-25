import { useOutletContext } from "react-router-dom";

export default function HomePage() {
  const { user } = useOutletContext();

  const displayName =
    user?.login_id || user?.account_id || user?.username || user?.name || "用户";

  return (
    <div className="space-y-4">
      <header>
        <p className="text-slate-400 text-sm">欢迎回来</p>
        <h1 className="text-xl font-bold mt-1">{displayName}</h1>
      </header>

      <section className="m-card">
        <h2 className="font-semibold mb-2">手机版工作台</h2>
        <p className="text-slate-400 text-sm leading-relaxed">
          这是独立的 C168 手机特别版。功能与排版将与桌面版分开开发，后续可在此添加移动端专属模块。
        </p>
      </section>

      <section className="grid grid-cols-2 gap-3">
        {[
          { title: "快捷采集", desc: "移动端录入入口（待开发）" },
          { title: "今日概览", desc: "核心指标卡片（待开发）" },
          { title: "待办提醒", desc: "消息与审批（待开发）" },
          { title: "扫一扫", desc: "扫码快捷操作（待开发）" },
        ].map((item) => (
          <article key={item.title} className="m-card min-h-[108px]">
            <h3 className="text-sm font-semibold">{item.title}</h3>
            <p className="text-xs text-slate-400 mt-2">{item.desc}</p>
          </article>
        ))}
      </section>
    </div>
  );
}
