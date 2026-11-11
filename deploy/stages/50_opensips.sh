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

if (( HAVE_PIKE )); then
  export OPENSIPS_PIKE_MODULE_BLOCK='loadmodule "pike.so"
modparam("pike", "sampling_time_unit", 2)
modparam("pike", "reqs_density_per_unit", 30)
modparam("pike", "remove_latency", 4)'
  export OPENSIPS_PIKE_CHECK_BLOCK='if (!pike_check_req()) {
		xlog("L_WARN", "pike drop flood from=$si method=$rm\n");
		drop();
		exit;
	}'
  log_info "pike anti-flood enabled (30 req / 2s sampling)"
else
  export OPENSIPS_PIKE_MODULE_BLOCK='# pike.so not installed'
  export OPENSIPS_PIKE_CHECK_BLOCK='# pike check skipped'
fi

# DNC hot-path (Redis preferred; sqlops fallback). Template always calls these routes.
mysql_root "${DB_OPENSIPS}" -e \
  "CREATE TABLE IF NOT EXISTS dnc_cache (
    group_id INT UNSIGNED NOT NULL,
    number_digits VARCHAR(32) NOT NULL,
    PRIMARY KEY (group_id, number_digits)
  ) ENGINE=InnoDB DEFAULT CHARSET=latin1" 2>/dev/null || true

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
    export OPENSIPS_DNC_ROUTE='route[check_sip_dnc] {
	if ($avp(os_dncid) == NULL || $(avp(os_dncid){s.int}) <= 0) {
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
	}
}'
    log_info "DNC: Redis SISMEMBER path enabled (cachedb_redis)"
  else
    export OPENSIPS_DNC_MODULES="${OPENSIPS_DNC_MODULES:-}"
    export OPENSIPS_DNC_ROUTE='route[check_sip_dnc] {
	return;
}
route[check_dnc] {
	return;
}'
    log_warn "DNC: cachedb_redis missing — stubs only (install opensips redis module)"
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
	$var(cap_user) = $au;
	if ($var(cap_user) == NULL || $(var(cap_user){s.len}) == 0) {
		$var(cap_user) = $fU;
	}
	if (!exec("env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env '"${_cap_php}"' '"${_cap_script}"' $var(cap_user) $rU $var(tid)")) {
		xlog("L_WARN", "Rate Cap Exceeded user=$var(cap_user) dst=$rU trunk=$var(tid)\n");
		if ($var(cap_tries) == NULL) {
			$var(cap_tries) = 0;
		}
		$var(cap_tries) = $(var(cap_tries){s.int}) + 1;
		if ($(var(cap_tries){s.int}) < 8 && use_next_gw()) {
			route(check_rate_cap);
			return;
		}
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
