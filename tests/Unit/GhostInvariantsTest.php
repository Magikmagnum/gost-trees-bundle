<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Tests\Unit;

use EricGansa\GhostTreesBundle\Resolver\GhostResolver;
use EricGansa\GhostTreesBundle\Tests\Fixtures\Entity\FakeTrajet;
use PHPUnit\Framework\TestCase;

/**
 * Tests des invariants fondamentaux du pattern.
 *
 * Chaque méthode teste un et un seul invariant, nommé explicitement
 * selon la convention testInvariant_<nom>().
 */
final class GhostInvariantsTest extends TestCase
{
    public function testInvariant_GhostReadsFromParentWhenNotMaterialized(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $this->assertSame('Paris', $fantome->getLieuDepart(), 'Un fantôme non matérialisé doit lire la valeur du parent.');
    }

    public function testInvariant_MaterializedValueShadowsParent(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuDepart('Lyon');

        $this->assertSame('Lyon', $fantome->getLieuDepart(), 'Une valeur locale doit faire écran à la valeur parente.');
    }

    public function testInvariant_DematerializationRestoresTransparency(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuDepart('Lyon');

        $fantome->setLieuDepart(null);

        $this->assertSame('Paris', $fantome->getLieuDepart(), 'Effacer la valeur locale doit restaurer la résolution dynamique.');
    }

    public function testInvariant_WriteIsolation(): void
    {
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $fantome->setLieuDepart('Lyon');

        $this->assertSame('Paris', $racine->getLieuDepart(), 'Modifier un fantôme ne doit jamais affecter le parent.');
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

        $this->assertSame('Bordeaux', $fantome->getLieuDepart(), 'Une modification du parent doit se propager aux fantômes non matérialisés.');
    }

    public function testIsMaterialized_ReturnsFalseForRoots(): void
    {
        $resolver = new GhostResolver(maxDepth: 1);
        $racine = (new FakeTrajet())->setLieuDepart('Paris');

        $this->assertFalse($resolver->isMaterialized($racine), 'Une racine n\'est jamais "matérialisée" au sens fantôme.');
    }

    public function testIsMaterialized_ReturnsFalseForTransparentGhost(): void
    {
        $resolver = new GhostResolver(maxDepth: 1);
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine);

        $this->assertFalse($resolver->isMaterialized($fantome));
    }

    public function testIsMaterialized_ReturnsTrueForDivergedGhost(): void
    {
        $resolver = new GhostResolver(maxDepth: 1);
        $racine = (new FakeTrajet())->setLieuDepart('Paris');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuDepart('Lyon');

        $this->assertTrue($resolver->isMaterialized($fantome));
    }

    public function testDebugResolution_ReportsLocalAndInheritedSources(): void
    {
        $resolver = new GhostResolver(maxDepth: 1);
        $racine = (new FakeTrajet())->setLieuDepart('Paris')->setLieuArrivee('Lyon');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuArrivee('Marseille');

        $debug = $resolver->debugResolution($fantome);

        $this->assertSame('inherited', $debug['lieuDepart']['source']);
        $this->assertSame('Paris', $debug['lieuDepart']['value']);
        $this->assertSame('local', $debug['lieuArrivee']['source']);
        $this->assertSame('Marseille', $debug['lieuArrivee']['value']);
    }

    public function testIncarnate_MaterializesAllInheritedValuesAndDetaches(): void
    {
        $resolver = new GhostResolver(maxDepth: 1);
        $racine = (new FakeTrajet())
            ->setLieuDepart('Paris')
            ->setLieuArrivee('Lyon')
            ->setMoyenTransport('TGV');
        $fantome = (new FakeTrajet())->setParent($racine)->setLieuArrivee('Marseille');

        $resolver->incarnate($fantome);

        $this->assertNull($fantome->getParent(), 'Après incarnation, le fantôme ne doit plus avoir de parent.');
        $this->assertSame('Paris', $fantome->getLieuDepart(), 'Les valeurs héritées doivent être matérialisées localement.');
        $this->assertSame('Marseille', $fantome->getLieuArrivee(), 'Les valeurs déjà locales sont conservées.');
        $this->assertSame('TGV', $fantome->getMoyenTransport());
    }
}
