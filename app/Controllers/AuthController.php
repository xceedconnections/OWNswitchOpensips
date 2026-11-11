<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Support\LoginCaptcha;
use Ownswitch\Support\Timezone;

final class AuthController extends BaseController
{
    public function loginForm(Request $r, array $p = []): Response
    {
        App::session()->start();
        if (App::session()->userId() !== null) {
            return $this->redirect($r, '/');
        }
        return $this->view('auth/login', $this->loginView([
            'error' => null,
            'email' => '',
            'login' => '',
            'captcha' => LoginCaptcha::issue(),
        ]));
    }

    public function login(Request $r, array $p = []): Response
    {
        $login = is_string($r->post['login'] ?? null) ? (string) $r->post['login'] : '';
        if ($login === '' && is_string($r->post['email'] ?? null)) {
            $login = (string) $r->post['email']; // backwards compatible
        }
        // Cap length to blunt oversized payload / log flooding attacks.
        $login = mb_substr(trim($login), 0, 190);
        $pass = is_string($r->post['password'] ?? null) ? (string) $r->post['password'] : '';
        if (strlen($pass) > 256) {
            $pass = substr($pass, 0, 256);
        }
        $captchaOk = LoginCaptcha::verify(
            is_string($r->post['captcha'] ?? null) ? (string) $r->post['captcha'] : null,
            is_string($r->post['captcha_token'] ?? null) ? (string) $r->post['captcha_token'] : null,
        );
        if (!$captchaOk) {
            App::auth()->recordPortalFail($r->ip(), $login !== '' ? $login : 'captcha');
            return $this->view('auth/login', $this->loginView([
                'error' => 'Incorrect security check. Please try again.',
                'email' => $login,
                'login' => $login,
                'captcha' => LoginCaptcha::issue(),
            ]), 401);
        }
        $res = App::auth()->attempt($login, $pass, $r->ip());
        if (!$res['ok']) {
            return $this->view('auth/login', $this->loginView([
                'error' => $res['error'],
                'email' => $login,
                'login' => $login,
                'captcha' => LoginCaptcha::issue(),
            ]), 401);
        }
        $r->setAttr('user', $res['user']);
        $this->audit($r, 'login', 'user', (int) $res['user']['id']);
        return $this->redirect($r, '/');
    }

    public function logout(Request $r, array $p = []): Response
    {
        $this->audit($r, 'logout', 'user', (int) ($this->user($r)['id'] ?? 0) ?: null);
        App::auth()->logout();
        return Response::redirect($r->basePath . '/login');
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function loginView(array $extra): array
    {
        $switchName = '';
        try {
            $sn = (new \Ownswitch\Services\Settings\SettingsService($this->db()))->get('switch_name', '');
            if (is_string($sn)) {
                $switchName = trim($sn);
            }
        } catch (\Throwable) {
            $switchName = '';
        }
        return array_merge([
            'title' => $switchName !== '' ? $switchName : 'Sign in',
            'logoUrl' => switch_logo_url(),
            'switchName' => $switchName,
        ], $extra);
    }

    public function passwordForm(Request $r, array $p = []): Response
    {
        return $this->view('auth/password', ['title' => 'Change password', 'errors' => []]);
    }

    public function passwordChange(Request $r, array $p = []): Response
    {
        $cur = is_string($r->post['current'] ?? null) ? (string) $r->post['current'] : '';
        $new = is_string($r->post['new'] ?? null) ? (string) $r->post['new'] : '';
        $confirm = is_string($r->post['confirm'] ?? null) ? (string) $r->post['confirm'] : '';
        if ($new !== $confirm) {
            return $this->view('auth/password', ['title' => 'Change password', 'errors' => ['New password and confirmation do not match.']], 422);
        }
        $res = App::auth()->changePassword((int) $this->user($r)['id'], $cur, $new);
        if (!$res['ok']) {
            return $this->view('auth/password', ['title' => 'Change password', 'errors' => $res['errors']], 422);
        }
        $this->audit($r, 'password_change', 'user', (int) $this->user($r)['id']);
        return $this->redirect($r, '/', 'Password changed.');
    }

    public function profileForm(Request $r, array $p = []): Response
    {
        $u = $this->user($r);
        $notify = $this->emailNotificationsFlag((int) ($u['id'] ?? 0), $u);
        return $this->view('auth/profile', [
            'title' => 'My profile',
            'user' => $u,
            'timezone' => Timezone::stored($u),
            'effective' => Timezone::fromUser($u),
            'options' => Timezone::OPTIONS,
            'form' => [
                'name' => (string) ($u['name'] ?? ''),
                'email' => (string) ($u['email'] ?? ''),
                'email_notifications' => $notify ? '1' : '0',
            ],
            'errors' => [],
        ]);
    }

    public function profileSave(Request $r, array $p = []): Response
    {
        $u = $this->user($r);
        $id = (int) ($u['id'] ?? 0);
        $name = mb_substr(trim(is_string($r->post['name'] ?? null) ? (string) $r->post['name'] : ''), 0, 120);
        $email = mb_substr(mb_strtolower(trim(is_string($r->post['email'] ?? null) ? (string) $r->post['email'] : '')), 0, 190);
        $current = is_string($r->post['current'] ?? null) ? (string) $r->post['current'] : '';
        $new = is_string($r->post['new'] ?? null) ? (string) $r->post['new'] : '';
        $confirm = is_string($r->post['confirm'] ?? null) ? (string) $r->post['confirm'] : '';
        $rawTz = trim(is_string($r->post['timezone'] ?? null) ? (string) $r->post['timezone'] : '');
        $emailNotifications = !empty($r->post['email_notifications']) ? 1 : 0;

        $fail = function (array $errors) use ($r, $u, $name, $email, $rawTz, $emailNotifications): Response {
            $tzStored = $rawTz !== '' ? $rawTz : Timezone::stored($u);
            return $this->view('auth/profile', [
                'title' => 'My profile',
                'user' => $u,
                'timezone' => $tzStored === '' ? Timezone::stored($u) : $tzStored,
                'effective' => Timezone::fromUser($u),
                'options' => Timezone::OPTIONS,
                'form' => [
                    'name' => $name,
                    'email' => $email,
                    'email_notifications' => $emailNotifications ? '1' : '0',
                ],
                'errors' => $errors,
            ], 422);
        };

        if ($id <= 0) {
            return $this->forbidden();
        }
        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]{2,120}$/', $name)) {
            return $fail(['Username must be 2–120 characters: letters, numbers, dots, underscores, or hyphens.']);
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $fail(['Enter a valid email address.']);
        }
        if ($rawTz === '' || strcasecmp($rawTz, Timezone::FOLLOW_SYSTEM) === 0) {
            $tz = Timezone::FOLLOW_SYSTEM;
        } else {
            try {
                new \DateTimeZone($rawTz);
                $tz = $rawTz;
            } catch (\Throwable) {
                return $fail(['Choose a timezone such as New York, London, or Karachi.']);
            }
        }
        if ($new !== '' && $new !== $confirm) {
            return $fail(['New password and confirmation do not match.']);
        }

