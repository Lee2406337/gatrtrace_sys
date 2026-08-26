<?php
namespace App;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function check(): bool
    {
        return isset($_SESSION['csrf'], $_POST['_csrf'])
            && hash_equals($_SESSION['csrf'], (string) $_POST['_csrf']);
    }
}
