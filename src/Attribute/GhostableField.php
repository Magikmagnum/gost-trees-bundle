<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Attribute;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Marque un champ comme fantomisable (héritable depuis un parent fantôme).
 *
 * Combine deux rôles :
 *  - métadonnée : le champ est exposé au resolver pour le debug et la sérialisation ;
 *  - validation : si "required" est vrai, le champ est obligatoire UNIQUEMENT
 *    sur les racines (les fantômes peuvent le laisser à null pour hériter).
 *
 * Exemple :
 *
 *     #[ORM\Column(length: 255, nullable: true)]
 *     #[GhostableField(required: true)]
 *     private ?string $lieuDepart = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class GhostableField extends Constraint
{
    public function __construct(
        public readonly bool $required = false,
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

    public function getTargets(): string|array
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
