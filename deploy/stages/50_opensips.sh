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
[[ -f "${OPENSIPS_MPATH}uac_registrant.so" ]] && [[ -f "${OPENSIPS_MPATH}uac_auth.so" ]] && [[ -f "${OPENSIPS_MPATH}uac.so" ]] && HAVE_UAC=1
HAVE_ALIAS_DB=0
[[ -f "${OPENSIPS_MPATH}alias_db.so" ]] && HAVE_ALIAS_DB=1
HAVE_EXEC=0
[[ -f "${OPENSIPS_MPATH}exec.so" ]] && HAVE_EXEC=1
HAVE_CACHEDB_REDIS=0
[[ -f "${OPENSIPS_MPATH}cachedb_redis.so" ]] && HAVE_CACHEDB_REDIS=1
HAVE_SQLOPS=0
[[ -f "${OPENSIPS_MPATH}sqlops.so" ]] && HAVE_SQLOPS=1
HAVE_CGRATES=0
[[ -f "${OPENSIPS_MPATH}cgrates.so" ]] && HAVE_CGRATES=1
HAVE_PROTO_WS=0
[[ -f "${OPENSIPS_MPATH}proto_ws.so" ]] && HAVE_PROTO_WS=1
HAVE_PIKE=0
[[ -f "${OPENSIPS_MPATH}pike.so" ]] && HAVE_PIKE=1

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
# OpenSIPS stock schema uses CHAR(25); widen so portal plaintext passwords are not truncated
# (calculate_ha1=1 reads password_column — truncated secrets break Eyebeam REGISTER).
if mysql_scalar "SELECT 1 FROM information_schema.columns WHERE table_schema='${DB_OPENSIPS}' AND table_name='subscriber' AND column_name='password' AND (data_type='char' OR (data_type='varchar' AND character_maximum_length < 64))" | grep -q 1; then
  log_info "Widening ${DB_OPENSIPS}.subscriber.password to VARCHAR(64)"
  mysql_root "${DB_OPENSIPS}" -e "ALTER TABLE \`subscriber\` MODIFY COLUMN password VARCHAR(64) NOT NULL DEFAULT ''" \
    || log_warn "ALTER subscriber.password VARCHAR(64) failed"
fi
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
# ACC extra_fields (src/dst/ip/cli/gw) — without these, CDR insert fails and reports stay empty
for _acc_tbl in acc missed_calls; do
  ensure_column "${_acc_tbl}" src_user 'src_user VARCHAR(64) NULL'
  ensure_column "${_acc_tbl}" dst_user 'dst_user VARCHAR(64) NULL'
  ensure_column "${_acc_tbl}" src_ip 'src_ip VARCHAR(45) NULL'
  ensure_column "${_acc_tbl}" cli 'cli VARCHAR(64) NULL'
  ensure_column "${_acc_tbl}" gw 'gw VARCHAR(64) NULL'
done
# Eyebeam / WebRTC Call-IDs often exceed CHAR(64)
mysql_root "${DB_OPENSIPS}" -e "ALTER TABLE acc MODIFY callid VARCHAR(128) NOT NULL DEFAULT ''; ALTER TABLE missed_calls MODIFY callid VARCHAR(128) NOT NULL DEFAULT '';" 2>/dev/null || true

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
  # timer_interval default is 100s (slow first REGISTER); keep >= hash buckets (2^hash_size).
  # uac_auth.so = AVP-based digest API; uac.so exports script function uac_auth()
  # (this OpenSIPS 3.6 package has no uac_auth "credential" modparam — use AVPs from trunk attrs)
  export OPENSIPS_UAC_BLOCK='
loadmodule "uac_auth.so"
modparam("uac_auth", "auth_username_avp", "$avp(auth_username)")
modparam("uac_auth", "auth_password_avp", "$avp(auth_password)")
modparam("uac_auth", "auth_realm_avp", "$avp(auth_realm)")
loadmodule "uac.so"
loadmodule "uac_registrant.so"
modparam("uac_registrant", "db_url", "'"${OPENSIPS_DB_URL}"'")
modparam("uac_registrant", "hash_size", 1)
modparam("uac_registrant", "timer_interval", 10)
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

# Pike DISABLED: capacity is enforced by SIP-user / trunk Channels+CPS, and illegal
# IPs are blocked by firewall (locked 5060) + fail2ban on auth failures. Dialers
# (ViciDial) must never be pike-dropped mid-burst.
export OPENSIPS_PIKE_MODULE_BLOCK='# pike disabled — use Channels/CPS + firewall/fail2ban'
export OPENSIPS_PIKE_CHECK_BLOCK='# pike disabled (Channels/CPS + firewall jail)'
log_info "pike anti-flood: disabled (limits via Channels/CPS; unknown IPs via firewall/fail2ban)"
if (( HAVE_PIKE )); then
  : # module present but intentionally not loaded
fi

