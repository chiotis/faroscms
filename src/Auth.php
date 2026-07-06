<?php

declare(strict_types=1);

namespace FarosCMS;

use Symfony\Component\Yaml\Yaml;

final class Auth
{
    private string $usersFile;
    private ?UserRepository $users;

    public function __construct(string $usersFile, ?UserRepository $users = null)
    {
        $this->usersFile = $usersFile;
        $this->users = $users;
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
        if ($this->users && $this->users->isAvailable()) {
            $user = $this->users->findForLogin($username);
            if ($user) {
                $hash = (string)($user['password_hash'] ?? '');
                if ($this->users->isActive($user) && $hash !== '' && password_verify($password, $hash)) {
                    $this->users->markLastLogin((int)$user['id']);
                    $_SESSION['user'] = $this->users->publicUser($user);
                    $_SESSION['user']['last_login_at'] = gmdate('c');
                    return true;
                }
                return false;
            }
        }

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

    public function loginUser(array $user): void
    {
        if ($this->users && isset($user['id'])) {
            $this->users->markLastLogin((int)$user['id']);
            $user = $this->users->publicUser($user);
            $user['last_login_at'] = gmdate('c');
        }
        $_SESSION['user'] = $user;
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
