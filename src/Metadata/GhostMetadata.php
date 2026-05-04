<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Metadata;

use EricGansa\GhostTreesBundle\Attribute\Ghostable;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;

/**
 * Cache d'introspection des propriétés fantomisables.
 *
 * Centralise les appels coûteux à la réflexion (ReflectionObject,
 * lecture des attributs) et les met en cache par classe. Sans ce service,
 * chaque appel à debugResolution() ou incarnate() sur 1000 entités
 * provoquerait 1000 introspections complètes.
 *
 * Cycle de vie : singleton applicatif (déclaré dans services.yaml).
 */
final class GhostMetadata
{
    /**
     * @var array<class-string, list<GhostablePropertyMetadata>>
     */
    private array $cache = [];

    /**
     * Retourne les métadonnées des propriétés fantomisables d'une classe.
     *
     * @return list<GhostablePropertyMetadata>
     */
    public function getProperties(string|GhostableInterface $classOrEntity): array
    {
        $class = is_object($classOrEntity) ? $classOrEntity::class : $classOrEntity;

        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        $properties = [];
        $reflection = new \ReflectionClass($class);

        // Remonter aussi les classes parentes pour les propriétés héritées.
        $current = $reflection;
        $seen = [];
        while ($current !== false) {
            foreach ($current->getProperties() as $property) {
                if (isset($seen[$property->getName()])) {
                    continue;
                }
                $seen[$property->getName()] = true;

                $attributes = $property->getAttributes(Ghostable::class);
                if (empty($attributes)) {
                    continue;
                }

                /** @var Ghostable $ghostableAttr */
                $ghostableAttr = $attributes[0]->newInstance();

                $property->setAccessible(true);

                $properties[] = new GhostablePropertyMetadata(
                    name: $property->getName(),
                    reflectionProperty: $property,
                    getter: $ghostableAttr->getter ?? 'get' . ucfirst($property->getName()),
                );
            }
            $current = $current->getParentClass();
        }

        return $this->cache[$class] = $properties;
    }

    /**
     * Vide le cache (utile en tests ou en environnement de dev).
     */
    public function clear(): void
    {
        $this->cache = [];
    }
}
