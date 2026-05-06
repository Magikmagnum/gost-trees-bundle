<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Attribute;

/**
 * Marque une propriété comme champ fantôme.
 *
 * Rôle PUREMENT INTROSPECTIF : signale au resolver, à l'inspector et aux
 * méthodes incarnate()/reset() que cette propriété participe à la résolution
 * dynamique depuis le parent.
 *
 * Pour la validation conditionnelle (obligatoire sur les racines uniquement),
 * utiliser en complément la contrainte #[RequiredOnRoot].
 *
 * Exemple :
 *
 *     #[ORM\Column(length: 255, nullable: true)]
 *     #[GhostField]
 *     #[RequiredOnRoot]
 *     private ?string $lieuDepart = null;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class GhostField
{
    public function __construct(
        /**
         * Nom du getter à invoquer sur le parent lors de la résolution.
         * Si null, le nom est dérivé automatiquement ("lieuDepart" → "getLieuDepart").
         */
        public readonly ?string $getter = null,
    ) {
    }
}
