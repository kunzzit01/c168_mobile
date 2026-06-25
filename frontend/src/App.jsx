import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import MobileShell from "./components/MobileShell.jsx";
import LoginPage from "./pages/LoginPage.jsx";
import HomePage from "./pages/HomePage.jsx";
import PlaceholderPage from "./pages/PlaceholderPage.jsx";
import ProfilePage from "./pages/ProfilePage.jsx";

function AppRoutes() {
  const basename = import.meta.env.PROD ? "/c168_mobile" : undefined;

  return (
    <BrowserRouter basename={basename}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/" element={<MobileShell requireAuth />}>
          <Route index element={<Navigate to="/home" replace />} />
          <Route path="home" element={<HomePage />} />
          <Route
            path="capture"
            element={
              <PlaceholderPage
                title="数据采集"
                description="移动端采集流程将独立设计，支持触控优化与简化字段。"
              />
            }
          />
          <Route
            path="transaction"
            element={
              <PlaceholderPage
                title="交易"
                description="移动端交易查询与快捷操作模块，排版与桌面版不同。"
              />
            }
          />
          <Route path="profile" element={<ProfilePage />} />
        </Route>
        <Route path="*" element={<Navigate to="/home" replace />} />
      </Routes>
    </BrowserRouter>
  );
}

export default function App() {
  return <AppRoutes />;
}
