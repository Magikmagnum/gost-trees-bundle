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
 * ──────────────────────────────────────────────────────────────────
 *  IMPORTANT — Mapping Doctrine
 * ──────────────────────────────────────────────────────────────────
 *
 *  La propriété $parent est DÉCLARÉE ICI mais NON MAPPÉE par Doctrine.
 *  Pourquoi ? Parce que le mapping doit référencer la classe concrète
 *  comme targetEntity (Trajet, Demande…), pas l'interface
 *  GhostableInterface — ce que Doctrine ne peut pas gérer depuis un
 *  trait générique.
 *
 *  Dans votre entité, vous DEVEZ donc redéclarer la propriété $parent
 *  avec les attributs Doctrine voulus. Le redéclarer suffit : la
 *  visibilité protected permet la surcharge, et les méthodes du trait
 *  continuent de fonctionner via $this->parent.
 *
 *  Exemple :
 *
 *      use EricGansa\GhostTreesBundle\Trait\GhostNodeTrait;
 *      use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
 *
 *      #[ORM\Entity]
 *      class Trajet implements GhostableInterface
 *      {
 *          use GhostNodeTrait;
 *
 *          #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
 *          #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
 *          protected ?GhostableInterface $parent = null;
 *
 *          // (Optionnel) Rétrécir le type de retour pour le confort d'usage.
 *          public function getParent(): ?self
 *          {
 *              return $this->parent;
 *          }
 *      }
 *
 *  Pour les usages sans Doctrine (entités en mémoire, fixtures de test),
 *  le trait fonctionne directement, sans aucune redéclaration.
 * ──────────────────────────────────────────────────────────────────
 */
trait GhostNodeTrait
{
    /**
     * Référence vers le parent dans la chaîne fantôme.
     * À redéclarer dans l'entité concrète pour ajouter le mapping Doctrine.
     */
    protected ?GhostableInterface $parent = null;

    /**
     * Retourne le parent direct, ou null si l'entité est une racine.
     */
    public function getParent(): ?GhostableInterface
    {
        return $this->parent;
    }

    /**
     * Définit le parent. La validation de profondeur et de cycle
     * est déléguée à GhostResolver::assertValidParent() — à appeler
     * explicitement avant cette méthode si besoin.
     */
    public function setParent(?GhostableInterface $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * Indique si l'entité est un fantôme (a un parent).
     */
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
     *
     * @param mixed  $local  Valeur stockée localement dans l'entité.
     * @param string $getter Nom du getter à invoquer récursivement sur le parent.
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
