#!/usr/bin/env bash
# Pull latest Ownswitch UI into a new release and switch traffic.
# Usage (on the server as root):
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/OWNswitchOpensips/main/deploy/refresh-ui.sh | sudo bash
# Or:
#   sudo bash /opt/ownswitch-src/deploy/refresh-ui.sh
set -euo pipefail

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_OPT="${OWN_OPT:-/opt/ownswitch}"
OWN_VAR="${OWN_VAR:-/var/lib/ownswitch}"
OWN_ETC="${OWN_ETC:-/etc/ownswitch}"
REPO_URL="${OWN_REPO_URL:-https://github.com/xceedconnections/OWNswitchOpensips.git}"
BRANCH="${OWN_BRANCH:-main}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

echo "== Ownswitch UI refresh =="
echo "SRC=$SRC  current=$(readlink -f "$OWN_OPT/current" 2>/dev/null || echo missing)"

if [[ ! -d "$SRC/.git" ]]; then
  echo "Cloning $REPO_URL → $SRC"
  install -d -m 0755 "$(dirname "$SRC")"
  git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$SRC"
else
  echo "Pulling $BRANCH…"
  git -C "$SRC" fetch origin "$BRANCH"
  git -C "$SRC" checkout "$BRANCH"
  git -C "$SRC" reset --hard "origin/$BRANCH"
fi

# The bash process that started this update still has the old script in memory.
# Re-exec the pulled refresh-ui so new steps (SSL helpers, migrate order, …) run.
if [[ "${OWNSWITCH_REFRESH_REEXEC:-}" != "1" ]]; then
  export OWNSWITCH_REFRESH_REEXEC=1
  echo "Re-executing updated refresh-ui.sh…"
  exec bash "$SRC/deploy/refresh-ui.sh"
fi

test -f "$SRC/public/index.php" || { echo "FATAL: $SRC/public/index.php missing"; exit 1; }
test -f "$SRC/public/assets/css/app.css" || { echo "FATAL: $SRC/public/assets/css/app.css missing"; exit 1; }
test -f "$SRC/app/Views/layout.php" || { echo "FATAL: layout.php missing"; exit 1; }

if ! grep -q 'os-auth-page\|os-topbar\|os-brand-mark' "$SRC/app/Views/layout.php"; then
  echo "FATAL: pulled layout.php does not contain the new CRM markup — wrong commit?"
  exit 1
fi
if ! grep -q 'MISSION CONTROL\|mc-pause-banner\|Calling paused' "$SRC/app/Views/dashboard/customer.php"; then
  echo "FATAL: customer dashboard missing Mission Control / Calling paused markup — wrong commit?"
  exit 1
fi
if grep -q 'usage, balance & call quality' "$SRC/app/Views/dashboard/customer.php"; then
  echo "FATAL: old customer dashboard still present (usage, balance & call quality) — pull failed?"
  exit 1
fi

HEAD="$(git -C "$SRC" rev-parse --short HEAD)"
echo "Source OK at $HEAD (Mission Control dashboard present)"

getent passwd ownswitch >/dev/null 2>&1 || useradd --system --home-dir "$OWN_OPT" --shell /usr/sbin/nologin --user-group ownswitch
install -d -m 0755 "$OWN_OPT/releases"
install -d -m 0750 -o ownswitch -g ownswitch "$OWN_VAR/storage/cache" "$OWN_VAR/storage/uploads" "$OWN_VAR/storage/uploads/branding" 2>/dev/null || true

REL="$OWN_OPT/releases/ui-$(date -u +%Y%m%d%H%M%S)-${HEAD}"
mkdir -p "$REL"
rsync -a --delete \
  --exclude '.git/' --exclude '.github/' --exclude 'deploy/' --exclude '/docs/' --exclude 'tests/' \
  --exclude 'node_modules/' --exclude '.env' --exclude '.env.*' --exclude '*.md' \
  --exclude '/storage/' --exclude '/var/' \
  "$SRC/" "$REL/"

test -f "$REL/public/index.php"
test -f "$REL/public/assets/css/app.css"
test -f "$REL/app/Views/layout.php"
# Portal Documentation UI (must not be excluded by /docs/ markdown folder rule)
test -f "$REL/app/Views/docs/index.php" || { echo "FATAL: app/Views/docs missing from release — check rsync excludes"; exit 1; }
test -f "$REL/app/Controllers/DocsController.php" || { echo "FATAL: DocsController missing from release"; exit 1; }
rm -f "$REL/.ownswitch-placeholder"
ln -sfn "$OWN_VAR/storage" "$REL/storage"
chown -R root:ownswitch "$REL"
# 0751 on the release root: other needs +x to traverse into public (nginx runs as www-data).
# Do NOT chmod -R o= on $REL itself — that causes static asset 404s.
chmod 0751 "$REL"
find "$REL" -mindepth 1 -maxdepth 1 ! -name public ! -name storage -exec chmod -R u=rwX,g=rX,o= {} +
chmod -R u=rwX,go=rX "$REL/public"
chmod 0755 /opt/ownswitch /opt/ownswitch/releases 2>/dev/null || true
# Ensure nginx can read group-owned trees even if a future chmod tightens other.
if id www-data >/dev/null 2>&1; then
  usermod -aG ownswitch www-data 2>/dev/null || true
