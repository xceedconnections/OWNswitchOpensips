# Ownswitch Operator Guide

Practical reference for the portal options — the same material is available in the UI under **Settings → Documentation**. Every form field with a description also has a **?** popup next to its label.

## Product map

| Area | Menu | Purpose |
|---|---|---|
| Customers | Clients → Users | Billing accounts, balance, tariff assignment |
| SIP peers | Clients → SIP Users | Authentication, CLI, media, CPS/CC |
| Carriers | Routes → Providers / Trunks | Vendor gateways and dial rewrite |
| Routing | Routes → Trunk Groups | Prefix → trunk failover / LCR / ASR |
| Sell rates | Rates → Plans / Tariffs | Customer charging |
| Buy rates | Routes → Provider Rates | Carrier cost |
| CDRs | Reports → CDR | Actual / billable durations, sell, buy, margin |
| System | Settings | Branding, codecs, failover, kill switch |

## Call path (short)

1. OpenSIPS authenticates the SIP user (directory OpenSIPS DB provisioning).
2. Dialplan authorises balance, tariff, DNC, CLI, CAP.
3. Trunk group builds a failover bridge to one or more trunks.
4. On hangup, OpenSIPS accounting / CDR import → rate sell + buy → ledger.

## Trunk options (summary)

| Field | Does |
|---|---|
| Host / Port / Transport | Vendor SIP endpoint |
| Auth | IP peer, REGISTER, or Invite digest |
| Add / Remove prefix | Dialled-number rewrite toward vendor |
| From user / domain | Force outbound CLI / From |
| CID add/remove / min/max | Digit CLI rewrite and length guards |
| CAP | Skip trunk when buy rate exceeds cap |
| DNC group | Block listed destinations before INVITE |
| Max call duration | `max call duration` on gateway leg |
| **Delayed Bye** | Extra seconds OpenSIPS holds BYE toward vendor |
| **Delayed Bye MCD** | Minimum connected duration for hold math |
| **Count Delayed duration in Buying** | Extend buy/cost on CDR when checked |
| Codecs | Gateway absolute codec string |

Full Delayed Bye wiring: [features/DELAYED-BYE.md](features/DELAYED-BYE.md). Spy/Whisper/Barge: [features/CALL-MONITOR.md](features/CALL-MONITOR.md).

## SIP user options (summary)

| Field | Does |
|---|---|
| Type (Dynamic / IP) | Digest REGISTER vs fixed IP peer |
| Allowed IP(s) | Dynamic = `dynamic`; IP mode = allowlist |
| CallerID mode / group | Outbound CLI source |
| CLI restrictions | Block listed CLIs (403) |
| Trunk group | Optional routing override |
| Media / record | Direct, relay, or record |
| CC / CPS / Max duration | Concurrent, rate, hard hangup |
| CAP | Block destinations above sell rate |

## Billing notes

- Customer charge uses **actual** talk time (then I/S increments).
- Carrier cost uses actual, unless trunk Delayed Bye “Count … Buying” is on — then buy uses `max(actual, MCD) + Delayed Bye`.
- Canonical formulas: [`BILLING_RULES.md`](../BILLING_RULES.md).

## Field help in the UI

Click **?** beside any labelled field on create/edit forms (trunks, SIP users, settings, …). The popup is the authoritative short description for that control.