        $row = $this->db()->prepare('SELECT id, name, email, password_hash, timezone, email_notifications FROM users WHERE id = ? LIMIT 1');
        try {
            $row->execute([$id]);
            $dbUser = $row->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $row = $this->db()->prepare('SELECT id, name, email, password_hash, timezone FROM users WHERE id = ? LIMIT 1');
            $row->execute([$id]);
            $dbUser = $row->fetch(\PDO::FETCH_ASSOC);
            if (is_array($dbUser)) {
                $dbUser['email_notifications'] = 1;
            }
        }
        if ($dbUser === false) {
            return $fail(['Account not found.']);
        }

        $nameChanged = strcasecmp((string) $dbUser['name'], $name) !== 0;
        $emailChanged = strcasecmp((string) $dbUser['email'], $email) !== 0;
        $sensitive = $nameChanged || $emailChanged || $new !== '';
        if ($sensitive) {
            if ($current === '' || !password_verify($current, (string) $dbUser['password_hash'])) {
                return $fail(['Current password is incorrect.']);
            }
        }
        if ($nameChanged) {
            $dup = $this->db()->prepare('SELECT id FROM users WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1');
            $dup->execute([$name, $id]);
            if ($dup->fetchColumn() !== false) {
                return $fail(['That username is already in use.']);
            }
        }
        if ($emailChanged) {
            $dup = $this->db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $dup->execute([$email, $id]);
            if ($dup->fetchColumn() !== false) {
                return $fail(['That email is already in use.']);
            }
        }

        if ($new !== '') {
            $problems = \Ownswitch\Services\Auth\AuthService::passwordProblems($new);
            if ($problems !== []) {
                return $fail($problems);
            }
        }

        try {
            $this->db()->prepare('UPDATE users SET name = ?, email = ?, timezone = ?, email_notifications = ? WHERE id = ?')
                ->execute([$name, $email, $tz, $emailNotifications, $id]);
        } catch (\Throwable) {
            $this->db()->prepare('UPDATE users SET name = ?, email = ?, timezone = ? WHERE id = ?')
                ->execute([$name, $email, $tz, $id]);
        }
        if ($new !== '') {
            App::auth()->changePassword($id, $current, $new);
        } elseif ($nameChanged || $emailChanged) {
            App::session()->regenerate();
        }

        $this->audit($r, 'profile_update', 'user', $id, [
            'name' => $dbUser['name'] ?? null,
            'email' => $dbUser['email'] ?? null,
            'timezone' => $dbUser['timezone'] ?? null,
            'email_notifications' => $dbUser['email_notifications'] ?? null,
        ], [
            'name' => $name,
            'email' => $email,
            'timezone' => $tz,
            'email_notifications' => $emailNotifications,
            'password_changed' => $new !== '',
        ]);

        $parts = ['Profile saved.'];
        if ($nameChanged || $emailChanged) {
            $parts[] = 'Sign in with the new username or email next time.';
        }
        if ($new !== '') {
            $parts[] = 'Password updated.';
        }
        $shown = $tz === Timezone::FOLLOW_SYSTEM
            ? Timezone::label(Timezone::systemDefault()) . ' (system default)'
            : Timezone::label($tz);
        $parts[] = 'Reports use ' . $shown . '.';
        $parts[] = $emailNotifications ? 'Portal service emails enabled.' : 'Portal service emails disabled.';
        return $this->redirect($r, '/account/profile', implode(' ', $parts));
    }

    /** @param array<string,mixed> $fallback */
    private function emailNotificationsFlag(int $userId, array $fallback): bool
    {
        if ($userId > 0) {
            try {
                $st = $this->db()->prepare('SELECT email_notifications FROM users WHERE id = ? LIMIT 1');
                $st->execute([$userId]);
                $v = $st->fetchColumn();
                if ($v !== false && $v !== null) {
                    return (int) $v === 1;
                }
            } catch (\Throwable) {
            }
        }
        if (array_key_exists('email_notifications', $fallback)) {
            return (int) $fallback['email_notifications'] === 1;
        }
        return true;
    }
}
