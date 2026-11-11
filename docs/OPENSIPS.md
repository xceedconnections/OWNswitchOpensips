# OpenSIPS portal

This repository is the **OpenSIPS** build of Ownswitch. OpenSIPS listens on UDP/TCP **5060**. The portal provisions `subscriber`, `address`, `dr_gateways` / `dr_rules`, and optional `uac_registrant` into the `opensips` MySQL database, then reloads via MI (HTTP `127.0.0.1:8888/mi` or FIFO).

Repository: https://github.com/xceedconnections/OWNswitchOpensips

## Fresh Ubuntu 24.04

```bash
git clone https://github.com/xceedconnections/OWNswitchOpensips.git /opt/ownswitch-src
cd /opt/ownswitch-src
sudo bash deploy/install.sh --yes
```

Or one-liner:

```bash
curl -fsSL https://raw.githubusercontent.com/xceedconnections/OWNswitchOpensips/main/install-server.sh \
  | sudo bash -s -- --domain voip.example.com --email ops@example.com
```

Health must show `opensips` active and `SIP 5060` owned by OpenSIPS.

Push trunks into drouting after the portal is up:

```bash
sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php /opt/ownswitch/current/bin/sync-opensips-trunks.php
```

## Host that still has FreeSWITCH

Stop FreeSWITCH so OpenSIPS can bind 5060, then re-run stage 50:

```bash
sudo systemctl disable --now freeswitch || true
cd /opt/ownswitch-src
git fetch origin && git reset --hard origin/main
sudo bash deploy/install.sh --only 50,90,99 --yes
```

Phones must register again to OpenSIPS. SIP usernames and passwords stay in the portal.
