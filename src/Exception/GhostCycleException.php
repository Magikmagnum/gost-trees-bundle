<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Exception;

/**
 * Levée lorsqu'une opération créerait un cycle dans la chaîne fantôme
 * (par exemple : tentative de définir une entité comme son propre parent
 * direct ou indirect).
 */
final class GhostCycleException extends \LogicException
{
}
