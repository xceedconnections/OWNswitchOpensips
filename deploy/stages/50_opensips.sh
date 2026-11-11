#!/usr/bin/env bash
# Stage 50 - OpenSIPS 3.6 SIP edge (UDP/TCP SIP_PORT) + MySQL schemas + baseline cfg.
# Packages from apt.opensips.org; config from deploy/templates/opensips.cfg.tpl.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "50_opensips"

mysql_root -e 'SELECT 1' >/dev/null 2>&1 || die "MySQL not reachable - run stage 30 first"
ensure_base_layout

# ---------------------------------------------------------------------------
# 1. OpenSIPS apt repository (3.6 / noble)
# ---------------------------------------------------------------------------
SERIES="${OPENSIPS_VERSION_SERIES:-3.6}"
KEYRING=/usr/share/keyrings/opensips-org.gpg
LIST=/etc/apt/sources.list.d/opensips.list
if [[ ! -f "$KEYRING" ]]; then
  log_info "Adding OpenSIPS apt signing key"
  curl -fsSL https://apt.opensips.org/pubkey.gpg | gpg --dearmor -o "$KEYRING"
  note_change "installed OpenSIPS apt key"
fi
desired="deb [signed-by=${KEYRING}] https://apt.opensips.org noble ${SERIES}-releases"
if [[ ! -f "$LIST" ]] || ! grep -qxF "$desired" "$LIST" 2>/dev/null; then
  printf '%s\n' "$desired" >"$LIST"
  note_change "wrote ${LIST}"
  apt_update
fi

# ---------------------------------------------------------------------------
# 2. Packages
# ---------------------------------------------------------------------------
# auth.so is in opensips-auth-modules (not the base package).
PKGS=(
  opensips
  opensips-cli
  opensips-mysql-module
  opensips-mysql-dbschema
  opensips-auth-modules
  opensips-http-modules
  opensips-presence-modules
  opensips-cgrates-module
  opensips-nathelper-module
)
apt_install "${PKGS[@]}"
# rtpengine / drouting / dialplan / websocket package names vary by OpenSIPS build
apt_install_optional opensips-rtpengine-module opensips-db-modules opensips-dialplan-module \
  opensips-uac-module opensips-tls-module opensips-websocket-module \
  opensips-http-module opensips-json-module opensips-restclient-module \
  opensips-prometheus-module opensips-cachedb-redis-module opensips-module-cachedb-redis \
  opensips-exec-module opensips-module-exec

# ---------------------------------------------------------------------------
# 3. Module path + required .so
# ---------------------------------------------------------------------------
MOD_SO="$(find /usr/lib /usr/lib64 -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1 || true)"
[[ -n "$MOD_SO" ]] || die "OpenSIPS modules directory not found after package install"
OPENSIPS_MPATH="$(dirname "$MOD_SO")/"
export OPENSIPS_MPATH
log_info "OPENSIPS_MPATH=${OPENSIPS_MPATH}"
for so in db_mysql.so auth.so auth_db.so usrloc.so registrar.so dialog.so permissions.so \
          drouting.so acc.so mi_fifo.so proto_udp.so proto_tcp.so signaling.so \
          sl.so tm.so rr.so maxfwd.so sipmsgops.so; do
  [[ -f "${OPENSIPS_MPATH}${so}" ]] || die "Missing required module: ${OPENSIPS_MPATH}${so}"
done
if [[ ! -f "${OPENSIPS_MPATH}rtpengine.so" ]]; then
  die "Missing rtpengine.so — install opensips-rtpengine-module (required for media relay/record)"
fi
if [[ ! -f "${OPENSIPS_MPATH}cgrates.so" ]]; then
  log_warn "cgrates.so missing — billing auth in opensips.cfg may fail until opensips-cgrates-module is installed"
