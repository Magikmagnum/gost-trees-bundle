<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\EventSubscriber;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Contract\GhostIncarnatorInterface;
use EricGansa\GhostTreesBundle\Event\GhostAffiliatedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly bool $autoPropagateCollections,
        private readonly string $onRootDelete,
    ) {
    }

    /** @return list<string> */
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
                    $ghost = $this->spawnGhostFor($insertedItem);
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

        // COMPATIBILITÉ Doctrine ORM 2.x / 3.x :
        // Doctrine 3.0 : PreRemoveEventArgs::getObject()
        // Doctrine 2.x : PreRemoveEventArgs::getEntity()
        /** @var object $entity */
        $entity = method_exists($args, 'getObject') ? $args->getObject() : $args->getEntity(); // @phpstan-ignore-line

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
    private function getChildrenOf(GhostableInterface $parent, EntityManagerInterface $em): array
    {
        $repository = $em->getRepository($parent::class);

        // L'API findBy() est suffisamment générique pour fonctionner avec
        // n'importe quel mapping qui respecte la convention "parent".
        try {
            $children = $repository->findBy(['parent' => $parent]);
        } catch (\Exception $e) {
            // Seul cas attendu : la propriété "parent" n'est pas mappée Doctrine
            // (entité hors-convention ou usage non-Doctrine). Dans ce cas, on
            // ne peut pas propager — c'est un no-op silencieux.
            //
            // Toute autre exception (connexion BDD perdue, timeout, erreur I/O)
            // doit remonter pour ne pas masquer une panne réelle.
            if (!$this->isMappingException($e)) {
                throw $e;
            }

            return [];
        }

        // findBy() sur un repository GhostableInterface retourne GhostableInterface[].
        /* @var list<GhostableInterface> $children */
        return $children;
    }

    /**
     * Détermine si l'exception est due à un mapping Doctrine absent ou invalide.
     *
     * Doctrine 2.x lève \Doctrine\ORM\Mapping\MappingException.
     * Doctrine 3.x lève des exceptions sous \Doctrine\ORM\Exception\ (base : ORMException).
     * La détection via class_exists + instanceof permet une compatibilité cross-version
     * sans provoquer d'erreur "class not found" dans un bloc catch.
     */
    private function isMappingException(\Exception $e): bool
    {
        // Doctrine 2.x
        if ($e instanceof \Doctrine\ORM\Mapping\MappingException) {
            return true;
        }

        // Doctrine 3.x
        if (class_exists(\Doctrine\ORM\Exception\ORMException::class)
            && $e instanceof \Doctrine\ORM\Exception\ORMException) {
            return true;
        }

        return false;
    }

    /**
     * Crée un fantôme vierge rattaché à une entité parente donnée,
     * puis dispatche GhostAffiliatedEvent pour permettre aux listeners
     * d'orchestrer des traitements complémentaires (cache, notification, etc.).
     *
     * Délègue à la fabrique statique GhostableInterface::createGhostOf(),
     * implémentée par défaut dans GhostNodeTrait (new static() + setParent).
     * Les entités dont le constructeur requiert des arguments DOIVENT surcharger
     * createGhostOf() pour fournir leur propre logique d'instanciation.
     */
    private function spawnGhostFor(GhostableInterface $parent): GhostableInterface
    {
        $ghost = $parent::createGhostOf($parent);
        $this->eventDispatcher->dispatch(new GhostAffiliatedEvent($ghost, $parent));

        return $ghost;
    }
}
