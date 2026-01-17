<?php

namespace App\Validator;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class UniqueUsernameCaseInsensitiveValidator extends ConstraintValidator
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueUsernameCaseInsensitive) {
            throw new UnexpectedTypeException($constraint, UniqueUsernameCaseInsensitive::class);
        }

        if (!$value instanceof User) {
            return;
        }

        $username = $value->getUsername();
        if (null === $username || '' === $username) {
            return;
        }

        // Check for existing user with same username (case-insensitive)
        $existingUser = $this->entityManager->getConnection()->executeQuery(
            'SELECT id FROM user WHERE LOWER(username) = LOWER(?)',
            [$username]
        )->fetchOne();

        // If found and it's not the same user (for updates), add violation
        if ($existingUser && (string) $existingUser !== (string) $value->getId()) {
            $this->context->buildViolation($constraint->message)
                ->atPath('username')
                ->addViolation();
        }
    }
}