# DNC hot-path (Redis preferred; sqlops fallback). Template always calls these routes.
mysql_root "${DB_OPENSIPS}" -e \
  "CREATE TABLE IF NOT EXISTS dnc_cache (
    group_id INT UNSIGNED NOT NULL,
    number_digits VARCHAR(32) NOT NULL,
    PRIMARY KEY (group_id, number_digits)
  ) ENGINE=InnoDB DEFAULT CHARSET=latin1" 2>/dev/null || true

_dnc_php="$(command -v php || echo /usr/bin/php)"
_dnc_script="/opt/ownswitch/current/bin/dial-dnc-check.php"
[[ -f "${_dnc_script}" ]] || _dnc_script="/opt/ownswitch-src/bin/dial-dnc-check.php"

# Shared DNC body helpers (digits from rewritten $rU / os_dst)
_dnc_sip_body_redis='	if ($avp(os_dncid) == NULL || $(avp(os_dncid){s.int}) <= 0) {
		return;
	}
	$var(dncid) = $avp(os_dncid);
	$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_key) = "ownswitch:dnc:" + $var(dncid);
	$avp(dnc_hit) = NULL;
	if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_dst)", "$avp(dnc_hit)") && $avp(dnc_hit) != NULL && $(avp(dnc_hit){s.int}) == 1) {
		xlog("L_WARN", "SIP DNC hit group=$var(dncid) dst=$var(dnc_dst) — block before trunk\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$avp(dnc_hit) = NULL;
		if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_alt)", "$avp(dnc_hit)") && $avp(dnc_hit) != NULL && $(avp(dnc_hit){s.int}) == 1) {
			xlog("L_WARN", "SIP DNC hit (NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}'

_dnc_trunk_body_redis='	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(dncid) = $(avp(dr_gw_attrs){param.value,dncid});
	if ($var(dncid) == NULL || $(var(dncid){s.len}) == 0 || $(var(dncid){s.int}) <= 0) {
		return;
	}
	$var(dnc_dst) = $(dlg_val(os_dst){re.subst,/[^0-9]//g});
	if ($var(dnc_dst) == NULL || $(var(dnc_dst){s.len}) < 3) {
		$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	}
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_key) = "ownswitch:dnc:" + $var(dncid);
	$avp(dnc_hit) = NULL;
	if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_dst)", "$avp(dnc_hit)") && $avp(dnc_hit) != NULL && $(avp(dnc_hit){s.int}) == 1) {
		xlog("L_WARN", "Trunk DNC hit group=$var(dncid) dst=$var(dnc_dst) — instant block (no vendor)\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$avp(dnc_hit) = NULL;
		if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_alt)", "$avp(dnc_hit)") && $avp(dnc_hit) != NULL && $(avp(dnc_hit){s.int}) == 1) {
			xlog("L_WARN", "Trunk DNC hit (NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}'

if [[ -z "${OPENSIPS_DNC_MODULES:-}" && -z "${OPENSIPS_DNC_ROUTE:-}" ]]; then
  if (( HAVE_CACHEDB_REDIS )); then
    _redis_pw="$(get_secret redis_password 2>/dev/null || true)"
    _redis_db="${REDIS_PORTAL_DB:-1}"
    if [[ -n "${_redis_pw}" ]]; then
      _redis_enc="$(P="${_redis_pw}" python3 -c 'import urllib.parse,os; print(urllib.parse.quote(os.environ["P"], safe=""))')"
      _redis_url="redis://:${_redis_enc}@127.0.0.1:6379/${_redis_db}"
    else
      _redis_url="redis://127.0.0.1:6379/${_redis_db}"
    fi
    export OPENSIPS_DNC_MODULES="loadmodule \"cachedb_redis.so\"
modparam(\"cachedb_redis\", \"cachedb_url\", \"redis:dnc=>${_redis_url}\")"
    export OPENSIPS_DNC_ROUTE="route[check_sip_dnc] {
${_dnc_sip_body_redis}
}
route[check_dnc] {
${_dnc_trunk_body_redis}
}"
    log_info "DNC: Redis SISMEMBER path enabled (cachedb_redis)"
  elif (( HAVE_SQLOPS )); then
    export OPENSIPS_DNC_MODULES="loadmodule \"sqlops.so\"
modparam(\"sqlops\", \"db_url\", \"${OPENSIPS_DB_URL}\")"
    export OPENSIPS_DNC_ROUTE='route[check_sip_dnc] {
	if ($avp(os_dncid) == NULL || $(avp(os_dncid){s.int}) <= 0) {
		return;
	}
	$var(dncid) = $avp(os_dncid);
	$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$avp(dnc_hit) = NULL;
	if (sql_query("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='\''$var(dnc_dst)'\'' limit 1", "$avp(dnc_hit)") && $avp(dnc_hit) != NULL) {
		xlog("L_WARN", "SIP DNC hit (sql) group=$var(dncid) dst=$var(dnc_dst)\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$avp(dnc_hit) = NULL;
		if (sql_query("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='\''$var(dnc_alt)'\'' limit 1", "$avp(dnc_hit)") && $avp(dnc_hit) != NULL) {
			xlog("L_WARN", "SIP DNC hit (sql NANP) group=$var(dncid) dst=$var(dnc_dst)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}
route[check_dnc] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(dncid) = $(avp(dr_gw_attrs){param.value,dncid});
	if ($var(dncid) == NULL || $(var(dncid){s.len}) == 0 || $(var(dncid){s.int}) <= 0) {
		return;
	}
	$var(dnc_dst) = $(dlg_val(os_dst){re.subst,/[^0-9]//g});
	if ($var(dnc_dst) == NULL || $(var(dnc_dst){s.len}) < 3) {
		$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	}
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$avp(dnc_hit) = NULL;
	if (sql_query("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='\''$var(dnc_dst)'\'' limit 1", "$avp(dnc_hit)") && $avp(dnc_hit) != NULL) {
		xlog("L_WARN", "Trunk DNC hit (sql) group=$var(dncid) dst=$var(dnc_dst)\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$avp(dnc_hit) = NULL;
		if (sql_query("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='\''$var(dnc_alt)'\'' limit 1", "$avp(dnc_hit)") && $avp(dnc_hit) != NULL) {
			xlog("L_WARN", "Trunk DNC hit (sql NANP) group=$var(dncid) dst=$var(dnc_dst)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}'
    log_info "DNC: sqlops dnc_cache fallback enabled"
  elif (( HAVE_EXEC )) && [[ -f "${_dnc_script}" ]]; then
    export OPENSIPS_DNC_MODULES='# DNC via PHP exec (no redis/sqlops)'
    export OPENSIPS_DNC_ROUTE='route[check_sip_dnc] {
	if ($avp(os_dncid) == NULL || $(avp(os_dncid){s.int}) <= 0) {
		return;
	}
	$var(dncid) = $avp(os_dncid);
	$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env '"${_dnc_php}"' '"${_dnc_script}"' $var(dncid) $var(dnc_dst)")) {
		xlog("L_WARN", "SIP DNC hit (php) group=$var(dncid) dst=$var(dnc_dst)\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
}
route[check_dnc] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(dncid) = $(avp(dr_gw_attrs){param.value,dncid});
	if ($var(dncid) == NULL || $(var(dncid){s.len}) == 0 || $(var(dncid){s.int}) <= 0) {
		return;
	}
	$var(dnc_dst) = $(dlg_val(os_dst){re.subst,/[^0-9]//g});
	if ($var(dnc_dst) == NULL || $(var(dnc_dst){s.len}) < 3) {
		$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	}
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env '"${_dnc_php}"' '"${_dnc_script}"' $var(dncid) $var(dnc_dst)")) {
		xlog("L_WARN", "Trunk DNC hit (php) group=$var(dncid) dst=$var(dnc_dst)\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
}'
    chmod 644 "${_dnc_script}" 2>/dev/null || true
    log_info "DNC: PHP exec fallback → ${_dnc_script}"
  else
    export OPENSIPS_DNC_MODULES="${OPENSIPS_DNC_MODULES:-}"
    export OPENSIPS_DNC_ROUTE='route[check_sip_dnc] {
	return;
}
route[check_dnc] {
	return;
}'
    log_warn "DNC: no redis/sqlops/exec — stubs only"
  fi
fi

# Load SIP profile for IP-auth peers (no digest → load_credentials skipped)
_rate_php="$(command -v php || echo /usr/bin/php)"
if [[ -z "${OPENSIPS_SIP_PROFILE_ROUTE:-}" ]]; then
  _prof_script="/opt/ownswitch/current/bin/dial-sip-profile.php"
  [[ -f "${_prof_script}" ]] || _prof_script="/opt/ownswitch-src/bin/dial-sip-profile.php"
  if (( HAVE_EXEC )) && [[ -f "${_prof_script}" ]]; then
    export OPENSIPS_SIP_PROFILE_ROUTE='route[load_sip_profile] {
	$var(prof_user) = $avp(os_auth_user);
	if ($var(prof_user) == NULL || $(var(prof_user){s.len}) == 0) {
		$var(prof_user) = $dlg_val(os_src);
	}
	if ($var(prof_user) == NULL || $(var(prof_user){s.len}) == 0) {
		$var(prof_user) = $au;
	}
	if ($var(prof_user) == NULL || $(var(prof_user){s.len}) == 0) {
		return;
	}
	# Only fill missing AVPs (digest path already has load_credentials)
	if ($avp(os_dncid) != NULL && $(avp(os_dncid){s.len}) > 0
		&& $avp(os_cid_mode) != NULL && $(avp(os_cid_mode){s.len}) > 0) {
		return;
	}
	$var(prof_out) = NULL;
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env '"${_rate_php}"' '"${_prof_script}"' $var(prof_user)", , $var(prof_out))) {
		return;
	}
	if ($var(prof_out) == NULL || $(var(prof_out){s.len}) < 5) {
		return;
	}
	if ($avp(os_dncid) == NULL || $(avp(os_dncid){s.len}) == 0 || $(avp(os_dncid){s.int}) <= 0) {
		$var(p_dnc) = $(var(prof_out){param.value,dncid});
		if ($var(p_dnc) != NULL && $(var(p_dnc){s.len}) > 0) {
			$avp(os_dncid) = $var(p_dnc);
		}
	}
	if ($avp(os_cid_mode) == NULL || $(avp(os_cid_mode){s.len}) == 0) {
		$var(p_mode) = $(var(prof_out){param.value,cid_mode});
		if ($var(p_mode) != NULL && $(var(p_mode){s.len}) > 0) {
			$avp(os_cid_mode) = $var(p_mode);
		}
	}
	if ($avp(os_cid) == NULL || $(avp(os_cid){s.len}) == 0) {
		$var(p_cid) = $(var(prof_out){param.value,cid});
		if ($var(p_cid) != NULL && $(var(p_cid){s.len}) > 0) {
			$avp(os_cid) = $var(p_cid);
		}
	}
	if ($avp(os_media) == NULL || $(avp(os_media){s.len}) == 0) {
		$var(p_media) = $(var(prof_out){param.value,media});
		if ($var(p_media) != NULL && $(var(p_media){s.len}) > 0) {
			$avp(os_media) = $var(p_media);
		}
	}
	if ($avp(os_dtmf) == NULL || $(avp(os_dtmf){s.len}) == 0) {
		$var(p_dtmf) = $(var(prof_out){param.value,dtmf});
		if ($var(p_dtmf) != NULL && $(var(p_dtmf){s.len}) > 0) {
			$avp(os_dtmf) = $var(p_dtmf);
		}
	}
	if ($avp(os_dstfmt) == NULL || $(avp(os_dstfmt){s.len}) == 0) {
		$var(p_fmt) = $(var(prof_out){param.value,dstfmt});
		if ($var(p_fmt) != NULL && $(var(p_fmt){s.len}) > 0) {
			$avp(os_dstfmt) = $var(p_fmt);
		}
	}
	if ($avp(os_cid_min) == NULL || $(avp(os_cid_min){s.len}) == 0) {
		$var(p_min) = $(var(prof_out){param.value,cidmin});
		if ($var(p_min) != NULL && $(var(p_min){s.len}) > 0) {
			$avp(os_cid_min) = $var(p_min);
		}
	}
	if ($avp(os_cid_max) == NULL || $(avp(os_cid_max){s.len}) == 0) {
		$var(p_max) = $(var(prof_out){param.value,cidmax});
		if ($var(p_max) != NULL && $(var(p_max){s.len}) > 0) {
			$avp(os_cid_max) = $var(p_max);
		}
	}
	if ($avp(os_allow) == NULL || $(avp(os_allow){s.len}) == 0) {
		$var(p_allow) = $(var(prof_out){param.value,allow});
		if ($var(p_allow) != NULL && $(var(p_allow){s.len}) > 0) {
			$avp(os_allow) = $var(p_allow);
		}
	}
	xlog("L_INFO", "SIP profile loaded user=$var(prof_user) dncid=$avp(os_dncid) cid_mode=$avp(os_cid_mode)\n");
}'
    chmod 644 "${_prof_script}" 2>/dev/null || true
    log_info "SIP profile: exec → ${_prof_script}"
  else
    export OPENSIPS_SIP_PROFILE_ROUTE='route[load_sip_profile] {
	return;
}'
    log_warn "SIP profile: exec/dial-sip-profile missing — stub only"
  fi
fi

# SIP-user destination prefix rewrite (exec → bin/dial-dst-rewrite.php)
if [[ -z "${OPENSIPS_DST_REWRITE_ROUTE:-}" ]]; then
  _rw_script="/opt/ownswitch/current/bin/dial-dst-rewrite.php"
  [[ -f "${_rw_script}" ]] || _rw_script="/opt/ownswitch-src/bin/dial-dst-rewrite.php"
  if (( HAVE_EXEC )) && [[ -f "${_rw_script}" ]]; then
    export OPENSIPS_DST_REWRITE_ROUTE='route[apply_sip_dst_rewrite] {
	$var(rw_user) = $avp(os_auth_user);
	if ($var(rw_user) == NULL || $(var(rw_user){s.len}) == 0) {
		$var(rw_user) = $au;
	}
	if ($var(rw_user) == NULL || $(var(rw_user){s.len}) == 0) {
		$var(rw_user) = $fU;
	}
	$var(rw_out) = NULL;
	if (exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env '"${_rate_php}"' '"${_rw_script}"' $var(rw_user) $rU", , $var(rw_out))) {
		$var(rw_dst) = $(var(rw_out){re.subst,/[^0-9]//g});
		if ($var(rw_dst) != NULL && $(var(rw_dst){s.len}) >= 3 && $var(rw_dst) != $rU) {
			xlog("L_INFO", "dst rewrite user=$var(rw_user) from=$rU to=$var(rw_dst)\n");
			$rU = $var(rw_dst);
		} else if ($var(rw_dst) != NULL && $(var(rw_dst){s.len}) >= 3) {
			$rU = $var(rw_dst);
		}
	}
}'
    usermod -aG ownswitch opensips 2>/dev/null || true
    chmod 755 "$(dirname "${_rw_script}")" 2>/dev/null || true
    chmod 644 "${_rw_script}" 2>/dev/null || true
    log_info "DST rewrite: exec → ${_rw_script}"
  else
    export OPENSIPS_DST_REWRITE_ROUTE='route[apply_sip_dst_rewrite] {
	return;
}'
    log_warn "DST rewrite: exec/dial-dst-rewrite missing — stub only"
  fi
fi

# Customer tariff / plan-prefix gate (exec → bin/dial-rate-check.php) — reject unrated dials
if [[ -z "${OPENSIPS_CUSTOMER_RATE_ROUTE:-}" ]]; then
  _rate_script="/opt/ownswitch/current/bin/dial-rate-check.php"
  [[ -f "${_rate_script}" ]] || _rate_script="/opt/ownswitch-src/bin/dial-rate-check.php"
  if (( HAVE_EXEC )) && [[ -f "${_rate_script}" ]]; then
    export OPENSIPS_CUSTOMER_RATE_ROUTE='route[check_customer_rate] {
	$var(rate_user) = $avp(os_auth_user);
	if ($var(rate_user) == NULL || $(var(rate_user){s.len}) == 0) {
		$var(rate_user) = $au;
	}
	if ($var(rate_user) == NULL || $(var(rate_user){s.len}) == 0) {
		$var(rate_user) = $dlg_val(os_src);
	}
	if ($var(rate_user) == NULL || $(var(rate_user){s.len}) == 0) {
		$var(rate_user) = $fU;
	}
	# Ensure ACC / CDR sees SIP user + (possibly rewritten) destination even on reject
	$acc_extra(src) = $dlg_val(os_src);
	$acc_extra(dst) = $rU;
	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$acc_extra(cli) = $dlg_val(os_cli);
	} else {
		$acc_extra(cli) = $fU;
	}
	$acc_extra(ip) = $si;
	$var(rate_out) = NULL;
	$avp(os_rplan) = 0;
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env '"${_rate_php}"' '"${_rate_script}"' $var(rate_user) $rU", , $var(rate_out))) {
		xlog("L_WARN", "Unrated Destination user=$var(rate_user) dst=$rU\n");
		acc_db_request("403 Unrated Destination", "acc");
		send_reply(403, "Unrated Destination");
		exit;
	}
	# Parse trunk-group id + optional plan-prefix-stripped route digits.
	# Use /s so trailing newline from PHP fwrite is consumed — otherwise rp becomes "3\\n"
	# and =~ "^[0-9]+$" fails → tg stays 0 → false "no trunk group" while dial-rate-check is OK.
	if ($var(rate_out) != NULL && $(var(rate_out){s.len}) > 0) {
		$var(rp) = $(var(rate_out){re.subst,/^.*rplan=([0-9]+).*$/\1/s});
		if ($var(rp) != NULL && $(var(rp){s.len}) > 0 && $var(rp) =~ "^[0-9]+$") {
			$avp(os_rplan) = $(var(rp){s.int});
		}
		$var(rd) = $(var(rate_out){re.subst,/^.*route_dst=([0-9]+).*$/\1/s});
		if ($var(rd) != NULL && $(var(rd){s.len}) >= 3 && $var(rd) =~ "^[0-9]+$" && $var(rd) != $rU) {
			xlog("L_INFO", "plan-prefix strip user=$var(rate_user) from=$rU to=$var(rd) tg=$avp(os_rplan)\n");
			$rU = $var(rd);
			$dlg_val(os_dst) = $var(rd);
			$acc_extra(dst) = $var(rd);
		}
	}
	xlog("L_INFO", "rated OK user=$var(rate_user) dst=$rU tg=$avp(os_rplan)\n");
}'
    usermod -aG ownswitch opensips 2>/dev/null || true
    chmod 755 "$(dirname "${_rate_script}")" 2>/dev/null || true
    chmod 644 "${_rate_script}" 2>/dev/null || true
    log_info "Customer rate gate: exec → ${_rate_script}"
  else
    export OPENSIPS_CUSTOMER_RATE_ROUTE='route[check_customer_rate] {
	return;
}'
    log_warn "Customer rate gate: exec/dial-rate-check missing — stub only"
  fi
fi

# Rate CAP via portal PHP (exec → bin/dial-cap-check.php)
_cap_php="$(command -v php || echo /usr/bin/php)"
if [[ -z "${OPENSIPS_RATE_CAP_ROUTE:-}" ]]; then
  _cap_script="/opt/ownswitch/current/bin/dial-cap-check.php"
  [[ -f "${_cap_script}" ]] || _cap_script="/opt/ownswitch-src/bin/dial-cap-check.php"
  if (( HAVE_EXEC )) && [[ -f "${_cap_script}" ]]; then
    export OPENSIPS_RATE_CAP_ROUTE='route[check_rate_cap] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(tid) = $(avp(dr_gw_attrs){param.value,tid});
	if ($var(tid) == NULL || $(var(tid){s.len}) == 0) {
		return;
	}
	$var(cap_user) = $avp(os_auth_user);
	if ($var(cap_user) == NULL || $(var(cap_user){s.len}) == 0) {
		$var(cap_user) = $dlg_val(os_src);
	}
	if ($var(cap_user) == NULL || $(var(cap_user){s.len}) == 0) {
		$var(cap_user) = $au;
	}
	if ($var(cap_user) == NULL || $(var(cap_user){s.len}) == 0) {
		$var(cap_user) = $fU;
	}
	# Cap against post-rewrite / pre-strip dialled digits when available
	$var(cap_dst) = $dlg_val(os_dst);
	if ($var(cap_dst) == NULL || $(var(cap_dst){s.len}) < 3) {
		$var(cap_dst) = $rU;
	}
	$var(cap_out) = NULL;
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env '"${_cap_php}"' '"${_cap_script}"' $var(cap_user) $var(cap_dst) $var(tid)", , $var(cap_out))) {
		$var(cap_out) = "DENY_TRUNK exec_failed";
	}
	if ($var(cap_out) =~ "DENY_USER") {
		xlog("L_WARN", "User Rate Cap Exceeded user=$var(cap_user) dst=$var(cap_dst)\n");
		acc_db_request("403 Rate Cap Exceeded", "acc");
		send_reply(403, "Rate Cap Exceeded");
		exit;
	}
	if ($var(cap_out) =~ "DENY_TRUNK") {
		xlog("L_WARN", "Trunk Rate Cap Exceeded user=$var(cap_user) dst=$var(cap_dst) trunk=$var(tid) — next gw\n");
		if ($var(cap_tries) == NULL) {
			$var(cap_tries) = 0;
		}
		$var(cap_tries) = $(var(cap_tries){s.int}) + 1;
		if ($(var(cap_tries){s.int}) < 8 && use_next_gw()) {
			route(check_dnc);
			route(apply_ruri_strip);
			route(apply_sip_cid);
			route(apply_callerid_group);
			route(check_cid_len);
			route(apply_trunk_attrs);
			route(check_trunk_cid_len);
			route(check_rate_cap);
			return;
		}
		acc_db_request("403 Rate Cap Exceeded", "acc");
		send_reply(403, "Rate Cap Exceeded");
		exit;
	}
}'
    # opensips runs as user opensips — release tree is root:ownswitch (g=rX)
    usermod -aG ownswitch opensips 2>/dev/null || true
    chmod 755 "$(dirname "${_cap_script}")" 2>/dev/null || true
    chmod 644 "${_cap_script}" 2>/dev/null || true
    log_info "Rate CAP: exec → ${_cap_script}"
  else
    export OPENSIPS_RATE_CAP_ROUTE='route[check_rate_cap] {
	return;
}'
    log_warn "Rate CAP: exec/dial-cap-check missing — stub only"
  fi
fi

# SIP-user Channels + CPS (exec → bin/dial-sip-limit-check.php)
if [[ -z "${OPENSIPS_SIP_LIMIT_ROUTE:-}" ]]; then
  _sip_lim_script="/opt/ownswitch/current/bin/dial-sip-limit-check.php"
  [[ -f "${_sip_lim_script}" ]] || _sip_lim_script="/opt/ownswitch-src/bin/dial-sip-limit-check.php"
  if (( HAVE_EXEC )) && [[ -f "${_sip_lim_script}" ]]; then
    export OPENSIPS_SIP_LIMIT_ROUTE='route[check_sip_limits] {
	$var(lim_user) = $avp(os_auth_user);
	if ($var(lim_user) == NULL || $(var(lim_user){s.len}) == 0) {
		$var(lim_user) = $dlg_val(os_src);
	}
	if ($var(lim_user) == NULL || $(var(lim_user){s.len}) == 0) {
		$var(lim_user) = $au;
	}
	if ($var(lim_user) == NULL || $(var(lim_user){s.len}) == 0) {
		return;
	}
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env '"${_cap_php}"' '"${_sip_lim_script}"' $var(lim_user)")) {
		xlog("L_WARN", "SIP Congestion user=$var(lim_user) from=$si (channels/CPS)\n");
		acc_db_request("503 Congestion", "acc");
		send_reply(503, "Congestion");
		exit;
	}
}'
    chmod 644 "${_sip_lim_script}" 2>/dev/null || true
    log_info "SIP limits: exec → ${_sip_lim_script}"
  else
    export OPENSIPS_SIP_LIMIT_ROUTE='route[check_sip_limits] {
	return;
}'
    log_warn "SIP limits: exec/dial-sip-limit-check missing — stub only"
  fi
fi

# CallerID group picker (exec → bin/dial-callerid-pick.php)
if [[ -z "${OPENSIPS_CALLERID_ROUTE:-}" ]]; then
  _cid_script="/opt/ownswitch/current/bin/dial-callerid-pick.php"
  [[ -f "${_cid_script}" ]] || _cid_script="/opt/ownswitch-src/bin/dial-callerid-pick.php"
  if (( HAVE_EXEC )) && [[ -f "${_cid_script}" ]]; then
    export OPENSIPS_CALLERID_ROUTE='route[apply_callerid_group] {
	$var(cid_user) = $avp(os_auth_user);
	if ($var(cid_user) == NULL || $(var(cid_user){s.len}) == 0) {
		$var(cid_user) = $dlg_val(os_src);
	}
	if ($var(cid_user) == NULL || $(var(cid_user){s.len}) == 0) {
		$var(cid_user) = $au;
	}
	if ($var(cid_user) == NULL || $(var(cid_user){s.len}) == 0) {
		return;
	}
	$var(cid_dst) = $dlg_val(os_dst);
	if ($var(cid_dst) == NULL || $(var(cid_dst){s.len}) < 3) {
		$var(cid_dst) = $rU;
	}
	$var(cid_out) = NULL;
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env '"${_cap_php}"' '"${_cid_script}"' $var(cid_user) $var(cid_dst)", , $var(cid_out))) {
		return;
	}
	$var(cid_cli) = $(var(cid_out){re.subst,/[^0-9+]//g});
	if ($var(cid_cli) == NULL || $(var(cid_cli){s.len}) < 3 || $(var(cid_cli){s.len}) > 30) {
		return;
	}
	if (!($var(cid_cli) =~ "^[+0-9]{3,30}$")) {
		return;
	}
	$var(cid_dom) = $fd;
	if ($var(cid_dom) == NULL || $(var(cid_dom){s.len}) == 0) {
		$var(cid_dom) = $td;
	}
	$dlg_val(os_cli) = $var(cid_cli);
	$acc_extra(cli) = $var(cid_cli);
	remove_hf("From");
	append_hf("From: <sip:$var(cid_cli)@$var(cid_dom)>;tag=$ft\r\n");
	remove_hf("P-Asserted-Identity");
	append_hf("P-Asserted-Identity: <sip:$var(cid_cli)@$var(cid_dom)>\r\n");
	remove_hf("Remote-Party-ID");
	append_hf("Remote-Party-ID: <sip:$var(cid_cli)@$var(cid_dom)>;party=calling;privacy=off;screen=yes\r\n");
	xlog("L_INFO", "CallerID group CLI=$var(cid_cli) user=$var(cid_user) dst=$var(cid_dst)\n");
}'
    chmod 644 "${_cid_script}" 2>/dev/null || true
    log_info "CallerID group: exec → ${_cid_script}"
  else
    export OPENSIPS_CALLERID_ROUTE='route[apply_callerid_group] {
	return;
}'
    log_warn "CallerID group: exec/dial-callerid-pick missing — stub only"
  fi
