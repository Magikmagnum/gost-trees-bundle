<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Exception;

/**
 * Levée lorsqu'une opération créerait une chaîne fantôme dépassant
 * la profondeur maximale configurée (ghost_trees.max_depth).
 */
final class GhostDepthExceededException extends \LogicException
{
}