fi
# Optional but preferred
HAVE_MI_HTTP=0
[[ -f "${OPENSIPS_MPATH}mi_http.so" || -f "${OPENSIPS_MPATH}httpd.so" ]] && HAVE_MI_HTTP=1
HAVE_NATHELPER=0
[[ -f "${OPENSIPS_MPATH}nathelper.so" ]] && HAVE_NATHELPER=1
HAVE_UAC=0
[[ -f "${OPENSIPS_MPATH}uac_registrant.so" || -f "${OPENSIPS_MPATH}uac.so" ]] && HAVE_UAC=1
HAVE_ALIAS_DB=0
[[ -f "${OPENSIPS_MPATH}alias_db.so" ]] && HAVE_ALIAS_DB=1
HAVE_EXEC=0
[[ -f "${OPENSIPS_MPATH}exec.so" ]] && HAVE_EXEC=1
HAVE_CGRATES=0
[[ -f "${OPENSIPS_MPATH}cgrates.so" ]] && HAVE_CGRATES=1

# ---------------------------------------------------------------------------
# 4. MySQL schemas for OpenSIPS modules
# ---------------------------------------------------------------------------
MYSQL_OPENSIPS_PASSWORD="$(get_secret mysql_opensips_password)"
apply_schema() { # module_hint table_name
  local hint=$1 table=$2 f
  if mysql_scalar "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_OPENSIPS}' AND table_name='${table}'" | grep -q 1; then
    return 0
  fi
  f="$(find /usr/share/opensips /usr/share/opensips-mysql-module /usr/share/doc \
    -type f \( -iname "${hint}-create.sql" -o -iname "*${hint}*create*.sql" \) 2>/dev/null | head -n1 || true)"
  if [[ -n "${f:-}" ]]; then
    log_info "Applying schema ${f} → ${DB_OPENSIPS}.${table}"
    mysql_root "${DB_OPENSIPS}" <"$f" || log_warn "schema ${f} returned non-zero"
    note_change "opensips schema ${hint}"
  else
    log_warn "No create SQL found for ${hint} (need table ${table})"
  fi
}
apply_schema standard version
apply_schema standard subscriber || apply_schema auth_db subscriber
apply_schema usrloc location
apply_schema dialog dialog
apply_schema permissions address
apply_schema drouting dr_gateways
apply_schema acc acc
apply_schema alias_db dbaliases || true
apply_schema uac registrant || apply_schema uac_registrant registrant || true

# Extra columns used by Ownswitch provisioner / auth_db load_credentials
mysql_root "${DB_OPENSIPS}" <<'SQL' || true
ALTER TABLE subscriber ADD COLUMN IF NOT EXISTS password VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE subscriber ADD COLUMN IF NOT EXISTS media_policy VARCHAR(32) DEFAULT NULL;
ALTER TABLE subscriber ADD COLUMN IF NOT EXISTS dtmf_pass TINYINT(1) DEFAULT 1;
ALTER TABLE subscriber ADD COLUMN IF NOT EXISTS cid_mode VARCHAR(32) DEFAULT NULL;
ALTER TABLE subscriber ADD COLUMN IF NOT EXISTS rpid VARCHAR(64) DEFAULT NULL;
ALTER TABLE subscriber ADD COLUMN IF NOT EXISTS cid_min_len INT DEFAULT NULL;
ALTER TABLE subscriber ADD COLUMN IF NOT EXISTS cid_max_len INT DEFAULT NULL;
ALTER TABLE subscriber ADD COLUMN IF NOT EXISTS allow_calls TINYINT(1) DEFAULT 1;
ALTER TABLE subscriber ADD COLUMN IF NOT EXISTS dst_format VARCHAR(32) DEFAULT NULL;
ALTER TABLE subscriber ADD COLUMN IF NOT EXISTS dnc_group_id INT DEFAULT NULL;
SQL

# ---------------------------------------------------------------------------
# 5. Runtime dirs + placeholders for opensips.cfg.tpl
# ---------------------------------------------------------------------------
install -d -m 0755 -o opensips -g opensips /run/opensips /var/run/opensips 2>/dev/null || \
  install -d -m 0755 /run/opensips
