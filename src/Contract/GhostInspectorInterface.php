<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Contract;

/**
 * Service d'introspection des fantômes.
 *
 * Sépare les responsabilités de lecture des métadonnées et de production
 * de rapports de debug, du resolver qui ne fait que la résolution.
 */
interface GhostInspectorInterface
{
    /**
     * Indique si l'entité a au moins une valeur matérialisée localement.
     * Retourne false pour une racine ou un fantôme totalement transparent.
     */
    public function isMaterialized(GhostableInterface $entity): bool;

    /**
     * Retourne un descriptif attribut par attribut de l'origine des valeurs.
     *
     * @return array<string, array{value: mixed, source: string, depth: int}>
     */
    public function debugResolution(GhostableInterface $entity): array;
}
