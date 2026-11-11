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
# OpenSIPS 3.6 noble apt: core modules (dialog, drouting, rtpengine, nathelper,
# uac, cfgutils, proto_udp/tcp/ws, …) ship inside the base "opensips" package.
# Separate debs exist only for modules with heavy/extra deps — see apt.opensips.org.
# There is NO opensips-nathelper-module / opensips-rtpengine-module / opensips-websocket-module.
PKGS=(
  opensips
  opensips-cli
  opensips-mysql-module
  opensips-mysql-dbschema
  opensips-auth-modules
  opensips-http-modules
  opensips-cgrates-module
)
apt_install "${PKGS[@]}"
# Optional extras that exist on apt.opensips.org (each tried separately)
apt_install_optional opensips-dialplan-module opensips-tls-module opensips-wss-module \
  opensips-json-module opensips-restclient-module opensips-prometheus-module \
  opensips-redis-module opensips-presence-modules

# ---------------------------------------------------------------------------
# 3. Module path + required .so
# ---------------------------------------------------------------------------
MOD_SO="$(find /usr/lib /usr/lib64 -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1 || true)"
[[ -n "$MOD_SO" ]] || die "OpenSIPS modules directory not found after package install"
OPENSIPS_MPATH="$(dirname "$MOD_SO")/"
export OPENSIPS_MPATH
log_info "OPENSIPS_MPATH=${OPENSIPS_MPATH}"
# proto_udp / proto_tcp are compiled into the OpenSIPS 3.6 binary on apt.opensips.org
# (loadmodule "proto_udp.so" still works; there is no .so file on disk).
for so in db_mysql.so auth.so auth_db.so usrloc.so registrar.so dialog.so permissions.so \
          drouting.so acc.so mi_fifo.so signaling.so \
          sl.so tm.so rr.so maxfwd.so sipmsgops.so cfgutils.so; do
  [[ -f "${OPENSIPS_MPATH}${so}" ]] || die "Missing required module: ${OPENSIPS_MPATH}${so}"
done
for so in rtpengine.so nathelper.so; do
  [[ -f "${OPENSIPS_MPATH}${so}" ]] || die "Missing required module: ${OPENSIPS_MPATH}${so}"
done
# Sanity: builtin UDP transport must be loadable even without a .so file on disk
cat >/tmp/ownswitch-proto-check.cfg <<EOF
mpath="${OPENSIPS_MPATH}"
socket=udp:127.0.0.1:5098
loadmodule "proto_udp.so"
loadmodule "signaling.so"
loadmodule "sl.so"
loadmodule "tm.so"
route { sl_send_reply(200, "OK"); }
EOF
if ! opensips -c -f /tmp/ownswitch-proto-check.cfg >/tmp/ownswitch-proto-check.out 2>&1; then
  cat /tmp/ownswitch-proto-check.out >&2 || true
  die "OpenSIPS cannot load builtin proto_udp — check opensips package"
fi
rm -f /tmp/ownswitch-proto-check.cfg /tmp/ownswitch-proto-check.out
if [[ ! -f "${OPENSIPS_MPATH}cgrates.so" ]]; then
  log_warn "cgrates.so missing — billing auth in opensips.cfg may fail until opensips-cgrates-module is installed"
fi
# Optional but preferred
HAVE_MI_HTTP=0
[[ -f "${OPENSIPS_MPATH}mi_http.so" || -f "${OPENSIPS_MPATH}httpd.so" ]] && HAVE_MI_HTTP=1
HAVE_NATHELPER=0
[[ -f "${OPENSIPS_MPATH}nathelper.so" ]] && HAVE_NATHELPER=1
HAVE_UAC=0
[[ -f "${OPENSIPS_MPATH}uac_registrant.so" ]] && [[ -f "${OPENSIPS_MPATH}uac_auth.so" ]] && HAVE_UAC=1
HAVE_ALIAS_DB=0
[[ -f "${OPENSIPS_MPATH}alias_db.so" ]] && HAVE_ALIAS_DB=1
HAVE_EXEC=0
[[ -f "${OPENSIPS_MPATH}exec.so" ]] && HAVE_EXEC=1
HAVE_CGRATES=0
[[ -f "${OPENSIPS_MPATH}cgrates.so" ]] && HAVE_CGRATES=1
HAVE_PROTO_WS=0
[[ -f "${OPENSIPS_MPATH}proto_ws.so" ]] && HAVE_PROTO_WS=1

