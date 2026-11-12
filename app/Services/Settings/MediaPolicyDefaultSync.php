<?php

declare(strict_types=1);

namespace Ownswitch\Services\Settings;

use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Support\MediaPolicy;
use PDO;

/**
 * Keep OpenSIPS in sync when Settings → Default media policy changes:
 * 1) Patch OWN_DEFAULT_MEDIA fallbacks in opensips.cfg
 * 2) Ensure record-call=yes is present on record rtpengine lines
 * 3) Re-push effective media_policy for portal SIP users that inherit Default
 */
final class MediaPolicyDefaultSync
{
    public function __construct(private PDO $pdo, private string $cfgPath = '/etc/opensips/opensips.cfg')
    {
    }

    /** @return array{ok:bool,detail:string,policy:string,subscribers:int} */
    public function sync(): array
    {
        $policy = MediaPolicy::resolve(null, $this->pdo);
        $patched = $this->patchCfg($policy);
        $n = $this->resyncDefaultSubscribers($policy);
        $detail = ($patched['ok'] ? 'cfg:' . $patched['detail'] : 'cfg-skip:' . $patched['detail'])
            . '; subscribers:' . $n;
        return [
            'ok' => $patched['ok'] || $n > 0,
            'detail' => $detail,
            'policy' => $policy,
            'subscribers' => $n,
        ];
    }

    /** @return array{ok:bool,detail:string} */
    private function patchCfg(string $policy): array
    {
        if (!is_readable($this->cfgPath) || !is_writable($this->cfgPath)) {
            return ['ok' => false, 'detail' => 'opensips.cfg not writable'];
        }
        $text = (string) file_get_contents($this->cfgPath);
        $before = $text;

        // Only lines marked # OWN_DEFAULT_MEDIA (not carrier inbound hardcodes).
        $text = preg_replace(
            '/(# OWN_DEFAULT_MEDIA\s*\n\s*\$avp\(os_media\)\s*=\s*")(direct|relay|record)(")/',
            '${1}' . $policy . '${3}',
            $text
        ) ?? $text;

        // Live cfg may predate record-call=yes — add it next to recording-label.
        $text = str_replace(
            'ICE=remove recording-label=',
            'ICE=remove record-call=yes recording-label=',
            $text
        );
        $text = str_replace(
            'rtcp-mux-demux recording-label=',
            'rtcp-mux-demux record-call=yes recording-label=',
            $text
        );
        // Avoid doubling if already present
        $text = str_replace(
            'record-call=yes record-call=yes ',
            'record-call=yes ',
            $text
        );

        if ($text === $before) {
            return ['ok' => true, 'detail' => 'unchanged'];
        }
        file_put_contents($this->cfgPath, $text);
        return ['ok' => true, 'detail' => 'updated'];
    }

    private function resyncDefaultSubscribers(string $policy): int
    {
        $prov = OpensipsProvisioner::fromConfig();
        if (!$prov->enabled()) {
            return 0;
        }
        try {
            $st = $this->pdo->query(
                "SELECT * FROM sip_accounts
                 WHERE status = 'active'
                   AND (media_policy IS NULL OR media_policy = '' OR LOWER(media_policy) IN ('default','inherit'))"
            );
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\Throwable) {
            return 0;
        }
        $n = 0;
        foreach ($rows as $row) {
            try {
                $row['_effective_media'] = $policy;
                $prov->upsertSubscriber(
                    (string) $row['username'],
                    (string) ($row['domain'] ?? ''),
                    (string) ($row['ha1'] ?? ''),
                    (string) ($row['ha1b'] ?? ''),
                    $row
                );
                $n++;
            } catch (\Throwable $e) {
                error_log('[ownswitch] media default sync ' . ($row['username'] ?? '') . ': ' . $e->getMessage());
            }
        }
        return $n;
    }
}
