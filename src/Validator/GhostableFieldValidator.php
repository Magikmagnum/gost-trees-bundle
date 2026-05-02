<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Validator;

use EricGansa\GhostTreesBundle\Attribute\GhostableField;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Valide la contrainte #[GhostableField].
 *
 * Sémantique :
 *  - sur une racine (parent = null) et required = true : la valeur ne peut pas être null ;
 *  - sur un fantôme : la contrainte est silencieuse (la valeur sera héritée du parent).
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

        // Si l'objet n'est pas fantomisable, on applique la contrainte standard
        // (la valeur ne peut pas être null).
        if (!$object instanceof GhostableInterface) {
            if (null === $value || '' === $value) {
                $this->context->buildViolation($constraint->getDefaultMessage())->addViolation();
            }
            return;
        }

        // Si l'entité est un fantôme, la valeur peut être null
        // (elle sera résolue depuis le parent).
        if ($object->isGhost()) {
            return;
        }

        // Sur une racine, la valeur est obligatoire.
        if (null === $value || '' === $value) {
            $this->context->buildViolation($constraint->getDefaultMessage())->addViolation();
        }
    }
}
