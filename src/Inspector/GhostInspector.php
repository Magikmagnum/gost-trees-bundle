<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Inspector;

use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Contract\GhostInspectorInterface;
use EricGansa\GhostTreesBundle\Metadata\GhostMetadata;

/**
 * Inspector fantôme : introspection des entités via le cache de métadonnées.
 *
 * Ne touche jamais aux valeurs (lecture seule). Les opérations qui modifient
 * l'état de l'entité sont dans GhostIncarnator.
 */
final class GhostInspector implements GhostInspectorInterface
{
    public function __construct(
        private readonly GhostMetadata $metadata,
    ) {
    }

    public function isMaterialized(GhostableInterface $entity): bool
    {
        if (!$entity->isGhost()) {
            return false;
        }

        foreach ($this->metadata->getProperties($entity) as $property) {
            if (null !== $property->readValue($entity)) {
                return true;
            }
        }

        return false;
    }

    public function debugResolution(GhostableInterface $entity): array
    {
        $result = [];

        foreach ($this->metadata->getProperties($entity) as $property) {
            $localValue = $property->readValue($entity);

            if (null !== $localValue) {
                $result[$property->name] = [
                    'value' => $localValue,
                    'source' => 'local',
                    'depth' => 0,
                ];
                continue;
            }

            // Remonter la chaîne fantôme jusqu'à trouver une valeur matérialisée.
            $current = $entity->getParent();
            $depth = 1;
            $value = null;
            $source = 'unset';

            while (null !== $current) {
                $parentProperties = $this->metadata->getProperties($current);
                foreach ($parentProperties as $parentProperty) {
                    if ($parentProperty->name !== $property->name) {
                        continue;
                    }
                    $parentValue = $parentProperty->readValue($current);
                    if (null !== $parentValue) {
                        $value = $parentValue;
                        $source = 'inherited';
                        break 2;
                    }
                }

                $current = $current instanceof GhostableInterface ? $current->getParent() : null;
                ++$depth;
            }

            $result[$property->name] = [
                'value' => $value,
                'source' => $source,
                'depth' => 'inherited' === $source ? $depth : 0,
            ];
        }

        return $result;
    }
}
