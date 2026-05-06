<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Resolver;

use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Contract\GhostResolverInterface;
use EricGansa\GhostTreesBundle\Exception\GhostCycleException;
use EricGansa\GhostTreesBundle\Exception\GhostDepthExceededException;

/**
 * Implémentation du resolver fantôme.
 *
 * Une seule responsabilité : la règle de résolution et son contrat
 * structurel (profondeur, cycle). Le debug et l'incarnation sont
 * délégués à d'autres services pour faciliter les tests et l'extension.
 */
final class GhostResolver implements GhostResolverInterface
{
    public function __construct(
        private readonly int $maxDepth = 1,
    ) {
        if ($maxDepth < 1) {
            throw new \InvalidArgumentException(\sprintf('max_depth doit être >= 1, %d donné.', $maxDepth));
        }
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

    public function assertValidParent(GhostableInterface $entity, ?GhostableInterface $parent): void
    {
        if (null === $parent) {
            return;
        }

        if ($parent === $entity) {
            throw new GhostCycleException('Une entité ne peut pas être son propre parent.');
        }

        // Calcul de la profondeur effective si on rattache $entity à $parent.
        // Pour respecter max_depth, on doit s'assurer que la chaîne complète
        // depuis $entity jusqu'à la racine n'excède pas max_depth niveaux.
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
                // La détection de cycle est prioritaire sur le dépassement de profondeur :
                // un cycle doit toujours lever GhostCycleException, quelle que soit
                // la valeur de max_depth configurée.
                if ($visited->contains($next)) {
                    throw new GhostCycleException('Cycle détecté dans la chaîne fantôme.');
                }
                ++$depth;

                if ($depth > $this->maxDepth) {
                    throw new GhostDepthExceededException(\sprintf('Profondeur fantôme dépassée : %d > %d (max).', $depth, $this->maxDepth));
                }
            }

            $current = $next;
        }
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }
}
