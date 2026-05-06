<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Tests\Unit;

use EricGansa\GhostTreesBundle\Exception\GhostCycleException;
use EricGansa\GhostTreesBundle\Exception\GhostDepthExceededException;
use EricGansa\GhostTreesBundle\Incarnator\GhostIncarnator;
use EricGansa\GhostTreesBundle\Inspector\GhostInspector;
use EricGansa\GhostTreesBundle\Metadata\GhostMetadata;
use EricGansa\GhostTreesBundle\Resolver\GhostResolver;
use EricGansa\GhostTreesBundle\Tests\Fixtures\Entity\FakeTrajet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests des invariants fondamentaux du pattern.
 *
 * Chaque méthode teste un et un seul invariant, nommé selon la convention
 * testInvariant_<nom>().
 */
final class GhostInvariantsTest extends TestCase
{
    private GhostMetadata $metadata;
    private GhostInspector $inspector;
    private GhostIncarnator $incarnator;
    private GhostResolver $resolver;

    protected function setUp(): void
    {
        $this->metadata = new GhostMetadata();
        $this->inspector = new GhostInspector($this->metadata);
        $this->incarnator = new GhostIncarnator($this->metadata, new EventDispatcher());
        $this->resolver = new GhostResolver(maxDepth: 1);
    }

    // ─── Résolution ───────────────────────────────────────────────────

    public function testInvariantGhostReadsFromParentWhenNotMaterialized(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $this->assertSame('Paris', $fantome->getLieuDepart());
    }

    public function testInvariantMaterializedValueShadowsParent(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuDepart('Lyon');

        $this->assertSame('Lyon', $fantome->getLieuDepart());
    }

    public function testInvariantDematerializationRestoresTransparency(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuDepart('Lyon');

        $fantome->setLieuDepart(null);

        $this->assertSame('Paris', $fantome->getLieuDepart());
    }

    public function testInvariantWriteIsolation(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $fantome->setLieuDepart('Lyon');

        $this->assertSame('Paris', $racine->getLieuDepart());
    }

    public function testInvariantPartialMaterializationIsGranular(): void
    {
        $racine = (new FakeTrajet())
            ->setLieuDepart('Paris')
            ->setLieuArrivee('Lyon')
            ->setMoyenTransport('TGV');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuArrivee('Marseille');

        $this->assertSame('Paris', $fantome->getLieuDepart());
        $this->assertSame('Marseille', $fantome->getLieuArrivee());
        $this->assertSame('TGV', $fantome->getMoyenTransport());
    }

    public function testInvariantParentChangePropagatesToTransparentGhosts(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $racine->setLieuDepart('Bordeaux');

        $this->assertSame('Bordeaux', $fantome->getLieuDepart());
    }

    // ─── Inspector ────────────────────────────────────────────────────

    public function testInspectorIsMaterializedReturnsFalseForRoots(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');

        $this->assertFalse($this->inspector->isMaterialized($racine));
    }

    public function testInspectorIsMaterializedReturnsFalseForTransparentGhost(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $this->assertFalse($this->inspector->isMaterialized($fantome));
    }

    public function testInspectorIsMaterializedReturnsTrueForDivergedGhost(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuDepart('Lyon');

        $this->assertTrue($this->inspector->isMaterialized($fantome));
    }

    public function testInspectorDebugResolutionReportsLocalAndInheritedSources(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris')->setLieuArrivee('Lyon');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuArrivee('Marseille');

        $debug = $this->inspector->debugResolution($fantome);

        $this->assertSame('inherited', $debug['lieuDepart']['source']);
        $this->assertSame('Paris', $debug['lieuDepart']['value']);
        $this->assertSame('local', $debug['lieuArrivee']['source']);
        $this->assertSame('Marseille', $debug['lieuArrivee']['value']);
    }

    // ─── Incarnator ───────────────────────────────────────────────────

    public function testIncarnatorMaterializesAllInheritedValuesAndDetaches(): void
    {
        $racine = (new FakeTrajet())
            ->setLieuDepart('Paris')
            ->setLieuArrivee('Lyon')
            ->setMoyenTransport('TGV');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuArrivee('Marseille');

        $this->incarnator->incarnate($fantome);

        $this->assertNull($fantome->getParent());
        $this->assertSame('Paris', $fantome->getLieuDepart());
        $this->assertSame('Marseille', $fantome->getLieuArrivee());
        $this->assertSame('TGV', $fantome->getMoyenTransport());
    }

