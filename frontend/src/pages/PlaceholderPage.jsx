export default function PlaceholderPage({ title, description }) {
  return (
    <div className="m-card">
      <h1 className="text-lg font-semibold">{title}</h1>
      <p className="text-slate-400 text-sm mt-3 leading-relaxed">{description}</p>
    </div>
  );
}
