#!/usr/bin/env bash
# =============================================================================
# Ownswitch — one-command installer for a NEW Hetzner Cloud (or any) Ubuntu
# Server 24.04 LTS (amd64) host.
#
# This script installs EVERYTHING for a working VoIP billing switch:
#   MySQL 8.4 · Redis · CGRateS · RTPengine · OpenSIPS 3.6 (SIP edge)
#   PHP 8.3-FPM · Nginx · portal · workers · firewall
#
# Usage (on a clean Ubuntu 24.04 server as root):
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/OWNswitchOpensips/main/install-server.sh \
#     | sudo bash -s -- --domain voip.example.com --email ops@example.com
#
# Or after cloning:
#
#   sudo bash install-server.sh --domain voip.example.com --email ops@example.com
#
# Options:
#   --domain FQDN          Portal / SIP realm (A/AAAA record → this server)
#   --email EMAIL          Admin + Let's Encrypt contact email
#   --password PASS        Admin password (min 12 chars); auto-generated if omitted
#   --repo URL             Git clone URL (default: xceedconnections/OWNswitchOpensips)
#   --branch BRANCH        Git branch (default: main)
#   --no-letsencrypt       Self-signed TLS only
#   --sip-allow CIDRS      Comma-separated CIDRs allowed to reach SIP (optional)
#   --force                Override soft hardware checks
#   -y | --yes             Non-interactive (implied when piped)
#   -h | --help
#
# Environment overrides (same names as deploy/install.sh) are also accepted.
# =============================================================================
set -Eeuo pipefail

REPO_URL="${OWNSWITCH_REPO_URL:-https://github.com/xceedconnections/OWNswitchOpensips.git}"
BRANCH="${OWNSWITCH_BRANCH:-main}"
SRC_DIR="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
DOMAIN="${OWNSWITCH_DOMAIN:-}"
EMAIL="${OWNSWITCH_ADMIN_EMAIL:-}"
ADMIN_PASS="${OWN_SWITCH_ADMIN_PASS:-${OWNSWITCH_ADMIN_PASS:-}}"
LETSENCRYPT=1
SIP_ALLOW_CIDRS="${SIP_ALLOW_CIDRS:-}"
FORCE="${FORCE:-0}"
ASSUME_YES=0

RED=$'\033[31m'; GRN=$'\033[32m'; YLW=$'\033[33m'; CYN=$'\033[36m'; BLD=$'\033[1m'; RST=$'\033[0m'
log()  { printf '%s[%s]%s %s\n' "$CYN" "$(date -u +%H:%M:%S)" "$RST" "$*"; }
ok()   { printf '%s[ OK ]%s %s\n' "$GRN" "$RST" "$*"; }
warn() { printf '%s[WARN]%s %s\n' "$YLW" "$RST" "$*"; }
die()  { printf '%s[FAIL]%s %s\n' "$RED" "$RST" "$*" >&2; exit 1; }

usage() { sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'; }

while (($#)); do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2 ;;
    --email) EMAIL="$2"; shift 2 ;;
    --password) ADMIN_PASS="$2"; shift 2 ;;
    --repo) REPO_URL="$2"; shift 2 ;;
    --branch) BRANCH="$2"; shift 2 ;;
    --no-freeswitch) shift ;; # ignored (OpenSIPS-only build)
    --no-letsencrypt) LETSENCRYPT=0; shift ;;
    --sip-allow) SIP_ALLOW_CIDRS="$2"; shift 2 ;;
    --force) FORCE=1; shift ;;
    -y|--yes) ASSUME_YES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown option: $1 (see --help)" ;;
  esac
done

# Piped curl|bash is non-interactive
if [[ ! -t 0 ]]; then ASSUME_YES=1; fi

[[ "$(id -u)" -eq 0 ]] || die "Run as root (sudo)."

# --- OS gate -----------------------------------------------------------------
. /etc/os-release 2>/dev/null || die "Cannot read /etc/os-release"
[[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "24.04" ]] \
  || die "Ubuntu Server 24.04 LTS (amd64) required (got ${PRETTY_NAME:-unknown})"
[[ "$(uname -m)" == "x86_64" ]] || die "amd64/x86_64 required (ARM not supported)"

