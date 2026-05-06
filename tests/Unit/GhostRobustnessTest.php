<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Tests\Unit;

use EricGansa\GhostTreesBundle\Exception\GhostCycleException;
use EricGansa\GhostTreesBundle\Incarnator\GhostIncarnator;
use EricGansa\GhostTreesBundle\Inspector\GhostInspector;
use EricGansa\GhostTreesBundle\Metadata\GhostMetadata;
use EricGansa\GhostTreesBundle\Tests\Fixtures\Entity\FakeTrajet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests de robustesse — scénarios adverses et limites du système.
 *
 * ─── Couverture ────────────────────────────────────────────────────────────
 *  - Cycles dans les données (manipulation SQL directe simulée par réflexion)
 *  - Double exécution d'incarnation
 *  - Double exécution de reset()
 *  - Chaîne null complète (pas de valeur dans toute la hiérarchie)
 *  - Incarnation d'un fantôme dont le parent est lui-même un fantôme (chaîne 2 niveaux)
 */
final class GhostRobustnessTest extends TestCase
{
    private GhostMetadata $metadata;
    private GhostIncarnator $incarnator;
    private GhostInspector $inspector;

    protected function setUp(): void
    {
        $this->metadata = new GhostMetadata();
        $this->incarnator = new GhostIncarnator($this->metadata, new EventDispatcher());
        $this->inspector = new GhostInspector($this->metadata);
    }

    // ─── Cycles en données corrompues ─────────────────────────────────────

    /**
     * Simule une corruption SQL : A.parent = B, B.parent = A.
     * GhostIncarnator::incarnate() DOIT lever GhostCycleException.
     */
    public function testIncarnatorThrowsOnCorruptCycleChain(): void
    {
        $a = new FakeTrajet();
        $b = new FakeTrajet();

        $rp = new \ReflectionProperty(FakeTrajet::class, 'parent');
        $rp->setValue($a, $b);
        $rp->setValue($b, $a);

        $this->expectException(GhostCycleException::class);
        $this->incarnator->incarnate($a);
    }

    /**
     * debugResolution() ne doit pas boucler sur un cycle corrompu.
     * Le résultat doit signaler "cycle_detected" sur toutes les propriétés.
     */
    public function testInspectorReportsCycleDetectedOnCorruptData(): void
    {
        $a = new FakeTrajet();
        $b = new FakeTrajet();

        $rp = new \ReflectionProperty(FakeTrajet::class, 'parent');
        $rp->setValue($a, $b);
        $rp->setValue($b, $a);

        $result = $this->inspector->debugResolution($a);

        $this->assertNotEmpty($result);

        foreach ($result as $info) {
            $this->assertSame('cycle_detected', $info['source']);
        }
    }

    /**
     * Le trait incarnate() utilise une traversée par réflexion avec SplObjectStorage.
     * Sur une chaîne corrompue A→B→A, le cycle est détecté et lève GhostCycleException.
     *
     * Ce comportement est identique à GhostIncarnator : les deux détectent les cycles
     * via SplObjectStorage et lèvent la même exception.
     */
    public function testTraitIncarnateOnCorruptCycleThrowsGhostCycleException(): void
    {
        $a = new FakeTrajet();
        $b = new FakeTrajet();

        $rp = new \ReflectionProperty(FakeTrajet::class, 'parent');
        $rp->setValue($a, $b);
        $rp->setValue($b, $a);

        $this->expectException(GhostCycleException::class);
        $a->incarnate();
    }

    // ─── Double exécution ─────────────────────────────────────────────────

    /**
     * Appeler incarnate() deux fois de suite est idempotent.
     * La seconde exécution est un no-op (l'entité est déjà une racine).
     */
    public function testIncarnatorDoubleIncarnateIsIdempotent(): void
    {
        $root = (new FakeTrajet())->setLieuDepart('Paris')->setLieuArrivee('Lyon');
        $ghost = (new FakeTrajet())->setParent($root)->setLieuArrivee('Marseille');

        $this->incarnator->incarnate($ghost);
        // Seconde exécution : l'entité est déjà une racine.
        $this->incarnator->incarnate($ghost);

        $this->assertNull($ghost->getParent());
        $this->assertSame('Paris', $ghost->getLieuDepart());
        $this->assertSame('Marseille', $ghost->getLieuArrivee());
    }

    public function testTraitDoubleIncarnateIsIdempotent(): void
    {
        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $ghost = (new FakeTrajet())->setParent($root);

        $ghost->incarnate();
        $ghost->incarnate(); // no-op

        $this->assertNull($ghost->getParent());
        $this->assertSame('Paris', $ghost->getLieuDepart());
    }