fi

# Trunk Channels + CPS with next-gw failover (exec → bin/dial-trunk-limit-check.php)
if [[ -z "${OPENSIPS_TRUNK_LIMIT_ROUTE:-}" ]]; then
  _trk_lim_script="/opt/ownswitch/current/bin/dial-trunk-limit-check.php"
  [[ -f "${_trk_lim_script}" ]] || _trk_lim_script="/opt/ownswitch-src/bin/dial-trunk-limit-check.php"
  if (( HAVE_EXEC )) && [[ -f "${_trk_lim_script}" ]]; then
    export OPENSIPS_TRUNK_LIMIT_ROUTE='route[check_trunk_limits] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(tid) = $(avp(dr_gw_attrs){param.value,tid});
	if ($var(tid) == NULL || $(var(tid){s.len}) == 0) {
		return;
	}
	$dlg_val(tid) = $var(tid);
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env '"${_cap_php}"' '"${_trk_lim_script}"' $var(tid)")) {
		xlog("L_WARN", "Trunk capacity exceeded tid=$var(tid) — trying next route\n");
		if ($var(trk_lim_tries) == NULL) {
			$var(trk_lim_tries) = 0;
		}
		$var(trk_lim_tries) = $(var(trk_lim_tries){s.int}) + 1;
		if ($(var(trk_lim_tries){s.int}) < 8 && use_next_gw()) {
			route(check_dnc);
			route(apply_ruri_strip);
			route(apply_sip_cid);
			route(apply_callerid_group);
			route(check_cid_len);
			route(apply_trunk_attrs);
			route(check_trunk_cid_len);
			route(check_rate_cap);
			route(check_trunk_limits);
			return;
		}
		acc_db_request("503 Congestion", "acc");
		send_reply(503, "Congestion");
		exit;
	}
}'
    chmod 644 "${_trk_lim_script}" 2>/dev/null || true
    log_info "Trunk limits: exec → ${_trk_lim_script}"
  else
    export OPENSIPS_TRUNK_LIMIT_ROUTE='route[check_trunk_limits] {
	return;
}'
    log_warn "Trunk limits: exec/dial-trunk-limit-check missing — stub only"
  fi
