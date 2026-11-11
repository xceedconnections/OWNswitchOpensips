<?php

declare(strict_types=1);

namespace Ownswitch\Services\Access;

use Ownswitch\Repositories\UserRepository;
use Ownswitch\Services\Auth\AuthService;
use PDO;

/**
 * Admins create sub-admin portal users and grant a subset of Admin Access features.
 * Sub-admins (managed_by_user_id set) only see keys enabled both globally and on their row.
 */
final class UserAccessService
{
    public function __construct(private PDO $db)
    {
    }

    public function ensureSchema(): void
    {
        try {
            $st = $this->db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'managed_by_user_id'"
            );
            if ((int) $st->fetchColumn() === 0) {
                $this->db->exec(
                    'ALTER TABLE users ADD COLUMN managed_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_users_managed_by (managed_by_user_id)'
                );
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] managed_by ensure: ' . $e->getMessage());
        }
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS user_feature_access (
                    user_id     BIGINT UNSIGNED NOT NULL,
                    feature_key VARCHAR(96) NOT NULL,
                    enabled     TINYINT(1) NOT NULL DEFAULT 1,
                    updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                    PRIMARY KEY (user_id, feature_key),
                    KEY ix_user_feature_key (feature_key),
                    CONSTRAINT fk_user_feature_access_user FOREIGN KEY (user_id) REFERENCES users (id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            error_log('[ownswitch] user_feature_access ensure: ' . $e->getMessage());
        }
    }

    /** Whether this portal user is a managed sub-admin (restricted grants apply). */
    public static function isManagedUser(?array $user): bool
    {
        return $user !== null && (int) ($user['managed_by_user_id'] ?? 0) > 0;
    }

