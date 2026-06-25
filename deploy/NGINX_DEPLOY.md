# count168 Nginx 部署指南（方案 A）

保持现有构建路径 `/frontend/dist/`，整站上传 + Nginx 替代 Apache `.htaccess`。

> **重要：** 带 `sudo`、`/etc/nginx/` 的命令都在 **Linux 服务器** 上执行。  
> 你在 Windows 本地只能做 `npm run build` 和用 WinSCP 上传文件。

---

## 从零开始（Windows 用户完整步骤）

### 准备工具

1. **WinSCP**（上传文件）：https://winscp.net/
2. **Windows Terminal / PowerShell**（SSH 登录服务器）

向服务器提供商（VPS 面板 / Hostinger 等）拿到：

- 服务器 **IP 地址**
- **SSH 用户名**（常见 `root`）
- **SSH 密码** 或私钥

---

### 步骤 1：本地打包（Windows）

在 PowerShell 里，项目目录下：

```powershell
cd C:\Users\kunzz\OneDrive\Desktop\count168test\frontend
npm run build
```

成功后应有 `frontend\dist\index.html`。

---

### 步骤 2：用 WinSCP 上传网站文件

1. 打开 WinSCP → 新建站点  
   - 协议：**SFTP**  
   - 主机名：服务器 IP  
   - 用户名 / 密码：你的 SSH 账号  

2. 连接后，在**右侧（服务器）**进入或新建目录，例如：  
   `/var/www/count168/`

3. 在**左侧（本地）**打开项目根目录：  
   `C:\Users\kunzz\OneDrive\Desktop\count168test`

4. 把下面这些**拖到服务器** `/var/www/count168/` 里（保持文件夹结构）：

   | 本地文件夹/文件 | 服务器上应变成 |
   |----------------|----------------|
   | `api/` | `/var/www/count168/api/` |
   | `includes/` | `/var/www/count168/includes/` |
   | `frontend/dist/` | `/var/www/count168/frontend/dist/` |
   | `images/` | `/var/www/count168/images/` |
   | `js/` | `/var/www/count168/js/` |
   | `favicon.ico` | `/var/www/count168/favicon.ico` |

5. **不要上传**：`node_modules/`、`.git/`、`frontend/src/`（可选）

6. 在 WinSCP 里编辑服务器上的  
   `/var/www/count168/includes/config.php`  
   改成该服务器的 MySQL 主机、库名、用户名、密码。

---

### 步骤 3：SSH 登录服务器

PowerShell：

```powershell
ssh root@你的服务器IP
```

输入密码后，提示符变成类似 `root@xxx:~#` 即表示已在服务器上。

---

### 步骤 4：安装 Nginx + PHP（若尚未安装）

在 **SSH 里**执行（Ubuntu/Debian 示例）：

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-mysql php-mbstring php-xml php-curl
```

查看 php-fpm 的 socket 路径（后面配置要用）：

```bash
ls /run/php/php*-fpm.sock
```

常见输出：`/run/php/php8.2-fpm.sock`（版本号可能不同，记下来）。

---

### 步骤 5：上传并编辑 Nginx 配置

**方式 A — WinSCP（推荐）**

1. 把本地的  
   `deploy\nginx\count168.site.conf`  
   上传到服务器：  
   `/etc/nginx/sites-available/count168.site`

2. 在 WinSCP 里右键该文件 → 编辑，确认三处：

   ```nginx
   root /var/www/count168;                    # 与步骤 2 上传目录一致
   server_name count168.site www.count168.site;
   fastcgi_pass unix:/run/php/php8.2-fpm.sock;  # 改成步骤 4 查到的路径
   ```

   文件中所有 `fastcgi_pass` 都要改成同一个 socket。

**方式 B — SSH 里手动创建**

```bash
sudo nano /etc/nginx/sites-available/count168.site
```

粘贴 `deploy/nginx/count168.site.conf` 的内容，改好 `root`、`server_name`、`fastcgi_pass`，保存：`Ctrl+O` 回车，`Ctrl+X` 退出。

---

### 步骤 6：启用站点（必须在 SSH 里执行）

```bash
sudo ln -sf /etc/nginx/sites-available/count168.site /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

