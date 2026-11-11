<?php

declare(strict_types=1);

namespace Ownswitch\Services\Settings;

use Ownswitch\App;
use PDO;

/** Key/value settings with config/app.php defaults. */
final class SettingsService
{
    /**
     * key => [label, type, help, group].
     * Groups: general | email. Only these keys are editable.
     */
    public const SCHEMA = [
        'default_currency'            => ['Default currency', 'text', '3-letter ISO code', 'general'],
        'tax_rate_percent'            => ['Tax rate (%)', 'decimal', 'Applied on new invoices (0 = no tax)', 'general'],
        'charge_connect_on_zero'      => ['Charge connection fee on zero-duration calls', 'bool', 'Default off', 'general'],
        'rating_tolerance'            => ['Rating discrepancy tolerance', 'decimal', 'Flag when |CGRateS − portal| exceeds this', 'general'],
        'prepaid_hard_cap_seconds'    => [
            'Prepaid hard max call duration (s)',
            'int',
            'System default max call length in seconds (e.g. 7200 = 2 hours). Used when a SIP user or trunk leaves Max call duration at 0 / Default. Applied by OpenSIPS routing (dialog timeout).',
            'general',
        ],
        'system_max_concurrent'       => ['System max concurrent calls', 'int0', '-1 = unlimited. Soft cap for capacity planning and health alerts.', 'general'],
        'system_max_cps'              => ['System max calls per second', 'int0', '-1 = unlimited. Soft CPS guidance for the switch.', 'general'],
        'failover_max_retries'        => ['Failover max trunk attempts', 'int', 'How many trunks OpenSIPS will try before giving up (1–20)', 'general'],
        'failover_sip_codes'          => ['Failover SIP codes', 'text', 'Comma-separated codes that try the next trunk: SIP replies (408,480,486,503) and TCAP (trunk rate CAP). UCAP (user rate CAP) never tries the next trunk even if listed. Example: 408,480,486,503,TCAP', 'general'],
        'kill_switch'                 => ['Emergency kill switch', 'bool', 'When on, OpenSIPS routing rejects new outbound calls', 'general'],
        'default_media_policy'        => [
            'Default media policy',
            'media',
            'OpenSIPS: Relay = RTP through this switch (direct media=false). Direct = peer-to-peer RTP (direct media=true). Record = Relay + recording to recordings/. Used when a SIP user leaves Media at Default.',
            'general',
        ],
        'default_codecs'              => [
            'Default codecs',
            'codecs',
            'Pre-selected when creating a new SIP user or trunk (order = preference). Default: every G* codec plus telephone-event (DTMF). Softphones/vendors still negotiate within this list.',
            'general',
        ],
        'recording_retention_days'    => ['Recording retention (days)', 'int', 'Days to keep OpenSIPS recording WAVs under recordings/ (cleanup job). Default 7.', 'general'],
        'switch_logo'                 => ['Switch logo', 'logo', 'PNG/JPG/WEBP/SVG, max 2 MB. Shown on the login page and in the top navigation.', 'general'],
        'switch_name'                 => ['Switch display name', 'text', 'Slogan on the login page and in the portal footer. Leave blank to hide the login title.', 'general'],
        'system_timezone'             => [
            'System timezone',
            'timezone',
            'Default for the dashboard, stats, and reports. A user who picks a timezone on My profile overrides this for their own login. Example: New York, London, or Karachi.',
            'general',
        ],
        'smtp_provider'               => ['SMTP provider', 'smtp_provider', 'Gmail uses smtp.gmail.com (App Password). Custom = your own SMTP host.', 'email'],
        'smtp_host'                   => ['SMTP host', 'text', 'e.g. smtp.gmail.com or mail.yourdomain.com', 'email'],
        'smtp_port'                   => ['SMTP port', 'smtp_port', '587 for STARTTLS (Gmail), 465 for SSL, 25 for plain', 'email'],
        'smtp_encryption'             => ['Encryption', 'smtp_encryption', 'tls = STARTTLS (recommended), ssl = SMTPS, none = no TLS', 'email'],
        'smtp_username'               => ['SMTP username', 'text', 'Usually the full mailbox address', 'email'],
        'smtp_password'               => ['SMTP password', 'secret', 'Gmail: use an App Password. Leave blank on save to keep the current password.', 'email'],
        'smtp_from_email'             => ['From email', 'email', 'Envelope / From address shown to users', 'email'],
        'smtp_from_name'              => ['From name', 'text', 'Display name (e.g. your switch brand)', 'email'],
        'email_on_refill_enabled'     => ['Allow refill emails', 'bool', 'Master switch. When on, admins can tick “Send email” on each refill. Users can still opt out on My profile.', 'email'],
    ];

