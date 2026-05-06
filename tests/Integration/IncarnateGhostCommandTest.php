<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use EricGansa\GhostTreesBundle\Command\IncarnateGhostCommand;
use EricGansa\GhostTreesBundle\Contract\GhostIncarnatorInterface;
use EricGansa\GhostTreesBundle\Handler\IncarnateGhostHandler;
use EricGansa\GhostTreesBundle\Tests\Fixtures\Entity\FakeTrajet;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests d'intégration de IncarnateGhostCommand.
 *
 * La commande est le seul point d'entrée CLI pour incarner un fantôme.
 * C'est une opération irréversible — chaque branche de validation
 * et d'exécution doit être couverte.
 */
final class IncarnateGhostCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private GhostIncarnatorInterface&MockObject $incarnator;
    private IncarnateGhostHandler $handler;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->incarnator = $this->createMock(GhostIncarnatorInterface::class);
        $this->handler = new IncarnateGhostHandler($this->incarnator, $this->em);

        $command = new IncarnateGhostCommand($this->em, $this->handler);
        $this->tester = new CommandTester($command);
    }

    public function testExecuteFailsWhenClassDoesNotExist(): void
    {
        $this->em->expects($this->never())->method('find');

        $this->tester->execute([
            'class' => 'App\Entity\NonExistentClass',
            'id' => '1',
        ]);

        $this->assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $this->assertStringContainsString('introuvable', $this->tester->getDisplay());
    }

    public function testExecuteFailsWhenEntityNotFound(): void
    {
        $this->em->method('find')
            ->with(FakeTrajet::class, '42')
            ->willReturn(null);

        $this->incarnator->expects($this->never())->method('incarnate');

        $this->tester->execute([
            'class' => FakeTrajet::class,
            'id' => '42',
        ]);

        $this->assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $this->assertStringContainsString('Aucune entité', $this->tester->getDisplay());
    }

    public function testExecuteFailsWhenEntityIsNotGhostable(): void
    {
        $notGhostable = new \stdClass();
        $this->em->method('find')->willReturn($notGhostable);

        $this->incarnator->expects($this->never())->method('incarnate');

        $this->tester->execute([
            'class' => \stdClass::class,
            'id' => '1',
        ]);

        $this->assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $this->assertStringContainsString('GhostableInterface', $this->tester->getDisplay());
    }

    public function testExecuteWarnsWhenEntityIsAlreadyARoot(): void
    {
        $root = (new FakeTrajet())->setLieuDepart('Paris'); // pas de parent → racine

        $this->em->method('find')->willReturn($root);
        $this->incarnator->expects($this->never())->method('incarnate');

        $this->tester->execute([
            'class' => FakeTrajet::class,
            'id' => '1',
        ]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $this->assertStringContainsString('déjà une racine', $this->tester->getDisplay());
    }

    public function testExecuteAbortsWhenUserDeniesConfirmation(): void
    {
        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $ghost = (new FakeTrajet())->setParent($root);

        $this->em->method('find')->willReturn($ghost);
        $this->incarnator->expects($this->never())->method('incarnate');

        // Répondre "no" à la confirmation interactive.
        $this->tester->setInputs(['no']);
        $this->tester->execute([
            'class' => FakeTrajet::class,
            'id' => '1',
        ]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $this->assertStringContainsString('Annulé', $this->tester->getDisplay());
    }

    public function testExecuteSucceedsWhenGhostConfirmedAndIncarnated(): void
    {
        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $ghost = (new FakeTrajet())->setParent($root);

        $this->em->method('find')->willReturn($ghost);

        // wrapInTransaction doit appeler le callback fourni.
        $this->em->method('wrapInTransaction')
            ->willReturnCallback(static function (callable $callback): void {
                $callback();
            });

        $this->incarnator->expects($this->once())->method('incarnate')->with($ghost);
        $this->em->expects($this->once())->method('flush');

        // Répondre "yes" à la confirmation interactive.
        $this->tester->setInputs(['yes']);
        $this->tester->execute([
            'class' => FakeTrajet::class,
            'id' => '1',
        ]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $this->assertStringContainsString('incarnée avec succès', $this->tester->getDisplay());
    }

    public function testExecuteFlushIsCalledInsideTransaction(): void
    {
        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $ghost = (new FakeTrajet())->setParent($root);

        $this->em->method('find')->willReturn($ghost);

        // Vérification que flush() est bien appelé à l'intérieur du callback
        // de wrapInTransaction(), et non en dehors.
        $callOrder = [];
        $this->em->method('wrapInTransaction')
            ->willReturnCallback(static function (callable $callback) use (&$callOrder): void {
                $callOrder[] = 'transaction_start';
                $callback();
                $callOrder[] = 'transaction_end';
            });

        $this->incarnator->method('incarnate')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'incarnate';
            });

        $this->em->method('flush')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'flush';
            });

        $this->tester->setInputs(['yes']);
        $this->tester->execute([
            'class' => FakeTrajet::class,
            'id' => '1',
        ]);

        // incarnate → flush doivent être entre transaction_start et transaction_end.
        $this->assertSame(
            ['transaction_start', 'incarnate', 'flush', 'transaction_end'],
            $callOrder,
            'flush() doit être appelé à l\'intérieur de wrapInTransaction(), après incarnate().',
        );
    }
}
