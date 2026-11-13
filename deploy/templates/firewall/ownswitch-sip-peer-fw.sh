#!/usr/bin/env bash
# Manage SIP:5060 firewall policy for Ownswitch.
# Usage:
#   ownswitch-sip-peer-fw sync <username> <ip[,ip2,...]> <peer_port>
#   ownswitch-sip-peer-fw remove <username>
#   ownswitch-sip-peer-fw sync-trunk <trunk_id> <ip[,ip2,...]> <sip_port>
#   ownswitch-sip-peer-fw remove-trunk <trunk_id>
#   ownswitch-sip-peer-fw ensure-mode <open|locked>
#   ownswitch-sip-peer-fw list
#
# locked = deny world SIP; only peer/trunk allow rules
# open   = allow world SIP (password/digest softphones from any IP)
set -euo pipefail

STATE_DIR=/var/lib/ownswitch/sip-peer-fw
TRUNK_STATE_DIR=/var/lib/ownswitch/sip-trunk-fw
MODE_FILE=/var/lib/ownswitch/sip-fw-mode
SIP_LISTEN="${SIP_PORT:-5060}"

die() { echo "ERR:$1" >&2; exit 1; }

safe_user() {
  local u="${1:-}"
  [[ "$u" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || die "invalid username"
  echo "$u"
}

safe_trunk() {
  local t="${1:-}"
  [[ "$t" =~ ^[0-9]{1,10}$ ]] || die "invalid trunk id"
  echo "$t"
}

safe_ip() {
  local ip="${1:-}"
  [[ "$ip" =~ ^[0-9a-fA-F:.]+$ ]] || die "invalid ip"
  echo "$ip"
}

safe_ips() {
  local raw="${1:-}" out=() one
  IFS=',' read -r -a parts <<< "$raw"
  for one in "${parts[@]}"; do
    one="$(echo "$one" | tr -d '[:space:]')"
    [[ -n "$one" ]] || continue
    out+=("$(safe_ip "$one")")
  done
  ((${#out[@]} > 0)) || die "invalid ip list"
  echo "${out[*]}"
}

safe_port() {
  local p="${1:-0}"
  [[ "$p" =~ ^[0-9]+$ ]] || die "invalid port"
  (( p >= 0 && p <= 65535 )) || die "invalid port"
  echo "$p"
}

backend() {
  if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld 2>/dev/null; then
    echo firewalld
  elif command -v ufw >/dev/null 2>&1; then
    echo ufw
  else
    echo none
  fi
}

mkdir -p "$STATE_DIR" "$TRUNK_STATE_DIR"
chmod 0750 "$STATE_DIR" "$TRUNK_STATE_DIR" 2>/dev/null || true

state_file() { echo "$STATE_DIR/$(safe_user "$1").env"; }
trunk_state_file() { echo "$TRUNK_STATE_DIR/$(safe_trunk "$1").env"; }

read_state() {
  local f
  f="$(state_file "$1")"
  IP=""; IPS=""; PORTS=""
  [[ -f "$f" ]] || return 1
  # shellcheck disable=SC1090
  source "$f"
  return 0
}

read_trunk_state() {
  local f
  f="$(trunk_state_file "$1")"
  IP=""; IPS=""; PORTS=""
  [[ -f "$f" ]] || return 1
  # shellcheck disable=SC1090
  source "$f"
  return 0
}

write_state() {
  local user="$1" ips="$2" ports="$3"
  local f first
  f="$(state_file "$user")"
  first="${ips%%,*}"
  cat >"$f" <<EOF
IP=${first}
IPS=${ips}
PORTS=${ports}
EOF
  chmod 0640 "$f" 2>/dev/null || true
}

write_trunk_state() {
  local tid="$1" ips="$2" ports="$3"
  local f first
  f="$(trunk_state_file "$tid")"
  first="${ips%%,*}"
  cat >"$f" <<EOF
IP=${first}
IPS=${ips}
PORTS=${ports}
EOF
  chmod 0640 "$f" 2>/dev/null || true
}

clear_state() {
  rm -f "$(state_file "$1")" 2>/dev/null || true
}

clear_trunk_state() {
  rm -f "$(trunk_state_file "$1")" 2>/dev/null || true
}

# --- firewalld ---
fw_remove_rule() {
  local ip="$1" port="$2" proto="$3"
  local rule
  rule="rule family=\"ipv4\" source address=\"${ip}\" port port=\"${port}\" protocol=\"${proto}\" accept"
  firewall-cmd --permanent --remove-rich-rule="${rule}" >/dev/null 2>&1 || true
  if [[ "$ip" == *:* ]]; then
    rule="rule family=\"ipv6\" source address=\"${ip}\" port port=\"${port}\" protocol=\"${proto}\" accept"
    firewall-cmd --permanent --remove-rich-rule="${rule}" >/dev/null 2>&1 || true
  fi
}

fw_add_rule() {
  local ip="$1" port="$2" proto="$3"
  local rule fam
  if [[ "$ip" == *:* ]]; then
    fam=ipv6
  else
    fam=ipv4
  fi
  rule="rule family=\"${fam}\" source address=\"${ip}\" port port=\"${port}\" protocol=\"${proto}\" accept"
  firewall-cmd --permanent --add-rich-rule="${rule}" >/dev/null 2>&1 || true
}

fw_remove_user() {
  local user="$1"
  local ip p ips
  read_state "$user" || return 0
  ips="${IPS:-$IP}"
  for ip in ${ips//,/ }; do
    [[ -n "$ip" ]] || continue
    for p in ${PORTS//,/ }; do
      [[ -n "$p" ]] || continue
      fw_remove_rule "$ip" "$p" udp
      fw_remove_rule "$ip" "$p" tcp
    done
  done
  clear_state "$user"
}

fw_remove_trunk() {
  local tid="$1"
  local ip p ips
  read_trunk_state "$tid" || return 0
  ips="${IPS:-$IP}"
  for ip in ${ips//,/ }; do
    [[ -n "$ip" ]] || continue
    for p in ${PORTS//,/ }; do
      [[ -n "$p" ]] || continue
      fw_remove_rule "$ip" "$p" udp
      fw_remove_rule "$ip" "$p" tcp
    done
  done
  clear_trunk_state "$tid"
}

fw_sync_user() {
  local user="$1" ip_list="$2" peer_port="$3"
  local ports=() p ip ips_csv
  fw_remove_user "$user"
  ports+=("$SIP_LISTEN")
  if [[ "$peer_port" != "0" && "$peer_port" != "$SIP_LISTEN" ]]; then
    ports+=("$peer_port")
  fi
  for ip in $ip_list; do
    for p in "${ports[@]}"; do
      fw_add_rule "$ip" "$p" udp
      fw_add_rule "$ip" "$p" tcp
    done
  done
  firewall-cmd --reload >/dev/null 2>&1 || true
  local joined
  joined=$(IFS=,; echo "${ports[*]}")
  ips_csv=$(echo "$ip_list" | tr ' ' ',')
  write_state "$user" "$ips_csv" "$joined"
  echo "OK firewalld sync user=${user} ips=${ips_csv} ports=${joined}"
}

fw_sync_trunk() {
  local tid="$1" ip_list="$2" sip_port="$3"
  local ports=() p ip ips_csv
  fw_remove_trunk "$tid"
  ports+=("$SIP_LISTEN")
  if [[ "$sip_port" != "0" && "$sip_port" != "$SIP_LISTEN" ]]; then
    ports+=("$sip_port")
  fi
  for ip in $ip_list; do
    for p in "${ports[@]}"; do
      fw_add_rule "$ip" "$p" udp
      fw_add_rule "$ip" "$p" tcp
    done
  done
  firewall-cmd --reload >/dev/null 2>&1 || true
  local joined
  joined=$(IFS=,; echo "${ports[*]}")
  ips_csv=$(echo "$ip_list" | tr ' ' ',')
  write_trunk_state "$tid" "$ips_csv" "$joined"
  echo "OK firewalld sync trunk=${tid} ips=${ips_csv} ports=${joined}"
}

fw_ensure_mode() {
  local mode="$1"
  if [[ "$mode" == "open" ]]; then
    firewall-cmd --permanent --add-port="${SIP_LISTEN}/udp" >/dev/null 2>&1 || true
    firewall-cmd --permanent --add-port="${SIP_LISTEN}/tcp" >/dev/null 2>&1 || true
  else
    firewall-cmd --permanent --remove-port="${SIP_LISTEN}/udp" >/dev/null 2>&1 || true
    firewall-cmd --permanent --remove-port="${SIP_LISTEN}/tcp" >/dev/null 2>&1 || true
  fi
  firewall-cmd --reload >/dev/null 2>&1 || true
  echo "$mode" >"$MODE_FILE"
  echo "OK firewalld mode=${mode} sip=${SIP_LISTEN}"
}

# --- ufw ---
ufw_delete_comment() {
  local comment="$1"
  local nums line n
  mapfile -t nums < <(ufw status numbered 2>/dev/null | grep -F "$comment" | sed -n 's/^\[\s*\([0-9]\+\)\].*/\1/p' | sort -rn || true)
  for n in "${nums[@]:-}"; do
    [[ -n "$n" ]] || continue
    yes | ufw delete "$n" >/dev/null 2>&1 || true
  done
}

ufw_remove_user() {
  local user="$1"
  local comment="ownswitch-peer:${user}"
  ufw_delete_comment "$comment"
  clear_state "$user"
}

ufw_remove_trunk() {
  local tid="$1"
  local comment="ownswitch-trunk:${tid}"
  ufw_delete_comment "$comment"
  clear_trunk_state "$tid"
}

ufw_sync_user() {
  local user="$1" ip_list="$2" peer_port="$3"
  local comment="ownswitch-peer:${user}"
  local ports=() p ip ips_csv
  ufw_remove_user "$user"
  ports+=("$SIP_LISTEN")
  if [[ "$peer_port" != "0" && "$peer_port" != "$SIP_LISTEN" ]]; then
    ports+=("$peer_port")
  fi
  for ip in $ip_list; do
    for p in "${ports[@]}"; do
      ufw allow from "$ip" to any port "$p" proto udp comment "$comment" >/dev/null 2>&1 || true
      ufw allow from "$ip" to any port "$p" proto tcp comment "$comment" >/dev/null 2>&1 || true
    done
  done
  local joined
  joined=$(IFS=,; echo "${ports[*]}")
  ips_csv=$(echo "$ip_list" | tr ' ' ',')
  write_state "$user" "$ips_csv" "$joined"
  echo "OK ufw sync user=${user} ips=${ips_csv} ports=${joined}"
}

ufw_sync_trunk() {
  local tid="$1" ip_list="$2" sip_port="$3"
  local comment="ownswitch-trunk:${tid}"
  local ports=() p ip ips_csv
  ufw_remove_trunk "$tid"
  ports+=("$SIP_LISTEN")
  if [[ "$sip_port" != "0" && "$sip_port" != "$SIP_LISTEN" ]]; then
    ports+=("$sip_port")
  fi
  for ip in $ip_list; do
    for p in "${ports[@]}"; do
      ufw allow from "$ip" to any port "$p" proto udp comment "$comment" >/dev/null 2>&1 || true
      ufw allow from "$ip" to any port "$p" proto tcp comment "$comment" >/dev/null 2>&1 || true
    done
  done
  local joined
  joined=$(IFS=,; echo "${ports[*]}")
  ips_csv=$(echo "$ip_list" | tr ' ' ',')
  write_trunk_state "$tid" "$ips_csv" "$joined"
  echo "OK ufw sync trunk=${tid} ips=${ips_csv} ports=${joined}"
}

ufw_ensure_mode() {
  local mode="$1"
  # Remove installer world-open comments and our managed world rules
  ufw_delete_comment "sip udp"
  ufw_delete_comment "sip tcp"
  ufw_delete_comment "ownswitch-sip-world"
  if [[ "$mode" == "open" ]]; then
    ufw allow "${SIP_LISTEN}/udp" comment 'ownswitch-sip-world' >/dev/null 2>&1 || true
    ufw allow "${SIP_LISTEN}/tcp" comment 'ownswitch-sip-world' >/dev/null 2>&1 || true
  fi
  echo "$mode" >"$MODE_FILE"
  echo "OK ufw mode=${mode} sip=${SIP_LISTEN}"
}

cmd_sync() {
  local user ip_list peer_port be
  user="$(safe_user "${1:-}")"
  ip_list="$(safe_ips "${2:-}")"
  peer_port="$(safe_port "${3:-0}")"
  be="$(backend)"
  case "$be" in
    firewalld) fw_sync_user "$user" "$ip_list" "$peer_port" ;;
    ufw)       ufw_sync_user "$user" "$ip_list" "$peer_port" ;;
    *) die "no firewalld/ufw backend" ;;
  esac
}

cmd_remove() {
  local user be
  user="$(safe_user "${1:-}")"
  be="$(backend)"
  case "$be" in
    firewalld) fw_remove_user "$user"; firewall-cmd --reload >/dev/null 2>&1 || true; echo "OK firewalld removed ${user}" ;;
    ufw)       ufw_remove_user "$user"; echo "OK ufw removed ${user}" ;;
    *) clear_state "$user"; echo "OK state cleared ${user} (no firewall backend)" ;;
  esac
}

cmd_sync_trunk() {
  local tid ip_list sip_port be
  tid="$(safe_trunk "${1:-}")"
  ip_list="$(safe_ips "${2:-}")"
  sip_port="$(safe_port "${3:-5060}")"
  be="$(backend)"
  case "$be" in
    firewalld) fw_sync_trunk "$tid" "$ip_list" "$sip_port" ;;
    ufw)       ufw_sync_trunk "$tid" "$ip_list" "$sip_port" ;;
    *) die "no firewalld/ufw backend" ;;
  esac
}

