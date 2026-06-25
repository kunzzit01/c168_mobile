# C168 Mobile（手机特别版）

独立于桌面版 [count168-mobile](../count168-mobile) 的移动端项目，使用单独 Git 仓库：[kunzzit01/c168_mobile](https://github.com/kunzzit01/c168_mobile.git)。

## 架构说明

| 项目 | 路径 | Git 远程 |
|------|------|----------|
| 桌面版 | `htdocs/count168-mobile` | `kunzzgroups/count168test` |
| **手机版** | `htdocs/c168_mobile` | `kunzzit01/c168_mobile` |

- **前端**：本仓库 `frontend/`，React + Vite + Tailwind，移动端专属 UI
- **后端**：通过目录联接（junction）复用桌面版的 `api/`、`includes/`、`images/`，共用同一数据库与登录会话
- **互不影响**：桌面版 Git 文件不会被修改

## 本地访问

### 1. 首次安装

```powershell
cd C:\xampp\htdocs\c168_mobile\frontend
npm install
npm run build
```

确保 XAMPP Apache 已启动，浏览器打开：

**http://localhost/c168_mobile/**

### 2. 开发模式（热更新）

```powershell
cd C:\xampp\htdocs\c168_mobile\frontend
npm run dev
```

打开 **http://localhost:5174/**（API 会代理到 `http://127.0.0.1/c168_mobile`）

### 3. 后端联接（新机器克隆后执行一次）

若 `api`、`includes`、`images` 联接不存在，在 `c168_mobile` 目录执行：

```powershell
cmd /c mklink /J api "..\count168-mobile\api"
cmd /c mklink /J includes "..\count168-mobile\includes"
cmd /c mklink /J images "..\count168-mobile\images"
```

桌面版需已存在且 `includes/config.local.php`（或 `config.php`）数据库配置可用。

## 推送到 GitHub

```powershell
cd C:\xampp\htdocs\c168_mobile
git add .
git commit -m "Initial mobile edition scaffold"
git push -u origin main
```

## 后续开发

在手机版 `frontend/src/pages/` 中独立添加页面与功能，无需改动桌面版代码。底部导航当前为：首页、采集、交易、我的（占位页可逐步替换为真实功能）。
