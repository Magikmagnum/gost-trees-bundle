# Ghost Trees Bundle

[![Tests](https://github.com/ericgansa/ghost-trees-bundle/actions/workflows/tests.yml/badge.svg)](https://github.com/ericgansa/ghost-trees-bundle/actions/workflows/tests.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](phpstan.neon)
[![Latest Version](https://img.shields.io/packagist/v/ericgansa/ghost-trees-bundle.svg)](https://packagist.org/packages/ericgansa/ghost-trees-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/ericgansa/ghost-trees-bundle.svg)](https://packagist.org/packages/ericgansa/ghost-trees-bundle)
[![License](https://img.shields.io/packagist/l/ericgansa/ghost-trees-bundle.svg)](LICENSE)




### Imaginez un Chef étoilé qui publie une recette

Trois cuisiniers s'inscrivent pour la cuisiner chez eux. Marie ne change rien — elle suit le Chef à la lettre. Thomas adapte juste le temps de cuisson à son four. Sophie réinvente la moitié de la recette. Le Chef corrige une faute de frappe : Marie en bénéficie automatiquement, Thomas aussi sur ce qu'il n'a pas modifié, Sophie reçoit ce qu'elle n'a pas touché. Personne n'a cliqué sur "synchroniser". Personne n'a invalidé un cache. **Les valeurs apparaissent simplement là où elles sont attendues.**

### Imaginez un manager qui prépare un déplacement pour son équipe

Cinq agents y sont rattachés. Chacun reçoit une copie *fantôme* du programme. Le manager modifie l'horaire du train du retour : tous les agents le voient. Un agent change d'hôtel pour des raisons personnelles : seule sa copie diverge, les autres restent alignées sur le programme initial. Plus tard, le manager annule la mission. Au lieu de tout perdre, **chaque agent voit sa copie incarnée en demande indépendante**, qu'il peut garder ou clôturer.

### Imaginez une plateforme SaaS multi-tenants

Une configuration globale définit les valeurs par défaut : couleurs de marque, quotas, feature flags. Chaque tenant hérite, et peut surcharger. Chaque utilisateur dans le tenant peut surcharger encore au-dessus. Le SaaS active une nouvelle fonctionnalité globalement : elle apparaît chez tous les tenants qui n'ont pas surchargé ce flag, et dans toutes les sessions utilisateurs concernées. **Aucun batch d'invalidation. Aucun job de propagation.** Juste de la résolution paresseuse à la lecture.

### Le pattern derrière ces trois scènes

Dans chaque cas, il y a un **original** et des **copies vivantes** qui héritent par défaut. La différence d'avec un *clone* : la copie n'est jamais figée — elle suit l'original tant qu'elle ne le contredit pas. La différence d'avec une *référence* : la copie peut diverger localement, sur le champ exact qu'elle veut, sans casser le lien.

C'est le pattern des **arbres fantômes**, et c'est ce que ce bundle apporte à Doctrine.

Le système repose sur un modèle d’arbre fantôme (Ghost Tree Pattern), dans lequel les entités métier sont structurées en hiérarchie parent/enfant. Chaque entité enfant hérite dynamiquement des attributs de son parent tout en pouvant surcharger individuellement certains champs. Cette approche permet de représenter des variations contextuelles d’un même objet métier sans duplication de données, en garantissant une cohérence structurelle et une flexibilité d’adaptation.

---

## Pitch en 30 secondes

```
Racine T1 :  lieuDepart="Paris"   lieuArrivee="Lyon"   moyenTransport="TGV"
                  ↑                       ↑
Fantôme T1a: lieuDepart=null    lieuArrivee="Marseille"  moyenTransport=null
              └→ "Paris"           "Marseille"              "TGV"
```

Effacer `"Marseille"` → la lecture redevient `"Lyon"`. Aucune copie. Aucun cache.

---

## Fonctionnalités

### Résolution dynamique attribut par attribut

Chaque getter d'un fantôme retourne sa valeur locale si elle existe, sinon la valeur du parent par traversée. Granularité au champ près : un fantôme peut hériter du titre et personnaliser la durée, sans logique applicative.

### Matérialisation et dématérialisation réversibles

Donner une valeur locale matérialise (l'attribut diverge). Remettre `null` dématérialise (l'attribut revient à hériter). Pas de bouton "synchroniser" à coder, pas d'historique à gérer — la sémantique est portée par la valeur elle-même.

### Incarnation (promotion en racine autonome)

Un fantôme suffisamment divergent peut être *détaché* de son parent : toutes ses valeurs résolues sont matérialisées localement, le lien parent est coupé. Utile pour les workflows où une copie devient une entité de premier rang (publication, archivage, autonomie).

### Propagation structurelle des collections

Quand un élément est ajouté à une collection de la racine, le bundle crée automatiquement les fantômes correspondants dans chaque enfant. Côté Doctrine, c'est un `EventSubscriber` câblé sur `onFlush`. Côté code applicatif, **rien à écrire**.

### Possession exclusive

Un élément créé directement par un fantôme (sans parent) lui appartient en propre. La racine ne peut ni le voir en écriture, ni le supprimer. Sépare proprement *ce que j'hérite* de *ce que j'ajoute*.

### Validation conditionnelle

L'attribut `#[RequiredOnRoot]` rend un champ obligatoire **uniquement** sur les racines. Sur les fantômes, la contrainte se tait — le champ peut rester `null` puisqu'il sera résolu depuis le parent. Plus de `Assert\When` qui pollue chaque entité.

### Stratégie de suppression configurable

Lorsqu'une racine est supprimée, deux comportements au choix :
- **`cascade`** : tous les fantômes sont supprimés en même temps.
- **`incarnate`** : tous les fantômes sont matérialisés en racines autonomes avant la suppression du parent.

Câblé via le subscriber, contrôlé en YAML.

### Outillage d'introspection

Trois services, exposés en interfaces, accessibles partout par autowiring :
- `GhostResolverInterface` — la résolution dynamique brute, validation profondeur/cycle.
- `GhostInspectorInterface` — sait pour chaque attribut s'il est local, hérité, ou non défini.
- `GhostIncarnatorInterface` — incarne un fantôme et émet l'événement correspondant.

Et deux commandes CLI prêtes à l'emploi : `debug:ghosts` et `ghosts:incarnate`.

### Cache de réflexion

`GhostMetadata` met en cache les `ReflectionProperty` des entités fantomisables après le premier accès. Les opérations massives (debug, incarnation par lot) ne re-introspectent pas à chaque appel.

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

| Option | Type | Défaut | Effet |
|---|---|---|---|
| `max_depth` | int ≥ 1 | `1` | Profondeur maximale de la chaîne fantôme. |
| `on_root_delete` | `cascade` \| `incarnate` | `cascade` | Comportement à la suppression d'une racine. |
| `auto_propagate_collections` | bool | `true` | Active la propagation structurelle automatique côté collections. |

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

> **Sans Doctrine** (DTO, fixtures, tests) : aucune redéclaration de `$parent` nécessaire. Le trait porte tout.

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

## Services injectables

Le bundle expose trois services par autowiring. Ils permettent les opérations qui ne peuvent pas vivre dans une entité (validation transversale, dispatch d'événements, accès à la base).

### GhostResolverInterface

Résolution brute et validation structurelle (profondeur, cycle).

```php
use EricGansa\GhostTreesBundle\Contract\GhostResolverInterface;

public function __construct(private GhostResolverInterface $resolver) {}

public function attachToParent(Trajet $child, Trajet $parent): void
{
    // Lève GhostDepthExceededException ou GhostCycleException si invalide.
    $this->resolver->assertValidParent($child, $parent);
    $child->setParent($parent);
}
```

### GhostInspectorInterface

Introspection : d'où vient chaque valeur, l'entité a-t-elle divergé.

```php
use EricGansa\GhostTreesBundle\Contract\GhostInspectorInterface;

public function showResolution(Trajet $ghost, GhostInspectorInterface $inspector): array
{
    return $inspector->debugResolution($ghost);
    // [
    //   'lieuDepart'  => ['value' => 'Paris',     'source' => 'inherited', 'depth' => 1],
    //   'lieuArrivee' => ['value' => 'Marseille', 'source' => 'local',     'depth' => 0],
    // ]
}
```

Idéal pour des badges UI "modifié / hérité / non défini" et le débogage en CLI.

### GhostIncarnatorInterface

Incarnation transactionnelle, avec dispatch d'événement.

```php
use EricGansa\GhostTreesBundle\Contract\GhostIncarnatorInterface;

public function incarnate(Trajet $ghost, GhostIncarnatorInterface $incarnator, EntityManagerInterface $em): void
{
    $em->wrapInTransaction(function () use ($incarnator, $ghost) {
        $incarnator->incarnate($ghost);  // émet GhostIncarnatedEvent
    });
}
```

> Les versions `$ghost->incarnate()` (méthode du trait) et `$incarnator->incarnate($ghost)` (service) coexistent : la première est autonome et silencieuse, la seconde émet l'événement applicatif. À choisir selon que ton domaine doit réagir à l'incarnation ou non.

---

## Événements

Le bundle dispatche des événements aux moments clés du cycle de vie. Tout listener Symfony classique peut s'y abonner.

| Événement | Quand il est émis | Charge utile |
|---|---|---|
| `GhostAffiliatedEvent` | Une entité vient d'être rattachée à un parent | `entity`, `parent` |
| `GhostIncarnatedEvent` | Une entité vient d'être incarnée (par le service) | `entity`, `previousParent` |

### Exemple : audit des incarnations

```php
use EricGansa\GhostTreesBundle\Event\GhostIncarnatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: GhostIncarnatedEvent::class)]
final class IncarnationAuditor
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(GhostIncarnatedEvent $event): void
    {
        $this->logger->info('Ghost incarnated', [
            'entity' => $event->entity::class . '#' . $event->entity->getId(),
            'previousParent' => $event->previousParent?->getId(),
        ]);
    }
}
```

### Exemple : notification à l'auteur de la racine

```php
#[AsEventListener(event: GhostIncarnatedEvent::class)]
final class NotifyAuthorOnIncarnation
{
    public function __invoke(GhostIncarnatedEvent $event): void
    {
        $previous = $event->previousParent;
        if ($previous instanceof Recipe && $previous->getAuthor()) {
            $this->mailer->send(new RecipeIncarnatedMail($previous->getAuthor(), $event->entity));
        }
    }
}
```

---

## Doctrine subscriber

Le bundle enregistre automatiquement `GhostPropagationSubscriber`, qui implémente deux comportements transversaux à toutes les entités fantomisables.

### Comportement 1 — Propagation structurelle (`onFlush`)

Lors de l'ajout d'un élément à une collection portée par une **racine**, le subscriber crée automatiquement les fantômes correspondants chez les enfants.

```
Racine "Tarte Tatin" → ajoute ingrédient "Cannelle"
                       │
                       ├── propage ──→ Fantôme Marie  : nouveau fantôme d'ingrédient (vide)
                       ├── propage ──→ Fantôme Thomas : nouveau fantôme d'ingrédient (vide)
                       └── propage ──→ Fantôme Sophie : nouveau fantôme d'ingrédient (vide)
```

Désactivable via la config `auto_propagate_collections: false` si tu veux gérer la propagation toi-même.

### Comportement 2 — Stratégie de suppression (`preRemove`)

Lorsqu'une racine est sur le point d'être supprimée, le subscriber consulte la config `on_root_delete` :

- **`cascade`** : aucune action particulière (Doctrine cascade via le mapping).
- **`incarnate`** : itère sur les enfants directs et appelle `GhostIncarnator::incarnate()` sur chacun avant que la racine ne disparaisse.

### Limites

- La propagation **ne descend pas dans les sous-collections** : ajouter un ingrédient dans la collection d'un trajet ne crée pas automatiquement les fantômes de ce nouvel ingrédient chez les fantômes du trajet. Si ce besoin existe, écrire un listener applicatif sur `GhostAffiliatedEvent`.
- Le subscriber **ne propage que depuis les racines**. Les ajouts côté fantôme restent locaux (possession exclusive).

---

## 🔐 Sécurité

### Protection contre les cycles

| Niveau | Mécanisme | Garantie |
|---|---|---|
| Direct (A→A) | `setParent()` dans le trait | Exception immédiate |
| Indirect (A→B→A) | `GhostResolver::assertValidParent()` | Exception avant persistence |
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
| **Possession exclusive** | Un élément créé directement par un fantôme lui appartient et n'est pas visible en écriture côté racine. |
| **Propagation structurelle** | Création automatique de fantômes dans les enfants quand un élément est ajouté à une collection de la racine. |

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
