# Delayed Bye & MCD

Trunk feature that delays SIP **BYE** toward the vendor after the real hangup, so the carrier’s connected time can meet a minimum (MCD). Customer (sell) billing always stays on real talk time.

## Where to configure

**Routes → Trunks → Edit → Delayed Bye** tab

| Field | Default | Meaning |
|---|---|---|
| Delayed Bye (s) | `0` | Extra seconds to delay BYE after hangup |
| Delayed Bye MCD (s) | `0` | Minimum duration used to calculate the hold |
| Count Delayed duration in Buying | off | When on, CDR **buy/cost** uses the extended duration |

Feature is **off** when both Delayed Bye and MCD are `0`.

## Formula

```
extension = max(0, MCD − actual) + Delayed Bye
carrier_connected ≈ actual + extension
               = max(actual, MCD) + Delayed Bye
```

### Example (MCD = 25, Delayed Bye = 0)

| Actual call | Extension | Carrier ≈ |
|---|---|---|
| 5 s | 20 s | 25 s |
| 10 s | 15 s | 25 s |
| 20 s | 5 s | 25 s |
| 25 s | 0 s | 25 s |
| 40 s | 0 s | 40 s |

With Delayed Bye = 2 on an 8 s call and MCD 25: extension = 17 + 2 = 19 → carrier ≈ 27 s.

## Billing behaviour

| Side | Duration used |
|---|---|
| Customer sell / ledger | **Actual** talk time only |
| Carrier buy / profit | Actual, **or** extended if “Count Delayed duration in Buying” is checked |

If the vendor does **not** bill the delayed seconds, leave the checkbox **off**. Admin CDR / margin then still shows true buy vs sell on actual.

## OpenSIPS wiring

1. Trunk dialstring sets B-leg channel vars when Delayed Bye or MCD &gt; 0:
   - `os_delayed_bye`, `os_delayed_bye_mcd`, `os_delayed_bye_count_buying`
   - `session_in_hangup_hook=true`
   - `api_hangup_hook=lua delayed_bye.lua`
2. Script: `/usr/local/freeswitch/share/freeswitch/scripts/delayed_bye.lua`
3. On hangup (when we are clearing toward the vendor), Lua sleeps for `extension` seconds so BYE is delayed. If the callee already sent BYE (`recv_bye`), no delay.

### Enable on a production host

```bash
cd /opt/ownswitch-src
git fetch origin main && git reset --hard origin/main
sudo bash deploy/refresh-ui.sh

sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php /opt/ownswitch/current/bin/migrate.php

sudo bash deploy/fix-delayed-bye.sh
sudo bash deploy/fix-esl-auth.sh   # loads mod_lua via OpenSIPS / RTPengine restart / ESL

PASS=$(tr -d '\r\n' </etc/ownswitch/secrets/freeswitch_esl_password)
/usr/local/freeswitch/bin/fs_cli -H 127.0.0.1 -P 8021 -p "$PASS" -x 'module_exists mod_lua'
```

Migration `060_delayed_bye.sql` adds the three columns on `carrier_trunks`.

## Verification checklist

1. Trunk: MCD `25`, Delayed Bye `0`, Buying checkbox **on**.
2. Place an ~8 s answered call through that trunk.
3. Confirm vendor/side SIP shows hangup ~25 s after answer (BYE delayed ~17 s).
4. Portal CDR: actual ≈ 8; customer charge on ~8 s; carrier billable/cost on ~25 s (after carrier I/S).
5. Turn Buying checkbox **off**, place another short call: buy should follow actual again.

## Limitations

- Applies per trunk that answered; failover legs that never answer are unaffected.
- Does not change customer prepaid reservation math (still based on real usage at settlement).
- Requires `mod_lua` and the installed `delayed_bye.lua` script.
- Callee-initiated BYE is not delayed (nothing left to hold toward the vendor).
