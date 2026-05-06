<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Attribute;

use Symfony\Component\Validator\Constraint;

/**
 * Contrainte de validation : champ obligatoire uniquement sur les racines.
 *
 * Rôle PUREMENT VALIDATIONNEL : si placé sur une propriété d'une entité racine
 * (sans parent), la valeur null déclenche une violation. Sur un fantôme
 * (avec parent), la contrainte est silencieuse — la valeur sera résolue depuis
 * le parent au moment de la lecture.
 *
 * Cette contrainte est INDÉPENDANTE de #[GhostField] : on peut valider une
 * propriété sur les racines sans que cette propriété participe à la résolution
 * dynamique (cas rares mais légitimes).
 *
 * Exemple :
 *
 *     #[ORM\Column(length: 255, nullable: true)]
 *     #[GhostField]
 *     #[RequiredOnRoot]
 *     private ?string $lieuDepart = null;
 *
 *     // Avec message personnalisé :
 *     #[RequiredOnRoot(message: 'Le lieu de départ est obligatoire pour un trajet racine.')]
 *     private ?string $lieuDepart = null;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class RequiredOnRoot extends Constraint
{
    public function __construct(
        public readonly ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);
    }

    public function getDefaultMessage(): string
    {
        return $this->message ?? 'Ce champ est obligatoire sur une entité racine.';
    }

    public function getTargets(): string
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
