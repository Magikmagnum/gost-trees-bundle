# Sécurité et Qualité — Ghost Trees Bundle

> Version du document : 2026-05-05
> Portée : PHP 8.2+, Symfony 6.4/7.x, Doctrine ORM 2.17/3.x

---

## Table des matières

1. [Introduction](#1-introduction)
2. [Modèle et invariants](#2-modèle-et-invariants)
3. [Sécurité](#3-sécurité)
4. [Qualité logicielle](#4-qualité-logicielle)
5. [Pipeline CI/CD](#5-pipeline-cicd)
6. [Bonnes pratiques](#6-bonnes-pratiques)

---

## 1. Introduction

### Objectifs

Ce document détaille les décisions de sécurité et de qualité qui gouvernent le bundle. Il est destiné aux :

- **contributeurs** qui souhaitent étendre ou modifier le bundle ;
- **intégrateurs** qui l'adoptent dans une application Symfony ;
- **auditeurs** qui évaluent la fiabilité du code avant mise en production.

### Périmètre de sécurité

Ghost Trees Bundle est une bibliothèque **sans surface web**. Il ne gère aucune requête HTTP, aucun formulaire, aucun upload. Sa surface d'attaque se limite à :

| Surface | Exposition |
| --- | --- |
| CLI (`debug:ghosts`, `ghosts:incarnate`) | Accès serveur requis — usage administrateur uniquement |
| Reflection PHP | Interne, sans entrée utilisateur directe |
| Doctrine ORM | Paramétré — pas d'interpolation SQL |
| EventDispatcher Symfony | Interne — pas de payload externe |

### Principes retenus

1. **Fail-fast** : toute configuration invalide lève une exception à l'initialisation, pas à l'exécution.
2. **Pas d'exécution dynamique** : aucun `eval`, `exec`, `shell_exec` dans le bundle.
3. **Immutabilité des métadonnées** : le cache de reflection est en lecture seule après construction.
4. **Transactions explicites** : la persistence est sous la responsabilité de l'appelant — le bundle ne flush jamais de sa propre initiative, sauf instruction explicite.

---

## 2. Modèle et invariants

### 2.1 Règles fondamentales du pattern

Une entité `GhostableInterface` est soit une **racine** (pas de parent), soit un **fantôme** (avec un parent). Le comportement d'un fantôme obéit à trois règles :

```text
Règle 1 — Héritage transparent
  Si local == null ET parent != null
  → retourner parent.getter()

Règle 2 — Matérialisation locale
  Si local != null
  → retourner local (le parent est ignoré)

Règle 3 — Valeur indéfinie
  Si local == null ET parent == null
  → retourner null
```

Ces règles sont implémentées à deux endroits **intentionnellement synchronisés** :

| Lieu | Usage | Fichier |
| --- | --- | --- |
| `GhostNodeTrait::resolve()` | Dans les getters d'entité (sans service) | `src/Trait/GhostNodeTrait.php` |
| `GhostResolver::resolve()` | Dans les services (debug, batch, sérialisation) | `src/Resolver/GhostResolver.php` |

> **Avertissement** : toute modification de la règle de résolution doit être appliquée aux **deux** implémentations. Le test `testTraitAndResolverAgree` vérifie leur équivalence sur le cas standard.

### 2.2 Invariants garantis

| Invariant | Garanti par | Testé dans |
| --- | --- | --- |
| Lecture fantôme = valeur parent si local null | `GhostNodeTrait::resolve()` | `testInvariant_GhostReadsFromParentWhenNotMaterialized` |
| Isolation des écritures (fantôme n'écrit pas sur le parent) | Immutabilité des setters | `testInvariant_WriteIsolation` |
| Dématérialisation restore la transparence | `setLieuDepart(null)` | `testInvariant_DematerializationRestoresTransparency` |
| Matérialisation granulaire par attribut | `GhostNodeTrait::resolve()` par champ | `testInvariant_PartialMaterializationIsGranular` |
| Pas d'auto-référence directe | `GhostNodeTrait::setParent()` | `testResolver_RejectsSelfAsParent` |
| Profondeur ≤ max_depth | `GhostResolver::assertValidParent()` | `testResolver_RejectsDepthOverflow` |
| Pas de cycle indirect | `GhostResolver::assertValidParent()` | `testResolver_RejectsCycle` |

### 2.3 Contraintes de profondeur

La configuration `ghost_trees.max_depth` (défaut : `1`) limite le nombre de degrés de fantômes :

```text
max_depth = 1 :  racine → fantôme          ✓
                 racine → fantôme → fantôme ✗ (GhostDepthExceededException)

max_depth = 2 :  racine → fantôme          ✓
                 racine → fantôme → fantôme ✓
                 (3 niveaux, 2 degrés)
```

> **Sémantique** : `max_depth` représente le nombre de **degrés de fantôme**, pas le nombre de niveaux dans l'arbre. Une racine n'est pas comptée.

### 2.4 Limites connues du modèle

#### Chaîne vide vs null

`''` (chaîne vide) est traité comme une valeur **matérialisée** :

```php
$ghost->setLieuDepart('');
$ghost->getLieuDepart(); // → '' (pas de délégation au parent)
```

Si votre domaine utilise des chaînes vides pour signifier "non défini", vous devez normaliser vers `null` dans vos setters avant d'appeler ceux du bundle.

#### Données corrompues en base

`assertValidParent()` protège contre les cycles **à la création**. Si un cycle est introduit directement en SQL (hors Doctrine), les méthodes `resolveFromAncestors()` et `debugResolution()` détecteront le cycle et lèveront `GhostCycleException` plutôt que de boucler indéfiniment.

---

## 3. Sécurité

### 3.1 Entrées utilisateur — commandes CLI

Les commandes `debug:ghosts` et `ghosts:incarnate` acceptent deux arguments : un FQCN de classe et un identifiant. Ces entrées sont **des vecteurs d'attaque potentiels** si les commandes sont accessibles à des utilisateurs non administrateurs.

#### Validation du FQCN

```php
// src/Command/IncarnateGhostCommand.php
if (!class_exists($class)) {
    $io->error(sprintf('Classe "%s" introuvable. Vérifiez le FQCN.', $class));
    return Command::FAILURE;
}
```

**Pourquoi** : `EntityManager::find()` lèverait une exception Doctrine opaque sans ce contrôle. Plus important, `class_exists()` déclenche l'autoloader Composer — sur un système compromis, ce vecteur doit rester réservé aux administrateurs.

**Risque résiduel** : l'autoloading peut avoir des effets de bord si une classe a des initialisations statiques complexes. Niveau de risque : **faible** (accès CLI requis, autoloading limité à l'espace Composer).

**Recommandation opérationnelle** : restreindre l'accès à ces commandes via les permissions système (`sudo -u app`) ou un firewall de commandes Symfony (`sfConsoleAccess`).

#### Sanitization de l'identifiant

L'identifiant est passé directement à `EntityManager::find()` qui utilise des requêtes paramétrées Doctrine. Pas d'interpolation SQL. Le risque d'injection SQL est **nul** sur ce point.

#### Résumé des vecteurs CLI

| Vecteur | Risque | Mitigation |
| --- | --- | --- |
| FQCN invalide | Faible | `class_exists()` avant `em->find()` |
| ID arbitraire | Nul | Doctrine utilise des requêtes paramétrées |
| Accès non autorisé | Élevé (opérationnel) | Contrôle d'accès au niveau OS/Symfony |

---

### 3.2 Persistence Doctrine — cohérence transactionnelle

#### Le problème des transactions sans flush

`EntityManager::wrapInTransaction()` ouvre une transaction et la commite à la fin — mais **ne flush pas automatiquement**. Sans `flush()`, les modifications restent en mémoire PHP. Le commit est vide.

```php
// ❌ MAUVAIS — rien n'est persisté en base
$em->wrapInTransaction(function () use ($entity) {
    $incarnator->incarnate($entity);
    // commit d'une transaction vide
});

// ✓ CORRECT — les changements sont inclus dans la transaction
$em->wrapInTransaction(function () use ($entity, $em) {
    $incarnator->incarnate($entity);
    $em->flush(); // écrit les SQL avant le commit
});
```

#### Règle d'or

> `GhostIncarnator::incarnate()` modifie l'état de l'entité **en mémoire uniquement**. La persistence est **toujours** sous la responsabilité de l'appelant.

#### Séquence atomique recommandée

```php
$em->wrapInTransaction(function () use ($entity, $em, $incarnator): void {
    // 1. Incarnation : matérialisation + détachement du parent
    $incarnator->incarnate($entity);

    // 2. Flush dans la transaction → SQL exécuté, puis commit
    $em->flush();
});
// Si une exception est levée dans le callback, rollback automatique.
```

#### Concurrence

L'incarnation n'utilise pas de **verrou pessimiste**. En cas de modifications simultanées sur le même fantôme, un race-condition peut produire des valeurs incohérentes.

Pour les cas critiques (ex: incarnation déclenchée par plusieurs processus worker), utilisez un verrou explicite :

```php
$em->wrapInTransaction(function () use ($entity, $em, $incarnator): void {
    // Verrou exclusif sur la ligne jusqu'au commit
    $locked = $em->find($entity::class, $entity->getId(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
    if (null !== $locked && $locked->isGhost()) {
        $incarnator->incarnate($locked);
        $em->flush();
    }
});
```

---

### 3.3 Protection contre les cycles

#### Deux niveaux de protection

#### Niveau 1 — À la création (assertValidParent)

Avant d'assigner un parent à une entité, appelez toujours le resolver :

```php
// Dans votre service applicatif
$resolver->assertValidParent($child, $parent); // lève GhostCycleException ou GhostDepthExceededException
$child->setParent($parent);
$em->flush();
```

```php
// src/Resolver/GhostResolver.php — algorithme de détection
$visited = new \SplObjectStorage();
$visited->attach($entity); // l'entité elle-même est marquée "visité"

$current = $parent;
while (null !== $current) {
    if ($visited->contains($current)) {
        throw new GhostCycleException('Cycle détecté dans la chaîne fantôme.');
    }
    $visited->attach($current);
    // ...remonter la chaîne
}
```

#### Niveau 2 — Auto-référence directe (setParent)

`GhostNodeTrait::setParent()` détecte et rejette immédiatement l'auto-référence directe :

```php
$entity->setParent($entity); // → GhostCycleException immédiate
```

#### Niveau 3 — Données corrompues (traversée)

Si un cycle existe malgré les protections (manipulation directe en base), `resolveFromAncestors()` et `debugResolution()` le détectent via `SplObjectStorage` et lèvent `GhostCycleException` plutôt que de boucler indéfiniment.

#### Ce qui N'est PAS protégé

`GhostNodeTrait::setParent()` **ne valide pas** les cycles indirects ni la profondeur. Ces validations nécessitent la configuration `max_depth` du container et sont déléguées au resolver.

```php
// ❌ Bypass non détecté par le trait seul
$a->setParent($b); // OK
$b->setParent($a); // ← cycle indirect, non détecté ici
// → assertValidParent() AURAIT détecté ce cas

// ✓ Séquence sécurisée
$resolver->assertValidParent($b, $a); // → GhostCycleException
$b->setParent($a);
```

---

### 3.4 Écriture via réflexion — bypass des setters

`GhostablePropertyMetadata::writeValue()` écrit directement dans la propriété PHP via `ReflectionProperty::setValue()`. Ce mécanisme **court-circuite intentionnellement** les setters de l'entité.

#### Pourquoi ce choix

L'incarnation doit être **atomique** : elle lit toutes les valeurs héritées et les écrit en une seule passe avant de couper le lien parent. Passer par les setters exposerait à des effets de bord imprévisibles (validation partielle, events déclenchés à mi-incarnation).

#### Risques

| Risque | Description | Mitigation |
| --- | --- | --- |
| Bypass des setters | Les validations dans les setters ne s'exécutent pas | Documenter que `incarnate()` écrit directement |
| Violation de contraintes Symfony Validator | Les `@Assert` sur les setters ne se déclenchent pas | Valider l'entité après incarnation si nécessaire |
| Contournement de la logique métier | Un setter `setPrice()` qui refuse les prix négatifs est ignoré | Utiliser `#[GhostableField]` pour la validation contextuelle |

#### Recommandation

Après une incarnation, validez l'entité si elle doit respecter des contraintes strictes :

```php
$incarnator->incarnate($entity);

$violations = $validator->validate($entity);
if (count($violations) > 0) {
    // L'entité incarnée viole des contraintes — lever une exception métier
    throw new \DomainException((string) $violations);
}

$em->flush();
```

---

### 3.5 Risques connus et mitigations

| Risque | Criticité | Statut | Mitigation |
| --- | --- | --- | --- |
| Données corrompues en base (cycle SQL) | Élevé | ✅ Détecté | `SplObjectStorage` dans `resolveFromAncestors` et `debugResolution` |
| Persist sans flush dans `wrapInTransaction` | Critique | ✅ Corrigé | `flush()` ajouté dans `IncarnateGhostCommand` |
| Incompatibilité Doctrine 2.x (`getObject()`) | Élevé | ✅ Corrigé | `method_exists` pour compatibilité 2.x/3.x |
| Accès CLI non restreint | Opérationnel | ⚠️ Non technique | Contrôle d'accès OS/Symfony |
| Race condition sur incarnation concurrente | Moyen | ⚠️ Documenté | Verrou pessimiste recommandé |
| Bypass setters via réflexion | Moyen | ⚠️ Documenté | Validation post-incarnation si requise |
| Constructeur avec arguments obligatoires | Moyen | ⚠️ Documenté | Convention : constructeur sans argument obligatoire |

---

## 4. Qualité logicielle

### 4.1 Architecture — rôle de chaque composant

```text
src/
├── Attribute/
│   ├── Ghostable.php            ← Marque une propriété comme fantomisable (introspection)
│   └── GhostableField.php       ← Contrainte Symfony Validator (validation contextuelle)
│
├── Contract/
│   ├── GhostableInterface.php   ← Contrat des entités (getParent, setParent, isGhost)
│   ├── GhostResolverInterface.php  ← Résolution de valeurs + validation structurelle
│   ├── GhostIncarnatorInterface.php ← Matérialisation + détachement
│   └── GhostInspectorInterface.php  ← Lecture seule (debug, isMaterialized)
│
├── Resolver/GhostResolver.php   ← Implémentation : règle de résolution + assertValidParent
├── Incarnator/GhostIncarnator.php ← Implémentation : incarnation atomique via réflexion
├── Inspector/GhostInspector.php ← Implémentation : debugResolution, isMaterialized
│
├── Metadata/
│   ├── GhostMetadata.php        ← Cache singleton de réflexion (par classe)
│   └── GhostablePropertyMetadata.php ← DTO : nom, getter, readValue/writeValue
│
├── Trait/GhostNodeTrait.php     ← Mixin entité : $parent, setParent, isGhost, resolve()
│
├── Event/                       ← Événements cycle de vie (GhostIncarnatedEvent, etc.)
├── EventSubscriber/
│   └── GhostPropagationSubscriber.php ← Doctrine listener : propagation + suppression
│
├── Exception/
│   ├── GhostCycleException.php          ← Cycle détecté
│   └── GhostDepthExceededException.php  ← Profondeur dépassée
│
├── Validator/GhostableFieldValidator.php ← Validator : required sur racines uniquement
│
├── Command/
│   ├── DebugGhostsCommand.php     ← CLI : debug:ghosts
│   └── IncarnateGhostCommand.php  ← CLI : ghosts:incarnate
│
└── DependencyInjection/
    ├── Configuration.php          ← Schéma YAML (max_depth, on_root_delete, etc.)
    └── GhostTreesExtension.php    ← Chargement services.yaml + paramètres container
```

#### Séparation des responsabilités (SOLID)

| Principe | Application |
| --- | --- |
| **S** (Single Responsibility) | Resolver ≠ Inspector ≠ Incarnator — chaque service a une responsabilité |
| **O** (Open/Closed) | Interfaces permettent l'extension sans modification du bundle |
| **L** (Liskov) | `GhostNodeTrait` satisfait pleinement `GhostableInterface` |
| **I** (Interface Segregation) | 3 interfaces distinctes (Resolver, Inspector, Incarnator) |
| **D** (Dependency Inversion) | Les commandes et subscribers dépendent des interfaces, pas des implémentations |

#### Point d'attention : duplication trait/resolver

La méthode `resolve()` existe dans `GhostNodeTrait` ET dans `GhostResolver`. Cette duplication est **intentionnelle** (les entités Doctrine ne peuvent pas injecter de services) mais représente un risque de désynchronisation.

**Règle** : tout changement à la règle de résolution doit être répercuté dans les deux fichiers, et le test `testTraitAndResolverAgree` doit couvrir le nouveau cas.

---

### 4.2 Stratégie de tests

#### Tests unitaires existants (`tests/Unit/GhostInvariantsTest.php`)

Couvrent les invariants fondamentaux sans Doctrine :

- Résolution (6 tests) — héritage, matérialisation, isolation
- Inspector (4 tests) — isMaterialized, debugResolution
- Incarnator (3 tests) — matérialisation complète, no-op sur racine, événement dispatché
- Resolver (5 tests) — null parent, auto-référence, profondeur, cycle
- Métadonnées (2 tests) — discovery, cache

#### Tests à implémenter

#### Unitaires prioritaires

```php
// GhostableFieldValidatorTest.php
public function testValidator_RequiresValueOnRoot(): void
{
    // Une racine sans valeur DOIT lever une violation
}

public function testValidator_SilentOnGhost(): void
{
    // Un fantôme sans valeur NE doit PAS lever de violation
    // (la valeur sera héritée du parent à la résolution)
}

// GhostIncarnatorTest.php
public function testIncarnator_ThrowsCycleExceptionOnCorruptData(): void
{
    // Simule un cycle en base via Reflection directe
    // Vérifie que GhostCycleException est levée (pas de boucle infinie)
}

public function testIncarnator_MultiLevelChain(): void
{
    // root → mid → leaf : incarnation de leaf copie la valeur de root
    $resolver = new GhostResolver(maxDepth: 2);
    $root = (new FakeTrajet())->setLieuDepart('Paris');
    $mid  = (new FakeTrajet())->setParent($root);
    $leaf = (new FakeTrajet())->setParent($mid);

    $this->incarnator->incarnate($leaf);

    $this->assertSame('Paris', $leaf->getLieuDepart());
    $this->assertNull($leaf->getParent());
}
```

#### Tests d'intégration (`tests/Integration/`)

```php
// GhostPropagationSubscriberTest.php — nécessite un kernel Symfony de test
public function testOnFlush_PropagatesGhostToChildrenOnInsert(): void { ... }
public function testPreRemove_IncarnatesGhostsBeforeRootDeletion(): void { ... }
public function testPreRemove_CascadeDeleteByDefault(): void { ... }

// GhostTreesExtensionTest.php
public function testExtensionRegistersServicesWithCorrectParameters(): void { ... }
public function testConfigurationDefaultValues(): void { ... }

// IncarnateGhostCommandTest.php
public function testCommandFlushesInsideTransaction(): void { ... }
public function testCommandFailsGracefullyOnUnknownClass(): void { ... }
```

#### Tests de sécurité

```php
public function testCycleDetectedInCorruptChain(): void
{
    // Injecte un cycle via ReflectionProperty (contourne setParent)
    $a = new FakeTrajet();
    $b = new FakeTrajet();
    $rp = new \ReflectionProperty(FakeTrajet::class, 'parent');
    $rp->setValue($a, $b);
    $rp->setValue($b, $a); // cycle

    $this->expectException(GhostCycleException::class);
    $this->incarnator->incarnate($a);
}

public function testDebugResolutionReportsCycleSource(): void
{
    // Vérifie que source='cycle_detected' est retourné, pas de boucle infinie
}
```

---

### 4.3 Analyse statique — Configuration PHPStan

Ajouter `phpstan.neon` à la racine :

```neon
# phpstan.neon
parameters:
    level: 8
    paths:
        - src
        - tests
    checkMissingIterableValueType: true
    checkGenericClassInNonGenericObjectType: true

    # Ignorer les lignes de compatibilité Doctrine 2.x/3.x
    ignoreErrors:
        - '#Call to an undefined method Doctrine\\ORM\\Event\\PreRemoveEventArgs::getEntity\(\)#'

includes:
    - vendor/phpstan/phpstan-symfony/extension.neon
    - vendor/phpstan/phpstan-doctrine/extension.neon
```

Ajouter dans `composer.json` (require-dev) :

```json
"phpstan/phpstan": "^1.10",
"phpstan/phpstan-symfony": "^1.3",
"phpstan/phpstan-doctrine": "^1.3"
```

---

## 5. Pipeline CI/CD

### Vue d'ensemble

```text
push/PR
  │
  ├─► [lint]     PHP Syntax + CS Fixer (bloquant)
  ├─► [analyse]  PHPStan niveau 8 (bloquant)
  ├─► [test]     PHPUnit — unitaires + intégration (bloquant)
  ├─► [matrix]   PHP 8.2/8.3/8.4 × Symfony 6.4/7.0/7.1 (bloquant)
  ├─► [security] Composer audit + CodeQL + Dependency Review (bloquant)
  └─► [coverage] Rapport Codecov (non bloquant)
```

### Étapes détaillées

#### 1. Lint

```yaml
php:lint:
  script:
    - find src tests -name "*.php" -exec php -l {} \;
    - vendor/bin/php-cs-fixer fix --dry-run --diff
```

**Objectif** : détecter les erreurs de syntaxe et les violations PSR-12 avant tout.

#### 2. Analyse statique

```yaml
phpstan:
  script:
    - vendor/bin/phpstan analyse src tests --level=8 --memory-limit=256M
```

**Objectif** : détecter les erreurs de typage, les appels de méthodes inexistantes, les retours null non gérés.

#### 3. Tests unitaires et intégration

```yaml
phpunit:unit:
  script:
    - vendor/bin/phpunit --testsuite=Unit --colors=always

phpunit:integration:
  script:
    - vendor/bin/phpunit --testsuite=Integration --colors=always
```

**Objectif** : valider les invariants du pattern et l'intégration Doctrine/Symfony.

#### 4. Matrice de compatibilité

```yaml
phpunit:matrix:
  parallel:
    matrix:
      - PHP_VERSION: ["8.2", "8.3", "8.4"]
        SYMFONY_VERSION: ["6.4.*", "7.0.*", "7.1.*"]
  script:
    - composer config extra.symfony.require "$SYMFONY_VERSION"
    - composer update --no-interaction --prefer-dist
    - vendor/bin/phpunit --colors=always
```

**Objectif** : garantir la compatibilité sur toutes les combinaisons déclarées dans `composer.json`.

#### 5. Audit sécurité

```yaml
security:composer-audit:
  script:
    - composer audit --no-dev --abandoned=report   # bloquant
    - composer audit --abandoned=report             # dev deps, non bloquant

security:codeql:
  # Analyse statique de sécurité GitHub CodeQL (PHP)

security:dependency-review:
  # Sur PR uniquement — bloque si dépendance avec CVE ≥ moderate
  if: github.event_name == 'pull_request'
```

**Objectif** : détecter les CVE dans les dépendances directes et transitives.

#### 6. Couverture de code

```yaml
coverage:
  script:
    - vendor/bin/phpunit --coverage-clover=coverage.xml
    - codecov upload coverage.xml
```

**Seuil recommandé** : 80% minimum sur `src/`. Configurer dans `phpunit.xml.dist` :

```xml
<coverage>
    <report>
        <clover outputFile="coverage.xml"/>
    </report>
</coverage>
```

---

## 6. Bonnes pratiques

### 6.1 Implémenter une entité fantomisable

#### Étape 1 — Implémenter l'interface et le trait

```php
<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use EricGansa\GhostTreesBundle\Attribute\Ghostable;
use EricGansa\GhostTreesBundle\Attribute\GhostableField;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Trait\GhostNodeTrait;

#[ORM\Entity]
class Trajet implements GhostableInterface
{
    use GhostNodeTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    // Redéclaration obligatoire pour le mapping Doctrine
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?GhostableInterface $parent = null;

    #[Ghostable]
    #[GhostableField(required: true)]
    #[ORM\Column(nullable: true)]
    private ?string $lieuDepart = null;

    public function getLieuDepart(): ?string
    {
        // Toujours passer par resolve() pour activer l'héritage
        return $this->resolve($this->lieuDepart, 'getLieuDepart');
    }

    public function setLieuDepart(?string $value): self
    {
        $this->lieuDepart = $value;
        return $this;
    }
}
```

#### Étape 2 — Assigner un parent via le resolver

```php
// ✓ CORRECT — validation structurelle avant persistence
$resolver->assertValidParent($child, $parent);
$child->setParent($parent);
$em->flush();

// ❌ INCORRECT — bypass la validation de profondeur et des cycles indirects
$child->setParent($parent);
$em->flush();
```

#### Étape 3 — Incarner un fantôme de façon atomique

```php
$em->wrapInTransaction(function () use ($entity, $em, $incarnator): void {
    $incarnator->incarnate($entity);
    $em->flush(); // OBLIGATOIRE dans la transaction
});
```

---

### 6.2 Configurer le bundle

```yaml
# config/packages/ghost_trees.yaml
ghost_trees:
    # Nombre de degrés de fantôme autorisés (défaut : 1)
    # 1 = racine → fantôme uniquement
    # 2 = racine → fantôme → fantôme
    max_depth: 1

    # Stratégie lors de la suppression d'une racine
    # 'cascade'   : Doctrine supprime les fantômes (via onDelete CASCADE en mapping)
    # 'incarnate' : les fantômes sont promus racines autonomes avant la suppression
    on_root_delete: 'cascade'

    # Propagation automatique des collections (défaut : true)
    # Si true : ajout d'un item sur la racine → création d'un fantôme pour chaque enfant
    # Désactiver si vous gérez la propagation manuellement
    auto_propagate_collections: true
```

---

### 6.3 Erreurs courantes à éviter

#### ❌ Oublier le flush dans la transaction

```php
// Bug silencieux : rien n'est persisté
$em->wrapInTransaction(fn() => $incarnator->incarnate($entity));

// ✓ Correct
$em->wrapInTransaction(function () use ($entity, $em) {
    $incarnator->incarnate($entity);
    $em->flush();
});
```

#### ❌ Appeler setParent() sans assertValidParent()

```php
// Risque : cycle indirect non détecté avant persistence
$child->setParent($parent);

// ✓ Toujours valider d'abord
$resolver->assertValidParent($child, $parent);
$child->setParent($parent);
```

#### ❌ Getter sans resolve()

```php
// ❌ Retourne toujours null si non matérialisé localement
public function getLieuDepart(): ?string
{
    return $this->lieuDepart;
}

// ✓ Délègue au parent si null localement
public function getLieuDepart(): ?string
{
    return $this->resolve($this->lieuDepart, 'getLieuDepart');
}
```

#### ❌ Constructeur avec arguments obligatoires

`GhostPropagationSubscriber::createGhostOf()` instancie les entités avec `new $class()`. Toute entité avec des arguments de constructeur obligatoires causera une erreur fatale lors de la propagation automatique.

```php
// ❌ Incompatible avec auto_propagate_collections
class Trajet {
    public function __construct(private readonly string $type) {}
}

// ✓ Compatible
class Trajet {
    private string $type = 'standard';
    public function __construct() {}
}
```

#### ❌ Utiliser des chaînes vides comme "valeur vide"

```php
// ❌ '' est traité comme matérialisé — pas de délégation au parent
$ghost->setLieuDepart('');

// ✓ Utiliser null pour signifier "non défini"
$ghost->setLieuDepart(null);
```

---

### 6.4 Limites documentées du système

| Limite | Description | Contournement |
| --- | --- | --- |
| Constructeur sans argument | `createGhostOf()` requiert un constructeur sans argument obligatoire | Désactiver `auto_propagate_collections` et gérer manuellement |
| Convention `parent` | La propagation suppose une propriété Doctrine nommée `parent` | Désactiver `auto_propagate_collections` pour les conventions hors-norme |
| Chaîne vide ≠ null | `''` est considéré comme une valeur matérialisée | Normaliser vers `null` dans les setters |
| Pas de verrouillage concurrent | Incarnation sans verrou pessimiste par défaut | Utiliser `PESSIMISTIC_WRITE` pour les usages concurrents |
| Bypass des setters | `writeValue()` contourne la logique des setters | Valider l'entité après incarnation si nécessaire |
| Propagation à un seul niveau | `createGhostOf()` ne propage pas les sous-collections | Abonnement à `GhostIncarnatedEvent` pour propagation en cascade |