    /** @return array<string,string> */
    public static function groups(): array
    {
        return [
            'general' => 'General',
            'email' => 'Email',
        ];
    }

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->cache === null) {
            try {
                $this->cache = $this->pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (\Throwable) {
                $this->cache = [];
            }
        }
        return $this->cache;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();
        if (array_key_exists($key, $all) && $all[$key] !== null) {
            return $all[$key];
        }
        $cfgKey = match ($key) {
            'default_currency'            => 'currency',
            'default_customer_initial'    => 'customer_initial',
            'default_customer_subsequent' => 'customer_subsequent',
            'default_carrier_initial'     => 'carrier_initial',
            'default_carrier_subsequent'  => 'carrier_subsequent',
            'default_media_policy'        => 'media_policy',
            'default_codecs'              => 'codecs',
            default                       => $key,
        };
        $v = App::config('app.defaults.' . $cfgKey);
        return $v === null ? $default : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
    }

    public function int(string $key, int $default): int
    {
        return (int) ($this->get($key) ?? $default);
    }

    public function bool(string $key): bool
    {
        return in_array($this->get($key), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, string $value, ?int $userId): void
    {
        if (!isset(self::SCHEMA[$key])) {
            throw new \InvalidArgumentException('Unknown setting');
        }
        $this->pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)')
            ->execute([$key, $value, $userId]);
        $this->cache = null;
    }

    /**
     * Validate a submitted value for a schema key.
     *
     * @return array{0:?string,1:?string} [normalised value, error]
     */
    public static function validate(string $key, mixed $raw): array
    {
        [$label, $type] = self::SCHEMA[$key];
        $v = is_string($raw) ? trim($raw) : '';
        switch ($type) {
            case 'bool':
                return [!empty($raw) ? '1' : '0', null];
            case 'secret':
                // Empty = keep existing (controller skips set). Non-empty = new value.
                return [$v, null];
            case 'email':
                if ($v === '') {
                    return ['', null];
                }
                return filter_var($v, FILTER_VALIDATE_EMAIL) ? [mb_strtolower($v), null] : [null, "{$label} must be a valid email."];
            case 'smtp_provider':
                return in_array($v, ['gmail', 'custom'], true) ? [$v, null] : [null, "{$label} must be gmail or custom."];
            case 'smtp_encryption':
                return in_array($v, ['tls', 'ssl', 'none'], true) ? [$v, null] : [null, "{$label} must be tls, ssl, or none."];
            case 'smtp_port':
                return preg_match('/^\d{1,5}$/', $v) && (int) $v >= 1 && (int) $v <= 65535
                    ? [(string) (int) $v, null]
                    : [null, "{$label} must be between 1 and 65535."];
            case 'int':
                if ($key === 'failover_max_retries') {
                    return preg_match('/^\d{1,2}$/', $v) && (int) $v >= 1 && (int) $v <= 20
                        ? [(string) (int) $v, null]
                        : [null, "{$label} must be between 1 and 20."];
                }
                if ($key === 'prepaid_hard_cap_seconds') {
                    return preg_match('/^\d{1,7}$/', $v) && (int) $v >= 60 && (int) $v <= 604800
                        ? [(string) (int) $v, null]
                        : [null, "{$label} must be between 60 and 604800 seconds (1 min – 7 days)."];
                }
                return preg_match('/^\d{1,7}$/', $v) && (int) $v >= 1 ? [(string) (int) $v, null] : [null, "{$label} must be a positive whole number."];
            case 'int0':
                // -1 = unlimited; 0 kept for older installs (also treated as unlimited in UI copy).
                if (!preg_match('/^-?\d{1,7}$/', $v)) {
                    return [null, "{$label} must be a whole number (-1 = unlimited)."];
                }
                $n = (int) $v;
                if ($n < -1) {
                    return [null, "{$label} must be -1 (unlimited) or 0+."];
                }
                return [(string) $n, null];
            case 'decimal':
                return preg_match('/^\d+(\.\d{1,8})?$/', $v) ? [$v, null] : [null, "{$label} must be a non-negative decimal."];
            case 'media':
                return in_array($v, ['direct', 'relay', 'record'], true) ? [$v, null] : [null, "{$label} must be direct, relay or record."];
            case 'codecs':
                $stored = \Ownswitch\Support\SipCodecs::toStorage($raw);
                if ($stored === null) {
                    return [null, "{$label}: select at least one codec (e.g. PCMU, PCMA, G729)."];
                }
                return [$stored, null];
            case 'timezone':
                try {
                    new \DateTimeZone($v);
                    return [$v, null];
                } catch (\Throwable) {
                    return [null, "{$label} must be a timezone such as New York, London, or Karachi."];
                }
            case 'logo':
                // Path set by controller after upload; empty keeps existing.
                return [$v, null];
            case 'text':
                if ($key === 'default_currency') {
                    return preg_match('/^[A-Z]{3}$/', strtoupper($v)) ? [strtoupper($v), null] : [null, "{$label} must be a 3-letter code."];
                }
                if ($key === 'failover_sip_codes') {
                    $norm = \Ownswitch\Support\FailoverSipCodes::normalize($v);
                    if ($norm === null) {
                        return [null, "{$label} must be comma-separated 3-digit SIP codes and/or UCAP, TCAP (e.g. 408,480,486,503,TCAP)."];
                    }
                    if (mb_strlen($norm) > 120) {
                        return [null, "{$label} must be at most 120 characters."];
                    }
                    return [$norm, null];
                }
                $maxLen = $key === 'failover_sip_codes' ? 120 : 80;
                if ($key === 'smtp_host' || $key === 'smtp_username' || $key === 'smtp_from_name') {
                    $maxLen = 190;
                }
                if (mb_strlen($v) > $maxLen) {
                    return [null, "{$label} must be at most {$maxLen} characters."];
                }
                return [$v, null];
            default:
                return preg_match('/^[A-Z]{3}$/', strtoupper($v)) ? [strtoupper($v), null] : [null, "{$label} must be a 3-letter code."];
        }
    }
}