fi

# CLI Restriction lists (block presented CallerID)
if [[ -z "${OPENSIPS_CLI_RESTRICT_ROUTE:-}" ]]; then
  _cli_script="/opt/ownswitch/current/bin/cli-restrict-check.php"
  [[ -f "${_cli_script}" ]] || _cli_script="/opt/ownswitch-src/bin/cli-restrict-check.php"
  if (( HAVE_EXEC )) && [[ -f "${_cli_script}" ]]; then
    chmod 644 "${_cli_script}" 2>/dev/null || true
    export OPENSIPS_CLI_RESTRICT_ROUTE='route[check_cli_restriction] {
	$var(cli_user) = $au;
	if ($var(cli_user) == NULL || $(var(cli_user){s.len}) == 0) {
		$var(cli_user) = $dlg_val(os_src);
	}
	if ($var(cli_user) == NULL || $(var(cli_user){s.len}) == 0) {
		return;
	}
	$var(cli_digits) = $(dlg_val(os_cli){re.subst,/[^0-9]//g});
	if ($var(cli_digits) == NULL || $(var(cli_digits){s.len}) < 3) {
		$var(cli_digits) = $(fU{re.subst,/[^0-9]//g});
	}
	if ($var(cli_digits) == NULL || $(var(cli_digits){s.len}) < 3) {
		return;
	}
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env '"${_cap_php}"' '"${_cli_script}"' $var(cli_user) $var(cli_digits)")) {
		xlog("L_WARN", "CLI restricted user=$var(cli_user) cli=$var(cli_digits)\n");
		acc_db_request("403 CLI restricted", "acc");
		send_reply(403, "CLI restricted");
		exit;
	}
}'
    log_info "CLI restriction: exec → ${_cli_script}"
  else
    export OPENSIPS_CLI_RESTRICT_ROUTE='route[check_cli_restriction] {
	return;
}'
    log_warn "CLI restriction: exec/cli-restrict-check missing — stub only"
  fi
fi

# Fail2ban feed: copy digest-auth failure lines into /var/log/opensips/auth.log
install -d -m 0755 /var/log/opensips
touch /var/log/opensips/auth.log
chown opensips:opensips /var/log/opensips/auth.log 2>/dev/null || true
chmod 640 /var/log/opensips/auth.log || true
if [[ -f "${TEMPLATE_DIR}/rsyslog-opensips-auth.conf" ]]; then
  install -m 0644 "${TEMPLATE_DIR}/rsyslog-opensips-auth.conf" /etc/rsyslog.d/30-opensips-auth.conf
  systemctl reload rsyslog 2>/dev/null || systemctl restart rsyslog 2>/dev/null || true
  note_change "rsyslog opensips auth → /var/log/opensips/auth.log"
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

# Settings → Default media policy (used when SIP user media_policy is empty / Default)
_default_media="relay"
if command -v mysql >/dev/null 2>&1; then
  _pw="$(cat /etc/ownswitch/secrets/mysql_ownswitch_password 2>/dev/null || true)"
  if [[ -n "${_pw}" ]]; then
    _v="$(mysql -uownswitch -p"${_pw}" -h127.0.0.1 ownswitch -N -e \
      "SELECT setting_value FROM system_settings WHERE setting_key='default_media_policy' LIMIT 1" 2>/dev/null || true)"
    _v="$(echo "${_v}" | tr '[:upper:]' '[:lower:]' | tr -d '[:space:]')"
    if [[ "${_v}" == "direct" || "${_v}" == "relay" || "${_v}" == "record" ]]; then
      _default_media="${_v}"
    fi
  fi
fi
export DEFAULT_MEDIA_POLICY="${_default_media}"
log_info "OpenSIPS DEFAULT_MEDIA_POLICY=${DEFAULT_MEDIA_POLICY}"

# Safe stubs if limit routes were not injected above (missing exec/scripts)
: "${OPENSIPS_SIP_LIMIT_ROUTE:=route[check_sip_limits] {
	return;
}}"
: "${OPENSIPS_TRUNK_LIMIT_ROUTE:=route[check_trunk_limits] {
	return;
}}"
: "${OPENSIPS_CALLERID_ROUTE:=route[apply_callerid_group] {
	return;
}}"
: "${OPENSIPS_SIP_PROFILE_ROUTE:=route[load_sip_profile] {
	return;
}}"
export OPENSIPS_SIP_LIMIT_ROUTE OPENSIPS_TRUNK_LIMIT_ROUTE OPENSIPS_CALLERID_ROUTE OPENSIPS_SIP_PROFILE_ROUTE

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

# Ensure unit enabled; prefer packaged systemd unit.
# Packaged After=mysqld.service misses Ubuntu's mysql.service → OpenSIPS can boot before MySQL and exit 255.
install -d /etc/systemd/system/opensips.service.d
cat >/etc/systemd/system/opensips.service.d/ownswitch-mysql.conf <<'EOF'
[Unit]
After=network-online.target mysql.service mysqld.service
Wants=mysql.service

[Service]
Restart=on-failure
RestartSec=5
EOF
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
