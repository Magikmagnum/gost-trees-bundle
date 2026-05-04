<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Event;

use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Classe de base des événements liés au cycle de vie d'un fantôme.
 *
 * Tous les événements concrets (Materialized, Dematerialized, Incarnated…)
 * exposent au minimum l'entité concernée.
 */
abstract class GhostEvent extends Event
{
    public function __construct(
        public readonly GhostableInterface $entity,
    ) {
    }
}
