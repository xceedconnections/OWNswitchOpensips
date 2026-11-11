<?php

declare(strict_types=1);

use Ownswitch\Support\Env;

return [
    'session' => [
        'name'     => 'OWNSWITCHSESS',
        'lifetime' => (int) Env::get('SESSION_LIFETIME', 7200),
        // Prefer explicit env; otherwise auto-enable Secure when the request is HTTPS.
        'secure'   => Env::get('SESSION_SECURE') !== null
            ? filter_var((string) Env::get('SESSION_SECURE'), FILTER_VALIDATE_BOOLEAN)
            : null,
        'samesite' => 'Lax',
    ],
    'csrf' => [
        'field'  => '_csrf',
        'header' => 'X-CSRF-Token',
    ],
    'auth' => [
        'min_password_length'   => (int) Env::get('MIN_PASSWORD_LENGTH', 10),
        'max_failed_logins'     => 5,
        'lockout_minutes'       => 15,
        'ip_window_minutes'     => 10,
        'ip_max_failures'       => 8,
    ],
    // REMOTE_ADDR values allowed to supply X-Forwarded-For.
    'trusted_proxies' => [],
    // Content-Security-Policy: no inline scripts/styles. Bootstrap is loaded from jsDelivr with SRI.
    'csp' => "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net; "
           . "img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self' stun:stun.l.google.com:19302; "
           . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
];
