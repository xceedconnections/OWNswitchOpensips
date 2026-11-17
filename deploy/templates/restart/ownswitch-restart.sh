#!/usr/bin/env bash
# Ownswitch restart helper — OpenSIPS service + host reboot.
# Installed as /usr/local/sbin/ownswitch-restart (root via sudoers).
set -euo pipefail

CMD="${1:-status}"

opensips_active() {
  systemctl is-active --quiet opensips 2>/dev/null
}

status() {
  local active=0 state load uptime
  state="$(systemctl is-active opensips 2>/dev/null || echo unknown)"
  load="$(systemctl is-enabled opensips 2>/dev/null || echo unknown)"
  [[ "$state" == "active" ]] && active=1
  uptime="$(uptime -p 2>/dev/null || true)"
  echo "opensips_active=${active}"
  echo "opensips_state=${state}"
  echo "opensips_enabled=${load}"
  echo "host_uptime=${uptime}"
  if (( active )); then
    echo "message=OpenSIPS is active. Host ${uptime:-uptime unknown}."
    return 0
  fi
  echo "message=OpenSIPS state: ${state}. Host ${uptime:-uptime unknown}."
  return 1
}

restart_opensips() {
  if ! systemctl list-unit-files opensips.service >/dev/null 2>&1 && ! systemctl status opensips >/dev/null 2>&1; then
    echo "opensips_active=0"
    echo "message=opensips.service was not found on this host."
    return 1
  fi
  if ! systemctl restart opensips; then
    echo "opensips_active=0"
    echo "message=systemctl restart opensips failed. Check: journalctl -u opensips -n 40"
    return 1
  fi
  sleep 1
  if opensips_active; then
    echo "opensips_active=1"
    echo "opensips_state=active"
    echo "message=OpenSIPS restarted and is active. Active calls and registrations may drop briefly."
    return 0
  fi
  local state
  state="$(systemctl is-active opensips 2>/dev/null || echo unknown)"
  echo "opensips_active=0"
  echo "opensips_state=${state}"
  echo "message=OpenSIPS restart finished but service state is ${state}. Check: journalctl -u opensips -n 40"
  return 1
}

reboot_server() {
  # Delay so the portal can return a success response before the host goes down.
  nohup bash -c 'sleep 3; /sbin/shutdown -r now "Ownswitch portal restart"' >/tmp/ownswitch-reboot.log 2>&1 &
  echo "reboot_scheduled=1"
  echo "message=Server reboot scheduled in a few seconds. The portal will be unreachable until the host is back."
  return 0
}

case "$CMD" in
  status) status; exit $? ;;
  opensips) restart_opensips; exit $? ;;
  server|reboot) reboot_server; exit $? ;;
  *) echo "message=Usage: $0 status|opensips|server"; exit 2 ;;
esac
