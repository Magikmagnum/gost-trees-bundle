<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Event;

use EricGansa\GhostTreesBundle\Contract\GhostableInterface;

/**
 * Émis après qu'une entité a été rattachée à un parent.
 *
 * Permet aux applications d'orchestrer la propagation structurelle :
 * créer les fantômes correspondants pour les collections du parent,
 * envoyer une notification, mettre à jour un cache, etc.
 */
final class GhostAffiliatedEvent extends GhostEvent
{
    public function __construct(
        GhostableInterface $entity,
        public readonly GhostableInterface $parent,
    ) {
        parent::__construct($entity);
    }
}
