<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Incarnator;

use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Contract\GhostIncarnatorInterface;
use EricGansa\GhostTreesBundle\Event\GhostIncarnatedEvent;
use EricGansa\GhostTreesBundle\Metadata\GhostMetadata;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Implémentation de l'incarnation.
 *
 * Algorithme :
 *  1. Pour chaque propriété fantomisable null localement, lire la valeur
 *     résolue depuis le parent et l'écrire localement.
 *  2. Couper le lien parent.
 *  3. Émettre un GhostIncarnatedEvent.
 *
 * Note transactionnelle : ce service ne gère PAS les transactions Doctrine.
 * Si l'incarnation doit être atomique avec la persistence, l'appelant
 * doit envelopper l'opération dans EntityManager::wrapInTransaction().
 */
final class GhostIncarnator implements GhostIncarnatorInterface
{
    public function __construct(
        private readonly GhostMetadata $metadata,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function incarnate(GhostableInterface $entity): void
    {
        if (!$entity->isGhost()) {
            return;
        }

        $previousParent = $entity->getParent();

        foreach ($this->metadata->getProperties($entity) as $property) {
            // Si la valeur locale est déjà présente, on la conserve.
            if (null !== $property->readValue($entity)) {
                continue;
            }

            // Sinon, on matérialise la valeur héritée.
            $resolvedValue = $this->resolveFromAncestors($entity, $property->name);
            if (null !== $resolvedValue) {
                $property->writeValue($entity, $resolvedValue);
            }
        }

        $entity->setParent(null);

        $this->eventDispatcher->dispatch(new GhostIncarnatedEvent($entity, $previousParent));
    }

    /**
     * Remonte la chaîne fantôme pour trouver la première valeur matérialisée
     * d'un attribut donné.
     */
    private function resolveFromAncestors(GhostableInterface $entity, string $propertyName): mixed
    {
        $current = $entity->getParent();
        while (null !== $current) {
            foreach ($this->metadata->getProperties($current) as $property) {
                if ($property->name !== $propertyName) {
                    continue;
                }
                $value = $property->readValue($current);
                if (null !== $value) {
                    return $value;
                }
            }
            $current = $current instanceof GhostableInterface ? $current->getParent() : null;
        }

        return null;
    }
}
