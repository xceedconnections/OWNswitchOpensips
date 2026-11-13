<?php

declare(strict_types=1);

namespace Ownswitch\Services\Dial;

use PDO;

/**
 * Pick outbound CLI from a CallerID group.
 *
 * Rules (matches portal UI):
 * - Longest `prefix` that the dialled number starts with wins (prefix `*` = default).
 * - Several CLIs sharing that prefix rotate (round-robin).
 * - If nothing matches, pick a random active CLI from the group.
 */
final class CallerIdPickerService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{cli:string,prefix:string,reason:string}|null
     */
    public function pickForSipUser(string $username, string $destination): ?array
    {
        $username = trim($username);
        $dst = preg_replace('/\D+/', '', $destination) ?? '';
        if ($username === '' || $dst === '') {
            return null;
        }
        try {
            $st = $this->pdo->prepare(
                "SELECT callerid_group_id FROM sip_accounts
                 WHERE username = ? AND status = 'active' LIMIT 1"
            );
            $st->execute([$username]);
            $gid = $st->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
        if ($gid === false || $gid === null || (int) $gid <= 0) {
            return null;
        }
        return $this->pick((int) $gid, $dst);
    }

    /**
     * @return array{cli:string,prefix:string,reason:string}|null
     */
    public function pick(int $groupId, string $destination): ?array
    {
        if ($groupId <= 0) {
            return null;
        }
        $dst = preg_replace('/\D+/', '', $destination) ?? '';
        if ($dst === '') {
            return null;
        }
        try {
            $st = $this->pdo->prepare(
                "SELECT id, prefix, cli_number FROM callerid_group_numbers
                 WHERE callerid_group_id = ? AND status = 'active'
                 ORDER BY LENGTH(prefix) DESC, id ASC"
            );
            $st->execute([$groupId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return null;
        }
        if ($rows === []) {
            return null;
        }

        $bestLen = -1;
        $matches = [];
        foreach ($rows as $r) {
            $pfx = trim((string) ($r['prefix'] ?? ''));
            if ($pfx === '' || $pfx === '*') {
                continue;
            }
            $pfxDigits = preg_replace('/\D+/', '', $pfx) ?? '';
            if ($pfxDigits === '') {
                continue;
            }
            if (!str_starts_with($dst, $pfxDigits)) {
                continue;
            }
            $len = strlen($pfxDigits);
            if ($len > $bestLen) {
                $bestLen = $len;
                $matches = [$r];
            } elseif ($len === $bestLen) {
                $matches[] = $r;
            }
        }

        if ($matches !== []) {
            $chosen = $this->rotate($groupId, 'p' . $bestLen, $matches);
            $cli = preg_replace('/\D+/', '', (string) ($chosen['cli_number'] ?? '')) ?? '';
            if ($cli !== '') {
                return [
                    'cli' => $cli,
                    'prefix' => (string) ($chosen['prefix'] ?? ''),
                    'reason' => 'prefix-match-rotate',
                ];
            }
        }

        // Locality: CLI and dialled share the same starting digits (try 7…3).
        // Example: dialled 12138007447 + CLI 12138789985 → match on 1213 (4 digits).
        for ($n = 7; $n >= 3; $n--) {
            if (strlen($dst) < $n) {
                continue;
            }
            $head = substr($dst, 0, $n);
            $local = [];
            foreach ($rows as $r) {
                $cli = preg_replace('/\D+/', '', (string) ($r['cli_number'] ?? '')) ?? '';
                if ($cli !== '' && str_starts_with($cli, $head)) {
                    $local[] = $r;
                }
            }
            if ($local !== []) {
                $chosen = $this->rotate($groupId, 'loc' . $n, $local);
                $cli = preg_replace('/\D+/', '', (string) ($chosen['cli_number'] ?? '')) ?? '';
                if ($cli !== '') {
                    return [
                        'cli' => $cli,
                        'prefix' => $head,
                        'reason' => "locality-{$n}-rotate",
                    ];
                }
            }
        }

        // Fallback: any active CLI (prefer explicit '*' rows, else all)
        $fallback = [];
        foreach ($rows as $r) {
            $pfx = trim((string) ($r['prefix'] ?? ''));
            if ($pfx === '*' || $pfx === '') {
                $fallback[] = $r;
            }
        }
        if ($fallback === []) {
            $fallback = $rows;
        }
        $chosen = $fallback[random_int(0, count($fallback) - 1)];
        $cli = preg_replace('/\D+/', '', (string) ($chosen['cli_number'] ?? '')) ?? '';
        if ($cli === '') {
            return null;
        }
        return [
            'cli' => $cli,
            'prefix' => (string) ($chosen['prefix'] ?? '*'),
            'reason' => 'random-fallback',
        ];
    }

    /**
     * @param list<array<string,mixed>> $matches
     * @return array<string,mixed>
     */
    private function rotate(int $groupId, string $bucket, array $matches): array
    {
        if (count($matches) === 1) {
            return $matches[0];
        }
        $key = 'ownswitch:cidrot:' . $groupId . ':' . $bucket;
        $idx = 0;
        $redis = $this->redis();
        if ($redis !== null) {
            try {
                $n = (int) $redis->incr($key);
                $redis->expire($key, 86400 * 7);
                $idx = ($n - 1) % count($matches);
                return $matches[$idx];
            } catch (\Throwable) {
            }
        }
        $dir = is_dir('/dev/shm') ? '/dev/shm/ownswitch-cidrot' : sys_get_temp_dir() . '/ownswitch-cidrot';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $file = $dir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $key) . '.cnt';
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return $matches[random_int(0, count($matches) - 1)];
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $n = max(0, (int) trim((string) $raw)) + 1;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) $n);
            flock($fp, LOCK_UN);
            $idx = ($n - 1) % count($matches);
            return $matches[$idx];
        } finally {
            fclose($fp);
        }
    }

    private function redis(): ?\Redis
    {
        if (!class_exists(\Redis::class)) {
            return null;
        }
        try {
            $host = (string) \Ownswitch\App::config('app.redis.host', '127.0.0.1');
            $port = (int) \Ownswitch\App::config('app.redis.port', 6379);
            $pass = (string) \Ownswitch\App::config('app.redis.password', '');
            $r = new \Redis();
            if (!$r->connect($host, $port, 0.2)) {
                return null;
            }
            if ($pass !== '') {
                $r->auth($pass);
            }
            return $r;
        } catch (\Throwable) {
            return null;
        }
    }
}
