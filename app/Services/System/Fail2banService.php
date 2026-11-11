<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

/**
 * Portal wrapper around /usr/local/sbin/ownswitch-fail2ban (sudo).
 * Covers SIP (opensips-auth) + portal login (ownswitch-portal) jails.
 */
final class Fail2banService
{
    private const HELPER = '/usr/local/sbin/ownswitch-fail2ban';
    public const JAIL_SIP = 'opensips-auth';
    public const JAIL_PORTAL = 'ownswitch-portal';

    public function available(): bool
    {
        return is_file(self::HELPER) && is_executable(self::HELPER);
    }

    /**
     * @return array{
     *   ok:bool,detail:string,enabled:bool,currently_banned:int,total_banned:int,
     *   banned:list<string>,ban_entries:list<array{ip:string,jail:string,label:string}>,
     *   ban_details:array<string,array>,jails:array<string,array<string,mixed>>,
     *   maxretry:string,findtime:string,bantime:string,jail_enabled:string,sip_port:string
     * }
     */
    public function status(bool $withDetails = true): array
    {
        $base = [
            'ok' => false,
            'detail' => '',
            'enabled' => false,
            'currently_banned' => 0,
            'total_banned' => 0,
            'banned' => [],
            'ban_entries' => [],
            'ban_details' => [],
            'jails' => [],
            'maxretry' => '5',
            'findtime' => '10m',
            'bantime' => '1h',
            'jail_enabled' => 'true',
            'sip_port' => '5060',
        ];
        if (!$this->available()) {
            $base['detail'] = 'Helper missing. On the server run: sudo bash /opt/ownswitch-src/deploy/fix-portal-firewall.sh';
            return $base;
        }
        [$code, $out, $err] = $this->run(['status-all']);
        if ($code !== 0) {
            // Older helper without status-all — fall back to SIP-only
            [$code, $out, $err] = $this->run(['status']);
            if ($code !== 0) {
                $base['detail'] = trim($err !== '' ? $err : $out) ?: 'fail2ban status failed';
                return $base;
            }
            return $this->statusFromSingle($out, $base, $withDetails);
        }
        $blocks = preg_split('/^---\s*$/m', $out) ?: [$out];
        $jails = [];
        $entries = [];
        $ips = [];
        $anyOn = false;
        $totalBanned = 0;
        foreach ($blocks as $block) {
            $kv = $this->parseKv($block);
            if (($kv['jail'] ?? '') === '' && !isset($kv['enabled'])) {
                continue;
            }
            $jail = (string) ($kv['jail'] ?? '');
            if ($jail === '') {
                continue;
            }
            $list = [];
            if (($kv['banned_list'] ?? '') !== '') {
                foreach (preg_split('/[\s,]+/', (string) $kv['banned_list']) ?: [] as $ip) {
                    $ip = trim($ip);
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                        $list[] = $ip;
                    }
                }
            }
            $enabled = ((string) ($kv['enabled'] ?? '0')) === '1';
            $anyOn = $anyOn || $enabled;
            $totalBanned += (int) ($kv['total_banned'] ?? 0);
            $label = (string) ($kv['label'] ?? $jail);
            $jails[$jail] = [
                'jail' => $jail,
                'label' => $label,
                'enabled' => $enabled,
                'currently_banned' => (int) ($kv['currently_banned'] ?? count($list)),
                'total_banned' => (int) ($kv['total_banned'] ?? 0),
                'banned' => $list,
                'maxretry' => (string) ($kv['maxretry'] ?? '5'),
                'findtime' => (string) ($kv['findtime'] ?? '10m'),
                'bantime' => (string) ($kv['bantime'] ?? '1h'),
                'jail_enabled' => (string) ($kv['jail_enabled'] ?? 'true'),
                'sip_port' => (string) ($kv['sip_port'] ?? ''),
                'detail' => (string) ($kv['detail'] ?? ''),
            ];
            foreach ($list as $ip) {
                $entries[] = ['ip' => $ip, 'jail' => $jail, 'label' => $label];
                $ips[] = $ip;
            }
        }
        $ips = array_values(array_unique($ips));
        $details = [];
        if ($withDetails) {
            foreach ($entries as $e) {
                $key = $e['ip'] . '|' . $e['jail'];
                $details[$key] = $this->banDetail($e['ip'], $e['jail']);
            }
        }
        $sip = $jails[self::JAIL_SIP] ?? [];
        return [
            'ok' => true,
            'detail' => 'ok',
            'enabled' => $anyOn,
            'currently_banned' => count($entries),
            'total_banned' => $totalBanned,
            'banned' => $ips,
            'ban_entries' => $entries,
            'ban_details' => $details,
            'jails' => $jails,
            'maxretry' => (string) ($sip['maxretry'] ?? '5'),
            'findtime' => (string) ($sip['findtime'] ?? '10m'),
            'bantime' => (string) ($sip['bantime'] ?? '1h'),
            'jail_enabled' => (string) ($sip['jail_enabled'] ?? 'true'),
            'sip_port' => (string) ($sip['sip_port'] ?? '5060'),
        ];
    }

    /** @param array<string,mixed> $base */
    private function statusFromSingle(string $out, array $base, bool $withDetails = true): array
    {
        $kv = $this->parseKv($out);
        $list = [];
        if (($kv['banned_list'] ?? '') !== '') {
            foreach (preg_split('/[\s,]+/', (string) $kv['banned_list']) ?: [] as $ip) {
                $ip = trim($ip);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $list[] = $ip;
                }
            }
        }
        $entries = [];
        $details = [];
        foreach ($list as $ip) {
            $entries[] = ['ip' => $ip, 'jail' => self::JAIL_SIP, 'label' => 'SIP auth'];
            if ($withDetails) {
                $details[$ip . '|' . self::JAIL_SIP] = $this->banDetail($ip, self::JAIL_SIP);
            }
        }
        return [
            'ok' => true,
            'detail' => ($kv['detail'] ?? '') !== '' ? (string) $kv['detail'] : 'ok',
            'enabled' => ((string) ($kv['enabled'] ?? '0')) === '1',
            'currently_banned' => (int) ($kv['currently_banned'] ?? count($list)),
            'total_banned' => (int) ($kv['total_banned'] ?? 0),
            'banned' => $list,
            'ban_entries' => $entries,
            'ban_details' => $details,
            'jails' => [
                self::JAIL_SIP => [
                    'jail' => self::JAIL_SIP,
                    'label' => 'SIP auth',
                    'enabled' => ((string) ($kv['enabled'] ?? '0')) === '1',
                    'currently_banned' => count($list),
                    'total_banned' => (int) ($kv['total_banned'] ?? 0),
                    'banned' => $list,
                    'maxretry' => (string) ($kv['maxretry'] ?? '5'),
                    'findtime' => (string) ($kv['findtime'] ?? '10m'),
                    'bantime' => (string) ($kv['bantime'] ?? '1h'),
                    'jail_enabled' => (string) ($kv['jail_enabled'] ?? 'true'),
                    'sip_port' => (string) ($kv['sip_port'] ?? '5060'),
                ],
            ],
            'maxretry' => (string) ($kv['maxretry'] ?? '5'),
            'findtime' => (string) ($kv['findtime'] ?? '10m'),
            'bantime' => (string) ($kv['bantime'] ?? '1h'),
            'jail_enabled' => (string) ($kv['jail_enabled'] ?? 'true'),
            'sip_port' => (string) ($kv['sip_port'] ?? '5060'),
        ];
    }

    /**
     * @return array{ip:string,country:string,city:string,isp:string,reason:string,attempts:list<string>,jail:string}
     */
    public function banDetail(string $ip, string $jail = self::JAIL_SIP): array
    {
        $geo = \Ownswitch\Support\GeoIp::lookup($ip);
        if (($geo['country'] ?? '') === '' || ($geo['country'] ?? '') === 'Unknown') {
            [$gc, $gout] = $this->run(['geo', $ip]);
            if ($gc === 0 && trim($gout) !== '') {
                $gkv = $this->parseKv($gout);
                if (($gkv['country'] ?? '') !== '' && ($gkv['country'] ?? '') !== 'Unknown') {
                    $geo = [
                        'country' => (string) $gkv['country'],
                        'countryCode' => (string) ($gkv['countryCode'] ?? ''),
                        'city' => (string) ($gkv['city'] ?? ''),
                        'isp' => (string) ($gkv['isp'] ?? ''),
                    ];
                }
            }
        }
        $attempts = [];
        [$code, $out] = $this->run(['ip-log', $ip]);
        if ($code === 0 && trim($out) !== '') {
            foreach (preg_split("/\r\n|\n|\r/", trim($out)) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $attempts[] = $line;
                }
            }
        }
        if ($jail === self::JAIL_PORTAL) {
            $reason = 'Repeated portal login failures (wrong password or CAPTCHA)';
            $portalLines = array_values(array_filter($attempts, static fn ($l) => stripos($l, 'portal') !== false || stripos($l, 'ownswitch-portal') !== false));
            if ($portalLines !== []) {
                $attempts = $portalLines;
            }
        } else {
            $reason = 'SIP digest auth failures (wrong password) on port 5060';
            $sample = $attempts !== [] ? $attempts[count($attempts) - 1] : '';
            if (stripos($sample, 'REGISTER') !== false) {
                $reason = 'Repeated REGISTER with wrong SIP password';
            } elseif (stripos($sample, 'INVITE') !== false) {
                $reason = 'Repeated INVITE with wrong SIP password';
            } elseif ($attempts === []) {
                $reason = 'Banned by opensips-auth jail (threshold reached) or manual ban';
            }
        }
        $country = (string) ($geo['country'] ?? 'Unknown');
        $cc = (string) ($geo['countryCode'] ?? '');
        if ($cc !== '' && $country !== 'Unknown' && !str_contains($country, '(')) {
            $country .= ' (' . $cc . ')';
        }
        return [
            'ip' => $ip,
            'jail' => $jail,
            'country' => $country !== '' ? $country : 'Unknown',
            'city' => (string) ($geo['city'] ?? ''),
            'isp' => (string) ($geo['isp'] ?? ''),
            'reason' => $reason,
            'attempts' => array_slice($attempts, -12),
        ];
    }

    /** @return array{ok:bool,detail:string} */
    public function unban(string $ip, ?string $jail = null): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'detail' => 'Invalid IP'];
        }
        $args = ['unban', $ip];
        if (is_string($jail) && $jail !== '') {
            $args[] = $jail;
        }
        [$code, $out, $err] = $this->run($args);
        return ['ok' => $code === 0, 'detail' => trim($code === 0 ? $out : ($err ?: $out))];
    }

    /** @return array{ok:bool,detail:string} */
    public function ban(string $ip, string $jail = self::JAIL_SIP): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'detail' => 'Invalid IP'];
        }
        if (!in_array($jail, [self::JAIL_SIP, self::JAIL_PORTAL], true)) {
            $jail = self::JAIL_SIP;
        }
        [$code, $out, $err] = $this->run(['ban', $ip, $jail]);
        return ['ok' => $code === 0, 'detail' => trim($code === 0 ? $out : ($err ?: $out))];
    }

    /**
     * Parse IPs from pasted text or uploaded file.
     * Accepts one IP per line, optional "ip|jail", commas/semicolons, # comments.
     *
     * @return array{0:list<string>,1:array<string,string>} [unique IPs, optional ip=>jail overrides]
     */
    public function parseIpList(string $raw): array
    {
        $ips = [];
        $jailByIp = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            foreach (preg_split('/[\s,;]+/', $line) ?: [] as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }
                $jail = '';
                if (str_contains($token, '|')) {
                    [$token, $jail] = array_map('trim', explode('|', $token, 2));
                }
                if (!filter_var($token, FILTER_VALIDATE_IP)) {
                    continue;
                }
                $ips[$token] = true;
                if (in_array($jail, [self::JAIL_SIP, self::JAIL_PORTAL], true)) {
                    $jailByIp[$token] = $jail;
                }
            }
        }
        return [array_keys($ips), $jailByIp];
    }

    /**
     * Ban many IPs (SIP jail blocks UDP/TCP 5060 by default).
     *
     * @param list<string> $ips
     * @param array<string,string> $jailByIp
     * @return array{ok:bool,detail:string,banned:int,failed:int,skipped:int}
     */
    public function banMany(array $ips, string $defaultJail = self::JAIL_SIP, array $jailByIp = []): array
    {
        if (!in_array($defaultJail, [self::JAIL_SIP, self::JAIL_PORTAL], true)) {
            $defaultJail = self::JAIL_SIP;
        }
        $clean = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $clean[] = $ip;
            }
        }
        $clean = array_values(array_unique($clean));
        if ($clean === []) {
            return ['ok' => false, 'detail' => 'No valid IPs found', 'banned' => 0, 'failed' => 0, 'skipped' => 0];
        }
        if (count($clean) > 500) {
            return ['ok' => false, 'detail' => 'Too many IPs (max 500 per import)', 'banned' => 0, 'failed' => 0, 'skipped' => count($clean)];
        }

        // Group by jail for ban-many helper calls.
        $byJail = [];
        foreach ($clean as $ip) {
            $j = $jailByIp[$ip] ?? $defaultJail;
            if (!in_array($j, [self::JAIL_SIP, self::JAIL_PORTAL], true)) {
                $j = $defaultJail;
            }
            $byJail[$j][] = $ip;
        }
        $banned = 0;
        $failed = 0;
        $notes = [];
        foreach ($byJail as $jail => $list) {
            foreach (array_chunk($list, 80) as $chunk) {
                [$code, $out, $err] = $this->run(array_merge(['ban-many', $jail], $chunk));
                $text = trim($code === 0 ? $out : ($err ?: $out));
                if (preg_match('/banned=(\d+)/', $text, $m)) {
                    $banned += (int) $m[1];
                } elseif ($code === 0) {
                    $banned += count($chunk);
                }
                if (preg_match('/failed=(\d+)/', $text, $m)) {
                    $failed += (int) $m[1];
                } elseif ($code !== 0) {
                    $failed += count($chunk);
                    $notes[] = $text !== '' ? $text : "ban-many failed for {$jail}";
                }
            }
        }
        $ok = $banned > 0 || $failed === 0;
        $detail = "Banned {$banned} IP(s) on " . ($defaultJail === self::JAIL_SIP ? 'SIP 5060' : 'portal');
        if ($failed > 0) {
            $detail .= "; {$failed} failed";
        }
        if ($notes !== []) {
            $detail .= ' — ' . mb_substr(implode('; ', $notes), 0, 240);
        }
        return ['ok' => $ok, 'detail' => $detail, 'banned' => $banned, 'failed' => $failed, 'skipped' => 0];
    }

    /**
     * Unban many IPs (all jails unless $jail is set).
     *
     * @param list<string> $ips
     * @return array{ok:bool,detail:string,unbanned:int,skipped:int}
     */
    public function unbanMany(array $ips, ?string $jail = null): array
    {
        $clean = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $clean[] = $ip;
            }
        }
        $clean = array_values(array_unique($clean));
        if ($clean === []) {
            return ['ok' => false, 'detail' => 'No valid IPs found', 'unbanned' => 0, 'skipped' => 0];
        }
        if (count($clean) > 500) {
            return ['ok' => false, 'detail' => 'Too many IPs (max 500 per import)', 'unbanned' => 0, 'skipped' => count($clean)];
        }
        $unbanned = 0;
        $skipped = 0;
        $notes = [];
        foreach (array_chunk($clean, 80) as $chunk) {
            $args = ['unban-many'];
            if (is_string($jail) && in_array($jail, [self::JAIL_SIP, self::JAIL_PORTAL], true)) {
                $args[] = $jail;
            }
            [$code, $out, $err] = $this->run(array_merge($args, $chunk));
            $text = trim($code === 0 ? $out : ($err ?: $out));
            if (preg_match('/unbanned=(\d+)/', $text, $m)) {
                $unbanned += (int) $m[1];
            } elseif ($code === 0) {
                $unbanned += count($chunk);
            }
            if (preg_match('/skipped=(\d+)/', $text, $m)) {
                $skipped += (int) $m[1];
            } elseif ($code !== 0) {
                $skipped += count($chunk);
                $notes[] = $text !== '' ? $text : 'unban-many failed';
            }
        }
        $ok = $unbanned > 0 || $skipped === count($clean);
        $detail = "Unbanned {$unbanned} IP(s)";
        if ($skipped > 0) {
            $detail .= "; {$skipped} not banned / skipped";
        }
        if ($notes !== []) {
            $detail .= ' — ' . mb_substr(implode('; ', $notes), 0, 240);
        }
        return ['ok' => $ok, 'detail' => $detail, 'unbanned' => $unbanned, 'skipped' => $skipped];
    }

    /** Export currently banned IPs as text (ip|jail lines). */
    public function exportList(): string
    {
        $st = $this->status(false);
        $lines = [
            '# Ownswitch firewall export',
            '# Format: ip|jail   (opensips-auth = SIP port 5060, ownswitch-portal = web login)',
            '# Ban on SIP jail drops unauthorized traffic to UDP/TCP 5060.',
            '# Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC',
            '',
        ];
        $entries = $st['ban_entries'] ?? [];
        if ($entries === []) {
            $lines[] = '# (no banned IPs)';
        } else {
            foreach ($entries as $e) {
                $ip = (string) ($e['ip'] ?? '');
                $jail = (string) ($e['jail'] ?? self::JAIL_SIP);
                if ($ip !== '') {
                    $lines[] = $ip . '|' . $jail;
                }
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array{maxretry?:string,findtime?:string,bantime?:string,enabled?:string,jail?:string} $cfg
     * @return array{ok:bool,detail:string}
     */
    public function setConfig(array $cfg): array
    {
        $args = ['set-config'];
        $jail = isset($cfg['jail']) && is_string($cfg['jail']) ? $cfg['jail'] : self::JAIL_SIP;
        if (!in_array($jail, [self::JAIL_SIP, self::JAIL_PORTAL], true)) {
            return ['ok' => false, 'detail' => 'Invalid jail'];
        }
        $args[] = 'jail=' . $jail;
        foreach (['maxretry', 'findtime', 'bantime', 'enabled'] as $k) {
            if (isset($cfg[$k]) && is_string($cfg[$k]) && $cfg[$k] !== '') {
                if (!preg_match('/^[a-zA-Z0-9]+$/', $k)) {
                    continue;
                }
                $v = $cfg[$k];
                if ($k === 'enabled') {
                    if (!preg_match('/^(true|false|1|0|yes|no)$/i', $v)) {
                        return ['ok' => false, 'detail' => "Invalid {$k}"];
                    }
                } elseif ($k === 'maxretry') {
                    if (!preg_match('/^\d{1,3}$/', $v) || (int) $v < 1 || (int) $v > 100) {
                        return ['ok' => false, 'detail' => "Invalid {$k}"];
                    }
                } elseif (!preg_match('/^\d+[smhd]?$/', $v)) {
                    return ['ok' => false, 'detail' => "Invalid {$k} (use e.g. 30m, 1h, 24h)"];
                }
                $args[] = "{$k}={$v}";
            }
        }
        if (count($args) <= 2) {
            return ['ok' => false, 'detail' => 'No settings to change'];
        }
        [$code, $out, $err] = $this->run($args);
        return ['ok' => $code === 0, 'detail' => trim($code === 0 ? $out : ($err ?: $out))];
    }

    /**
     * @param list<string> $args
     * @return array{0:int,1:string,2:string}
     */
    private function run(array $args): array
    {
        if (!$this->available()) {
            return [1, '', 'helper missing'];
        }
        $cmd = 'sudo -n ' . escapeshellarg(self::HELPER);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        $err = '';
        if ($code !== 0) {
            $err = $text;
        }
        return [$code, $text, $err];
    }

    /** @return array<string,string> */
    private function parseKv(string $out): array
    {
        $kv = [];
        foreach (preg_split("/\r\n|\n|\r/", $out) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $kv[trim($k)] = trim($v);
        }
        return $kv;
    }
}
