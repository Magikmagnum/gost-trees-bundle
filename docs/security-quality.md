# Sécurité & Qualité — Ghost Trees Bundle

> Version : 2026-05-05 · PHP 8.2+ · Symfony 6.4/7.x · Doctrine ORM 2.17/3.x

---

## 1. Invariants du système

### I1 — Profondeur bornée

Aucune chaîne fantôme ne dépasse `max_depth` niveaux.

**Mécanisme** : `GhostResolver::assertValidParent()` — **DOIT** être appelé avant toute
affectation de parent et tout `flush()`.

**Risque si ignoré** : getters récursifs → stack overflow ou requêtes N+1 Doctrine en cascade.

---

### I2 — Absence de cycles

Une entité ne peut pas être son propre ancêtre.

| Situation | Mécanisme | Comportement |
|---|---|---|
| Auto-référence directe `$e->setParent($e)` | `GhostNodeTrait::setParent()` | `GhostCycleException` immédiate |
| Cycle indirect `A→B→A` avant persistence | `GhostResolver::assertValidParent()` | `GhostCycleException` |
| Cycle en données SQL corrompues | `SplObjectStorage` dans `GhostIncarnator` | `GhostCycleException` |
| Debug sur données corrompues | `SplObjectStorage` dans `GhostInspector` | `source='cycle_detected'`, pas d'exception |

**Limite — `GhostNodeTrait::incarnate()`** : repose sur les getters (un saut par appel).
Sur un cycle corrompu A→B→A, `incarnate()` renvoie `null` sans exception.
→ Pour la protection cycle multi-niveaux, utiliser `GhostIncarnatorInterface`.

---

### I3 — Transparence de lecture

Un fantôme dont un champ est `null` localement retourne la valeur du parent (via `resolve()`).
La lecture ne modifie jamais l'état.

---

### I4 — Isolation d'écriture

Écrire sur un fantôme n'affecte jamais le parent. Les setters écrivent toujours en local.

---

### I5 — Réversibilité

`reset()` efface les surcharges locales et restaure la lecture transparente sans modifier le lien parent.

---

## 2. Gestion des cycles

### Pré-persistence

```php
// TOUJOURS appeler avant setParent() + flush()
$resolver->assertValidParent($entity, $newParent);
$entity->setParent($newParent);
$em->flush();
```

`assertValidParent()` utilise `SplObjectStorage` pour remonter la chaîne en O(d) où d ≤ `max_depth`.

### Données corrompues

- `GhostIncarnator` → lève `GhostCycleException` dès cycle détecté
- `GhostInspector::debugResolution()` → interrompt proprement, retourne `source='cycle_detected'`
- `GhostNodeTrait::incarnate()` → pas de boucle, valeur null, **pas d'exception** (limite)

---

## 3. Doctrine et transactions

### Ce que le bundle gère

- **Propagation `onFlush`** : fantômes créés lors de l'ajout sur les collections d'une racine.
- **Suppression `preRemove`** (mode `incarnate`) : fantômes incarnés avant suppression de racine.
- **Compatibilité 2.x/3.x** : `method_exists($args, 'getObject')` pour `getObject()`/`getEntity()`.

### Ce que le bundle ne gère PAS

**Transactions** — le bundle ne flushes jamais de sa propre initiative :

```php
// ✓ Opération atomique correcte
$em->wrapInTransaction(function () use ($entity, $em, $incarnator): void {
    $incarnator->incarnate($entity);
    $em->flush(); // OBLIGATOIRE — sans flush, la transaction est vide
});
```

---

## 4. Concurrence

### État actuel (v0.x) — Limitation documentée

Le bundle ne fournit aucun verrou applicatif. Les lectures concurrentes sont sans état (safe).
Les **écritures concurrentes** (double incarnation en base) ne sont pas protégées.

### Verrou pessimiste (recommandé)

```php
$em->wrapInTransaction(function () use ($em, $ghost, $incarnator): void {
    $locked = $em->find(
        $ghost::class,
        $ghost->getId(),
        \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE
    );
    if ($locked?->isGhost()) {
        $incarnator->incarnate($locked);
        $em->flush();
    }
});
```

### Verrou optimiste (alternative légère)

```php
#[ORM\Version]
#[ORM\Column(type: 'integer')]
private int $version = 0;
// → OptimisticLockException si version modifiée entre-temps
```

---

## 5. Méthodes `incarnate()` et `reset()` du trait

### `incarnate()` — Matérialisation autonome

```php
$ghost->incarnate();
// 1. Pour chaque #[GhostField] local à null : appelle $this->$getter()
//    → résout via resolve() → remonte la chaîne naturellement
// 2. Écrit la valeur résolue dans la propriété locale via Reflection
// 3. Coupe le lien parent ($this->parent = null)
// → L'entité est maintenant une racine indépendante
```

