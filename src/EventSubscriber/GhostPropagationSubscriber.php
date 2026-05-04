<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\EventSubscriber;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Contract\GhostIncarnatorInterface;

/**
 * Subscriber Doctrine assurant la cohérence des arbres fantômes en base.
 *
 * Deux responsabilités :
 *
 *  1. PROPAGATION STRUCTURELLE (onFlush)
 *     Lors de l'ajout d'un élément à une collection d'une racine,
 *     créer les fantômes correspondants pour chaque enfant de la racine.
 *
 *  2. SUPPRESSION DE RACINE (preRemove)
 *     - mode "cascade" : déléguer à Doctrine via onDelete CASCADE en mapping ;
 *     - mode "incarnate" : incarner les fantômes avant que la racine soit
 *       effectivement supprimée, pour préserver leurs données.
 *
 * LIMITES CONNUES :
 *  - La propagation structurelle se base sur la convention que les collections
 *    OneToMany porteuses de fantômes utilisent une propriété "parent" sur les
 *    éléments. Les conventions hors-norme nécessitent une extension ou une
 *    désactivation via auto_propagate_collections=false.
 *  - L'incarnation à la suppression ne gère pas les sous-collections en cascade :
 *    incarner un Trajet n'incarne pas automatiquement son Hebergement masqué.
 *    Si ce besoin existe, l'application doit s'abonner à GhostIncarnatedEvent
 *    et propager elle-même.
 */
final class GhostPropagationSubscriber
{
    public function __construct(
        private readonly GhostIncarnatorInterface $incarnator,
        private readonly bool $autoPropagateCollections,
        private readonly string $onRootDelete,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::onFlush,
            Events::preRemove,
        ];
    }

    /**
     * Propagation structurelle : à l'ajout d'un élément côté racine,
     * créer les fantômes correspondants chez les enfants.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->autoPropagateCollections) {
            return;
        }

        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            /** @var Collection $collection */
            $owner = $collection->getOwner();
            if (!$owner instanceof GhostableInterface) {
                continue;
            }

            // On ne propage que depuis les racines : un fantôme qui ajoute
            // un élément à sa propre collection crée un élément qui lui
            // appartient en propre (possession exclusive).
            if ($owner->isGhost()) {
                continue;
            }

            $children = $this->getChildrenOf($owner, $em);
            if (empty($children)) {
                continue;
            }

            $insertedItems = $collection->getInsertDiff();
            foreach ($insertedItems as $insertedItem) {
                if (!$insertedItem instanceof GhostableInterface) {
                    continue;
                }

                foreach ($children as $child) {
                    $ghost = $this->createGhostOf($insertedItem);
                    $em->persist($ghost);

                    // Recalcul nécessaire pour que Doctrine prenne en compte
                    // les entités créées dans onFlush.
                    $classMetadata = $em->getClassMetadata($ghost::class);
                    $uow->computeChangeSet($classMetadata, $ghost);
                }
            }
        }
    }

    /**
     * Suppression d'une racine : si le mode est "incarnate",
     * matérialiser les fantômes avant que la racine soit supprimée.
     */
    public function preRemove(PreRemoveEventArgs $args): void
    {
        if ('incarnate' !== $this->onRootDelete) {
            return;
        }

        $entity = $args->getObject();
        if (!$entity instanceof GhostableInterface) {
            return;
        }

        // Seules les racines sont concernées : la suppression d'un fantôme
        // ne modifie rien à sa hiérarchie.
        if ($entity->isGhost()) {
            return;
        }

        $em = $args->getObjectManager();
        $children = $this->getChildrenOf($entity, $em);
        foreach ($children as $child) {
            $this->incarnator->incarnate($child);
        }
    }

    /**
     * Récupère les enfants directs d'une entité racine.
     *
     * Repose sur la convention : les fantômes d'une classe sont stockés
     * dans la même table, identifiables par leur propriété "parent"
     * pointant vers l'entité fournie.
     *
     * @return list<GhostableInterface>
     */
    private function getChildrenOf(GhostableInterface $parent, $em): array
    {
        $repository = $em->getRepository($parent::class);

        // L'API findBy() est suffisamment générique pour fonctionner avec
        // n'importe quel mapping qui respecte la convention "parent".
        try {
            $children = $repository->findBy(['parent' => $parent]);
        } catch (\Throwable) {
            // Si la propriété "parent" n'est pas mappée Doctrine sur la classe,
            // on ne peut pas propager. C'est un usage non-Doctrine ou un
            // mapping non standard : pas une erreur, juste un no-op.
            return [];
        }

        return array_values(array_filter(
            $children,
            static fn ($child) => $child instanceof GhostableInterface
        ));
    }

    /**
     * Crée un fantôme vierge rattaché à une entité parente donnée.
     *
     * Convention : la classe est instanciable sans arguments. Les setters
     * ne sont pas appelés, l'entité est laissée dans son état par défaut
     * (toutes valeurs locales à null = transparence totale).
     */
    private function createGhostOf(GhostableInterface $parent): GhostableInterface
    {
        $class = $parent::class;
        $ghost = new $class();
        $ghost->setParent($parent);

        return $ghost;
    }
}
