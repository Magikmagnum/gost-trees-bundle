<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Validator;

use EricGansa\GhostTreesBundle\Attribute\RequiredOnRoot;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Valide la contrainte #[RequiredOnRoot].
 *
 * Sémantique :
 *  - sur une racine (parent = null) : valeur null déclenche une violation ;
 *  - sur un fantôme : la contrainte est silencieuse (la valeur sera résolue
 *    dynamiquement depuis le parent au moment de la lecture).
 *
 * Ce validator NE consulte PAS #[GhostField]. C'est volontaire :
 *  - #[GhostField] est purement introspectif (resolver / inspector) ;
 *  - #[RequiredOnRoot] est purement validationnel.
 *
 * Les deux peuvent coexister ou être utilisés séparément.
 */
final class RequiredOnRootValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RequiredOnRoot) {
            throw new UnexpectedTypeException($constraint, RequiredOnRoot::class);
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
