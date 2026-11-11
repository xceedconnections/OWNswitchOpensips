#!/usr/bin/env bash
# =============================================================================
# Ownswitch — force-finish remaining stack after MySQL/Redis are up.
#
# Runs stages one-by-one with full logs (does not hide failures).
# Use when health still shows opensips/cgrates/nginx/php inactive.
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/OWNswitchOpensips/main/deploy/force-finish.sh \
#     | sudo bash
# =============================================================================
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
LOG="/var/log/ownswitch-force-finish.log"
export DEBIAN_FRONTEND=noninteractive
export MYSQL_INSTALL_METHOD=direct
export FORCE_MYSQL_DIRECT=1
export NON_INTERACTIVE=1
export APP_SRC="${APP_SRC:-$SRC}"
export FREESWITCH_ENABLED=0

exec > >(tee -a "$LOG") 2>&1
log() { printf '\n====[%s] %s====\n' "$(date -u +%H:%M:%S)" "$*"; }
die() { printf 'FATAL: %s\n' "$*" >&2; exit 1; }

log "Force-finish starting. Log: ${LOG}"

apt-get install -y -qq ca-certificates curl git gnupg rsync >/dev/null || true
if [[ -d "${SRC}/.git" ]]; then
  git -C "${SRC}" fetch --depth 1 origin main
  git -C "${SRC}" reset --hard origin/main
else
  git clone --depth 1 https://github.com/xceedconnections/OWNswitchOpensips.git "${SRC}"
fi
find "${SRC}/deploy" -type f -name '*.sh' -exec chmod +x {} +
[[ -f "${SRC}/public/index.php" ]] || die "public/index.php missing in ${SRC} — cannot deploy portal"

mkdir -p /etc/ownswitch
grep -q '^FREESWITCH_ENABLED=' /etc/ownswitch/install.conf 2>/dev/null \
  && sed -i 's/^FREESWITCH_ENABLED=.*/FREESWITCH_ENABLED=0/' /etc/ownswitch/install.conf \
  || echo 'FREESWITCH_ENABLED=0' >>/etc/ownswitch/install.conf
grep -q '^MYSQL_INSTALL_METHOD=' /etc/ownswitch/install.conf 2>/dev/null \
  && sed -i 's/^MYSQL_INSTALL_METHOD=.*/MYSQL_INSTALL_METHOD=direct/' /etc/ownswitch/install.conf \
  || echo 'MYSQL_INSTALL_METHOD=direct' >>/etc/ownswitch/install.conf

systemctl is-active --quiet mysql || die "MySQL is not active — run repair-and-continue.sh first"
systemctl is-active --quiet redis-server || {
  log "Starting redis…"
  bash "${SRC}/deploy/install.sh" --only 40 --yes --non-interactive
}
mysql --protocol=socket -uroot -e 'SELECT 1' >/dev/null

# Free 5060 if leftover FreeSWITCH still owns it
if ss -H -ulnp 'sport = :5060' 2>/dev/null | grep -q freeswitch; then
  log "Stopping leftover FreeSWITCH so OpenSIPS can bind 5060"
  systemctl disable --now freeswitch 2>/dev/null || true
fi

run_stage() {
  local n=$1
  log "Running stage ${n}"
  if bash "${SRC}/deploy/install.sh" --only "${n}" --yes --non-interactive; then
    log "Stage ${n} OK"
    return 0
  fi
  log "Stage ${n} FAILED — collecting diagnostics"
  case "$n" in
    50) journalctl -u opensips -n 80 --no-pager || true
        opensips -c -f /etc/opensips/opensips.cfg 2>&1 || true
        ls /etc/apt/sources.list.d/opensips* 2>/dev/null || true
        ;;
    60) journalctl -u cgrates -n 80 --no-pager || true
        [[ -x /usr/local/bin/cgr-engine ]] && /usr/local/bin/cgr-engine -version || true
        ls -la /usr/share/cgrates/storage/mysql 2>/dev/null || true
        ;;
    70) journalctl -u 'rtpengine*' -n 40 --no-pager || true ;;
    80) journalctl -u nginx -n 40 --no-pager || true
        journalctl -u php8.3-fpm -n 40 --no-pager || true
        nginx -t 2>&1 || true
        php -v 2>&1 || true
        ;;
    90) ls -la /opt/ownswitch/current/public/index.php 2>/dev/null || true
        ls -la "${SRC}/public/index.php" || true
        ;;
  esac
  return 1
}

FAILED=()
for n in 50 60 70 80 90 95; do
  if ! run_stage "$n"; then
    FAILED+=("$n")
  fi
done

log "Force-starting installed services…"
for u in opensips cgrates php8.3-fpm nginx; do
  if systemctl list-unit-files --no-legend "$u.service" 2>/dev/null | grep -q .; then
    systemctl enable "$u" >/dev/null 2>&1 || true
    systemctl restart "$u" >/dev/null 2>&1 || journalctl -u "$u" -n 30 --no-pager || true
  fi
done
for u in rtpengine rtpengine-daemon ngcp-rtpengine-daemon; do
  systemctl restart "$u" >/dev/null 2>&1 || true
done
systemctl enable --now ownswitch-scheduler.timer ownswitch-backup.timer >/dev/null 2>&1 || true
systemctl enable --now ownswitch-worker >/dev/null 2>&1 || true

log "Health report…"
bash "${SRC}/deploy/install.sh" --only 99 --yes --non-interactive || true

if ((${#FAILED[@]})); then
  die "Stages failed: ${FAILED[*]} — see ${LOG}"
fi
log "Force-finish complete"
exit 0
