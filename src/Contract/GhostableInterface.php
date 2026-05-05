<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Contract;

/**
 * Contrat des entités supportant le pattern d'arbres fantômes.
 *
 * Une entité fantomisable peut être soit une racine (pas de parent),
 * soit un fantôme (avec un parent). Quand elle est fantôme, ses getters
 * peuvent emprunter dynamiquement les valeurs de son parent tant qu'elle
 * n'a pas matérialisé ses propres valeurs.
 */
interface GhostableInterface
{
    /**
     * Retourne le parent direct de cette entité, ou null si elle est racine.
     */
    public function getParent(): ?self;

    /**
     * Définit le parent de cette entité.
     *
     * Garantit : lève GhostCycleException si l'entité se désigne elle-même
     * comme parent direct ($entity->setParent($entity)).
     *
     * Délègue : la validation de profondeur et des cycles indirects est
     * confiée à GhostResolverInterface::assertValidParent(), qui requiert la
     * configuration max_depth et doit être appelé avant la persistence.
     *
     * @throws \EricGansa\GhostTreesBundle\Exception\GhostCycleException
     */
    public function setParent(?self $parent): static;

    /**
     * Indique si cette entité est un fantôme (a un parent),
     * indépendamment de son état de matérialisation.
     */
    public function isGhost(): bool;
}
