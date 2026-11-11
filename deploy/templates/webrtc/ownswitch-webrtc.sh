#!/usr/bin/env bash
# Ownswitch WebRTC helper — OpenSIPS proto_ws on 127.0.0.1:5066 + nginx /sip-ws.
# Installed as /usr/local/sbin/ownswitch-webrtc (root via sudoers).
set -euo pipefail

CMD="${1:-status}"
CFG="${OPENSIPS_CFG:-/etc/opensips/opensips.cfg}"
NGINX_SITE=/etc/nginx/sites-available/ownswitch.conf

have_ws_so() {
  find /usr/lib /usr/lib64 -type f \( -name proto_ws.so -o -name proto_wss.so \) -path '*opensips/modules*' 2>/dev/null | head -n1 | grep -q .
}

ws_listening() {
  ss -H -ltn 'sport = :5066' 2>/dev/null | grep -q 127.0.0.1 || \
  ss -H -ltn 'sport = :5066' 2>/dev/null | grep -q .
}

cfg_has_ws() {
  [[ -f "$CFG" ]] && grep -qE 'loadmodule\s+"proto_ws\.so"|socket=ws:' "$CFG"
}

status() {
  local enabled=0 listening=0
  cfg_has_ws && enabled=1
  ws_listening && listening=1
  echo "enabled=${enabled}"
  echo "listening=${listening}"
  if (( enabled && listening )); then
    echo "message=OpenSIPS WebSocket SIP is enabled on 127.0.0.1:5066 (nginx /sip-ws)."
    return 0
  fi
  if ! have_ws_so; then
    echo "message=proto_ws.so missing. Try: apt-get install -y opensips-wss-module"
    return 1
  fi
  echo "message=WebSocket SIP is not active yet. Use Enable in the portal or: sudo ownswitch-webrtc enable"
  return 1
}

enable() {
  export DEBIAN_FRONTEND=noninteractive
  # OpenSIPS 3.6 apt name is opensips-wss-module (legacy name opensips-websocket-module does not exist)
  apt-get install -y opensips-wss-module 2>/dev/null || true
  apt-get install -y opensips-websocket-module 2>/dev/null || true
  if ! have_ws_so; then
    echo "enabled=0"
    echo "listening=0"
    echo "message=Could not enable WebSocket SIP (proto_ws.so missing after opensips-wss-module)."
    return 1
  fi
  [[ -f "$CFG" ]] || { echo "message=Missing ${CFG}"; return 1; }

  local mpath
  mpath="$(dirname "$(find /usr/lib /usr/lib64 -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1)")/"
  [[ -d "$mpath" ]] || { echo "message=OpenSIPS modules path not found"; return 1; }

  if ! grep -q 'loadmodule "proto_ws.so"' "$CFG"; then
    cp -a "$CFG" "/var/backups/ownswitch-config/opensips.cfg.webrtc.$(date -u +%Y%m%dT%H%M%SZ)" 2>/dev/null || true
    # Insert after proto_tcp if present, else after mpath
    if grep -q 'loadmodule "proto_tcp.so"' "$CFG"; then
      sed -i '/loadmodule "proto_tcp.so"/a loadmodule "proto_ws.so"' "$CFG"
    else
      sed -i "/^mpath=/a loadmodule \"proto_ws.so\"" "$CFG"
    fi
  fi
  if ! grep -qE 'socket=ws:127\.0\.0\.1:5066' "$CFG"; then
    sed -i '/^socket=tcp:/a socket=ws:127.0.0.1:5066' "$CFG" || \
      printf '\nsocket=ws:127.0.0.1:5066\n' >>"$CFG"
  fi

  if ! opensips -c -f "$CFG" >/tmp/opensips-ws-check.out 2>&1; then
    echo "enabled=0"
    echo "listening=0"
    echo "message=opensips -c failed after WebSocket edit — see /tmp/opensips-ws-check.out"
    return 1
  fi

  systemctl restart opensips
  sleep 1
  if ws_listening; then
    echo "enabled=1"
    echo "listening=1"
    echo "message=OpenSIPS WebSocket SIP listening on 127.0.0.1:5066. Dialer uses wss://host/sip-ws"
    return 0
  fi
  echo "enabled=1"
  echo "listening=0"
  echo "message=Config updated but :5066 is not listening yet. Check: journalctl -u opensips -n 40"
  return 1
}

case "$CMD" in
  status) status; exit $? ;;
  enable) enable; exit $? ;;
  *) echo "message=Usage: $0 status|enable"; exit 2 ;;
esac
