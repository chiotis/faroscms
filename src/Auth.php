<?php

declare(strict_types=1);

namespace FarosCMS;

use Symfony\Component\Yaml\Yaml;

final class Auth
{
    private string $usersFile;

    public function __construct(string $usersFile)
    {
        $this->usersFile = $usersFile;
    }

    public function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function attempt(string $username, string $password): bool
    {
        $users = $this->loadUsers();
        foreach ($users as $user) {
            if (($user['username'] ?? '') !== $username) {
                continue;
            }

            $hash = (string)($user['password'] ?? '');
            if (str_starts_with($hash, 'plain:')) {
                if (substr($hash, 6) === $password) {
                    $_SESSION['user'] = $user;
                    return true;
                }
                return false;
            }

            if ($hash !== '' && password_verify($password, $hash)) {
                $_SESSION['user'] = $user;
                return true;
            }
            return false;
        }
        return false;
    }

    public function logout(): void
    {
        unset($_SESSION['user']);
    }

    /** @return array<int, array<string, mixed>> */
    public function loadUsers(): array
    {
        if (!file_exists($this->usersFile)) {
            return [];
        }
        $data = Yaml::parseFile($this->usersFile);
        return is_array($data) ? $data : [];
    }
}
