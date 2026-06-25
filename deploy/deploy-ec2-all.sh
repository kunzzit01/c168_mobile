#!/usr/bin/env bash
# EC2 上执行：同时部署 count168.site + count168.org（GitHub Actions push main 调用）
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "========== count168.site (/var/www/count168) =========="
bash "$DIR/deploy.sh"

echo "========== count168.org (/var/www/count168.org) =========="
bash "$DIR/deploy-org.sh"

echo "========== EC2 deploy all OK =========="
