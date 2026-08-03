#!/usr/bin/env bash
set -Eeuo pipefail

# Remote one-click installer. It downloads the open-source tree and delegates
# the actual Docker setup to scripts/docker-install.sh.

INSTALL_DIR="${TF_INSTALL_DIR:-/www/docker/tfcloud}"
REPO_SLUG="${TF_REPO_SLUG:-longfie/TFcloud}"
BRANCH="${TF_BRANCH:-main}"
GITHUB_MIRROR="${TF_GITHUB_MIRROR:-}"
DOCKER_ARGS=()

usage() {
  cat <<'EOF'
TFcloud 开源版一键安装

用法：
  curl -fsSL https://raw.githubusercontent.com/longfie/TFcloud/main/scripts/install.sh | bash

选项：
  --install-dir DIR       安装目录，默认 /www/docker/tfcloud
  --repo OWNER/REPO       GitHub 仓库，默认 longfie/TFcloud
  --branch BRANCH         Git 分支，默认 main
  其他参数将传给 Docker 安装脚本，例如：
  --site-url URL          站点地址
  --port PORT              对外端口，默认 8080
  --admin-username NAME   管理员用户名
  --password PASSWORD     管理员密码；不填写时交互式输入
  --no-install             只启动容器，不自动创建管理员

示例：
  curl -fsSL https://raw.githubusercontent.com/longfie/TFcloud/main/scripts/install.sh | \
    bash -s -- --site-url https://example.com --admin-username admin
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --install-dir)
      INSTALL_DIR="${2:?缺少 --install-dir 的值}"
      shift 2
      ;;
    --repo)
      REPO_SLUG="${2:?缺少 --repo 的值}"
      shift 2
      ;;
    --branch)
      BRANCH="${2:?缺少 --branch 的值}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      DOCKER_ARGS+=("$1")
      shift
      ;;
  esac
done

if [[ -z "$INSTALL_DIR" || "$INSTALL_DIR" == "/" ]]; then
  echo '安装目录不安全，请使用一个具体目录。' >&2
  exit 2
fi
if [[ ! "$REPO_SLUG" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]; then
  echo "GitHub 仓库格式错误：$REPO_SLUG" >&2
  exit 2
fi
if [[ ! "$BRANCH" =~ ^[A-Za-z0-9_./-]+$ ]]; then
  echo "分支名格式错误：$BRANCH" >&2
  exit 2
fi
if [[ -n "$GITHUB_MIRROR" && "$GITHUB_MIRROR" != */ ]]; then
  GITHUB_MIRROR="$GITHUB_MIRROR/"
fi

for command_name in curl tar docker; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    echo "未找到 $command_name，请先安装 Docker 和基础工具。" >&2
    exit 1
  fi
done
if ! docker compose version >/dev/null 2>&1; then
  echo '未找到 Docker Compose v2，请先在宝塔 Docker 中安装 Compose。' >&2
  exit 1
fi

DIRECT_SOURCE_URL="https://github.com/${REPO_SLUG}/archive/refs/heads/${BRANCH}.tar.gz"
SOURCE_URL="${GITHUB_MIRROR}${DIRECT_SOURCE_URL}"
TEMP_DIR="$(mktemp -d)"
cleanup() {
  rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

echo "正在下载 TFcloud 开源版：$REPO_SLUG@$BRANCH"
curl -fL --retry 3 --connect-timeout 15 "$SOURCE_URL" -o "$TEMP_DIR/source.tar.gz"
tar -xzf "$TEMP_DIR/source.tar.gz" -C "$TEMP_DIR"

ARCHIVE_ROOT="$(find "$TEMP_DIR" -mindepth 1 -maxdepth 1 -type d -print -quit)"
if [[ -z "$ARCHIVE_ROOT" ]]; then
  echo '无法识别下载的源代码目录。' >&2
  exit 1
fi

if [[ -f "$ARCHIVE_ROOT/open-source/docker-compose.yml" ]]; then
  SOURCE_ROOT="$ARCHIVE_ROOT/open-source"
elif [[ -f "$ARCHIVE_ROOT/docker-compose.yml" ]]; then
  SOURCE_ROOT="$ARCHIVE_ROOT"
else
  echo '下载内容中没有找到 open-source/docker-compose.yml。' >&2
  exit 1
fi

mkdir -p "$INSTALL_DIR"
cp -a "$SOURCE_ROOT/." "$INSTALL_DIR/"

echo "程序文件已准备：$INSTALL_DIR"
cd "$INSTALL_DIR"
if [[ ! -t 0 ]] && exec 3<>/dev/tty 2>/dev/null; then
  exec bash "$INSTALL_DIR/scripts/docker-install.sh" "${DOCKER_ARGS[@]}" <&3
fi
exec bash "$INSTALL_DIR/scripts/docker-install.sh" "${DOCKER_ARGS[@]}"
