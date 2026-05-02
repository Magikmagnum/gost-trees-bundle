<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Contract;

/**
 * Service centralisé de résolution des valeurs fantômes.
 *
 * Permet d'extraire la logique des getters pour la rendre testable,
 * inspectable (debug) et extensible (décoration).
 */
interface GhostResolverInterface
{
    /**
     * Résout la valeur d'un attribut donné en remontant la chaîne fantôme
     * jusqu'à trouver une valeur non nulle, ou en retournant null si la
     * racine elle-même n'a pas la valeur.
     *
     * @param string $getter Nom du getter à appeler récursivement sur le parent.
     */
    public function resolve(GhostableInterface $entity, mixed $localValue, string $getter): mixed;

    /**
     * Indique si l'entité a au moins une valeur matérialisée localement.
     * Retourne false pour une entité racine ou un fantôme totalement transparent.
     */
    public function isMaterialized(GhostableInterface $entity): bool;

    /**
     * Retourne un descriptif attribut par attribut de l'origine des valeurs
     * (locale, héritée du parent, héritée du grand-parent, etc.).
     *
     * @return array<string, array{value: mixed, source: string, depth: int}>
     */
    public function debugResolution(GhostableInterface $entity): array;

    /**
     * Incarne un fantôme : matérialise localement toutes les valeurs
     * actuellement résolues, puis détache le lien parent.
     *
     * Après incarnation, l'entité devient une racine autonome.
     */
    public function incarnate(GhostableInterface $entity): void;
}
