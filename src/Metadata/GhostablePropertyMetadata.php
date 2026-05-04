<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Metadata;

/**
 * Métadonnées d'une propriété fantomisable, extraites une fois pour toutes
 * et conservées en cache par GhostMetadata.
 */
final class GhostablePropertyMetadata
{
    public function __construct(
        public readonly string $name,
        public readonly \ReflectionProperty $reflectionProperty,
        public readonly string $getter,
    ) {
    }

    public function readValue(object $entity): mixed
    {
        return $this->reflectionProperty->getValue($entity);
    }

    public function writeValue(object $entity, mixed $value): void
    {
        $this->reflectionProperty->setValue($entity, $value);
    }
}
