<?php

declare(strict_types=1);

namespace Ownswitch\Services\Mail;

use Ownswitch\Services\Settings\SettingsService;
use Ownswitch\Support\Crypto;
use PDO;

/** Portal outbound email (SMTP). Used for refill notices and similar service mail. */
final class MailService
{
    public function __construct(private PDO $pdo, private ?SettingsService $settings = null)
    {
        $this->settings ??= new SettingsService($pdo);
    }

    public function configured(): bool
    {
        $host = trim((string) $this->settings->get('smtp_host', ''));
        $from = trim((string) $this->settings->get('smtp_from_email', ''));
        return $host !== '' && $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL);
    }

    public function refillEmailsEnabled(): bool
    {
        return $this->settings->bool('email_on_refill_enabled') && $this->configured();
    }

    /**
     * @return array{host:string,port:int,encryption:string,username:string,password:string,from_email:string,from_name:string}
     */
    public function smtpConfig(): array
    {
        $provider = strtolower(trim((string) $this->settings->get('smtp_provider', 'custom')));
        $host = trim((string) $this->settings->get('smtp_host', ''));
        $port = (int) ($this->settings->get('smtp_port', '587') ?? 587);
        $enc = strtolower(trim((string) $this->settings->get('smtp_encryption', 'tls')));
        if ($provider === 'gmail') {
            $host = $host !== '' ? $host : 'smtp.gmail.com';
            $port = $port > 0 ? $port : 587;
            $enc = in_array($enc, ['tls', 'ssl'], true) ? $enc : 'tls';
        }
        if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
            $enc = 'tls';
        }
        return [
            'host' => $host,
            'port' => $port > 0 ? $port : 587,
            'encryption' => $enc,
            'username' => (string) $this->settings->get('smtp_username', ''),
            'password' => $this->smtpPassword(),
            'from_email' => trim((string) $this->settings->get('smtp_from_email', '')),
            'from_name' => trim((string) $this->settings->get('smtp_from_name', '')),
        ];
    }

    public function smtpPassword(): string
    {
        $raw = (string) $this->settings->get('smtp_password', '');
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, 'enc:')) {
            try {
                return Crypto::decrypt(substr($raw, 4));
            } catch (\Throwable) {
                return '';
            }
        }
        return $raw;
    }

    /** Store SMTP password (encrypt when possible). Empty string clears. */
    public static function encodeSmtpPassword(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }
        try {
            return 'enc:' . Crypto::encrypt($plain);
        } catch (\Throwable) {
            return $plain;
        }
    }

    /** @return array{ok:bool,detail:string} */
    public function send(string $to, string $subject, string $textBody): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'detail' => 'SMTP is not configured.'];
        }
        return (new SmtpTransport())->send($this->smtpConfig(), $to, $subject, $textBody);
    }

    /**
     * Resolve whether this customer/user should get a refill email, and where.
     *
     * @param array<string,mixed> $customer
     * @return array{ok:bool,to:string,detail:string}
     */
    public function refillRecipient(array $customer): array
    {
        if (!$this->refillEmailsEnabled()) {
            return ['ok' => false, 'to' => '', 'detail' => 'Refill emails are disabled in Settings → Email.'];
        }
        $cid = (int) ($customer['id'] ?? 0);
        $notify = 1;
        try {
            $st = $this->pdo->prepare(
                "SELECT email_notifications, email FROM users
                 WHERE customer_id = ? AND status = 'active'
                 ORDER BY id ASC LIMIT 1"
            );
            $st->execute([$cid]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($u)) {
                $notify = (int) ($u['email_notifications'] ?? 1);
            }
        } catch (\Throwable) {
            $u = null;
        }
        if ($notify !== 1) {
            return ['ok' => false, 'to' => '', 'detail' => 'User opted out of portal emails.'];
        }
        $to = trim((string) ($customer['email_address'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $to = trim((string) ($customer['contact_email'] ?? ''));
        }
        if (($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) && is_array($u ?? null)) {
            $to = trim((string) ($u['email'] ?? ''));
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'to' => '', 'detail' => 'No valid email address on this user.'];
        }
        return ['ok' => true, 'to' => $to, 'detail' => ''];
    }

    /**
     * @param array<string,mixed> $customer
     * @return array{ok:bool,detail:string}
     */
    public function sendRefillNotice(array $customer, string $type, string $amount, string $balance, string $description): array
    {
        $who = $this->refillRecipient($customer);
        if (!$who['ok']) {
            return ['ok' => false, 'detail' => $who['detail']];
        }
        $brand = trim((string) $this->settings->get('switch_name', ''));
        if ($brand === '') {
            $brand = 'Ownswitch';
        }
        $name = trim((string) ($customer['full_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($customer['name'] ?? 'Customer'));
        }
        $typeLabel = match ($type) {
            'topup' => 'Top-up',
            'refund' => 'Refund',
            'adjustment' => 'Adjustment',
            default => ucfirst($type),
        };
        $currency = (string) ($customer['currency'] ?? 'USD');
        $subject = "{$brand}: {$typeLabel} of {$amount} {$currency}";
        $body = "Hello {$name},\n\n"
            . "A ledger {$typeLabel} was posted on your account.\n\n"
            . "Amount: {$amount} {$currency}\n"
            . "New balance: {$balance} {$currency}\n"
            . "Note: {$description}\n\n"
            . "If you did not expect this message, contact your administrator.\n\n"
            . "— {$brand}\n";
        return $this->send($who['to'], $subject, $body);
    }
}
