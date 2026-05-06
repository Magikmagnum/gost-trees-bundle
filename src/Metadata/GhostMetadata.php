<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Metadata;

use EricGansa\GhostTreesBundle\Attribute\GhostField;
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
     * @param class-string $class
     *
     * @return list<GhostablePropertyMetadata>
     */
    public function getProperties(string $class): array
    {
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        $properties = [];
        $seen = [];
        $current = new \ReflectionClass($class);

        // Remonter aussi les classes parentes pour les propriétés héritées.
        do {
            foreach ($current->getProperties() as $property) {
                if (isset($seen[$property->getName()])) {
                    continue;
                }
                $seen[$property->getName()] = true;

                // GhostField est la classe de base ; Ghostable (alias déprécié) en hérite.
                $attributes = $property->getAttributes(GhostField::class, \ReflectionAttribute::IS_INSTANCEOF);

                if (empty($attributes)) {
                    continue;
                }

                /** @var GhostField $ghostFieldAttr */
                $ghostFieldAttr = $attributes[0]->newInstance();

                $properties[] = new GhostablePropertyMetadata(
                    name: $property->getName(),
                    reflectionProperty: $property,
                    getter: $ghostFieldAttr->getter ?? 'get' . ucfirst($property->getName()),
                );
            }
            $current = $current->getParentClass();
        } while ($current instanceof \ReflectionClass);

        return $this->cache[$class] = $properties;
    }

    /**
     * Raccourci pour les appelants qui disposent d'une instance plutôt que d'un FQCN.
     *
     * @return list<GhostablePropertyMetadata>
     */
    public function getPropertiesFor(GhostableInterface $entity): array
    {
        return $this->getProperties($entity::class);
    }

    /**
     * Vide le cache (utile en tests ou en environnement de dev).
     */
    public function clear(): void
    {
        $this->cache = [];
    }
}
