<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Contract;

/**
 * Service d'incarnation des fantômes.
 *
 * Une opération unique mais critique : matérialiser toutes les valeurs
 * résolues d'un fantôme et couper son lien parent. C'est une opération
 * destructive qui mérite son propre service pour faciliter le test
 * et l'extension (par exemple : version transactionnelle, version
 * batch sur plusieurs entités, etc.).
 */
interface GhostIncarnatorInterface
{
    /**
     * Incarne un fantôme en racine autonome.
     *
     * Si l'entité est déjà une racine, l'opération est silencieuse.
     */
    public function incarnate(GhostableInterface $entity): void;
}
