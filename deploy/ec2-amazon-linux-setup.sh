#!/usr/bin/env bash
# count168 — Amazon Linux 2023 首次部署（在 EC2 上以 root 或 sudo 运行）
# 用法:
#   curl -fsSL ... 或 git clone 后:
#   sudo bash deploy/ec2-amazon-linux-setup.sh
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/count168}"
REPO_URL="${REPO_URL:-https://github.com/kunzzgroups/count168test.git}"
BRANCH="${BRANCH:-main}"

echo "==> 1/7 安装 Nginx、PHP-FPM、MariaDB、Git"
dnf update -y
dnf install -y nginx php-fpm php-mysqlnd php-mbstring php-xml php-curl php-pdo mariadb105-server git

echo "==> 2/7 启动 MariaDB / Nginx / PHP-FPM"
systemctl enable --now mariadb nginx php-fpm

echo "==> 3/7 PHP 上传限制"
PHP_INI="$(php -i 2>/dev/null | awk -F'=> ' '/^Loaded Configuration File/{print $2}' | tr -d ' ')"
if [[ -f "$PHP_INI" ]]; then
  sed -i 's/^post_max_size.*/post_max_size = 64M/' "$PHP_INI" || true
  sed -i 's/^upload_max_filesize.*/upload_max_filesize = 64M/' "$PHP_INI" || true
  grep -q '^post_max_size' "$PHP_INI" || echo 'post_max_size = 64M' >> "$PHP_INI"
  grep -q '^upload_max_filesize' "$PHP_INI" || echo 'upload_max_filesize = 64M' >> "$PHP_INI"
fi
systemctl restart php-fpm

echo "==> 4/7 拉取代码到 ${APP_ROOT}"
mkdir -p "$(dirname "$APP_ROOT")"
if [[ ! -d "${APP_ROOT}/.git" ]]; then
  git clone --branch "$BRANCH" --depth 1 "$REPO_URL" "$APP_ROOT"
else
  echo "    已有 git 仓库，跳过 clone"
fi

echo "==> 5/7 Nginx 站点（替换默认 Welcome 页）"
rm -f /etc/nginx/conf.d/default.conf
cp "${APP_ROOT}/deploy/nginx/count168.site.amazon-linux.conf" /etc/nginx/conf.d/count168.site.conf
nginx -t
systemctl reload nginx

echo "==> 6/7 目录权限 + SELinux（Amazon Linux 默认 Enforcing）"
chown -R ec2-user:nginx "$APP_ROOT"
find "$APP_ROOT" -type d -exec chmod 755 {} \;
find "$APP_ROOT" -type f -exec chmod 644 {} \;
if command -v chcon >/dev/null 2>&1; then
  chcon -R -t httpd_sys_content_t "$APP_ROOT" || true
  setsebool -P httpd_can_network_connect_db 1 || true
fi

echo "==> 7/7 检查 frontend/dist"
if [[ ! -f "${APP_ROOT}/frontend/dist/index.html" ]]; then
  echo "    警告: frontend/dist/index.html 不存在。"
  echo "    请在本地 npm run build 后上传 frontend/dist/，或在服务器安装 node 后 build。"
fi

cat <<EOF

========================================
基础环境已装好。还需你手动完成：

1) 数据库
   sudo mysql_secure_installation
   sudo mysql -e "CREATE DATABASE count168 CHARACTER SET utf8mb4;"
   sudo mysql -e "CREATE USER 'count168'@'localhost' IDENTIFIED BY '你的密码';"
   sudo mysql -e "GRANT ALL ON count168.* TO 'count168'@'localhost';"
   导入 dump 后编辑: ${APP_ROOT}/includes/config.php

2) 验证
   curl -I http://127.0.0.1/login
   浏览器打开 http://count168.site/login

3) HTTPS（推荐）
   sudo dnf install -y certbot python3-certbot-nginx
   sudo certbot --nginx -d count168.site -d www.count168.site

4) AWS 安全组：入站 80、443 已开放
========================================
EOF
