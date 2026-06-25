#!/usr/bin/env bash
# EC2 上执行：拉取 main 并生效（由 GitHub Actions SSH 调用，或手动运行）
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/count168}"
BRANCH="${BRANCH:-main}"

echo "==> deploy start: user=$(whoami) host=$(hostname) root=${APP_ROOT}"
df -h "$APP_ROOT" / 2>/dev/null | tail -n +2 || true

cd "$APP_ROOT"

if [[ ! -d "$APP_ROOT/.git" ]]; then
  echo "ERROR: ${APP_ROOT}/.git missing — run deploy/ec2-amazon-linux-setup.sh first"
  exit 1
fi

fix_repo_permissions() {
  echo "==> fixing repo ownership for $(whoami)"
  if ! command -v sudo >/dev/null 2>&1; then
    echo "ERROR: ${APP_ROOT}/.git is not writable and sudo is unavailable"
    exit 1
  fi
  if ! sudo chown -R "$(whoami):nginx" "$APP_ROOT"; then
    sudo chown -R "$(whoami):$(id -gn)" "$APP_ROOT"
  fi
}

if [[ ! -w "$APP_ROOT/.git/objects" ]] || [[ ! -w "$APP_ROOT/.git/FETCH_HEAD" ]]; then
  fix_repo_permissions
fi

echo "==> git fetch + reset to origin/${BRANCH}"
if ! git fetch origin "$BRANCH"; then
  echo "==> git fetch failed, retry after chown"
  fix_repo_permissions
  git fetch origin "$BRANCH"
fi
git reset --hard "origin/${BRANCH}"

if command -v chcon >/dev/null 2>&1; then
  chcon -R -t httpd_sys_content_t "$APP_ROOT" 2>/dev/null || true
fi

# 同步 Nginx 站点配置（git pull 不会自动更新 /etc/nginx/）
# certbot 已上 HTTPS 时跳过，避免覆盖 le-ssl
NGINX_SRC="$APP_ROOT/deploy/nginx/count168.site.amazon-linux.conf"
NGINX_DST="/etc/nginx/conf.d/count168.site.conf"
NGINX_SSL="/etc/nginx/conf.d/count168.site-le-ssl.conf"
LE_CERT="/etc/letsencrypt/live/count168.site/fullchain.pem"
if [[ -f "$LE_CERT" ]] || [[ -f "$NGINX_SSL" ]]; then
  echo "==> skip nginx config sync (certbot HTTPS active for count168.site)"
elif [[ -f "$NGINX_SRC" ]]; then
  echo "==> sync nginx site config"
  NGINX_BAK="$(mktemp)"
  sudo cp "$NGINX_DST" "$NGINX_BAK" 2>/dev/null || true
  sudo rm -f /etc/nginx/conf.d/default.conf 2>/dev/null || true
  sudo cp "$NGINX_SRC" "$NGINX_DST"
  if ! sudo nginx -t; then
    echo "ERROR: nginx -t failed after config sync — restoring previous config"
    if [[ -f "$NGINX_BAK" ]]; then
      sudo cp "$NGINX_BAK" "$NGINX_DST"
      sudo nginx -t || true
    fi
    rm -f "$NGINX_BAK"
    exit 1
  fi
  rm -f "$NGINX_BAK"
fi

if systemctl is-active --quiet nginx 2>/dev/null; then
  sudo systemctl reload nginx || true
fi

echo "==> Deploy OK at $(date -Iseconds)"
FRONTEND_INDEX="${APP_ROOT}/frontend/dist/index.html"
if [[ -f "$FRONTEND_INDEX" ]]; then
  grep -o 'index-[A-Za-z0-9_-]*\.js' "$FRONTEND_INDEX" | head -1 || true
fi
