<?php
// Shared by the tests that log in over HTTP: a throwaway admin account, so
// they never depend on (or fail a login against) the real "admin" account —
// its password is rotated after deploy, and every failed attempt counts
// toward the login lockout. Defaults to the Programmer role (id 1) so every
// page is allowed; pass 2 for the Executive role (read-only dashboards).
//
// The row is deleted by a shutdown function registered right here, at the
// moment it's created — so it's removed even if the test bails out early
// (e.g. exit(1) because its server never came up), which a cleanup
// registered later in the test would miss, leaving a permanent
// full-permission admin behind.

function createTestAdmin(PDO $pdo, int $roleId = 1): array
{
    $username = 'zz_test_' . bin2hex(random_bytes(4));
    $password = 'T3st-' . bin2hex(random_bytes(6));
    $pdo->prepare('INSERT INTO admins (username, password_hash, role_id) VALUES (:u, :p, :r)')
        ->execute(['u' => $username, 'p' => password_hash($password, PASSWORD_DEFAULT), 'r' => $roleId]);
    register_shutdown_function(static function () use ($pdo, $username) {
        try {
            $pdo->prepare('DELETE FROM admins WHERE username = :u')->execute(['u' => $username]);
        } catch (Throwable $e) {
            echo "WARN  could not delete test admin $username: {$e->getMessage()}\n";
        }
    });
    return ['username' => $username, 'password' => $password];
}
