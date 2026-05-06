<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Contract;

use EricGansa\GhostTreesBundle\Exception\GhostCycleException;
use EricGansa\GhostTreesBundle\Exception\GhostDepthExceededException;

/**
 * Resolver fantôme : résolution dynamique des valeurs et validation
 * structurelle (profondeur, cycle).
 *
 * NE FAIT PAS : l'incarnation (voir GhostIncarnatorInterface) ni le debug
 * (voir GhostInspectorInterface). Cette séparation permet de tester chaque
 * responsabilité indépendamment.
 */
interface GhostResolverInterface
{
    /**
     * Résout la valeur d'un attribut donné.
     *
     * @param string $getter nom du getter à appeler récursivement sur le parent
     */
    public function resolve(GhostableInterface $entity, mixed $localValue, string $getter): mixed;

    /**
     * Vérifie qu'un parent peut être affecté à une entité sans dépasser
     * la profondeur maximale ni créer de cycle.
     *
     * @throws GhostCycleException
     * @throws GhostDepthExceededException
     */
    public function assertValidParent(GhostableInterface $entity, ?GhostableInterface $parent): void;

    /**
     * Profondeur maximale configurée pour cette instance.
     */
    public function getMaxDepth(): int;
}