    public function testIncarnatorNoOpOnRoot(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');

        $this->incarnator->incarnate($racine);

        $this->assertNull($racine->getParent());
        $this->assertSame('Paris', $racine->getLieuDepart());
    }

    public function testIncarnatorDispatchesEvent(): void
    {
        $dispatched = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            \EricGansa\GhostTreesBundle\Event\GhostIncarnatedEvent::class,
            static function ($event) use (&$dispatched): void {
                $dispatched[] = $event;
            },
        );

        $incarnator = new GhostIncarnator($this->metadata, $dispatcher);

        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $incarnator->incarnate($fantome);

        $this->assertCount(1, $dispatched);
        $this->assertSame($fantome, $dispatched[0]->entity);
        $this->assertSame($racine, $dispatched[0]->previousParent);
    }

    // ─── Resolver — validation structurelle ──────────────────────────

    public function testResolverAllowsNullParent(): void
    {
        $entity = new FakeTrajet();
        $this->expectNotToPerformAssertions();

        $this->resolver->assertValidParent($entity, null);
    }

    public function testResolverRejectsSelfAsParent(): void
    {
        $entity = new FakeTrajet();

        $this->expectException(GhostCycleException::class);
        $this->resolver->assertValidParent($entity, $entity);
    }

    public function testResolverRejectsDepthOverflow(): void
    {
        // max_depth=1 : grandparent → parent → child est interdit.
        $resolver = new GhostResolver(maxDepth: 1);

        $grandparent = new FakeTrajet();
        $parent = (new FakeTrajet())->setParent($grandparent);
        $child = new FakeTrajet();

        $this->expectException(GhostDepthExceededException::class);
        $resolver->assertValidParent($child, $parent);
    }

    public function testResolverAllowsConfiguredDepth(): void
    {
        $resolver = new GhostResolver(maxDepth: 2);

        $grandparent = new FakeTrajet();
        $parent = (new FakeTrajet())->setParent($grandparent);
        $child = new FakeTrajet();

        $this->expectNotToPerformAssertions();
        $resolver->assertValidParent($child, $parent);
    }

    public function testResolverRejectsCycle(): void
    {
        $a = new FakeTrajet();
        $b = (new FakeTrajet())->setParent($a);

        // Tenter de mettre $b comme parent de $a créerait un cycle.
        $this->expectException(GhostCycleException::class);
        $this->resolver->assertValidParent($a, $b);
    }

    // ─── Trait / Resolver — équivalence ──────────────────────────────

    public function testTraitAndResolverAgree(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        // Le trait passe par sa propre logique (résolution locale).
        $valeurViaTrait = $fantome->getLieuDepart();

        // Le resolver passe par la sienne.
        $valeurViaResolver = $this->resolver->resolve($fantome, null, 'getLieuDepart');

        $this->assertSame($valeurViaTrait, $valeurViaResolver);
    }

    // ─── Corrections de régression ────────────────────────────────────

    /**
     * Correction #2 — setParent() doit rejeter l'auto-référence directe.
     */
    public function testTraitSetParentRejectsSelfReference(): void
    {
        $entity = new FakeTrajet();

        $this->expectException(GhostCycleException::class);
        $entity->setParent($entity);
    }

    /**
     * Correction #4 — resolveFromAncestors() doit lever GhostCycleException
     * sur une chaîne corrompue en base (cycle A→B→A simulé par réflexion).
     */
    public function testIncarnatorThrowsCycleExceptionOnCorruptChain(): void
    {
        $a = new FakeTrajet(); // lieuDepart = null (doit remonter)
        $b = new FakeTrajet(); // lieuDepart = null (aucune valeur à propager)

        // Simule une corruption SQL : A.parent = B, B.parent = A
        $rp = new \ReflectionProperty(FakeTrajet::class, 'parent');
        $rp->setValue($a, $b);
        $rp->setValue($b, $a);

        $this->expectException(GhostCycleException::class);
        $this->incarnator->incarnate($a);
    }

    /**
     * Correction #5 — debugResolution() ne doit pas boucler indéfiniment
     * sur des données corrompues ; il doit retourner source='cycle_detected'.
     */
    public function testDebugResolutionHandlesCycleInCorruptData(): void
    {
        $a = new FakeTrajet();
        $b = new FakeTrajet();

        $rp = new \ReflectionProperty(FakeTrajet::class, 'parent');
        $rp->setValue($a, $b);
        $rp->setValue($b, $a);

        $result = $this->inspector->debugResolution($a);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        foreach ($result as $propertyName => $info) {
            $this->assertSame(
                'cycle_detected',
                $info['source'],
                \sprintf('La propriété "%s" devrait signaler un cycle.', $propertyName),
            );
        }
    }

    // ─── Trait — incarnate() ──────────────────────────────────────────

    public function testTraitIncarnateMaterializesAndDetaches(): void
    {
        $racine = (new FakeTrajet())
            ->setLieuDepart('Paris')
            ->setLieuArrivee('Lyon')
            ->setMoyenTransport('TGV');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuArrivee('Marseille');

        $fantome->incarnate();

        $this->assertNull($fantome->getParent(), 'incarnate() doit couper le lien parent.');
        $this->assertSame('Paris', $fantome->getLieuDepart(), 'valeur héritée matérialisée.');
        $this->assertSame('Marseille', $fantome->getLieuArrivee(), 'valeur locale conservée.');
        $this->assertSame('TGV', $fantome->getMoyenTransport(), 'valeur héritée matérialisée.');
    }

    public function testTraitIncarnateNoOpOnRoot(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');

        $racine->incarnate();

        $this->assertNull($racine->getParent());
        $this->assertSame('Paris', $racine->getLieuDepart());
    }

    public function testTraitIncarnateReturnsFluent(): void
    {
        $fantome = (new FakeTrajet())->setParent(new FakeTrajet());

        $this->assertSame($fantome, $fantome->incarnate());
    }

    // ─── Trait — reset() ──────────────────────────────────────────────

    public function testTraitResetClearsLocalValuesAndKeepsParent(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris')->setLieuArrivee('Lyon');
        $fantome = (new FakeTrajet())->setParent($racine)
            ->setLieuDepart('Bordeaux')
            ->setLieuArrivee('Marseille');

        $fantome->reset();

        // Lien parent conservé.
        $this->assertSame($racine, $fantome->getParent());
        // Résolution redevient transparente.
        $this->assertSame('Paris', $fantome->getLieuDepart());
        $this->assertSame('Lyon', $fantome->getLieuArrivee());
    }

    public function testTraitResetOnRootLeavesSemanticsIntact(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');

        $racine->reset();

        // Toujours une racine (pas de parent).
        $this->assertNull($racine->getParent());
        // Mais sans valeur locale maintenant.
        $this->assertNull($racine->getLieuDepart());
    }

    public function testTraitResetReturnsFluent(): void
    {
        $entity = new FakeTrajet();

        $this->assertSame($entity, $entity->reset());
    }

    public function testTraitIncarnateAfterResetRestoresIndependence(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris')->setLieuArrivee('Lyon');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuDepart('Bordeaux');

        // Annule les surcharges, repasse en transparent.
        $fantome->reset();
        $this->assertSame('Paris', $fantome->getLieuDepart());

        // Puis incarne → devient racine autonome avec les valeurs du parent.
        $fantome->incarnate();
        $this->assertNull($fantome->getParent());
        $this->assertSame('Paris', $fantome->getLieuDepart());
        $this->assertSame('Lyon', $fantome->getLieuArrivee());
    }

    // ─── Trait — createGhostOf() ──────────────────────────────────────

    public function testTraitCreateGhostOfProducesTransparentGhost(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');

        $ghost = FakeTrajet::createGhostOf($racine);

        $this->assertSame($racine, $ghost->getParent());
        $this->assertTrue($ghost->isGhost());
        $this->assertSame('Paris', $ghost->getLieuDepart(), 'Le fantôme doit lire depuis le parent.');
    }

    // ─── Métadonnées ──────────────────────────────────────────────────

    public function testMetadataDiscoversGhostableProperties(): void
    {
        $properties = $this->metadata->getProperties(FakeTrajet::class);
        $names = array_map(static fn ($p) => $p->name, $properties);

        $this->assertContains('lieuDepart', $names);
        $this->assertContains('lieuArrivee', $names);
        $this->assertContains('moyenTransport', $names);
    }

    public function testMetadataCachesByClass(): void
    {
        $first = $this->metadata->getProperties(FakeTrajet::class);
        $second = $this->metadata->getProperties(FakeTrajet::class);

        // Même instances renvoyées (cache effectif).
        $this->assertSame($first, $second);
    }
}
