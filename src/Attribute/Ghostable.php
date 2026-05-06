<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Attribute;

/**
 * @deprecated Utiliser #[GhostField] à la place. Sera supprimé en v1.0.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class Ghostable extends GhostField
{
}