# ---------------------------------------------------------------------------
# 4. MySQL schemas for OpenSIPS modules
# ---------------------------------------------------------------------------
MYSQL_OPENSIPS_PASSWORD="$(get_secret mysql_opensips_password)"
apply_schema() { # module_hint table_name
  local hint=$1 table=$2 f
  if mysql_scalar "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_OPENSIPS}' AND table_name='${table}'" | grep -q 1; then
    return 0
  fi
  # Prefer exact ${hint}-create.sql under the packaged mysql schema dir
  f="$(find /usr/share/opensips/mysql /usr/share/opensips /usr/share/opensips-mysql-module \
    -type f -iname "${hint}-create.sql" 2>/dev/null | head -n1 || true)"
  if [[ -z "${f:-}" ]]; then
    f="$(find /usr/share/opensips /usr/share/opensips-mysql-module /usr/share/doc \
      -type f -iname "*${hint}*create*.sql" 2>/dev/null | head -n1 || true)"
  fi
  if [[ -n "${f:-}" ]]; then
    log_info "Applying schema ${f} → ${DB_OPENSIPS}.${table}"
    mysql_root "${DB_OPENSIPS}" <"$f" || log_warn "schema ${f} returned non-zero"
    note_change "opensips schema ${hint}"
  else
    log_warn "No create SQL found for ${hint} (need table ${table})"
  fi
  # Return success only when the expected table now exists (MySQL has no ADD IF NOT EXISTS;
  # re-applying standard-create.sql fails on version and must not mask missing subscriber).
  if mysql_scalar "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_OPENSIPS}' AND table_name='${table}'" | grep -q 1; then
    return 0
  fi
  return 1
}
apply_schema standard version || true
apply_schema auth_db subscriber || apply_schema standard subscriber || log_warn "subscriber schema missing"
apply_schema usrloc location || true
apply_schema dialog dialog || true
apply_schema permissions address || true
apply_schema drouting dr_gateways || true
apply_schema acc acc || true
apply_schema alias_db dbaliases || true
apply_schema registrant registrant || apply_schema uac_registrant registrant || true

# Extra columns used by Ownswitch provisioner / auth_db load_credentials.
# MySQL 8.x does NOT support "ADD COLUMN IF NOT EXISTS" (that is MariaDB-only).
ensure_column() {
  local table=$1 column=$2 ddl=$3
  if mysql_scalar "SELECT 1 FROM information_schema.columns WHERE table_schema='${DB_OPENSIPS}' AND table_name='${table}' AND column_name='${column}'" | grep -q 1; then
    return 0
  fi
  if ! mysql_scalar "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_OPENSIPS}' AND table_name='${table}'" | grep -q 1; then
    log_warn "skip column ${table}.${column}: table missing"
    return 0
  fi
  log_info "Adding column ${DB_OPENSIPS}.${table}.${column}"
  mysql_root "${DB_OPENSIPS}" -e "ALTER TABLE \`${table}\` ADD COLUMN ${ddl}" || log_warn "ALTER ${table}.${column} failed"
}
# Avoid backticks around column names — bash treats them as command substitution.
ensure_column subscriber password "password VARCHAR(64) NOT NULL DEFAULT ''"
ensure_column subscriber media_policy 'media_policy VARCHAR(32) DEFAULT NULL'
ensure_column subscriber dtmf_pass 'dtmf_pass TINYINT(1) DEFAULT 1'
ensure_column subscriber cid_mode 'cid_mode VARCHAR(32) DEFAULT NULL'
ensure_column subscriber rpid 'rpid VARCHAR(64) DEFAULT NULL'
ensure_column subscriber cid_min_len 'cid_min_len INT DEFAULT NULL'
ensure_column subscriber cid_max_len 'cid_max_len INT DEFAULT NULL'
ensure_column subscriber allow_calls 'allow_calls TINYINT(1) DEFAULT 1'
ensure_column subscriber dst_format 'dst_format VARCHAR(32) DEFAULT NULL'
ensure_column subscriber dnc_group_id 'dnc_group_id INT DEFAULT NULL'
ensure_column acc talk 'talk INT UNSIGNED DEFAULT NULL'
ensure_column missed_calls talk 'talk INT UNSIGNED DEFAULT NULL'

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
  # OpenSIPS 3.4+: nat_uac_test() takes named flag strings (not legacy bitmask ints).
  # received_avp is required for fix_nated_register(); usrloc nat_bflag persists the branch flag.
  export OPENSIPS_NATHELPER_BLOCK='
