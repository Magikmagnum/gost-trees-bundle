<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Event;

use EricGansa\GhostTreesBundle\Contract\GhostableInterface;

/**
 * Émis après l'incarnation d'un fantôme : ses valeurs ont été matérialisées
 * et son lien parent a été coupé. L'entité est désormais une racine autonome.
 *
 * Le parent original est exposé pour permettre aux écouteurs de tracer
 * l'origine de l'incarnation (audit, notifications, etc.).
 */
final class GhostIncarnatedEvent extends GhostEvent
{
    public function __construct(
        GhostableInterface $entity,
        public readonly ?GhostableInterface $previousParent,
    ) {
        parent::__construct($entity);
    }
}
