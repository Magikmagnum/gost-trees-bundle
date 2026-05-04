<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Attribute;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Contrainte de validation pour un champ fantomisable.
 *
 * Rôle PUREMENT VALIDATIONNEL : si "required" est vrai, le champ est
 * obligatoire UNIQUEMENT sur les racines. Sur les fantômes, la contrainte
 * est silencieuse (la valeur sera héritée du parent).
 *
 * Cette contrainte est INDÉPENDANTE de l'attribut #[Ghostable] : on peut
 * vouloir une validation conditionnelle sans avoir besoin de la résolution
 * dynamique (cas rare mais légitime).
 *
 * Exemple :
 *
 *     #[ORM\Column(length: 255, nullable: true)]
 *     #[Ghostable]
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