# --- Inputs ------------------------------------------------------------------
[[ -n "$DOMAIN" ]] || die "Missing --domain FQDN"
[[ -n "$EMAIL" ]] || EMAIL="admin@${DOMAIN}"
if [[ -z "$ADMIN_PASS" ]]; then
  ADMIN_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 20)Aa1!"
  warn "Admin password auto-generated (saved to /root/ownswitch-credentials.txt)"
fi
[[ ${#ADMIN_PASS} -ge 12 ]] || die "Admin password must be at least 12 characters"

log "Domain: ${DOMAIN}"
log "Email:  ${EMAIL}"
log "Repo:   ${REPO_URL} @ ${BRANCH}"
log "SIP:    OpenSIPS 3.6"

# --- Soft hardware checks ----------------------------------------------------
cpus="$(nproc 2>/dev/null || echo 1)"
ram_mb="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)"
if (( cpus < 2 || ram_mb < 3500 )) && [[ "$FORCE" != "1" ]]; then
  die "Need ≥2 vCPU and ≥3.5 GB RAM (have ${cpus} CPU / ${ram_mb} MB). Re-run with --force to override."
fi
if (( ram_mb < 6000 )); then
  if [[ ! -f /swapfile ]]; then
    log "RAM=${ram_mb}MB — creating 2G swapfile (helps CGRateS builds)…"
    fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
  fi
fi

# --- Clone / update source ---------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq git curl ca-certificates >/dev/null

if [[ -d "${SRC_DIR}/.git" ]]; then
  log "Updating ${SRC_DIR}…"
  git -C "$SRC_DIR" remote set-url origin "$REPO_URL"
  git -C "$SRC_DIR" fetch --depth 1 origin "$BRANCH"
  git -C "$SRC_DIR" checkout -B "$BRANCH" "FETCH_HEAD"
  git -C "$SRC_DIR" reset --hard "origin/${BRANCH}" 2>/dev/null \
    || git -C "$SRC_DIR" reset --hard "FETCH_HEAD"
else
  log "Cloning ${REPO_URL} → ${SRC_DIR}…"
  rm -rf "$SRC_DIR"
  git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$SRC_DIR"
fi
ok "Source ready at ${SRC_DIR}"

chmod +x "${SRC_DIR}/deploy/install.sh" 2>/dev/null || true
find "${SRC_DIR}/deploy" -name '*.sh' -exec chmod +x {} + 2>/dev/null || true

# --- Run installer -----------------------------------------------------------
export OWNSWITCH_DOMAIN="$DOMAIN"
export OWNSWITCH_ADMIN_EMAIL="$EMAIL"
export OWN_SWITCH_ADMIN_PASS="$ADMIN_PASS"
export OWNSWITCH_ADMIN_PASS="$ADMIN_PASS"
export APP_SRC="$SRC_DIR"
export FREESWITCH_ENABLED=0
export FORCE
[[ -n "$SIP_ALLOW_CIDRS" ]] && export SIP_ALLOW_CIDRS
if [[ "$LETSENCRYPT" == "1" ]]; then
  export LETSENCRYPT_EMAIL="$EMAIL"
else
  unset LETSENCRYPT_EMAIL || true
fi

log "Starting deploy/install.sh (typically 15–40 minutes)…"
bash "${SRC_DIR}/deploy/install.sh" --yes --non-interactive

# --- Credentials file --------------------------------------------------------
CRED=/root/ownswitch-credentials.txt
{
  echo "Ownswitch OpenSIPS install — $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "Domain:   https://${DOMAIN}"
  echo "Admin:    ${EMAIL}"
  echo "Password: ${ADMIN_PASS}"
  echo "SIP:      ${DOMAIN} (or public IP) UDP/TCP 5060 — OpenSIPS"
  echo "Source:   ${SRC_DIR}"
  echo "App:      /opt/ownswitch/current"
  echo "Env:      /etc/ownswitch/ownswitch.env"
} >"$CRED"
chmod 600 "$CRED"

ok "Install finished"
echo
echo "${BLD}Portal:${RST}  https://${DOMAIN}"
echo "${BLD}Login:${RST}   ${EMAIL}"
echo "${BLD}Pass:${RST}    (see ${CRED})"
echo "${BLD}SIP:${RST}     OpenSIPS on UDP/TCP 5060 — realm ${DOMAIN}"
echo
exit 0