    /** @return list<array<string,mixed>> */
    public function listManagedBy(int $managerId): array
    {
        $st = $this->db->prepare(
            "SELECT u.id, u.email, u.name, u.status, u.created_at, u.last_login_at, r.code AS role,
                    (SELECT COUNT(*) FROM user_feature_access f WHERE f.user_id = u.id AND f.enabled = 1) AS grant_count
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.managed_by_user_id = ? AND IFNULL(u.is_vendor, 0) = 0
             ORDER BY u.name, u.email
             LIMIT 500"
        );
        $st->execute([$managerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function findManaged(int $id, int $managerId): ?array
    {
        $st = $this->db->prepare(
            "SELECT u.*, r.code AS role FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND u.managed_by_user_id = ? AND IFNULL(u.is_vendor, 0) = 0
             LIMIT 1"
        );
        $st->execute([$id, $managerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,bool> feature_key => enabled */
    public function grantsFor(int $userId): array
    {
        $out = [];
        try {
            $st = $this->db->prepare('SELECT feature_key, enabled FROM user_feature_access WHERE user_id = ?');
            $st->execute([$userId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $out[(string) $row['feature_key']] = !empty($row['enabled']);
            }
        } catch (\Throwable) {
        }
        return $out;
    }

    /**
     * Admin-audience catalog keys the manager may assign (must be globally on, and if manager
     * is themselves managed, must be in their grants).
     *
     * @return array<string, array{0:string,1:string,2:?string,3:string}>
     */
    public function assignableCatalog(?array $manager): array
    {
        $features = new FeatureVisibilityService($this->db);
        $out = [];
        foreach (FeatureVisibilityService::catalog() as $key => $meta) {
            if (($meta[1] ?? '') !== 'admin') {
                continue;
            }
            if (!$features->canSee($manager, $key)) {
                continue;
            }
            $out[$key] = $meta;
        }
        return $out;
    }

    /**
     * @param list<string> $enabledKeys
     * @return array{ok:true,id:int}|array{ok:false,error:string}
     */
    public function createSubAdmin(
        array $manager,
        string $email,
        string $name,
        string $password,
        string $status,
        array $enabledKeys
    ): array {
        $managerId = (int) ($manager['id'] ?? 0);
        if ($managerId <= 0) {
            return ['ok' => false, 'error' => 'Not signed in.'];
        }
        $email = mb_strtolower(trim($email));
        $name = trim($name);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'A valid email is required.'];
        }
        if ($name === '') {
            $name = explode('@', $email)[0] ?: 'Admin';
        }
        $problems = AuthService::passwordProblems($password);
        if ($problems) {
            return ['ok' => false, 'error' => $problems[0]];
        }
        $status = $status === 'disabled' ? 'disabled' : 'active';
        $users = new UserRepository($this->db);
        if ($users->findByEmail($email) !== null) {
            return ['ok' => false, 'error' => 'That email is already used.'];
        }
        $roleId = $users->roleId('admin');
        if ($roleId === null) {
            return ['ok' => false, 'error' => 'admin role missing — run migrations.'];
        }
        $allowed = array_keys($this->assignableCatalog($manager));
        $keys = array_values(array_intersect($enabledKeys, $allowed));
        try {
            $id = $users->insert([
                'email' => $email,
                'name' => mb_substr($name, 0, 120),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role_id' => $roleId,
                'reseller_id' => null,
                'customer_id' => null,
                'managed_by_user_id' => $managerId,
                'status' => $status,
                'is_vendor' => 0,
                'failed_logins' => 0,
                'locked_until' => null,
            ]);
            // BaseRepository may ignore unknown fillable — force managed_by if needed.
            $this->db->prepare('UPDATE users SET managed_by_user_id = ?, is_vendor = 0 WHERE id = ?')
                ->execute([$managerId, $id]);
            $this->replaceGrants((int) $id, $keys);
            return ['ok' => true, 'id' => (int) $id];
        } catch (\PDOException $e) {
            $msg = ((int) ($e->errorInfo[1] ?? 0) === 1062)
                ? 'That email is already used.'
                : 'Could not create the user.';
            return ['ok' => false, 'error' => $msg];
        }
    }

    /**
     * @param list<string> $enabledKeys
     * @return array{ok:true}|array{ok:false,error:string}
     */
    public function updateSubAdmin(
        array $manager,
        int $userId,
        string $email,
        string $name,
        ?string $password,
        string $status,
        array $enabledKeys
    ): array {
        $managerId = (int) ($manager['id'] ?? 0);
        $row = $this->findManaged($userId, $managerId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        $email = mb_strtolower(trim($email));
        $name = trim($name);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'A valid email is required.'];
        }
        if ($name === '') {
            return ['ok' => false, 'error' => 'Name is required.'];
        }
        $status = $status === 'disabled' ? 'disabled' : 'active';
        $users = new UserRepository($this->db);
        $byEmail = $users->findByEmail($email);
        if ($byEmail !== null && (int) $byEmail['id'] !== $userId) {
            return ['ok' => false, 'error' => 'That email is already used.'];
        }
        if ($password !== null && $password !== '') {
            $problems = AuthService::passwordProblems($password);
            if ($problems) {
                return ['ok' => false, 'error' => $problems[0]];
            }
        }
        $allowed = array_keys($this->assignableCatalog($manager));
        $keys = array_values(array_intersect($enabledKeys, $allowed));
        try {
            $this->db->prepare(
                'UPDATE users SET email = ?, name = ?, status = ?, failed_logins = CASE WHEN ? = \'active\' THEN 0 ELSE failed_logins END,
                    locked_until = CASE WHEN ? = \'active\' THEN NULL ELSE locked_until END
                 WHERE id = ? AND managed_by_user_id = ?'
            )->execute([
                $email,
                mb_substr($name, 0, 120),
                $status,
                $status,
                $status,
                $userId,
                $managerId,
            ]);
            if ($password !== null && $password !== '') {
                $this->db->prepare(
                    'UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ? AND managed_by_user_id = ?'
                )->execute([password_hash($password, PASSWORD_DEFAULT), $userId, $managerId]);
            }
            $this->replaceGrants($userId, $keys);
            return ['ok' => true];
        } catch (\PDOException $e) {
            $msg = ((int) ($e->errorInfo[1] ?? 0) === 1062)
                ? 'That email is already used.'
                : 'Could not save the user.';
            return ['ok' => false, 'error' => $msg];
        }
    }

    public function deleteSubAdmin(int $userId, int $managerId): bool
    {
        $row = $this->findManaged($userId, $managerId);
        if ($row === null) {
            return false;
        }
        $this->db->prepare('DELETE FROM users WHERE id = ? AND managed_by_user_id = ? AND IFNULL(is_vendor,0) = 0')
            ->execute([$userId, $managerId]);
        return true;
    }

    /** @param list<string> $enabledKeys */
    public function replaceGrants(int $userId, array $enabledKeys): void
    {
        $this->db->prepare('DELETE FROM user_feature_access WHERE user_id = ?')->execute([$userId]);
        if ($enabledKeys === []) {
            return;
        }
        $ins = $this->db->prepare(
            'INSERT INTO user_feature_access (user_id, feature_key, enabled) VALUES (?,?,1)'
        );
        foreach (array_unique($enabledKeys) as $key) {
            if (!is_string($key) || $key === '' || !isset(FeatureVisibilityService::catalog()[$key])) {
                continue;
            }
            $ins->execute([$userId, $key]);
        }
    }
}
