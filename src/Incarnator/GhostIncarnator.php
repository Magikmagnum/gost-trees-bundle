<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Incarnator;

use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Contract\GhostIncarnatorInterface;
use EricGansa\GhostTreesBundle\Event\GhostIncarnatedEvent;
use EricGansa\GhostTreesBundle\Exception\GhostCycleException;
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

        foreach ($this->metadata->getPropertiesFor($entity) as $property) {
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
     *
     * Protection contre les cycles : un SplObjectStorage trace les nœuds
     * déjà visités. Un cycle détecté en données (manipulation SQL directe)
     * lève GhostCycleException plutôt que de boucler indéfiniment.
     *
     * @throws GhostCycleException si un cycle est détecté dans la chaîne
     */
    private function resolveFromAncestors(GhostableInterface $entity, string $propertyName): mixed
    {
        $visited = new \SplObjectStorage();
        $current = $entity->getParent();

        while (null !== $current) {
            if ($visited->contains($current)) {
                throw new GhostCycleException(\sprintf('Cycle détecté lors de la résolution de la propriété "%s". La chaîne fantôme contient une boucle — vérifiez l\'intégrité des données.', $propertyName));
            }
            $visited->attach($current);

            foreach ($this->metadata->getPropertiesFor($current) as $property) {
                if ($property->name !== $propertyName) {
                    continue;
                }
                $value = $property->readValue($current);

                if (null !== $value) {
                    return $value;
                }
            }
            $current = $current->getParent();
        }

        return null;
    }
}
