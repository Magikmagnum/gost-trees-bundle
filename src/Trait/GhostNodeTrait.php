<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Trait;

use EricGansa\GhostTreesBundle\Contract\GhostableInterface;

/**
 * Trait à utiliser dans les entités fantomisables.
 *
 * Fournit :
 *  - la propriété $parent et ses accesseurs (getParent / setParent) ;
 *  - la méthode resolve() utilisée par les getters métier ;
 *  - l'implémentation de isGhost().
 *
 * IMPORTANT — résolution : la logique est intentionnellement DUPLIQUÉE
 * avec celle de GhostResolver::resolve(). Pourquoi ? Parce que les entités
 * Doctrine ne peuvent pas dépendre du container (pas d'injection de service
 * dans une entité). Le trait porte la logique en dur ; le service la porte
 * pour les contextes hors-entité (debug, sérialisation, batch, etc.).
 *
 * Les deux implémentations DOIVENT rester synchronisées. Un test d'invariant
 * vérifie cette équivalence.
 *
 * IMPORTANT — Mapping Doctrine : la propriété $parent est non mappée ici.
 * Pour une entité Doctrine, redéclarez la propriété avec ses attributs ORM
 * (voir README pour un exemple complet).
 */
trait GhostNodeTrait
{
    /**
     * Référence vers le parent dans la chaîne fantôme.
     * À redéclarer dans l'entité concrète pour ajouter le mapping Doctrine.
     */
    protected ?GhostableInterface $parent = null;

    public function getParent(): ?GhostableInterface
    {
        return $this->parent;
    }

    public function setParent(?GhostableInterface $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    public function isGhost(): bool
    {
        return null !== $this->parent;
    }

    /**
     * Résolution locale d'un attribut.
     *
     * Règle :
     *  - valeur locale non nulle           → valeur locale (matérialisée) ;
     *  - valeur locale nulle + parent      → délégation au getter du parent ;
     *  - valeur locale nulle + pas parent  → null.
     */
    protected function resolve(mixed $local, string $getter): mixed
    {
        if (null !== $local) {
            return $local;
        }

        if (null !== $this->parent && method_exists($this->parent, $getter)) {
            return $this->parent->$getter();
        }

        return null;
    }
}
