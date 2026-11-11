# Delayed Bye & MCD

Trunk feature that holds the **carrier SIP BYE** after the customer hangs up early, so the vendor’s connected time can meet a minimum (MCD). Customer (sell) billing always stays on real talk time.

## Where to configure

**Routes → Trunks → Edit → Delayed Bye** tab

| Field | Default | Meaning |
|---|---|---|
| Delayed Bye (s) | `0` | Extra seconds always held after hangup |
| Delayed Bye MCD (s) | `0` | Minimum target duration. Hold includes `max(0, MCD − actual)` |
| Count Delayed duration in Buying | off | When on, CDR **buy/cost** uses the extended (held) duration |

Feature is **off** when both Delayed Bye and MCD are `0`.

## Formula (hold seconds)

```
hold = max(0, MCD − actual) + Delayed Bye
```

OpenSIPS stores `dbye` / `dmcd` on the trunk gateway attrs, then on a customer BYE (`$DLG_dir == downstream`) sleeps that many seconds before relaying BYE to the vendor (cap 600s).

### Example (MCD = 25, Delayed Bye = 0)

| Actual call | Hold | Carrier connected ≈ |
|---|---|---|
| 5 s | 20 s | 25 s |
| 10 s | 15 s | 25 s |
| 20 s | 5 s | 25 s |
| 25 s | 0 s | 25 s |
| 40 s | 0 s | 40 s |

## Billing behaviour

| Side | Duration used |
|---|---|
| Customer sell / ledger | **Actual** talk time (`acc.talk` when Delayed Bye held the BYE) |
| Carrier buy / profit | Full ACC duration (includes hold) when “Count Delayed duration in Buying” is on; otherwise actual |

Portal ingest (`CdrIngestService` + `OpensipsAccImporter`) prefers SIP-held ACC duration for buy when present; otherwise applies the same formula for billing-only trunks.

## Deploy notes

1. Re-render OpenSIPS cfg (stage 50 / refresh) so `route[delayed_bye_hold]` is present.
2. Re-sync trunks so `dr_gateways.attrs` includes `dbye=` / `dmcd=`.
3. ACC table needs a `talk` column (stage 50 adds it).

## Verification checklist

1. Trunk: MCD `25`, Delayed Bye `0`, Buying checkbox **on**.
2. Place an ~8 s answered call through that trunk.
3. Vendor/OpenSIPS ACC duration ≈ 25 s; portal sell on ~8 s; buy on ~25 s when the checkbox is on.
