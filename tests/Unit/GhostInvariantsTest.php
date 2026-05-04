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

    public function testInvariant_GhostReadsFromParentWhenNotMaterialized(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $this->assertSame('Paris', $fantome->getLieuDepart());
    }

    public function testInvariant_MaterializedValueShadowsParent(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuDepart('Lyon');

        $this->assertSame('Lyon', $fantome->getLieuDepart());
    }

    public function testInvariant_DematerializationRestoresTransparency(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuDepart('Lyon');

        $fantome->setLieuDepart(null);

        $this->assertSame('Paris', $fantome->getLieuDepart());
    }

    public function testInvariant_WriteIsolation(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $fantome->setLieuDepart('Lyon');

        $this->assertSame('Paris', $racine->getLieuDepart());
    }

    public function testInvariant_PartialMaterializationIsGranular(): void
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

    public function testInvariant_ParentChangePropagatesToTransparentGhosts(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $racine->setLieuDepart('Bordeaux');

        $this->assertSame('Bordeaux', $fantome->getLieuDepart());
    }

    // ─── Inspector ────────────────────────────────────────────────────

    public function testInspector_IsMaterialized_ReturnsFalseForRoots(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');

        $this->assertFalse($this->inspector->isMaterialized($racine));
    }

    public function testInspector_IsMaterialized_ReturnsFalseForTransparentGhost(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $this->assertFalse($this->inspector->isMaterialized($fantome));
    }

    public function testInspector_IsMaterialized_ReturnsTrueForDivergedGhost(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuDepart('Lyon');

        $this->assertTrue($this->inspector->isMaterialized($fantome));
    }

    public function testInspector_DebugResolution_ReportsLocalAndInheritedSources(): void
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

    public function testIncarnator_MaterializesAllInheritedValuesAndDetaches(): void
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

    public function testIncarnator_NoOpOnRoot(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');

        $this->incarnator->incarnate($racine);

        $this->assertNull($racine->getParent());
        $this->assertSame('Paris', $racine->getLieuDepart());
    }

    public function testIncarnator_DispatchesEvent(): void
    {
        $dispatched = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            \EricGansa\GhostTreesBundle\Event\GhostIncarnatedEvent::class,
            function ($event) use (&$dispatched) {
                $dispatched[] = $event;
            }
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

    public function testResolver_AllowsNullParent(): void
    {
        $entity = new FakeTrajet();
        $this->expectNotToPerformAssertions();

        $this->resolver->assertValidParent($entity, null);
    }

    public function testResolver_RejectsSelfAsParent(): void
    {
        $entity = new FakeTrajet();

        $this->expectException(GhostCycleException::class);
        $this->resolver->assertValidParent($entity, $entity);
    }

    public function testResolver_RejectsDepthOverflow(): void
    {
        // max_depth=1 : grandparent → parent → child est interdit.
        $resolver = new GhostResolver(maxDepth: 1);

        $grandparent = new FakeTrajet();
        $parent = (new FakeTrajet())->setParent($grandparent);
        $child = new FakeTrajet();

        $this->expectException(GhostDepthExceededException::class);
        $resolver->assertValidParent($child, $parent);
    }

    public function testResolver_AllowsConfiguredDepth(): void
    {
        $resolver = new GhostResolver(maxDepth: 2);

        $grandparent = new FakeTrajet();
        $parent = (new FakeTrajet())->setParent($grandparent);
        $child = new FakeTrajet();

        $this->expectNotToPerformAssertions();
        $resolver->assertValidParent($child, $parent);
    }

    public function testResolver_RejectsCycle(): void
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

    // ─── Métadonnées ──────────────────────────────────────────────────

    public function testMetadata_DiscoversGhostableProperties(): void
    {
        $properties = $this->metadata->getProperties(FakeTrajet::class);
        $names = array_map(static fn ($p) => $p->name, $properties);

        $this->assertContains('lieuDepart', $names);
        $this->assertContains('lieuArrivee', $names);
        $this->assertContains('moyenTransport', $names);
    }

    public function testMetadata_CachesByClass(): void
    {
        $first = $this->metadata->getProperties(FakeTrajet::class);
        $second = $this->metadata->getProperties(FakeTrajet::class);

        // Même instances renvoyées (cache effectif).
        $this->assertSame($first, $second);
    }
}
