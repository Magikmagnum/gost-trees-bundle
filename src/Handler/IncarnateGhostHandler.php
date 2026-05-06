<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Handler;

use Doctrine\ORM\EntityManagerInterface;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Contract\GhostIncarnatorInterface;

/**
 * Orchestre l'incarnation atomique d'un fantôme avec persistence.
 *
 * Encapsule le triplet (incarnate + flush) dans une transaction Doctrine,
 * garantissant que la matérialisation et la mise à jour en base forment
 * une unité atomique.
 *
 * Séparation des responsabilités :
 *  - GhostIncarnator : matérialise les valeurs héritées, ne sait pas persister.
 *  - IncarnateGhostHandler : orchestre la transaction. Ne sait pas trouver
 *    les entités ni interagir avec l'utilisateur.
 *  - IncarnateGhostCommand : interagit avec l'utilisateur et délègue ici.
 *
 * Réutilisable depuis n'importe quel point d'entrée (CLI, API, Messenger…).
 */
final class IncarnateGhostHandler
{
    public function __construct(
        private readonly GhostIncarnatorInterface $incarnator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Incarne l'entité et persiste le résultat dans une transaction atomique.
     *
     * Si l'entité n'est pas un fantôme, l'appel est un no-op (délégué à
     * GhostIncarnator qui l'ignore silencieusement).
     */
    public function handle(GhostableInterface $entity): void
    {
        $this->em->wrapInTransaction(function () use ($entity): void {
            $this->incarnator->incarnate($entity);
            $this->em->flush();
        });
    }
}