cmd_remove_trunk() {
  local tid be
  tid="$(safe_trunk "${1:-}")"
  be="$(backend)"
  case "$be" in
    firewalld) fw_remove_trunk "$tid"; firewall-cmd --reload >/dev/null 2>&1 || true; echo "OK firewalld removed trunk ${tid}" ;;
    ufw)       ufw_remove_trunk "$tid"; echo "OK ufw removed trunk ${tid}" ;;
    *) clear_trunk_state "$tid"; echo "OK trunk state cleared ${tid}" ;;
  esac
}

cmd_ensure_mode() {
  local mode="${1:-}" be
  [[ "$mode" == "open" || "$mode" == "locked" ]] || die "mode must be open|locked"
  be="$(backend)"
  case "$be" in
    firewalld) fw_ensure_mode "$mode" ;;
    ufw)       ufw_ensure_mode "$mode" ;;
    *) die "no firewalld/ufw backend" ;;
  esac
}

cmd_list() {
  local f base
  echo "backend=$(backend)"
  echo "sip_listen=${SIP_LISTEN}"
  echo "mode=$(cat "$MODE_FILE" 2>/dev/null || echo unknown)"
  for f in "$STATE_DIR"/*.env; do
    [[ -f "$f" ]] || continue
    base="$(basename "$f" .env)"
    # shellcheck disable=SC1090
    source "$f"
    echo "user=${base} ips=${IPS:-${IP:-}} ports=${PORTS:-}"
  done
  for f in "$TRUNK_STATE_DIR"/*.env; do
    [[ -f "$f" ]] || continue
    base="$(basename "$f" .env)"
    # shellcheck disable=SC1090
    source "$f"
    echo "trunk=${base} ips=${IPS:-${IP:-}} ports=${PORTS:-}"
  done
}

case "${1:-}" in
  sync)         shift; cmd_sync "$@" ;;
  remove)       shift; cmd_remove "$@" ;;
  sync-trunk)   shift; cmd_sync_trunk "$@" ;;
  remove-trunk) shift; cmd_remove_trunk "$@" ;;
  ensure-mode)  shift; cmd_ensure_mode "$@" ;;
  list)         shift; cmd_list "$@" ;;
  *)
    echo "usage: $0 {sync|remove|sync-trunk|remove-trunk|ensure-mode|list}" >&2
    exit 2
    ;;
esac
