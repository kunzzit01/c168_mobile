import { useNavigate, useOutletContext } from "react-router-dom";
import PlaceholderPage from "./PlaceholderPage.jsx";
import { logout } from "../lib/api.js";

export default function ProfilePage() {
  const navigate = useNavigate();
  const { setUser } = useOutletContext();

  const onLogout = async () => {
    await logout();
    setUser?.(null);
    navigate("/login", { replace: true });
  };

  return (
    <div className="space-y-4">
      <PlaceholderPage
        title="我的"
        description="个人设置、语言、通知偏好等移动端专属选项将在此实现。"
      />
      <button type="button" className="m-btn m-btn-primary bg-red-500/90" onClick={onLogout}>
        退出登录
      </button>
    </div>
  );
}