**Différence avec `GhostIncarnatorInterface`** :

| Critère | `GhostNodeTrait::incarnate()` | `GhostIncarnatorInterface` |
|---|---|---|
| Service requis | Non (autonome) | Oui (injection) |
| Protection cycle | Getters (1 saut) — **pas d'exception sur cycle** | `SplObjectStorage` — lève `GhostCycleException` |
| Événement dispatché | Non | Oui (`GhostIncarnatedEvent`) |
| Cas d'usage | Entités en mémoire, tests, CLI léger | Production, batch, opérations critiques |

### `reset()` — Retour à la transparence

```php
$ghost->reset();
// Efface tous les champs #[GhostField] locaux (set à null)
// Le lien parent est CONSERVÉ
// → Si parent existe : fantôme totalement transparent
// → Si pas de parent : racine sans valeurs locales
```

---

## 6. Fabrique `createGhostOf()`

### Avant (fragilité)

```php
// Ancienne implémentation dans GhostPropagationSubscriber
$ghost = new $class(); // Suppose un constructeur sans argument
$ghost->setParent($parent);
```

### Après (correction v0.x+)

`createGhostOf(self $original): static` est maintenant un contrat de `GhostableInterface`,
implémenté par défaut dans `GhostNodeTrait`. Les entités avec constructeur à arguments **DOIVENT**
surcharger la méthode :

```php
public static function createGhostOf(GhostableInterface $original): static
{
    $ghost = new static($requiredArg);
    $ghost->setParent($original);
    return $ghost;
}
```

---

## 7. Attributs PHP — noms expressifs

| Nouveau nom | Ancien nom (déprécié) | Rôle |
|---|---|---|
| `#[GhostField]` | `#[Ghostable]` | Marque une propriété pour la résolution dynamique |
| `#[RequiredOnRoot]` | `#[GhostableField(required: true)]` | Validation : obligatoire sur les racines |

Les anciens noms (`Ghostable`, `GhostableField`) restent fonctionnels en v0.x via héritage.
Ils seront supprimés en v1.0.

---

## 8. Duplication trait / resolver

### Risque

`GhostNodeTrait::resolve()` et `GhostResolver::resolve()` partagent la même logique.
Une divergence introduirait des comportements incohérents.

### Justification

Les entités Doctrine **ne peuvent pas dépendre du container** (pas d'injection de service).
Le trait doit embarquer la logique. Le service la porte pour les contextes hors-entité.

### Mitigation

Test d'invariant `testTraitAndResolverAgree` dans `GhostInvariantsTest`.

---

## 9. Tests et couverture

### Architecture

```
tests/
  Unit/
    GhostInvariantsTest.php         — 35+ cas : résolution, inspector, incarnator, resolver, trait
    GhostRobustnessTest.php         — cycles corrompus, double exécution, concurrence simulée
  Integration/
    GhostPropagationSubscriberTest.php — 10 cas : onFlush, preRemove, modes cascade/incarnate
  Fixtures/
    Entity/FakeTrajet.php           — entité de test sans Doctrine
```

### Objectifs

| Suite | Cible |
|---|---|
| Unitaire | > 90 % des lignes sur la logique cœur |
| Intégration | 100 % des branches du subscriber |
| Mutation (Infection) | MSI ≥ 70 %, Covered MSI ≥ 85 % |

---

## 10. Analyse statique

`phpstan.neon` niveau 8. Deux `ignoreErrors` justifiés :

1. **Compat Doctrine 2/3** : `getEntity()` / `getObject()` via `method_exists()` → faux positif PHPStan.
2. **ReflectionProperty** : accès propriétés privées via Reflection (autorisé PHP 8.1+).

---

## 11. Limites connues (v0.x)

| Limite | Impact | Mitigation |
|---|---|---|
| Pas de verrou sur incarnation concurrente | Race condition en écriture | Verrou pessimiste Doctrine |
| `incarnate()` du trait sans exception sur cycle | Données corrompues → null silencieux | Utiliser `GhostIncarnatorInterface` |
| Propagation limitée aux collections directes | Sous-collections non propagées | Extension via `GhostIncarnatedEvent` |
| Chaîne vide `''` = valeur matérialisée | Pas de délégation si `''` | Normaliser vers `null` dans les setters |
| Bypass setters via Reflection dans `writeValue()` | Validation setters non déclenchée | Valider l'entité après incarnation si requis |

---

## 12. Roadmap

- **v1.0** : suppression de `#[Ghostable]` et `#[GhostableField]` (alias dépréciés).
- **v1.x** : support multi-parent configurable.
- **Proposition** : `#[GhostVersioned]` pour intégration native verrou optimiste.
