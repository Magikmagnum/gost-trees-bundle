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
 *  - sur une racine (parent = null) avec required=true : valeur non null obligatoire ;
 *  - sur un fantôme : la contrainte est silencieuse (la valeur sera résolue depuis le parent).
 *
 * Le validator NE consulte PAS l'attribut #[Ghostable]. C'est volontaire :
 *  - l'attribut #[Ghostable] est purement introspectif (utilisé par le resolver/inspector) ;
 *  - la contrainte #[GhostableField] est pure validation.
 *
 * Les deux peuvent coexister sur une même propriété, ou être utilisés
 * séparément selon le besoin.
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

        // Sur un fantôme, la contrainte est inopérante.
        if ($isGhost) {
            return;
        }

        // Sur une racine (ou objet non fantomisable), la valeur est obligatoire.
        if (null === $value || '' === $value) {
            $this->context->buildViolation($constraint->getDefaultMessage())->addViolation();
        }
    }
}