fi

# Migrate the new release BEFORE switching current and restarting PHP.
# Switching first left the portal on new code with the old schema (500: tables missing)
# and the in-flight Update request never reached its redirect.
if [[ -f "$REL/bin/migrate.php" ]]; then
  echo "Running migrations…"
  if runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="${OWN_ETC}/ownswitch.env" php "$REL/bin/migrate.php"; then
    echo "Migrations OK"
  elif env OWNSWITCH_ENV_FILE="${OWN_ETC}/ownswitch.env" php "$REL/bin/migrate.php"; then
    echo "Migrations OK (root)"
  else
    echo "FATAL: migrations failed — leaving the current release in place" >&2
    exit 1
  fi
fi

ln -sfn "$REL" "$OWN_OPT/current"
echo "Switched current → $REL"

# Detect PHP-FPM unit (8.3 preferred)
php_fpm_unit() {
  for s in php8.3-fpm php8.2-fpm php8.1-fpm php-fpm; do
    if systemctl cat "${s}.service" >/dev/null 2>&1; then
      echo "$s"
      return 0
    fi
  done
  echo "php8.3-fpm"
}
PHP_FPM_SVC="$(php_fpm_unit)"

# Keep nginx document root on /opt/ownswitch/current/public
NGINX_CONF="/etc/nginx/sites-available/ownswitch.conf"
if [[ -f "$NGINX_CONF" ]]; then
  if grep -qE 'root .+/public;' "$NGINX_CONF"; then
    sed -i -E 's#root[[:space:]]+[^;]+/public;#root /opt/ownswitch/current/public;#g' "$NGINX_CONF"
    echo "Nginx root → /opt/ownswitch/current/public"
  fi
  if nginx -t; then
    systemctl reload nginx || systemctl restart nginx || true
  else
    echo "WARN: nginx -t failed — leaving nginx running; fix config then: systemctl reload nginx" >&2
  fi
else
  echo "WARN: $NGINX_CONF missing — skip nginx rewrite"
fi

# PHP-FPM is restarted after this script exits. Restarting it here kills the
# portal request before it can redirect the admin back to /settings/version.

# Persist APP_SRC
if [[ -f "$OWN_ETC/install.conf" ]]; then
  grep -v '^APP_SRC=' "$OWN_ETC/install.conf" > /tmp/ownswitch-ic.$$ || true
  echo "APP_SRC=$SRC" >> /tmp/ownswitch-ic.$$
  mv /tmp/ownswitch-ic.$$ "$OWN_ETC/install.conf"
fi

# Keep fail2ban helper + SIP/portal jails in sync
if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sh" ]]; then
  install -m 0755 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sh" /usr/local/sbin/ownswitch-fail2ban
  if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sudoers" ]]; then
    install -m 0440 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sudoers" /etc/sudoers.d/ownswitch-fail2ban
    visudo -cf /etc/sudoers.d/ownswitch-fail2ban >/dev/null 2>&1 || true
  fi
fi
# IP-based SIP peer firewall (firewalld or ufw)
if [[ -f "$SRC/deploy/templates/firewall/ownswitch-sip-peer-fw.sh" ]]; then
  install -m 0755 "$SRC/deploy/templates/firewall/ownswitch-sip-peer-fw.sh" /usr/local/sbin/ownswitch-sip-peer-fw
  if [[ -f "$SRC/deploy/templates/firewall/ownswitch-sip-peer-fw.sudoers" ]]; then
    install -m 0440 "$SRC/deploy/templates/firewall/ownswitch-sip-peer-fw.sudoers" /etc/sudoers.d/ownswitch-sip-peer-fw
    visudo -cf /etc/sudoers.d/ownswitch-sip-peer-fw >/dev/null 2>&1 || true
  fi
  mkdir -p /var/lib/ownswitch/sip-peer-fw
  chmod 0750 /var/lib/ownswitch/sip-peer-fw 2>/dev/null || true
fi
# Portal “Version → Update now” (helper + sudoers, always refresh)
if [[ -f "$SRC/deploy/install-update-helper.sh" ]]; then
  bash "$SRC/deploy/install-update-helper.sh" >/dev/null 2>&1 || true
elif [[ -f "$SRC/deploy/templates/update/ownswitch-update.sh" ]]; then
  install -m 0755 "$SRC/deploy/templates/update/ownswitch-update.sh" /usr/local/sbin/ownswitch-update
  if [[ -f "$SRC/deploy/templates/update/ownswitch-update.sudoers" ]]; then
    install -m 0440 "$SRC/deploy/templates/update/ownswitch-update.sudoers" /etc/sudoers.d/ownswitch-update
    visudo -cf /etc/sudoers.d/ownswitch-update >/dev/null 2>&1 || true
  fi
