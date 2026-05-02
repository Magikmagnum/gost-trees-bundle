<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;

/**
 * Propagation structurelle des collections fantômes.
 *
 * Lorsqu'un élément est ajouté à la collection d'une entité racine,
 * ce subscriber crée automatiquement les fantômes correspondants
 * dans chaque entité enfant rattachée à cette racine.
 *
 * NOTE : ce squelette pose les hooks ; la logique complète de
 * traversée des collections et de création des fantômes doit être
 * implémentée en s'appuyant sur les métadonnées Doctrine (associations
 * OneToMany / OneToOne portant des entités GhostableInterface).
 */
final class GhostPropagationSubscriber implements EventSubscriber
{
    public function __construct(
        private readonly bool $enabled = true,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::onFlush,
        ];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof GhostableInterface) {
                continue;
            }

            // TODO : pour chaque association OneToMany/OneToOne portant des
            // GhostableInterface, propager la création vers les enfants
            // de l'entité racine concernée.
            //
            // Étapes :
            //  1. Récupérer les métadonnées Doctrine de $entity.
            //  2. Pour chaque association mappée vers une GhostableInterface :
            //      a. Identifier la racine concernée et ses enfants ;
            //      b. Pour chaque enfant, créer un fantôme rattaché à $entity ;
            //      c. Persister les fantômes créés.
            //
            // Cette logique est volontairement laissée à implémenter au cas par cas
            // car elle dépend des conventions de mapping du projet utilisateur.
        }
    }
}