loadmodule "nathelper.so"
modparam("nathelper", "natping_interval", 30)
modparam("nathelper", "ping_nated_only", 1)
modparam("nathelper", "received_avp", "$avp(received)")
modparam("nathelper", "sipping_from", "sip:ping@'"${OWNSWITCH_DOMAIN}"'")
modparam("usrloc", "nat_bflag", "NAT_BFLAG")
modparam("registrar", "received_avp", "$avp(received)")
'
  export OPENSIPS_NAT_REGISTER_BLOCK='force_rport();
		if (nat_uac_test("private-contact,diff-ip-src-via,diff-port-src-via")) {
			fix_nated_register();
			setbflag("NAT_BFLAG");
		}'
else
  export OPENSIPS_NATHELPER_BLOCK='# nathelper not installed'
  export OPENSIPS_NAT_REGISTER_BLOCK=''
fi

if (( HAVE_UAC )); then
  # OpenSIPS 3.6: uac_registrant depends on uac_auth (not legacy uac.so alone).
  export OPENSIPS_UAC_BLOCK='
loadmodule "uac_auth.so"
loadmodule "uac_registrant.so"
modparam("uac_registrant", "db_url", "'"${OPENSIPS_DB_URL}"'")
'
else
  export OPENSIPS_UAC_BLOCK='# uac_registrant/uac_auth not installed — use Auth mode = IP peer for trunks'
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

# DNC / rate-cap: template always calls these routes — provide no-op stubs so
# OpenSIPS can start. Real Redis/SQL DNC is applied later by fix-opensips-dnc.sh
# / portal when cachedb_redis or sqlops is available.
export OPENSIPS_DNC_MODULES="${OPENSIPS_DNC_MODULES:-}"
if [[ -z "${OPENSIPS_DNC_ROUTE:-}" ]]; then
  export OPENSIPS_DNC_ROUTE='route[check_sip_dnc] {
	return;
}
route[check_dnc] {
	return;
}'
fi
if [[ -z "${OPENSIPS_RATE_CAP_ROUTE:-}" ]]; then
  export OPENSIPS_RATE_CAP_ROUTE='route[check_rate_cap] {
	return;
}'
fi

if ! (( HAVE_CGRATES )); then
  log_warn "opensips-cgrates-module missing — cfg still loads cgrates.so; install package or strip module later"
fi

# WebRTC (proto_ws) — enabled by default when the module package is present
if (( HAVE_PROTO_WS )); then
  export OPENSIPS_WS_MODULE_BLOCK='loadmodule "proto_ws.so"'
  export OPENSIPS_WS_SOCKET_BLOCK='socket=ws:127.0.0.1:5066'
  log_info "WebRTC: proto_ws.so present — enabling ws:127.0.0.1:5066"
else
  export OPENSIPS_WS_MODULE_BLOCK='# proto_ws.so not found — install opensips (base) / opensips-wss-module for WebRTC'
  export OPENSIPS_WS_SOCKET_BLOCK='# socket=ws:127.0.0.1:5066'
  log_warn "proto_ws.so missing — WebRTC dialer needs opensips with WebSocket support (try: apt-get install -y opensips-wss-module)"
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
