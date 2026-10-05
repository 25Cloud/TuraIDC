#!/usr/bin/env bash
# 官网（frontend-user-v3-www）手动部署到 192.168.5.100
#
# 本地构建 → 打包上传 → 服务器单次 sudo 执行交换脚本。
# 纯静态文件，不需要 nginx reload。
#
# 用法：
#   bash deploy/deploy-www.sh [--dry-run] [--rollback]
set -euo pipefail

TARGET="shanshui@192.168.5.100"
PROJ_DIR="/opt/turaidc/frontend-user-v3-www"
PLINK="${PLINK:-/c/Program Files/PuTTY/plink}"
NODE="${NODE:-C:/Users/Shanshui2023/.workbuddy/binaries/node/versions/22.22.2-3/node.exe}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROJ="$REPO_ROOT/frontend-user-v3-www"
OUT_DIR="$PROJ/dist-deploy"
KEEP_BACKUPS=3

: "${DEPLOY_SSH_PW:=Zhangdingbo2008}"
: "${DEPLOY_SUDO_PW:=$DEPLOY_SSH_PW}"

DRY_RUN=0
ROLLBACK=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --rollback) ROLLBACK=1 ;;
    *) echo "未知参数: $arg" >&2; exit 2 ;;
  esac
done

log()  { printf '\033[36m[deploy]\033[0m %s\n' "$*"; }
warn() { printf '\033[33m[deploy]\033[0m %s\n' "$*"; }
die()  { printf '\033[31m[deploy]\033[0m %s\n' "$*" >&2; exit 1; }

[ -f "$PLINK" ] || die "找不到 plink: $PLINK （可用 PLINK 环境变量覆盖）"

# 在服务器上以 root 执行一段脚本。
# 脚本内容直接经 stdin 送到远端 `cat > 文件`，不落本地临时文件
# （Windows 的 mktemp 返回 C:\ 路径，会被本地安全删除 shim 拦掉）。
# 密码走独立管道：sudo -S 会读空 stdin，所以必须先传脚本、后另喂密码。
remote_root() {
  local script="$1"
  local sftp="/tmp/.deploy-run-$$.sh"

  "$PLINK" -ssh -pw "$DEPLOY_SSH_PW" -batch "$TARGET" "cat > $sftp" <<<"$script" \
    || die "上传远程脚本失败"

  echo "$DEPLOY_SUDO_PW" \
    | "$PLINK" -ssh -pw "$DEPLOY_SSH_PW" -batch "$TARGET" "sudo -S -p '' bash $sftp"
  local rc=$?
  remote_plain "rm -f $sftp" >/dev/null 2>&1 || true
  return $rc
}

# 在服务器上以 shanshui 身份执行（只读操作用）
remote_plain() {
  "$PLINK" -ssh -pw "$DEPLOY_SSH_PW" -batch "$TARGET" "$1" </dev/null
}

# ---------------------------------------------------------------- rollback
if [ "$ROLLBACK" -eq 1 ]; then
  log "查找可回滚的备份…"
  BAK="$(remote_plain "ls -1dt $PROJ_DIR/dist.bak-* 2>/dev/null | head -1" | tr -d '\r')"
  [ -n "$BAK" ] || die "没有可用的 dist.bak-* 备份"
  log "将回滚：$(basename "$BAK") → dist"
  [ "$DRY_RUN" -eq 1 ] && { log "dry-run，跳过"; exit 0; }

  remote_root "
set -e
[ -d '$BAK' ] || { echo '备份不存在'; exit 1; }
mv '$PROJ_DIR/dist' \"\$PROJ_DIR/dist.failed-\$(date +%s)\" 2>/dev/null || true
mv '$BAK' '$PROJ_DIR/dist'
chown -R www-data:www-data '$PROJ_DIR/dist'
nginx -t
"
  log "回滚完成"
  exit 0
fi

# ------------------------------------------------------------------- build
log "本地构建 www → $(basename "$OUT_DIR")"
cd "$PROJ"
"$NODE" node_modules/vite/bin/vite.js build --mode production \
  --outDir "$(basename "$OUT_DIR")" --emptyOutDir false >/tmp/www-build.log 2>&1 \
  || { warn "构建失败，日志尾部："; tail -20 /tmp/www-build.log; exit 1; }
tail -3 /tmp/www-build.log | sed 's/^/[build] /'

[ -f "$OUT_DIR/index.html" ] || die "构建产物缺少 index.html"

if [ "$DRY_RUN" -eq 1 ]; then
  log "dry-run：将推送 $(find "$OUT_DIR" -type f | wc -l) 个文件（不实际上传）"
  exit 0
fi

# -------------------------------------------------------------------- push
TS="$(date +%Y%m%d-%H%M%S)"
TARBALL="/tmp/www-dist-$TS.tar.gz"
log "打包并上传…"
tar -czf "$TARBALL" -C "$OUT_DIR" .
# 注意：这里不能用 </dev/null，否则会覆盖掉 < "$TARBALL"，推送变成 0 字节
"$PLINK" -ssh -pw "$DEPLOY_SSH_PW" -batch "$TARGET" "cat > $TARBALL" < "$TARBALL"
log "上传完成（$(du -h "$TARBALL" | cut -f1)），执行原子替换…"

# -------------------------------------------------------------------- swap
remote_root "
set -e
PROJ='$PROJ_DIR'
TARBALL='$TARBALL'
TS='$TS'

rm -rf \"\$PROJ/dist.new\"
mkdir -p \"\$PROJ/dist.new\"
tar -xzf \"\$TARBALL\" -C \"\$PROJ/dist.new\"
chown -R www-data:www-data \"\$PROJ/dist.new\"

# 产物完整性校验：缺关键文件就中止，不动线上 dist
[ -f \"\$PROJ/dist.new/index.html\" ] || { echo '新产物缺 index.html，中止'; exit 1; }
[ -d \"\$PROJ/dist.new/assets\" ]     || { echo '新产物缺 assets 目录，中止'; exit 1; }

if [ -d \"\$PROJ/dist\" ]; then
  mv \"\$PROJ/dist\" \"\$PROJ/dist.bak-\$TS\"
fi
mv \"\$PROJ/dist.new\" \"\$PROJ/dist\"

# 只保留最近 $KEEP_BACKUPS 份备份
ls -1dt \"\$PROJ\"/dist.bak-* 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -rf

rm -f \"\$TARBALL\"
nginx -t
echo \"DEPLOY_OK 备份=\$(ls -1dt \$PROJ/dist.bak-* 2>/dev/null | head -1)\"
"

log "完成。验证：curl -s https://cloud.shanshui.site/ | grep -o 'assets/js/index-[^\"]*\\.js'"