    public function testTraitDoubleResetIsIdempotent(): void
    {
        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $ghost = (new FakeTrajet())->setParent($root)->setLieuDepart('Lyon');

        $ghost->reset();
        $ghost->reset(); // no-op — déjà null

        $this->assertSame($root, $ghost->getParent());
        $this->assertSame('Paris', $ghost->getLieuDepart());
    }

    // ─── Chaîne sans valeur ───────────────────────────────────────────────

    public function testIncarnatorGhostWithNoValuesAnywhereStaysWithNulls(): void
    {
        $root = new FakeTrajet(); // aucune valeur
        $ghost = (new FakeTrajet())->setParent($root);

        $this->incarnator->incarnate($ghost);

        $this->assertNull($ghost->getParent());
        $this->assertNull($ghost->getLieuDepart());
        $this->assertNull($ghost->getLieuArrivee());
        $this->assertNull($ghost->getMoyenTransport());
    }

    // ─── Chaîne multi-niveaux (max_depth > 1) ────────────────────────────

    /**
     * GhostIncarnator remonte toute la chaîne pour trouver la valeur.
     * Ici : grand-parent → parent (sans valeur) → fantôme.
     * La valeur du grand-parent doit être matérialisée.
     */
    public function testIncarnatorMaterializesValueFromGrandparent(): void
    {
        $grandParent = (new FakeTrajet())->setLieuDepart('Paris');
        $parent = (new FakeTrajet())->setParent($grandParent); // pas de lieuDepart local
        $ghost = (new FakeTrajet())->setParent($parent);       // pas de lieuDepart local

        $this->incarnator->incarnate($ghost);

        $this->assertNull($ghost->getParent());
        $this->assertSame('Paris', $ghost->getLieuDepart(), 'Valeur remontée depuis le grand-parent.');
    }

    public function testTraitIncarnateResolvesFromGrandparent(): void
    {
        $grandParent = (new FakeTrajet())->setLieuDepart('Paris');
        $parent = (new FakeTrajet())->setParent($grandParent);
        $ghost = (new FakeTrajet())->setParent($parent);

        $ghost->incarnate();

        $this->assertNull($ghost->getParent());
        $this->assertSame('Paris', $ghost->getLieuDepart());
    }

    // ─── Concurrence simulée ──────────────────────────────────────────────

    /**
     * Simulation de lecture concurrente : deux entités lisent le même parent
     * simultanément. La résolution est sans état (aucun verrou, aucun flag) :
     * les deux lectures renvoient la même valeur. Aucune condition de course
     * sur la lecture pure.
     *
     * LIMITE CONNUE : les écritures concurrentes (deux incarnations simultanées
     * sur la même entité en base) ne sont pas protégées au niveau applicatif.
     * Cela requiert un verrou pessimiste Doctrine (PESSIMISTIC_WRITE) ou un
     * verrou optimiste (@Version). Voir docs/security-quality.md.
     */
    public function testConcurrentReadAccessNeitherReadMutatesState(): void
    {
        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $ghost1 = (new FakeTrajet())->setParent($root);
        $ghost2 = (new FakeTrajet())->setParent($root);

        // Lecture depuis deux fantômes différents sur le même parent.
        $val1 = $ghost1->getLieuDepart();
        $val2 = $ghost2->getLieuDepart();

        $this->assertSame('Paris', $val1);
        $this->assertSame('Paris', $val2);

        // Aucune mutation sur le parent.
        $this->assertSame('Paris', $root->getLieuDepart());
    }

    /**
     * Simulation de double-incarnation concurrente :
     * deux appels incarnate() sur la même entité en mémoire.
     * Le second appel est un no-op car le parent est déjà null après le premier.
     */
    public function testConcurrentDoubleIncarnationSecondCallIsNoOp(): void
    {
        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $ghost = (new FakeTrajet())->setParent($root);

        $incarnator1 = new GhostIncarnator($this->metadata, new EventDispatcher());
        $incarnator2 = new GhostIncarnator($this->metadata, new EventDispatcher());

        // Simule deux exécutions séquentielles (le vrai parallélisme n'est pas
        // possible en PHP synchrone, mais ce test vérifie l'idempotence).
        $incarnator1->incarnate($ghost);
        $incarnator2->incarnate($ghost); // no-op, $ghost->isGhost() === false

        $this->assertNull($ghost->getParent());
        $this->assertSame('Paris', $ghost->getLieuDepart());
    }
}