# URL-encode password for mysql:// (minimal: escape : @ /)
_pw_enc="$(python3 -c 'import urllib.parse,os; print(urllib.parse.quote(os.environ["P"], safe=""))' \
  P="${MYSQL_OPENSIPS_PASSWORD}" 2>/dev/null || printf '%s' "${MYSQL_OPENSIPS_PASSWORD}")"
export OPENSIPS_DB_URL="mysql://opensips:${_pw_enc}@127.0.0.1/${DB_OPENSIPS}"
export OPENSIPS_FIFO="${OPENSIPS_FIFO:-/run/opensips/opensips_fifo}"
export OPENSIPS_UDP_WORKERS="${OPENSIPS_UDP_WORKERS:-8}"
export CGR_BIJSON_ADDR="${CGR_BIJSON_ADDR:-127.0.0.1:2014}"
export RTPENGINE_SOCK="${RTPENGINE_SOCK:-udp:127.0.0.1:${RTPENGINE_NG_PORT:-22222}}"
export FAILOVER_SIP_STATUS="${FAILOVER_SIP_STATUS:-408|480|486|500|502|503|504}"

# Advertise public IP on sockets (NAT)
if [[ -n "${PUBLIC_IP}" && -n "${PRIVATE_IP}" && "${PUBLIC_IP}" != "${PRIVATE_IP}" ]]; then
  export SIP_SOCKET_UDP="udp:${PRIVATE_IP}:${SIP_PORT} as ${PUBLIC_IP}:${SIP_PORT}"
  export SIP_SOCKET_TCP="tcp:${PRIVATE_IP}:${SIP_PORT} as ${PUBLIC_IP}:${SIP_PORT}"
else
  export SIP_SOCKET_UDP="udp:0.0.0.0:${SIP_PORT} as ${PUBLIC_IP:-0.0.0.0}:${SIP_PORT}"
  export SIP_SOCKET_TCP="tcp:0.0.0.0:${SIP_PORT} as ${PUBLIC_IP:-0.0.0.0}:${SIP_PORT}"
fi

OPENSIPS_EXTRA_ALIASES=""
for ip in $(hostname -I 2>/dev/null || true); do
  [[ "$ip" == "${PUBLIC_IP}" ]] && continue
  [[ "$ip" == "${OWNSWITCH_DOMAIN}" ]] && continue
  OPENSIPS_EXTRA_ALIASES="${OPENSIPS_EXTRA_ALIASES}alias=\"${ip}\"
"
done
export OPENSIPS_EXTRA_ALIASES

if (( HAVE_MI_HTTP )); then
  export OPENSIPS_MI_HTTP_BLOCK='
loadmodule "httpd.so"
modparam("httpd", "ip", "127.0.0.1")
modparam("httpd", "port", 8888)
loadmodule "mi_http.so"
'
else
  export OPENSIPS_MI_HTTP_BLOCK='# mi_http not installed'
fi

if (( HAVE_NATHELPER )); then
  export OPENSIPS_NATHELPER_BLOCK='
loadmodule "nathelper.so"
modparam("nathelper", "natping_interval", 30)
modparam("nathelper", "ping_nated_only", 1)
modparam("nathelper", "sipping_from", "sip:ping@'"${OWNSWITCH_DOMAIN}"'")
'
  export OPENSIPS_NAT_REGISTER_BLOCK='if (nat_uac_test("19")) { fix_nated_register(); setbflag(NAT_BFLAG); }'
else
  export OPENSIPS_NATHELPER_BLOCK='# nathelper not installed'
  export OPENSIPS_NAT_REGISTER_BLOCK=''
fi

if (( HAVE_UAC )); then
  export OPENSIPS_UAC_BLOCK='
