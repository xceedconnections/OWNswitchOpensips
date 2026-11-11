# Delayed Bye & MCD

Trunk feature that can extend **carrier (buy) billable duration** after a short real call, so buy cost can meet a minimum connected time (MCD). Customer (sell) billing always stays on real talk time.

## Where to configure

**Routes → Trunks → Edit → Delayed Bye** tab

| Field | Default | Meaning |
|---|---|---|
| Delayed Bye (s) | `0` | Extra seconds added to the buy-side duration formula |
| Delayed Bye MCD (s) | `0` | Minimum duration used to calculate the extension |
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

## Billing behaviour (OpenSIPS build)

| Side | Duration used |
|---|---|
| Customer sell / ledger | **Actual** talk time only |
| Carrier buy / profit | Actual, **or** extended if “Count Delayed duration in Buying” is checked |

The portal CDR ingest applies this when rating the buy side (`CdrIngestService` + trunk columns from migration `060_delayed_bye.sql`).

## Signalling note

This OpenSIPS build does **not** hold the SIP BYE toward the vendor in the proxy script. Delayed Bye / MCD here is a **billing** control so your buy cost can match vendor MCD policies. If you need the vendor’s SIP CDR to literally show a longer connected time, that requires a media-server hangup hook (out of scope for OpenSIPS-only).

## Verification checklist

1. Trunk: MCD `25`, Delayed Bye `0`, Buying checkbox **on**.
2. Place an ~8 s answered call through that trunk.
3. Portal CDR: actual ≈ 8; customer charge on ~8 s; carrier billable/cost on ~25 s (after carrier I/S).
4. Turn Buying checkbox **off**, place another short call: buy should follow actual again.
