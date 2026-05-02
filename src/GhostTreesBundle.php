<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Bundle "Ghost Trees" — héritage dynamique pour entités Doctrine.
 *
 * Une entité fantôme (ghost node) hérite dynamiquement des attributs
 * d'une entité racine (root) tant qu'elle n'a pas matérialisé ses propres
 * valeurs. La matérialisation est granulaire (par attribut) et réversible.
 */
final class GhostTreesBundle extends Bundle
{
}