loadmodule "uac.so"
loadmodule "uac_registrant.so"
modparam("uac_registrant", "db_url", "'"${OPENSIPS_DB_URL}"'")
'
else
  export OPENSIPS_UAC_BLOCK='# uac_registrant not installed — use Auth mode = IP peer for trunks'
fi

if (( HAVE_ALIAS_DB )); then
  export OPENSIPS_ALIAS_DB_BLOCK='
loadmodule "alias_db.so"
modparam("alias_db", "db_url", "'"${OPENSIPS_DB_URL}"'")
'
  export OPENSIPS_ALIAS_LOOKUP='alias_db_lookup("dbaliases");'
else
  export OPENSIPS_ALIAS_DB_BLOCK='# alias_db not installed'
  export OPENSIPS_ALIAS_LOOKUP=''
fi

if (( HAVE_EXEC )); then
  export OPENSIPS_EXEC_BLOCK='
loadmodule "exec.so"
'
else
  export OPENSIPS_EXEC_BLOCK='# exec not installed'
fi

# DNC / rate-cap route bodies are optional; empty keeps cfg valid
export OPENSIPS_DNC_MODULES="${OPENSIPS_DNC_MODULES:-}"
export OPENSIPS_DNC_ROUTE="${OPENSIPS_DNC_ROUTE:-}"
export OPENSIPS_RATE_CAP_ROUTE="${OPENSIPS_RATE_CAP_ROUTE:-}"

if ! (( HAVE_CGRATES )); then
  log_warn "opensips-cgrates-module missing — cfg still loads cgrates.so; install package or strip module later"
fi

# ---------------------------------------------------------------------------
# 6. Render + validate + activate cfg
# ---------------------------------------------------------------------------
CFG=/etc/opensips/opensips.cfg
TMP_CFG="$(mktemp)"
render_to_stdout "${TEMPLATE_DIR}/opensips.cfg.tpl" >"$TMP_CFG" \
  || { rm -f "$TMP_CFG"; die "Failed to render opensips.cfg.tpl"; }

if ! opensips -c -f "$TMP_CFG" >/tmp/opensips-cfg-check.out 2>&1; then
  log_error "opensips -c failed:"
  cat /tmp/opensips-cfg-check.out >&2 || true
  rm -f "$TMP_CFG"
  die "Refusing to install invalid opensips.cfg"
fi

backup_file "$CFG"
install -m 0640 -o root -g opensips "$TMP_CFG" "$CFG"
rm -f "$TMP_CFG"
note_change "wrote ${CFG}"

# Ensure unit enabled; prefer packaged systemd unit
systemctl daemon-reload || true
systemctl enable opensips >/dev/null 2>&1 || true
# Free port 5060 if something else still holds it (e.g. leftover FreeSWITCH)
if ss -H -ulnp "sport = :${SIP_PORT}" 2>/dev/null | grep -q freeswitch; then
  log_warn "Stopping FreeSWITCH so OpenSIPS can bind ${SIP_PORT}"
  systemctl stop freeswitch 2>/dev/null || true
  systemctl disable freeswitch 2>/dev/null || true
fi
svc_apply opensips 1
sleep 1
systemctl is-active --quiet opensips || {
  journalctl -u opensips -n 40 --no-pager >&2 || true
  die "OpenSIPS failed to start"
}

wait_for_tcp 127.0.0.1 "${SIP_PORT}" 30 || log_warn "SIP ${SIP_PORT}/tcp not yet listening (UDP-only is OK)"
if (( HAVE_MI_HTTP )); then
  wait_for_tcp 127.0.0.1 8888 20 || log_warn "MI HTTP 8888 not listening"
fi

persist_setting OPENSIPS_VERSION_SERIES "${SERIES}"
log_ok "OpenSIPS ${SERIES} active on SIP ${SIP_PORT}; MI fifo=${OPENSIPS_FIFO}"
record_result "${STAGE_FILE}" "OK" "OpenSIPS ${SERIES} on :${SIP_PORT}"
exit 0
