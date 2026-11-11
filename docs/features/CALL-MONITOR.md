# Call monitoring (Spy / Whisper / Barge)

Admin live-call tools on **Calls Online**. Your monitor SIP extension must be registered on this OpenSIPS.

| Mode | Behaviour |
|---|---|
| **Spy** | Listen only — RTPengine forks both legs (sendonly) to your phone |
| **Whisper** | Coach the caller — bidirectional subscribe to the caller leg |
| **Barge** | Talk to both sides — bidirectional subscribe to all participants |

## Requirements

1. The live call must use **media relay or record** (not Directmedia). RTPengine must be in the media path.
2. Set **Monitor extension** on Calls Online (e.g. `1001`) and keep that SIP user registered.
3. Portal can reach RTPengine NG on `RTPENGINE_HOST` / `RTPENGINE_PORT` (default `127.0.0.1:22222`).

## How it works

1. Portal looks up the dialog Call-ID and your extension’s Contact.
2. RTPengine `subscribe request` creates a media fork of the live call.
3. Portal INVITEs your phone with that SDP offer; on 200 OK it completes `subscribe answer`.
4. Your phone receives (and for whisper/barge, can send) forked media.

## Notes

- Hangup on Calls Online still uses OpenSIPS `dlg_end_dlg` (BYE both legs) and is unrelated to monitoring.
- If subscribe fails with “direct” media, change the SIP user / call path to relay and retry.
