<?php

declare(strict_types=1);

namespace App\Platform\Gii\Spec;

use InvalidArgumentException;

/**
 * Invalid generator input (module name, field spec, generator list, overwrite list). Carries all
 * errors per input key so the console and the admin form can show them together.
 */
final class GiiSpecException extends InvalidArgumentException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode(' ', array_merge(...array_values($errors))));
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
