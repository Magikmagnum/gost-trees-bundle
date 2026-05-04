<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Attribute;

use Attribute;

/**
 * Métadonnée déclarant une propriété comme fantomisable.
 *
 * Rôle PUREMENT INTROSPECTIF : marque la propriété pour que le resolver
 * et les outils de debug sachent qu'elle suit la règle de résolution
 * fantôme. Ne porte aucune logique de validation.
 *
 * Pour la validation conditionnelle (champ requis sur les racines uniquement),
 * utiliser en complément la contrainte #[GhostableField].
 *
 * Exemple :
 *
 *     #[ORM\Column(length: 255, nullable: true)]
 *     #[Ghostable]
 *     #[GhostableField(required: true)]
 *     private ?string $lieuDepart = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Ghostable
{
    public function __construct(
        /**
         * Nom du getter à invoquer sur le parent lors de la résolution.
         * Si null, le nom est dérivé automatiquement du nom de la propriété
         * ("lieuDepart" → "getLieuDepart").
         */
        public readonly ?string $getter = null,
    ) {
    }
}
