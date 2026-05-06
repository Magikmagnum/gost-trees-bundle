<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Trait;

use EricGansa\GhostTreesBundle\Attribute\GhostField;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Exception\GhostCycleException;

/**
 * Trait à utiliser dans les entités fantomisables.
 *
 * Fournit :
 *  - la propriété $parent et ses accesseurs (getParent / setParent) ;
 *  - isGhost() ;
 *  - resolve() pour les getters métier ;
 *  - incarnate() : matérialisation autonome (sans service) + détachement ;
 *  - reset() : effacement des surcharges locales (retour transparent) ;
 *  - createGhostOf() : fabrique statique de fantômes.
 *
 * ─── DUPLICATION DOCUMENTÉE ──────────────────────────────────────────────────
 * La logique de resolve() est intentionnellement dupliquée avec
 * GhostResolver::resolve(). Les entités Doctrine ne peuvent pas dépendre du
 * container (pas d'injection de service). Les deux DOIVENT rester synchronisées.
 * Un test d'invariant (testTraitAndResolverAgree) vérifie cette équivalence.
 *
 * ─── CONTRAT setParent() ─────────────────────────────────────────────────────
 * Le trait garantit uniquement la détection de l'auto-référence directe.
 * La validation de profondeur et des cycles indirects requiert une configuration
 * externe et est déléguée à GhostResolverInterface::assertValidParent(),
 * qui DOIT être appelé avant toute persistence.
 *
 * ─── MAPPING DOCTRINE ────────────────────────────────────────────────────────
 * La propriété $parent est non mappée ici. Pour une entité Doctrine, redéclarez
 * la propriété avec ses attributs ORM (voir README).
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
        // Détection de l'auto-référence directe : seule validation possible
        // sans service externe. Les cycles indirects (A→B→A) et la profondeur
        // doivent être validés via GhostResolverInterface::assertValidParent().
        if (null !== $parent && $parent === $this) {
            throw new GhostCycleException('Une entité fantôme ne peut pas être son propre parent.');
        }

        $this->parent = $parent;

        return $this;
    }

    public function isGhost(): bool
    {
        return null !== $this->parent;
    }

    /**
     * Résolution locale d'un champ fantôme.
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

    /**
     * Incarne l'entité en racine autonome, sans service externe.
     *
     * Algorithme :
     *  1. Pour chaque champ #[GhostField] dont la valeur locale est null,
     *     traverser la chaîne parent en lisant les valeurs LOCALES directement
     *     via réflexion (sans appeler les getters) jusqu'à trouver une valeur.
     *  2. Écrire la valeur résolue dans la propriété locale.
     *  3. Couper le lien parent ($this->parent = null).
     *
     * Protection cycles : SplObjectStorage détecte les cycles en données
     * corrompues et lève GhostCycleException plutôt que de boucler indéfiniment.
     *
     * Pourquoi ne pas utiliser les getters ? Un getter appelle resolve() qui
     * rappelle le getter du parent, etc. Sur une chaîne corrompue A→B→A,
     * cela provoque un stack overflow. La lecture directe via réflexion évite
     * toute récursion.
     *
     * @throws GhostCycleException si un cycle est détecté dans la chaîne
     */
    public function incarnate(): static
    {
        if (!$this->isGhost()) {
            return $this;
        }

        foreach ($this->ghostFieldProperties() as $property) {
            if (null !== $property->getValue($this)) {
                continue;
            }

            $resolved = $this->resolvePropertyFromChain($property);

            if (null !== $resolved) {
                $property->setValue($this, $resolved);
            }
        }

        $this->parent = null;

        return $this;
    }

    /**
     * Remonte la chaîne parent en lisant les valeurs locales via réflexion,
     * sans passer par les getters (évite les boucles infinies).
     *
     * @throws GhostCycleException si un cycle est détecté
     */
    private function resolvePropertyFromChain(\ReflectionProperty $property): mixed
    {
        $visited = new \SplObjectStorage();
        $current = $this->parent;

        while (null !== $current) {
            if ($visited->contains($current)) {
                throw new GhostCycleException(\sprintf('Cycle détecté dans la chaîne fantôme lors de l\'incarnation (propriété "%s").', $property->getName()));
            }
            $visited->attach($current);

            $value = $this->readLocalProperty($current, $property->getName());

            if (null !== $value) {
                return $value;
            }

            $current = $current instanceof GhostableInterface ? $current->getParent() : null;
        }

        return null;
    }

    /**
     * Lit la valeur d'une propriété directement via réflexion, en remontant
     * la hiérarchie de classes si la propriété est héritée.
     */
    private function readLocalProperty(object $object, string $propertyName): mixed
    {
        $rc = new \ReflectionClass($object);
        do {
            if ($rc->hasProperty($propertyName)) {
                return $rc->getProperty($propertyName)->getValue($object);
            }
            $rc = $rc->getParentClass();
        } while ($rc instanceof \ReflectionClass);

        return null;
    }

    /**
     * Réinitialise tous les champs #[GhostField] locaux à null.
     *
     * Le lien parent est conservé : si l'entité avait un parent, elle redevient
     * un fantôme totalement transparent après reset(). Si elle n'avait pas de
     * parent (racine), elle reste une racine mais sans aucune valeur locale.
     *
     * Cas d'usage typique : annuler des surcharges locales pour reprendre
     * intégralement l'héritage du parent.
     */
    public function reset(): static
    {
        foreach ($this->ghostFieldProperties() as $property) {
            $property->setValue($this, null);
        }

        return $this;
    }

    /**
     * Fabrique statique : crée un fantôme vierge rattaché à l'entité donnée.
     *
     * Convention : la classe est instanciable sans arguments (new static()).
     * Pour les entités dont le constructeur requiert des arguments, surcharger
     * cette méthode dans la classe concrète.
     */
    public static function createGhostOf(GhostableInterface $original): static
    {
        $ghost = new static();
        $ghost->setParent($original);

        return $ghost;
    }

    /**
     * Retourne les ReflectionProperty marquées #[GhostField] (ou ses sous-classes).
     *
     * Résultat mis en cache par classe (cache statique partagé entre toutes
     * les instances). La réflexion n'est exécutée qu'une seule fois par classe
     * sur le cycle de vie du processus.
     *
     * Remonte toute la hiérarchie de classes pour inclure les propriétés héritées.
     * Chaque propriété n'est visitée qu'une fois (évite les doublons en cas
     * de redéclaration dans une sous-classe).
     *
     * @return list<\ReflectionProperty>
     */
    private function ghostFieldProperties(): array
    {
        /** @var array<class-string, list<\ReflectionProperty>> $cache */
        static $cache = [];

        $class = static::class;

        if (isset($cache[$class])) {
            return $cache[$class];
        }

        $properties = [];
        $seen = [];
        $current = new \ReflectionClass($this);

        do {
            foreach ($current->getProperties() as $property) {
                if (isset($seen[$property->getName()])) {
                    continue;
                }
                $seen[$property->getName()] = true;

                if (!empty($property->getAttributes(GhostField::class, \ReflectionAttribute::IS_INSTANCEOF))) {
                    $properties[] = $property;
                }
            }
            $current = $current->getParentClass();
        } while ($current instanceof \ReflectionClass);

        return $cache[$class] = $properties;
    }
}