- `nginx -t` 必须显示 **syntax is ok**  
- 若报错，把完整错误信息复制下来排查

---

### 步骤 7：浏览器验证

| 地址 | 预期 |
|------|------|
| `http://count168.site/` | 跳转到 `/login` |
| `http://count168.site/member` | React 会员页（不再是 Welcome to nginx!） |
| F12 → Network → `/api/session/...` | 返回 JSON，不是 502 |

---

### 步骤 8：以后只改前端时

本地：

```powershell
cd frontend
npm run build
```

WinSCP 只覆盖上传 **`frontend/dist/`** 到服务器同名目录即可。

---

## 目录结构速查

```
/var/www/count168/          ← Nginx root
├── api/                    ← PHP 接口
├── includes/config.php     ← 数据库配置
├── frontend/dist/          ← npm run build 产物
│   ├── index.html
│   ├── assets/
│   └── css/
├── images/
├── js/
└── favicon.ico
```

---

## PHP 大 POST 限制

Apache 的 `.htaccess` 里有 `post_max_size=64M` 等；Nginx 需在服务器 **php.ini** 设置：

```ini
post_max_size = 64M
upload_max_filesize = 64M
max_input_vars = 5000
max_input_time = 300
max_execution_time = 300
memory_limit = 256M
```

Nginx 配置里已设 `client_max_body_size 64M;`。改 php.ini 后：`sudo systemctl restart php8.2-fpm`。

---

## 性能优化（换服务器后建议做）

更新 Nginx 配置后，在 **SSH** 里：

```bash
sudo nginx -t && sudo systemctl reload nginx
```

仓库里的 `deploy/nginx/count168.site.conf` 已包含：

- **gzip** — 压缩 JS/CSS/JSON，减少传输体积
- **静态资源缓存** — `/frontend/dist/assets/` 长期缓存；`/images/`、`/js/` 短期缓存
- **API fastcgi buffer** — 略减大 JSON 响应时的缓冲开销

### 开启 PHP OPcache（接口加速）

新装 PHP 常默认未开 OPcache。SSH 里检查：

```bash
php -m | grep -i opcache
```

若无输出，新建（Ubuntu 示例）：

```bash
sudo tee /etc/php/8.2/fpm/conf.d/99-opcache.ini <<'EOF'
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
EOF
sudo systemctl restart php8.2-fpm
```

### 启用 HTTPS + HTTP/2（可选，进一步加速多资源加载）

用 Certbot 申请免费证书后，在 Nginx 里启用 `listen 443 ssl http2;`（见 `count168.site.conf` 顶部注释）。

### 验证是否生效

浏览器 F12 → Network → 刷新：

- 静态 `.js` / `.css` 响应头应有 `content-encoding: gzip`
- `/frontend/dist/assets/*.js` 第二次访问应显示 `(disk cache)` 或 `304`

---

## 数据库导入

见 [`database/HOSTINGER_IMPORT.md`](../database/HOSTINGER_IMPORT.md)。

---

## 常见问题

**在 Windows 跑 sudo 报错 “Sudo is disabled”**  
正常。`sudo` 只能在 SSH 登录服务器后使用，不能在本地 PowerShell 跑。

**仍显示 “Welcome to nginx!”**  
- 默认站点没删：确认执行了 `rm sites-enabled/default`  
- `root` 指错：应是 `/var/www/count168`，不是 `/usr/share/nginx/html`  
- 域名没指到这个 server 块：检查 `server_name`

**页面白屏 / CSS 404**  
检查服务器上是否存在 `/var/www/count168/frontend/dist/css/style.css`。

**API 502**  
`fastcgi_pass` 路径不对，或 php-fpm 未运行：`sudo systemctl status php8.2-fpm`。

**登录失败 / 数据库错误**  
检查 `includes/config.php` 与 MySQL 是否已导入。
