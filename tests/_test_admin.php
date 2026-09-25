<?php
// Shared by the tests that log in over HTTP: a throwaway admin account, so
// they never depend on (or fail a login against) the real "admin" account —
// its password is rotated after deploy, and every failed attempt counts
// toward the login lockout. Programmer role (id 1) so every page is allowed.

function createTestAdmin(PDO $pdo): array
{
    $username = 'zz_test_' . bin2hex(random_bytes(4));
    $password = 'T3st-' . bin2hex(random_bytes(6));
    $pdo->prepare('INSERT INTO admins (username, password_hash, role_id) VALUES (:u, :p, 1)')
        ->execute(['u' => $username, 'p' => password_hash($password, PASSWORD_DEFAULT)]);
    return ['username' => $username, 'password' => $password];
}

function deleteTestAdmin(PDO $pdo, string $username): void
{
    try {
        $pdo->prepare('DELETE FROM admins WHERE username = :u')->execute(['u' => $username]);
    } catch (Throwable $e) {
        echo "WARN  could not delete test admin $username: {$e->getMessage()}\n";
    }
}