fi
if [[ -f "$SRC/deploy/install-ssl-webrtc-helpers.sh" ]]; then
  bash "$SRC/deploy/install-ssl-webrtc-helpers.sh" >/dev/null 2>&1 || true
fi
if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-portal.conf" ]]; then
  install -m 0644 "$SRC/deploy/templates/fail2ban/ownswitch-portal.conf" /etc/fail2ban/filter.d/ownswitch-portal.conf
fi
if [[ -f "$SRC/deploy/templates/fail2ban/opensips-auth.conf" ]]; then
  install -m 0644 "$SRC/deploy/templates/fail2ban/opensips-auth.conf" /etc/fail2ban/filter.d/opensips-auth.conf
fi
if [[ -f "$SRC/deploy/templates/fail2ban/ownswitch-portal.local" && ! -f /etc/fail2ban/jail.d/ownswitch-portal.local ]]; then
  install -m 0644 "$SRC/deploy/templates/fail2ban/ownswitch-portal.local" /etc/fail2ban/jail.d/ownswitch-portal.local
fi
cat >/etc/cron.d/ownswitch-sip-firewall <<'EOF'
# Keep the SIP ban list on OpenSIPS auth failures, and never ban this host or a carrier trunk.
*/10 * * * * root /usr/local/sbin/ownswitch-fail2ban ensure-sip >/dev/null 2>&1
EOF
chmod 0644 /etc/cron.d/ownswitch-sip-firewall
if [[ -x /usr/local/sbin/ownswitch-fail2ban ]]; then
  /usr/local/sbin/ownswitch-fail2ban ensure-sip || echo "WARN: sip jail ensure failed" >&2
fi

echo
echo "== Verify =="
echo "layout markers: $(grep -c 'os-auth-page\|os-topbar\|os-brand-mark' "$OWN_OPT/current/app/Views/layout.php" || true)"
echo "mission-control: $(grep -c 'MISSION CONTROL\|mc-pause-banner' "$OWN_OPT/current/app/Views/dashboard/customer.php" || true)"
echo "mc-css: $(grep -c 'mc-pause-banner\|mc-low-banner' "$OWN_OPT/current/public/assets/css/app.css" || true)"
echo "css bytes: $(wc -c < "$OWN_OPT/current/public/assets/css/app.css")"
ls -la "$OWN_OPT/current/public/assets/css/app.css" "$OWN_OPT/current/public/assets/js/app.js"
if ! grep -q 'MISSION CONTROL\|mc-pause-banner' "$OWN_OPT/current/app/Views/dashboard/customer.php"; then
  echo "FATAL: deployed current/ still has old customer dashboard" >&2
  exit 1
fi

# Recordings dir: ownswitch + rtpengine (or opensips) can write; portal can read.
REC_DIR="${RECORDINGS_DIR:-/var/lib/ownswitch/recordings}"
install -d -m 2770 -o ownswitch -g ownswitch "$REC_DIR" 2>/dev/null || true
if getent group rtpengine >/dev/null 2>&1; then
  chown ownswitch:rtpengine "$REC_DIR" 2>/dev/null || true
  chmod 2770 "$REC_DIR" 2>/dev/null || true
fi
if systemctl is-active --quiet opensips; then
  echo "OpenSIPS: active"
else
  echo "WARN: opensips service is not active" >&2
fi

# Final service health (after helpers / migrations).
# Delay the PHP restart so the Update request can redirect to /settings/version first.
echo "Scheduling ${PHP_FPM_SVC} restart in 5s…"
if command -v systemd-run >/dev/null 2>&1 && systemd-run --on-active=5s --unit="ownswitch-fpm-restart-$$" --collect /bin/systemctl restart "$PHP_FPM_SVC"; then
  echo "PHP-FPM restart queued"
else
  nohup /bin/bash -c "sleep 5; systemctl restart $(printf '%q' "$PHP_FPM_SVC") || systemctl restart php8.3-fpm || true" >/tmp/ownswitch-fpm-restart.log 2>&1 &
  disown || true
  echo "PHP-FPM restart queued (background)"
fi
systemctl reload nginx 2>/dev/null || true

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$OWN_ETC/install.conf" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
echo
echo "Hard-refresh the browser (Ctrl+Shift+R)."
echo "Checks:"
echo "  curl -k -I https://${DOMAIN}/assets/css/app.css"
echo "  curl -k -s https://${DOMAIN}/login | grep -E 'os-auth-page|bootstrap-icons|os-brand-mark'"
echo "  systemctl is-active nginx ${PHP_FPM_SVC}"
echo "Done ($HEAD)."
if ! systemctl is-active --quiet nginx || { ! systemctl is-active --quiet "$PHP_FPM_SVC" 2>/dev/null && ! systemctl is-active --quiet php8.3-fpm 2>/dev/null; }; then
  echo "WARN: services unhealthy — run: sudo bash $SRC/deploy/fix-portal-502.sh" >&2
fi
