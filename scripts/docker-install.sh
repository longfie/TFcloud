#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env.docker"
AUTO_INSTALL=1
SITE_URL=""
ADMIN_USERNAME="admin"
ADMIN_PASSWORD=""
APP_PORT="8080"

usage() {
  cat <<'EOF'
用法：
  ./scripts/docker-install.sh                 构建、启动并进入安装向导
  ./scripts/docker-install.sh --no-install    只构建并启动容器
  ./scripts/docker-install.sh --password '...' --site-url https://example.com

可选参数：
  --port PORT              对外端口，默认 8080（仅首次生成配置时生效）
  --site-url URL           站点地址，默认 http://localhost:8080
  --admin-username NAME    管理员用户名，默认 admin
  --password PASSWORD      管理员密码；不填写时交互式输入
  --no-install             启动服务后不自动提交安装请求
EOF
}

random_hex() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex "$1"
  else
    od -An -N "$1" -tx1 /dev/urandom | tr -d ' \n'
  fi
}

random_base64() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -base64 "$1" | tr -d '\r\n'
  else
    random_hex "$1"
  fi
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --no-install)
      AUTO_INSTALL=0
      shift
      ;;
    --port)
      APP_PORT="${2:?缺少 --port 的值}"
      shift 2
      ;;
    --site-url)
      SITE_URL="${2:?缺少 --site-url 的值}"
      shift 2
      ;;
    --admin-username)
      ADMIN_USERNAME="${2:?缺少 --admin-username 的值}"
      shift 2
      ;;
    --password)
      ADMIN_PASSWORD="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "未知参数：$1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if ! command -v docker >/dev/null 2>&1; then
  echo '未找到 Docker，请先安装 Docker Engine 或 Docker Desktop。' >&2
  exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo '未找到 Docker Compose v2，请升级 Docker。' >&2
  exit 1
fi
if ! command -v curl >/dev/null 2>&1; then
  echo '未找到 curl，安装脚本需要它检查服务状态并提交安装请求。' >&2
  exit 1
fi

cd "$ROOT_DIR"
if [[ ! -f "$ENV_FILE" ]]; then
  umask 077
  cat > "$ENV_FILE" <<EOF
APP_PORT=$APP_PORT

DB_NAME=tf_sign
DB_USER=tf_sign
DB_PASSWORD=$(random_hex 24)
MYSQL_ROOT_PASSWORD=$(random_hex 32)

APP_DEBUG=false
APP_KEY=base64:$(random_base64 32)
CREDENTIAL_KEY_VERSION=1
CREDENTIAL_FINGERPRINT_KEY=$(random_hex 32)
LEGACY_PASSWORD_SALT=qq1790716272
CORS_ALLOWED_ORIGINS=http://localhost:$APP_PORT
SESSION_SECURE=false

SIGN_WORKER_ENABLED=true
SIGN_WORKER_COUNT=1
TIEBA_RETRY_WORKER_ENABLED=true
TIEBA_RETRY_WORKER_COUNT=1
SIGN_SCHEDULER_ENABLED=true
MAIL_WORKER_ENABLED=true
TASK_LOCK_TIMEOUT_SECONDS=7200
MAINTENANCE_ENABLED=true
WEBMAN_WORKER_COUNT=4

UPDATE_VERSION_URL=https://raw.githubusercontent.com/longfie/TFcloud/main/backend/VERSION
UPDATE_PAGE_URL=https://github.com/longfie/TFcloud/releases
UPDATE_GITHUB_TOKEN=
EOF
  echo "已生成 $ENV_FILE"
fi

set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a
APP_PORT="${APP_PORT:-8080}"
SITE_URL="${SITE_URL:-http://localhost:${APP_PORT}}"

COMPOSE=(docker compose --env-file "$ENV_FILE")
"${COMPOSE[@]}" up -d --build

BASE_URL="http://127.0.0.1:${APP_PORT}"
echo '正在等待 TFcloud 启动……'
for _ in $(seq 1 60); do
  if curl -fsS "$BASE_URL/health/live" >/dev/null 2>&1; then
    break
  fi
  sleep 2
done
if ! curl -fsS "$BASE_URL/health/live" >/dev/null 2>&1; then
  echo '容器已启动，但健康检查未通过，请执行：' >&2
  echo "  ${COMPOSE[*]} logs --tail=100" >&2
  exit 1
fi

if [[ "$AUTO_INSTALL" != '1' ]]; then
  echo "服务已启动：$BASE_URL"
  echo "请打开 $BASE_URL/install 完成安装。"
  exit 0
fi

STATUS="$(${COMPOSE[@]} exec -T backend php -r 'echo file_exists("/app/runtime/install.lock") ? "installed" : "pending";' 2>/dev/null || true)"
if [[ "$STATUS" == 'installed' ]]; then
  echo "系统已经安装，访问：$BASE_URL"
  exit 0
fi

if [[ -z "$ADMIN_PASSWORD" ]]; then
  read -r -s -p '请输入管理员密码（至少 8 位）：' ADMIN_PASSWORD
  echo
  read -r -s -p '请再次输入管理员密码：' ADMIN_PASSWORD_CONFIRM
  echo
  if [[ "$ADMIN_PASSWORD" != "$ADMIN_PASSWORD_CONFIRM" ]]; then
    echo '两次密码不一致。容器已启动，请稍后打开安装页面继续。' >&2
    exit 1
  fi
fi
if [[ "${#ADMIN_PASSWORD}" -lt 8 ]]; then
  echo '管理员密码至少需要 8 位。' >&2
  exit 1
fi

PAYLOAD="$(${COMPOSE[@]} exec -T \
  -e TF_SITE_URL="$SITE_URL" \
  -e TF_ADMIN_USERNAME="$ADMIN_USERNAME" \
  -e TF_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
  backend php -r '
    $site = getenv("TF_SITE_URL") ?: "http://localhost:8080";
    $data = [
        "site_url" => $site,
        "session_secure" => str_starts_with($site, "https://"),
        "database" => [
            "host" => "mysql",
            "port" => 3306,
            "name" => getenv("DB_NAME") ?: "tf_sign",
            "user" => getenv("DB_USER") ?: "tf_sign",
            "password" => getenv("DB_PASSWORD") ?: "",
            "socket" => "",
        ],
        "admin" => [
            "username" => getenv("TF_ADMIN_USERNAME") ?: "admin",
            "password" => getenv("TF_ADMIN_PASSWORD") ?: "",
            "display_name" => "系统管理员",
        ],
    ];
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  ' )"

echo '正在执行数据库初始化和管理员创建……'
if ! RESPONSE="$(curl -fsS "$BASE_URL/api/install" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  --data-binary "$PAYLOAD")"; then
  echo '自动安装失败，请打开安装页面查看详细错误：' >&2
  echo "  $BASE_URL/install" >&2
  exit 1
fi
echo "$RESPONSE"

"${COMPOSE[@]}" restart backend >/dev/null
echo
echo '安装完成。'
echo "访问地址：$BASE_URL"
echo "管理员账号：$ADMIN_USERNAME"
echo '后端已重启以加载新生成的密钥和进程配置。'
