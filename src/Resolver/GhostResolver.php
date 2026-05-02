<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Resolver;

use EricGansa\GhostTreesBundle\Attribute\GhostableField;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Contract\GhostResolverInterface;
use EricGansa\GhostTreesBundle\Exception\GhostCycleException;
use EricGansa\GhostTreesBundle\Exception\GhostDepthExceededException;

/**
 * Implémentation du resolver fantôme.
 *
 * Centralise la logique de :
 *  - traversée fantôme (résolution des valeurs en remontant la chaîne) ;
 *  - introspection (état de matérialisation, debug) ;
 *  - incarnation (promotion d'un fantôme en racine).
 */
final class GhostResolver implements GhostResolverInterface
{
    public function __construct(
        private readonly int $maxDepth = 1,
    ) {
    }

    public function resolve(GhostableInterface $entity, mixed $localValue, string $getter): mixed
    {
        if (null !== $localValue) {
            return $localValue;
        }

        $parent = $entity->getParent();
        if (null === $parent) {
            return null;
        }

        if (!method_exists($parent, $getter)) {
            return null;
        }

        return $parent->$getter();
    }

    public function isMaterialized(GhostableInterface $entity): bool
    {
        if (!$entity->isGhost()) {
            return false;
        }

        foreach ($this->getGhostableProperties($entity) as $property) {
            $property->setAccessible(true);
            if (null !== $property->getValue($entity)) {
                return true;
            }
        }

        return false;
    }

    public function debugResolution(GhostableInterface $entity): array
    {
        $result = [];

        foreach ($this->getGhostableProperties($entity) as $property) {
            $name = $property->getName();
            $property->setAccessible(true);
            $localValue = $property->getValue($entity);

            if (null !== $localValue) {
                $result[$name] = [
                    'value' => $localValue,
                    'source' => 'local',
                    'depth' => 0,
                ];
                continue;
            }

            // Remonter la chaîne fantôme jusqu'à trouver une valeur.
            $current = $entity->getParent();
            $depth = 1;
            $value = null;
            $source = 'unset';

            while (null !== $current && $depth <= $this->maxDepth + 1) {
                $reflection = new \ReflectionObject($current);
                if ($reflection->hasProperty($name)) {
                    $parentProperty = $reflection->getProperty($name);
                    $parentProperty->setAccessible(true);
                    $parentValue = $parentProperty->getValue($current);
                    if (null !== $parentValue) {
                        $value = $parentValue;
                        $source = 'inherited';
                        break;
                    }
                }
                $current = $current instanceof GhostableInterface ? $current->getParent() : null;
                ++$depth;
            }

            $result[$name] = [
                'value' => $value,
                'source' => $source,
                'depth' => 'inherited' === $source ? $depth : 0,
            ];
        }

        return $result;
    }

    public function incarnate(GhostableInterface $entity): void
    {
        if (!$entity->isGhost()) {
            return;
        }

        foreach ($this->getGhostableProperties($entity) as $property) {
            $name = $property->getName();
            $property->setAccessible(true);

            // Si la valeur locale est déjà présente, ne touche à rien.
            if (null !== $property->getValue($entity)) {
                continue;
            }

            // Sinon, matérialiser la valeur héritée.
            $current = $entity->getParent();
            while (null !== $current) {
                $reflection = new \ReflectionObject($current);
                if ($reflection->hasProperty($name)) {
                    $parentProperty = $reflection->getProperty($name);
                    $parentProperty->setAccessible(true);
                    $parentValue = $parentProperty->getValue($current);
                    if (null !== $parentValue) {
                        $property->setValue($entity, $parentValue);
                        break;
                    }
                }
                $current = $current instanceof GhostableInterface ? $current->getParent() : null;
            }
        }

        // Détache le lien parent : l'entité devient une racine.
        $entity->setParent(null);
    }

    /**
     * Vérifie qu'un parent peut être affecté à une entité sans dépasser
     * la profondeur maximale ni créer de cycle. À appeler depuis setParent().
     *
     * @throws GhostCycleException
     * @throws GhostDepthExceededException
     */
    public function assertValidParent(GhostableInterface $entity, ?GhostableInterface $parent): void
    {
        if (null === $parent) {
            return;
        }

        if ($parent === $entity) {
            throw new GhostCycleException('Une entité ne peut pas être son propre parent.');
        }

        // Détection de cycle et calcul de la profondeur.
        $depth = 1;
        $current = $parent;
        $visited = new \SplObjectStorage();
        $visited->attach($entity);

        while (null !== $current) {
            if ($visited->contains($current)) {
                throw new GhostCycleException('Cycle détecté dans la chaîne fantôme.');
            }
            $visited->attach($current);

            $next = $current->getParent();
            if (null !== $next) {
                ++$depth;
                if ($depth > $this->maxDepth) {
                    throw new GhostDepthExceededException(sprintf(
                        'Profondeur fantôme dépassée : %d > %d (max).',
                        $depth,
                        $this->maxDepth
                    ));
                }
            }

            $current = $next;
        }
    }

    /**
     * @return iterable<\ReflectionProperty>
     */
    private function getGhostableProperties(GhostableInterface $entity): iterable
    {
        $reflection = new \ReflectionObject($entity);
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(GhostableField::class);
            if (!empty($attributes)) {
                yield $property;
            }
        }
    }
}
