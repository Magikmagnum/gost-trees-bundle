<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Validator;

use EricGansa\GhostTreesBundle\Attribute\GhostableField;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * @deprecated Utiliser RequiredOnRootValidator à la place. Sera supprimé en v1.0.
 */
final class GhostableFieldValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof GhostableField) {
            throw new UnexpectedTypeException($constraint, GhostableField::class);
        }

        if (!$constraint->required) {
            return;
        }

        $object = $this->context->getObject();
        $isGhost = $object instanceof GhostableInterface && $object->isGhost();

        if ($isGhost) {
            return;
        }

        if (null === $value || '' === $value) {
            $this->context->buildViolation($constraint->getDefaultMessage())->addViolation();
        }
    }
}
