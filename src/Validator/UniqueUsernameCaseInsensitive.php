<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class UniqueUsernameCaseInsensitive extends Constraint
{
    public string $message = 'auth.register.error.username_already_used';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
