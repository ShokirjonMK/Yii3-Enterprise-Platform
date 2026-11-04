<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application;

use App\Shared\Domain\Exception\ValidationException;
use SensitiveParameter;

/**
 * Password rules (OWASP ASVS V2.1): minimum length 12, character classes, no username,
 * no common passwords, no reuse of the last N passwords.
 */
final class PasswordPolicy
{
    private const COMMON = [
        'password', 'password1', 'password123', 'qwerty', 'qwerty123', '123456', '12345678', '123456789',
        '1234567890', 'admin', 'admin123', 'administrator', 'letmein', 'welcome', 'iloveyou', 'monkey',
        'dragon', 'football', 'baseball', 'superman', 'trustno1', 'changeme', 'parol', 'parol123', 'qwertyuiop',
        'p@ssw0rd', 'passw0rd', 'master', 'secret', 'root', 'toor',
    ];

    public function __construct(
        private readonly int $minLength = 12,
        private readonly bool $requireMixedCase = true,
        private readonly bool $requireDigit = true,
        private readonly bool $requireSymbol = true,
    ) {}

    /**
     * @return list<string> violations (empty = valid)
     */
    public function violations(#[SensitiveParameter] string $password, ?string $username = null): array
    {
        $errors = [];
        if (mb_strlen($password) < $this->minLength) {
            $errors[] = sprintf('Password must be at least %d characters long.', $this->minLength);
        }
        if (mb_strlen($password) > 256) {
            $errors[] = 'Password is too long.';
        }
        if ($this->requireMixedCase && (!preg_match('/\p{Lu}/u', $password) || !preg_match('/\p{Ll}/u', $password))) {
            $errors[] = 'Password must contain upper- and lower-case letters.';
        }
        if ($this->requireDigit && !preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain a digit.';
        }
        if ($this->requireSymbol && !preg_match('/[^\p{L}\d]/u', $password)) {
            $errors[] = 'Password must contain a symbol.';
        }
        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON, true) || in_array(preg_replace('/[^a-z0-9@]/', '', $lower), self::COMMON, true)) {
            $errors[] = 'Password is too common.';
        }
        if ($username !== null && mb_strlen($username) >= 3 && str_contains($lower, mb_strtolower($username))) {
            $errors[] = 'Password must not contain the username.';
        }
        return $errors;
    }

    public function assertValid(#[SensitiveParameter] string $password, ?string $username = null, string $field = 'password'): void
    {
        $errors = $this->violations($password, $username);
        if ($errors !== []) {
            throw new ValidationException([$field => $errors], $errors[0]);
        }
    }

    /** Cryptographically random password satisfying the policy (for bootstrap / resets). */
    public static function generate(int $length = 20): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*-_=+?'];
        $all = implode('', $sets);
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }
}
