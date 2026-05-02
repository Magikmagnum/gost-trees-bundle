# Les arbres fantômes — concepts

> Documentation conceptuelle du pattern. Pour l'usage pratique, voir le [README](../README.md). Pour les recettes, voir [cookbook.md](cookbook.md).

---

## Table des matières

1. [Le problème](#1-le-problème)
2. [L'idée centrale](#2-lidée-centrale)
3. [Vocabulaire](#3-vocabulaire)
4. [Modèle de données](#4-modèle-de-données)
5. [La règle de résolution](#5-la-règle-de-résolution)
6. [Cycle de vie](#6-cycle-de-vie)
7. [Propagation structurelle](#7-propagation-structurelle)
8. [Suppression](#8-suppression)
9. [Invariants garantis](#9-invariants-garantis)
10. [Quand utiliser ce pattern (et quand ne pas)](#10-quand-utiliser-ce-pattern-et-quand-ne-pas)
11. [Pièges et anti-patterns](#11-pièges-et-anti-patterns)
12. [Comparaison avec d'autres approches](#12-comparaison-avec-dautres-approches)

---

## 1. Le problème

Dans de nombreux domaines métier, on rencontre la situation suivante :

- une entité **pivot** porte un état structuré (attributs scalaires, sous-entités, collections) ;
- plusieurs autres entités doivent **partager** tout ou partie de cet état ;
- chacune doit pouvoir **diverger localement** sur certains attributs ;
- les modifications de l'entité pivot doivent **se propager** aux autres tant qu'elles n'ont pas divergé ;
- la duplication brute (`clone`) est inacceptable : elle casse la traçabilité et fait dériver les copies.

### Exemples concrets

- **Gabarits avec personnalisations** : un template de document partagé entre plusieurs équipes, chacune pouvant ajuster certains champs sans perdre le lien avec le modèle.
- **Configurations hiérarchiques** : une config globale, surchargée par environnement, surchargée par utilisateur — chaque niveau ne porte que ses différences.
- **Déplacements groupés** (cas d'origine du bundle) : un manager organise un voyage pour son équipe, chaque agent pouvant ajuster son trajet ou son hébergement.
- **Droits dérivés** : un rôle modèle dont héritent des rôles utilisateurs personnalisés.

### Pourquoi les approches naïves échouent

| Approche | Problème |
|---|---|
| **Clonage profond** | Les modifications du parent ne se propagent plus. La cohérence est perdue dès la première écriture côté parent. |
| **Référence directe (lien fort)** | L'enfant ne peut rien personnaliser sans impacter le parent. |
| **Duplication conditionnelle** | Mélange des deux mondes, code illisible, bugs de synchronisation. |
| **Héritage de classe (POO)** | Statique à la compilation, inutilisable pour des données dynamiques en base. |

Il manque un mécanisme **runtime, granulaire et réversible**.

---

## 2. L'idée centrale

> **Un fantôme est une entité qui hérite dynamiquement d'un parent, attribut par attribut, et n'écrit localement que ce qui diverge.**

Trois propriétés clés :

1. **Transparence** — tant qu'aucune modification locale n'existe, le fantôme expose les valeurs du parent.
2. **Isolation** — toute modification locale reste confinée au fantôme.
3. **Réversibilité** — effacer une valeur locale rétablit la transparence avec le parent.

Le fantôme est à la fois un **lien** vers le parent et un **conteneur de divergences**.

### En une phrase

*Un fantôme, ce n'est pas une copie. C'est une vue éditable d'un original, qui ne stocke que ses différences.*

---

## 3. Vocabulaire

| Terme | Définition |
|---|---|
| **Racine** | Entité sans parent. Source des valeurs originales. |
| **Fantôme** (ou *ghost*, ou *nœud fantôme*) | Entité avec un parent. Hérite dynamiquement des valeurs. |
| **Attribut résolu** | Valeur retournée par un getter après application de la règle de résolution. |
| **Attribut local** | Valeur effectivement stockée dans la ligne du fantôme (peut être `null`). |
| **Matérialisation** | Action de donner une valeur locale à un attribut fantôme. |
| **Dématérialisation** | Action d'effacer (`null`) une valeur locale. La résolution dynamique reprend. |
| **Traversée fantôme** | Lecture d'un attribut qui remonte la chaîne fantôme jusqu'à trouver une valeur. |
| **Incarnation** | Promotion d'un fantôme en racine autonome. Toutes les valeurs résolues sont matérialisées et le lien parent coupé. |
| **Divergence** | État d'un fantôme dont au moins un attribut local est renseigné. |
| **Profondeur** | Nombre de niveaux dans la chaîne fantôme. La racine est au niveau 0. |

Le vocabulaire est **narrativement cohérent** : un fantôme se *matérialise* quand on lui donne une valeur propre, se *dématérialise* quand on l'efface, et s'*incarne* quand on le promeut en racine. Cette cohérence aide la lecture du code et la documentation.

---

## 4. Modèle de données

Chaque entité supportant le pattern porte une **auto-référence** vers son parent éventuel.

```
Entity
├── id              identifiant
├── parent_id       nullable, FK vers la même table
├── attribut_1      nullable
├── attribut_2      nullable
└── ...
```

### Règles structurelles

- **Tous les attributs métier sont `nullable`.** Un fantôme vide (purement transparent) est légitime.
- **La relation parent est auto-référencée** (`ManyToOne` côté fantôme, `OneToMany` côté racine).
- **La profondeur est paramétrable** via `ghost_trees.max_depth` (par défaut 1).

### Conséquence sur la validation

Les contraintes "champ obligatoire" deviennent **conditionnelles** : obligatoires *uniquement* sur les racines. Le bundle fournit l'attribut `#[GhostableField(required: true)]` qui encapsule cette règle.

---

## 5. La règle de résolution

C'est le cœur du pattern. Chaque getter applique cette logique :

```
resolve(valeurLocale, nomGetter):
    si valeurLocale n'est pas null:
        retourner valeurLocale
    si parent existe:
        retourner parent.nomGetter()
    retourner null
```

### Table de vérité

| Parent défini ? | Valeur locale | Valeur retournée | Sémantique |
|:---:|:---:|:---:|---|
| Non | `null` | `null` | Racine vide |
| Non | `X` | `X` | Racine standard |
| Oui | `null` | valeur du parent | **Lecture transparente** (traversée) |
| Oui | `X` | `X` | **Override actif** (matérialisé) |

La 3ᵉ ligne est la magie du pattern : aucune duplication, propagation automatique, calculée à la lecture.

### Cas multi-niveaux (profondeur > 1)

Si `max_depth = 2` et qu'on a une chaîne `racine → fantôme1 → fantôme2`, la résolution remonte la chaîne **jusqu'à trouver une valeur non nulle**. Pour un attribut `lieuDepart` :

- `fantôme2.lieuDepart` local non nul → on retourne cette valeur ;
- `fantôme2.lieuDepart` null, `fantôme1.lieuDepart` local non nul → on retourne celle de fantôme1 ;
- les deux null, racine non nulle → on retourne celle de la racine ;
- toutes null → on retourne null.

C'est un mécanisme de **résolution en cascade ascendante**.

---

## 6. Cycle de vie

### 6.1. Création d'un fantôme

Au moment de la création, un fantôme est une **coquille vide** :

```
créerFantôme(parent):
    fantôme = new Entity()
    fantôme.parent = parent
    // tous les attributs métier restent à null
    persister(fantôme)
```

Aucun attribut n'est copié. Aucune valeur n'est dupliquée. Le fantôme expose intégralement les valeurs du parent par résolution dynamique.

> **C'est ici qu'il faut résister à la tentation du clonage.** Cloner = casser le pattern.

### 6.2. Matérialisation (divergence)

Quand on appelle un setter sur le fantôme :

```
fantôme.setLieuDepart("Lyon"):
    this.lieuDepart = "Lyon"
    // la valeur locale supplante désormais celle du parent pour cet attribut
```

Conséquences :

- la valeur locale **fait écran** au parent **pour cet attribut uniquement** ;
- les autres attributs continuent de résoudre depuis le parent ;
- le fantôme est dit **partiellement matérialisé**.

C'est la **granularité par attribut** qui rend le pattern puissant. On peut matérialiser `lieuDepart` sans toucher à `lieuArrivee` ni `moyenTransport`.

### 6.3. Dématérialisation (réinitialisation)

```
fantôme.setLieuDepart(null):
    this.lieuDepart = null
    // la résolution dynamique reprend automatiquement
```

C'est la propriété la plus contre-intuitive et la plus puissante : **effacer une valeur locale n'efface pas la donnée affichée**, elle restaure le lien dynamique avec le parent.

> *Effacer un override = revenir à l'héritage.*

### 6.4. Incarnation

L'incarnation transforme un fantôme en **racine autonome** :

```
incarner(fantôme):
    pour chaque attribut fantomisable:
        si valeur locale est null:
            valeur locale = valeur résolue depuis le parent
    fantôme.parent = null
```

Après incarnation :

- toutes les valeurs précédemment héritées sont **matérialisées localement** ;
- les valeurs déjà locales sont **conservées** ;
- le lien parent est **coupé** ;
- le fantôme devient une **racine indépendante**.

L'incarnation est une opération **destructive** : elle ne peut pas être défaite (sauf à recréer manuellement un lien parent et à réinitialiser tous les attributs).

---

## 7. Propagation structurelle

Le pattern ne s'applique pas qu'aux scalaires. Il s'étend aux **collections liées** (`OneToMany`, `OneToOne`).

### 7.1. Ajout d'un élément côté racine

Quand un nouvel élément `E` est ajouté à la collection d'une racine `R`, il faut :

1. créer automatiquement, pour **chaque fantôme** de `R`, un fantôme `En` rattaché à `E` ;
2. l'insérer dans la collection correspondante du fantôme ;
3. tous les attributs de `En` restent à `null` (résolution dynamique).

C'est la **propagation structurelle** : équivalent collection de la résolution scalaire.

### 7.2. Affiliation tardive d'un fantôme

Cas symétrique : un nouveau fantôme `F` est rattaché à une racine `R` **après** que `R` a déjà été modifiée et enrichie.

```
affilier(racine, fantôme):
    fantôme.parent = racine
    pour chaque collection liée de racine:
        pour chaque élément E de cette collection:
            créerFantôme(E) et l'ajouter à fantôme
```

L'état initial du nouveau fantôme est une **photographie résolue** de l'état courant de la racine, sans copier aucune donnée scalaire.

### 7.3. Création directe par le fantôme

Si un fantôme **crée son propre élément** dans une collection (sans parent), cet élément :

- appartient **exclusivement** au fantôme ;
- est **invisible** pour les setters de la racine — la racine ne peut ni le modifier ni le supprimer ;
- est **visible en lecture seule** côté racine pour les besoins d'affichage (selon politique métier).

C'est la **possession exclusive** : un élément créé par un fantôme reste à lui.

---

## 8. Suppression

La suppression est le cas limite le plus délicat. Elle se paramètre via `ghost_trees.on_root_delete`.

### 8.1. Mode `cascade` (par défaut)

Lors de la suppression d'une racine, **tous les fantômes qui en dépendent sont supprimés**.

C'est le comportement le plus simple et le plus prévisible. Convient quand les fantômes n'ont pas de sens en l'absence du parent.

### 8.2. Mode `incarnate`

Lors de la suppression d'une racine, **tous les fantômes sont incarnés** (matérialisés en racines autonomes) avant que le parent ne soit effectivement supprimé.

Convient quand les fantômes doivent **survivre** au parent (par exemple : les agents conservent leur déplacement même si le manager supprime la demande groupée d'origine).

### 8.3. Choix de la stratégie

Le choix dépend du domaine métier :

- **Cascade** quand le parent est *constitutif* du sens des fantômes.
- **Incarnate** quand les fantômes ont une vie propre une fois créés.

Il n'y a pas de bonne réponse universelle — c'est une décision de design qu'il faut **expliciter**.

### 8.4. Suppression d'un élément côté parent

Si un élément de collection est supprimé côté racine, par cohérence :

- les fantômes correspondants chez les enfants sont également supprimés (**cascade**) ;
- ou, en mode `incarnate`, ils sont matérialisés avant suppression du parent.

---

## 9. Invariants garantis

Une implémentation correcte doit garantir ces invariants en permanence :

| # | Invariant | Vérifié par |
|---|---|---|
| 1 | **Profondeur** : aucune chaîne fantôme ne dépasse `max_depth`. | `GhostResolver::assertValidParent()` |
| 2 | **Pas de cycle** : une entité ne peut pas être son propre ancêtre direct ou indirect. | `GhostResolver::assertValidParent()` |
| 3 | **Cohérence d'appartenance** : un fantôme appartient au contexte de son enfant, jamais au contexte du parent. | À garantir par le code applicatif. |
| 4 | **Symétrie de propagation** : tout ajout d'élément côté racine déclenche la création des fantômes correspondants chez chaque enfant. | `GhostPropagationSubscriber` |
| 5 | **Transparence de lecture** : `getter()` d'un fantôme non matérialisé est égal à `getter()` du parent. | `GhostNodeTrait::resolve()` |
| 6 | **Isolation d'écriture** : `setter()` d'un fantôme ne modifie jamais le parent. | `GhostNodeTrait` (les setters n'écrivent que `$this->...`). |
| 7 | **Réversibilité** : effacer une valeur locale (`null`) restaure la résolution dynamique. | Conséquence directe de la règle de résolution. |
| 8 | **Possession exclusive** : un élément créé directement par un fantôme (sans parent) ne peut être ni modifié ni supprimé par la racine. | À garantir par le code applicatif et l'UI. |

Chaque invariant est testé par une méthode dédiée dans `tests/Unit/GhostInvariantsTest.php`.

---

## 10. Quand utiliser ce pattern (et quand ne pas)

### Bons candidats

✅ **Gabarits / templates avec personnalisations** : un modèle partagé, des variations locales.

✅ **Configurations hiérarchiques** : global → équipe → utilisateur.

✅ **Partage d'état entre entités liées par une relation métier forte** : où la duplication briserait la sémantique.

✅ **Besoins de divergence partielle réversible** : où l'utilisateur doit pouvoir personnaliser, puis revenir au modèle d'un clic.

✅ **Volumes maîtrisés** : la résolution dynamique a un coût, raisonnable jusqu'à quelques milliers de fantômes par racine.

### Mauvais candidats

❌ **Entités totalement indépendantes** : pas de relation hiérarchique → pas de pattern.

❌ **Versions historiques figées** : la duplication est attendue, la propagation est dangereuse.

❌ **Domaines régulés (audit, compliance, finance)** : la propagation automatique des modifications est rarement acceptable. Préférer des copies explicites avec horodatage.

❌ **Très gros volumes avec lectures massives** : chaque getter peut déclencher une remontée de chaîne. Si tu lis 100 000 fantômes en boucle, tu paies 100 000 traversées.

❌ **Modèles à profondeur arborescente naturelle** (catégories, threads) : le pattern n'est pas conçu pour ça. Utilise un Tree NestedSet, Materialized Path, ou similaire.

### Règle de pouce

> *Si tu veux écrire « copie liée » ou « variant héritant du modèle », pense « fantôme ». Si tu veux écrire « copie indépendante », ne le fais pas.*

---

## 11. Pièges et anti-patterns

### 11.1. Oublier `nullable`

Un attribut fantomisable **doit** être `nullable` en base. Sinon, un fantôme purement transparent est rejeté à la persistence et le pattern casse.

```php
// ❌ Mauvais
#[ORM\Column(length: 255)]
#[GhostableField(required: true)]
private string $lieuDepart;

// ✅ Bon
#[ORM\Column(length: 255, nullable: true)]
#[GhostableField(required: true)]
private ?string $lieuDepart = null;
```

### 11.2. Validation Symfony aveugle

`#[Assert\NotBlank]` sur un attribut fantomisable casse les fantômes vides. Toujours utiliser `#[GhostableField(required: true)]` ou des contraintes conditionnelles.

### 11.3. N+1 queries

La résolution dynamique multiplie les accès au parent. Sur de grandes collections de fantômes :

- prévoir un **eager loading** sur la relation `parent` (fetch=EAGER ou jointure explicite) ;
- ou un **cache de requête** si les valeurs résolues sont lues massivement en lecture seule.

### 11.4. Sérialisation non triée

JSON, exports, logs : valeurs **résolues** ou valeurs **locales** ?

- **API publique et UI** → résolues (l'utilisateur veut voir ce qui s'applique).
- **Audit, logs, exports techniques** → locales (pour reconstituer l'historique des divergences).
- **Sauvegardes / migrations** → les deux, avec un format qui préserve la structure.

À trancher **avant** la mise en production. Sinon chaque endpoint inventera sa propre règle.

### 11.5. Égalité d'objets

Deux fantômes avec les mêmes valeurs résolues **ne sont pas égaux** s'ils divergent en interne (l'un local, l'autre hérité). Selon le contexte (cache, déduplication, comparaison), il faut décider quelle égalité on utilise.

### 11.6. Cloner un fantôme

Cloner un fantôme **avec** son `$parent` crée un autre fantôme du même parent. Cloner **sans** parent matérialise implicitement les valeurs (équivalent d'une incarnation), ce qui peut être surprenant. Préférer un appel explicite à `incarnate()` plutôt qu'un `clone`.

### 11.7. Modifier un attribut côté racine et s'attendre à une notification

La propagation est **paresseuse** : calculée à la lecture suivante. Aucun événement n'est émis pour notifier les fantômes d'un changement côté racine. Si ton domaine en a besoin (websocket, cache externe), il faudra l'implémenter explicitement.

---

## 12. Comparaison avec d'autres approches

### 12.1. Doctrine STI / CTI (Single/Class Table Inheritance)

L'héritage d'entités Doctrine est **statique** (déterminé à la définition de classe). Les arbres fantômes sont **dynamiques** (le lien parent est une donnée, pas une structure de code). Les deux ne résolvent pas le même problème.

### 12.2. Event Sourcing

L'event sourcing reconstruit l'état à partir d'une séquence d'événements. Il offre une traçabilité parfaite mais une complexité opérationnelle élevée. Les arbres fantômes sont **incomparablement plus simples** mais offrent une traçabilité limitée (on sait ce qui diverge, pas comment ni quand).

### 12.3. Copy-on-Write (Git, Datomic, ZFS)

Le copy-on-write duplique uniquement les blocs modifiés. Les arbres fantômes en sont une variante **au niveau attribut** dans le contexte des entités relationnelles. La filiation conceptuelle est claire, mais l'implémentation est radicalement différente (on n'a pas de structure d'arbre Merkle ici).

### 12.4. Configurations hiérarchiques (Spring, .NET, etc.)

Les frameworks de config supportent souvent l'héritage de configurations (override par environnement, par tenant…). Les arbres fantômes en sont la **généralisation au niveau base de données**, pour des données métier — pas seulement pour de la config.

---

## Pour aller plus loin

- [README](../README.md) — usage rapide, installation, exemple minimal.
- [cookbook.md](cookbook.md) *(à venir)* — recettes pratiques.
- [Tests d'invariants](../tests/Unit/GhostInvariantsTest.php) — code exécutable des invariants.

---

*Documentation rédigée pour la version 1.0 du bundle.*
