# Ghost Trees Bundle

[![Tests](https://github.com/ericgansa/ghost-trees-bundle/actions/workflows/tests.yml/badge.svg)](https://github.com/ericgansa/ghost-trees-bundle/actions/workflows/tests.yml)
[![Security](https://github.com/ericgansa/ghost-trees-bundle/actions/workflows/security.yml/badge.svg)](https://github.com/ericgansa/ghost-trees-bundle/actions/workflows/security.yml)
[![Latest Version](https://img.shields.io/packagist/v/ericgansa/ghost-trees-bundle.svg)](https://packagist.org/packages/ericgansa/ghost-trees-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/ericgansa/ghost-trees-bundle.svg)](https://packagist.org/packages/ericgansa/ghost-trees-bundle)
[![License](https://img.shields.io/packagist/l/ericgansa/ghost-trees-bundle.svg)](LICENSE)

> **Héritage dynamique pour entités Doctrine.**
> Les *arbres fantômes* permettent à une entité enfant d'hériter dynamiquement des attributs d'une entité parente, attribut par attribut, sans duplication de données et avec propagation automatique.

---

## Le pitch en 30 secondes

Vous avez une entité dont l'état doit être partagé avec d'autres entités, mais chacune doit pouvoir personnaliser localement certains attributs *sans* perdre le lien avec l'original ?

Au lieu de cloner ou de référencer en dur, vous créez un **fantôme** : une entité qui pointe vers un parent et n'écrit localement que ce qui *diverge*. Tant qu'un attribut reste à `null` localement, il est *résolu dynamiquement* depuis le parent.

```
Racine T1 :  lieuDepart = "Paris"     lieuArrivee = "Lyon"     moyenTransport = "TGV"
                ↑                             ↑
                │                             │
Fantôme T1n :  lieuDepart = null     lieuArrivee = "Marseille"  moyenTransport = null
                │
                └─→ Lecture résolue : "Paris"     "Marseille"   "TGV"
```

Effacer la valeur locale `"Marseille"` rétablit automatiquement la lecture transparente vers `"Lyon"`. Aucune copie. Aucun cache. Aucun événement.

## Installation

```bash
composer require ericgansa/ghost-trees-bundle
```

Ajoutez le bundle à `config/bundles.php` (Flex le fait pour vous) :

```php
return [
    // ...
    EricGansa\GhostTreesBundle\GhostTreesBundle::class => ['all' => true],
];
```

## Configuration

```yaml
# config/packages/ghost_trees.yaml
ghost_trees:
    max_depth: 1                    # Profondeur maximale (1 = racine + fantômes)
    on_root_delete: cascade         # cascade | incarnate
    auto_propagate_collections: true
```

## Rendre une entité fantomisable

Deux étapes :

1. Implémenter `GhostableInterface` et utiliser `GhostNodeTrait` — le trait fournit `$parent`, `getParent()`, `setParent()`, `isGhost()` et `resolve()`.
2. Marquer les attributs fantomisables avec `#[GhostableField]`, et faire passer leurs getters par `resolve()`.

Pour une entité Doctrine, il suffit en plus de **redéclarer la propriété `$parent`** avec son mapping (le trait ne peut pas le faire à votre place — Doctrine a besoin de la classe concrète comme `targetEntity`).

```php
use EricGansa\GhostTreesBundle\Attribute\Ghostable;
use EricGansa\GhostTreesBundle\Attribute\GhostableField;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Trait\GhostNodeTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Trajet implements GhostableInterface
{
    use GhostNodeTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Ghostable]                         // métadonnée d'introspection
    #[GhostableField(required: true)]    // contrainte de validation conditionnelle
    private ?string $lieuDepart = null;

    // Redéclaration nécessaire UNIQUEMENT pour le mapping Doctrine.
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?GhostableInterface $parent = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

    public function getLieuDepart(): ?string
    {
        return $this->resolve($this->lieuDepart, 'getLieuDepart');
    }

    public function setLieuDepart(?string $lieuDepart): static
    {
        $this->lieuDepart = $lieuDepart;
        return $this;
    }
}
```

> **Pour un usage sans Doctrine** (DTO, fixtures de test, entités en mémoire), aucune redéclaration n'est nécessaire : le trait fonctionne tel quel.

## Vocabulaire

| Terme | Sens |
|---|---|
| **Racine** | Entité sans parent. Source des valeurs originales. |
| **Fantôme** | Entité avec un parent. Hérite dynamiquement des valeurs. |
| **Matérialisation** | Action de donner une valeur locale à un attribut fantôme. |
| **Dématérialisation** | Action d'effacer (`null`) une valeur locale. La résolution dynamique reprend. |
| **Incarnation** | Promotion d'un fantôme en racine autonome. Toutes les valeurs sont matérialisées et le lien parent coupé. |
| **Traversée** | Lecture d'un attribut qui remonte la chaîne fantôme jusqu'à trouver une valeur. |

## Outillage

```bash
# Inspecter l'état de résolution d'une entité fantôme
php bin/console debug:ghosts "App\Entity\Trajet" 42

# Incarner un fantôme en racine autonome
php bin/console ghosts:incarnate "App\Entity\Trajet" 42
```

## Invariants garantis

1. **Profondeur** : aucune chaîne fantôme ne dépasse `max_depth`.
2. **Pas de cycle** : une entité ne peut pas être son propre ancêtre.
3. **Transparence de lecture** : un fantôme non matérialisé renvoie les valeurs du parent.
4. **Isolation d'écriture** : modifier un fantôme n'affecte jamais le parent.
5. **Réversibilité** : effacer une valeur locale (`null`) restaure la résolution dynamique.

## Limites connues

- **Relations Doctrine** : la résolution dynamique fonctionne sur les attributs scalaires et les relations dont la cible est elle-même fantomisable. Pour des relations cross-entités complexes (clé étrangère vers une entité non fantomisable, par exemple), prévoir une logique d'application dédiée.
- **Performance en lecture massive** : chaque getter d'un fantôme peut déclencher un accès au parent. Sur de grandes collections lues en boucle, prévoir l'eager loading de la relation `parent` ou un cache.
- **Propagation structurelle** : le subscriber Doctrine fourni couvre l'ajout d'éléments aux collections de la racine. Les cas exotiques (sous-collections en cascade, mappings non standards) nécessitent une extension côté projet.
- **Transactions** : les opérations d'incarnation à grande échelle ne sont pas automatiquement atomiques. Encadrer avec `EntityManager::wrapInTransaction()` côté appelant.

## Documentation

- [Concepts](docs/concepts.md) — la théorie des arbres fantômes en détail.
- [Cookbook](docs/cookbook.md) — recettes pratiques.

## Licence

MIT.
