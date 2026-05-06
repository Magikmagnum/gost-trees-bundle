# Ghost Trees Bundle

[![Tests](https://github.com/ericgansa/ghost-trees-bundle/actions/workflows/tests.yml/badge.svg)](https://github.com/ericgansa/ghost-trees-bundle/actions/workflows/tests.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](phpstan.neon)
[![Latest Version](https://img.shields.io/packagist/v/ericgansa/ghost-trees-bundle.svg)](https://packagist.org/packages/ericgansa/ghost-trees-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/ericgansa/ghost-trees-bundle.svg)](https://packagist.org/packages/ericgansa/ghost-trees-bundle)
[![License](https://img.shields.io/packagist/l/ericgansa/ghost-trees-bundle.svg)](LICENSE)

> **Héritage dynamique pour entités Doctrine.**
> Une entité *fantôme* hérite des valeurs de son parent attribut par attribut, sans copie, sans cache, sans événement — jusqu'à ce qu'elle matérialise ses propres valeurs.

---

## Pitch en 30 secondes

```
Racine T1 :  lieuDepart="Paris"   lieuArrivee="Lyon"   moyenTransport="TGV"
                  ↑                       ↑
Fantôme T1a: lieuDepart=null    lieuArrivee="Marseille"  moyenTransport=null
              └→ "Paris"           "Marseille"              "TGV"
```

Effacer `"Marseille"` → lecture redevient `"Lyon"`. Aucune copie. Aucun cache.

---

## Installation

```bash
composer require ericgansa/ghost-trees-bundle
```

Activez le bundle dans `config/bundles.php` (Flex le fait pour vous) :

```php
EricGansa\GhostTreesBundle\GhostTreesBundle::class => ['all' => true],
```

---

## Configuration

```yaml
# config/packages/ghost_trees.yaml
ghost_trees:
    max_depth: 1                    # Profondeur max (1 = racine + 1 niveau de fantômes)
    on_root_delete: cascade         # cascade | incarnate
    auto_propagate_collections: true
```

---

## Rendre une entité fantomisable

```php
use EricGansa\GhostTreesBundle\Attribute\GhostField;
use EricGansa\GhostTreesBundle\Attribute\RequiredOnRoot;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Trait\GhostNodeTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Trajet implements GhostableInterface
{
    use GhostNodeTrait;   // fournit getParent(), setParent(), isGhost(),
                           // resolve(), incarnate(), reset(), createGhostOf()

    #[ORM\Column(nullable: true)]
    #[GhostField]                // marque la propriété pour la résolution dynamique
    #[RequiredOnRoot]            // obligatoire sur les racines, silencieux sur les fantômes
    private ?string $lieuDepart = null;

    // Redéclaration UNIQUEMENT pour le mapping Doctrine (targetEntity requis).
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?GhostableInterface $parent = null;

    public function getLieuDepart(): ?string
    {
        return $this->resolve($this->lieuDepart, 'getLieuDepart');
        //             ↑ retourne la valeur locale si non-null, sinon remonte au parent
    }
}
```

> **Sans Doctrine** (DTO, fixtures, tests) : aucune redéclaration de `$parent` nécessaire.

---

## API de l'entité (méthodes du trait)

| Méthode | Description |
|---|---|
| `isGhost()` | Vrai si l'entité a un parent |
| `getParent()` / `setParent()` | Accès au lien parent |
| `incarnate()` | Matérialise toutes les valeurs héritées localement, coupe le lien parent |
| `reset()` | Efface toutes les surcharges locales (retour à la transparence) |
| `createGhostOf($original)` | Fabrique un fantôme vierge rattaché à `$original` *(statique)* |
| `resolve($local, $getter)` | Résolution d'un attribut dans les getters *(protégé)* |

### Exemples

```php
// Incarnation sans service (autonome)
$ghost->incarnate();
// → $ghost est maintenant une racine indépendante avec toutes ses valeurs matérialisées

// Retour à la transparence (annule les surcharges)
$ghost->reset();
// → $ghost lit de nouveau toutes ses valeurs depuis le parent

// Fabrique un fantôme
$copy = Trajet::createGhostOf($original);
// → $copy->getParent() === $original, tous les champs à null (lecture transparente)
```

---

## 🔐 Sécurité

### Protection contre les cycles

| Niveau | Mécanisme | Garantie |
|---|---|---|
| Direct (`A→A`) | `setParent()` dans le trait | Exception immédiate |
| Indirect (`A→B→A`) | `GhostResolver::assertValidParent()` | Exception avant persistence |
| Données corrompues (SQL direct) | `SplObjectStorage` dans `GhostIncarnator` | `GhostCycleException` |
| Debug sur données corrompues | `SplObjectStorage` dans `GhostInspector` | Retourne `source='cycle_detected'` |

### Invariants garantis

1. **Profondeur** : aucune chaîne fantôme ne dépasse `max_depth`.
2. **Pas de cycle** : une entité ne peut pas être son propre ancêtre.
3. **Transparence de lecture** : un fantôme non matérialisé lit depuis le parent.
4. **Isolation d'écriture** : modifier un fantôme n'affecte jamais le parent.
5. **Réversibilité** : `reset()` restaure la lecture transparente.

### Gestion Doctrine sécurisée

- Les transactions **ne sont pas gérées** par le bundle : encadrer `incarnate()` dans `EntityManager::wrapInTransaction()` pour les opérations atomiques.
- Le subscriber `GhostPropagationSubscriber` ne persiste les fantômes que depuis des **racines** (jamais depuis des fantômes déjà existants).

---

## 🧪 Qualité

### Analyse statique

```bash
composer stan          # PHPStan niveau 8
```

### Tests

```bash
composer test          # Toutes les suites
composer test:unit     # Tests unitaires seuls
composer test:integration  # Tests d'intégration (mocks Doctrine)
```

### Formatage

```bash
composer cs:check      # Vérifie sans modifier
composer cs:fix        # Corrige le style
```

### Mutation testing (optionnel)

```bash
composer mutation      # Infection — MSI cible ≥ 70 %
```

### QA complète (avant PR)

```bash
composer qa            # cs:check + stan + test
```

### Hooks pre-commit (GrumPHP)

```bash
composer require --dev phpro/grumphp
vendor/bin/grumphp git:init   # Installe le hook
```

Bloque le commit si : test cassé · erreur PHPStan · code non formaté · CVE connue.

---

## Attributs PHP

| Attribut | Rôle |
|---|---|
| `#[GhostField]` | Marque une propriété pour la résolution dynamique (introspection) |
| `#[RequiredOnRoot]` | Validation : champ obligatoire sur les racines, silencieux sur les fantômes |

> `#[Ghostable]` et `#[GhostableField]` sont des **alias dépréciés** conservés pour la compatibilité. Ils seront supprimés en v1.0.

---

## Vocabulaire

| Terme | Sens |
|---|---|
| **Racine** | Entité sans parent. Source des valeurs originales. |
| **Fantôme** | Entité avec un parent. Hérite dynamiquement les valeurs non matérialisées. |
| **Matérialisation** | Écriture d'une valeur locale sur un champ fantôme. |
| **Dématérialisation** | Effacement d'une valeur locale (→ `reset()`). La résolution dynamique reprend. |
| **Incarnation** | Promotion d'un fantôme en racine : matérialisation + coupure du lien parent. |

---

## Outillage CLI

```bash
php bin/console debug:ghosts "App\Entity\Trajet" 42
php bin/console ghosts:incarnate "App\Entity\Trajet" 42
```

---

## Limites connues (v0.x)

- **Concurrence** : pas de verrou applicatif. Pour les incarnations concurrentes en base, utiliser un verrou Doctrine (`PESSIMISTIC_WRITE` ou `@Version`).
- **Relations cross-entités** : la résolution dynamique fonctionne sur les scalaires et les relations dont la cible est elle-même `GhostableInterface`.
- **Propagation en cascade** : le subscriber couvre l'ajout sur collections directes de racines ; les sous-collections en cascade nécessitent une extension projet.
- **Constructeur avec arguments** : surcharger `createGhostOf()` si le constructeur requiert des arguments.

---

## Documentation

- [Concepts](docs/concepts.md) — théorie des arbres fantômes.
- [Cookbook](docs/cookbook.md) — recettes pratiques.
- [Sécurité & Qualité](docs/security-quality.md) — invariants, limites, CI/CD.

## Licence

MIT.
