#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$SCRIPT_DIR/install.sh" ]]; then
  exec bash "$SCRIPT_DIR/install.sh" "$@"
fi

TEMP_SCRIPT="$(mktemp)"
trap 'rm -f "$TEMP_SCRIPT"' EXIT
curl -fsSL --retry 3 \
  'https://raw.githubusercontent.com/longfie/TFcloud/main/scripts/install.sh' \
  -o "$TEMP_SCRIPT"
exec bash "$TEMP_SCRIPT" "$@"
