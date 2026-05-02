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
     * Définit le parent de cette entité. Doit lever une exception si :
     *  - on essaie de se référencer soi-même comme parent ;
     *  - le parent dépasserait la profondeur maximale autorisée.
     */
    public function setParent(?self $parent): static;

    /**
     * Indique si cette entité est un fantôme (a un parent),
     * indépendamment de son état de matérialisation.
     */
    public function isGhost(): bool;
}
