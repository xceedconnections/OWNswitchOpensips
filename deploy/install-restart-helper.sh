#!/usr/bin/env bash
# Install Settings → Restart helper + sudoers.
# Run as root:  sudo bash /opt/ownswitch-src/deploy/install-restart-helper.sh
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
if [[ ! -f "$SRC/deploy/templates/restart/ownswitch-restart.sh" ]]; then
  if [[ -f /opt/ownswitch/current/deploy/templates/restart/ownswitch-restart.sh ]]; then
    SRC="$(readlink -f /opt/ownswitch/current)"
  fi
fi

HELPER_SRC="$SRC/deploy/templates/restart/ownswitch-restart.sh"
SUDOERS_SRC="$SRC/deploy/templates/restart/ownswitch-restart.sudoers"

[[ -f "$HELPER_SRC" ]] || { echo "Missing $HELPER_SRC — pull latest main first"; exit 1; }

install -m 0755 "$HELPER_SRC" /usr/local/sbin/ownswitch-restart

if [[ -f "$SUDOERS_SRC" ]]; then
  install -m 0440 "$SUDOERS_SRC" /etc/sudoers.d/ownswitch-restart
else
  cat >/etc/sudoers.d/ownswitch-restart <<'EOF'
# Ownswitch portal Settings → Restart
ownswitch ALL=(root) NOPASSWD: /usr/local/sbin/ownswitch-restart
EOF
  chmod 0440 /etc/sudoers.d/ownswitch-restart
fi
visudo -cf /etc/sudoers.d/ownswitch-restart

echo "OK: /usr/local/sbin/ownswitch-restart"
echo "OK: sudoers ownswitch-restart"
echo "Test: sudo -u ownswitch sudo -n /usr/local/sbin/ownswitch-restart status"
ls -la /usr/local/sbin/ownswitch-restart
