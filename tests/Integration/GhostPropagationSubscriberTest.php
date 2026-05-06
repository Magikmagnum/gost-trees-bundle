<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use EricGansa\GhostTreesBundle\Contract\GhostIncarnatorInterface;
use EricGansa\GhostTreesBundle\Event\GhostAffiliatedEvent;
use EricGansa\GhostTreesBundle\EventSubscriber\GhostPropagationSubscriber;
use EricGansa\GhostTreesBundle\Tests\Fixtures\Entity\FakeTrajet;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests d'intégration du GhostPropagationSubscriber.
 *
 * Note sur les classes Doctrine finales (ORM 3.x) :
 *  - PreRemoveEventArgs, OnFlushEventArgs, PersistentCollection sont TOUTES final.
 *  - PreRemoveEventArgs et OnFlushEventArgs sont instanciées directement.
 *  - PersistentCollection est remplacée par un stub anonyme ayant les méthodes
 *    getOwner() et getInsertDiff() utilisées par le subscriber.
 */
final class GhostPropagationSubscriberTest extends TestCase
{
    private GhostIncarnatorInterface&MockObject $incarnator;
    private EntityManagerInterface&MockObject $em;
    private UnitOfWork&MockObject $uow;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->incarnator = $this->createMock(GhostIncarnatorInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->uow = $this->createMock(UnitOfWork::class);
        $this->dispatcher = new EventDispatcher();
        $this->em->method('getUnitOfWork')->willReturn($this->uow);
    }

    // ─── preRemove — mode incarnate ────────────────────────────────────────

    public function testPreRemoveIncarnatesGhostsOfRootWhenModeIsIncarnate(): void
    {
        $subscriber = $this->makeSubscriber(onRootDelete: 'incarnate');

        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $ghost1 = (new FakeTrajet())->setParent($root);
        $ghost2 = (new FakeTrajet())->setParent($root);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->with(['parent' => $root])->willReturn([$ghost1, $ghost2]);
        $this->em->method('getRepository')->willReturn($repo);

        $this->incarnator->expects($this->exactly(2))
            ->method('incarnate')
            ->with($this->logicalOr(
                $this->identicalTo($ghost1),
                $this->identicalTo($ghost2),
            ));

        $subscriber->preRemove(new PreRemoveEventArgs($root, $this->em));
    }

    public function testPreRemoveDoesNotIncarnateWhenModeIsCascade(): void
    {
        $subscriber = $this->makeSubscriber(onRootDelete: 'cascade');

        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $this->incarnator->expects($this->never())->method('incarnate');

        $subscriber->preRemove(new PreRemoveEventArgs($root, $this->em));
    }

    public function testPreRemoveNoOpWhenEntityIsNotGhostable(): void
    {
        $subscriber = $this->makeSubscriber(onRootDelete: 'incarnate');

        $this->incarnator->expects($this->never())->method('incarnate');

        $subscriber->preRemove(new PreRemoveEventArgs(new \stdClass(), $this->em));
    }

    public function testPreRemoveNoOpWhenEntityIsGhost(): void
    {
        $subscriber = $this->makeSubscriber(onRootDelete: 'incarnate');

        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $ghost = (new FakeTrajet())->setParent($root);

        $this->incarnator->expects($this->never())->method('incarnate');

        $subscriber->preRemove(new PreRemoveEventArgs($ghost, $this->em));
    }

    public function testPreRemoveIncarnatesZeroGhostsWhenRootHasNoChildren(): void
    {
        $subscriber = $this->makeSubscriber(onRootDelete: 'incarnate');

        $root = (new FakeTrajet())->setLieuDepart('Paris');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([]);
        $this->em->method('getRepository')->willReturn($repo);

        $this->incarnator->expects($this->never())->method('incarnate');

        $subscriber->preRemove(new PreRemoveEventArgs($root, $this->em));
    }

    public function testPreRemoveHandlesNonStandardMappingGracefully(): void
    {
        $subscriber = $this->makeSubscriber(onRootDelete: 'incarnate');

        $root = (new FakeTrajet())->setLieuDepart('Paris');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willThrowException(new \Doctrine\ORM\Mapping\MappingException('No mapping for property'));
        $this->em->method('getRepository')->willReturn($repo);

        $this->incarnator->expects($this->never())->method('incarnate');

        $subscriber->preRemove(new PreRemoveEventArgs($root, $this->em));
    }

    public function testPreRemoveRethrowsNonMappingExceptions(): void
    {
        $subscriber = $this->makeSubscriber(onRootDelete: 'incarnate');

        $root = (new FakeTrajet())->setLieuDepart('Paris');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willThrowException(new \RuntimeException('Connection lost'));
        $this->em->method('getRepository')->willReturn($repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection lost');

        $subscriber->preRemove(new PreRemoveEventArgs($root, $this->em));
    }

    // ─── onFlush ──────────────────────────────────────────────────────────

    public function testOnFlushNoOpWhenAutoPropagateDisabled(): void
    {
        $subscriber = $this->makeSubscriber(autoPropagateCollections: false);

        $this->uow->expects($this->never())->method('getScheduledCollectionUpdates');

        $subscriber->onFlush(new OnFlushEventArgs($this->em));
    }

    public function testOnFlushNoOpWhenOwnerIsGhost(): void
    {
        $subscriber = $this->makeSubscriber();

        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $ghost = (new FakeTrajet())->setParent($root);

        $this->uow->method('getScheduledCollectionUpdates')->willReturn([
            $this->makeCollectionStub($ghost, [new FakeTrajet()]),
        ]);

        $this->em->expects($this->never())->method('persist');

        $subscriber->onFlush(new OnFlushEventArgs($this->em));
    }

    public function testOnFlushNoOpWhenOwnerIsNotGhostable(): void
    {
        $subscriber = $this->makeSubscriber();

        $this->uow->method('getScheduledCollectionUpdates')->willReturn([
            $this->makeCollectionStub(new \stdClass(), [new FakeTrajet()]),
        ]);

        $this->em->expects($this->never())->method('persist');

        $subscriber->onFlush(new OnFlushEventArgs($this->em));
    }

    public function testOnFlushNoOpWhenNoChildrenExist(): void
    {
        $subscriber = $this->makeSubscriber();

        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $newItem = (new FakeTrajet())->setLieuDepart('Lyon');

        $this->uow->method('getScheduledCollectionUpdates')->willReturn([
            $this->makeCollectionStub($root, [$newItem]),
        ]);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([]);
        $this->em->method('getRepository')->willReturn($repo);

        $this->em->expects($this->never())->method('persist');

        $subscriber->onFlush(new OnFlushEventArgs($this->em));
    }

    public function testOnFlushCreatesAndPersistsGhostForEachChildOfRoot(): void
    {
        $subscriber = $this->makeSubscriber();

        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $child1 = (new FakeTrajet())->setParent($root);
        $child2 = (new FakeTrajet())->setParent($root);
        $newItem = (new FakeTrajet())->setLieuDepart('Lyon');

        $this->uow->method('getScheduledCollectionUpdates')->willReturn([
            $this->makeCollectionStub($root, [$newItem]),
        ]);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->with(['parent' => $root])->willReturn([$child1, $child2]);
        $this->em->method('getRepository')->willReturn($repo);
        $this->em->method('getClassMetadata')->willReturn($this->createMock(ClassMetadata::class));

        // 2 enfants × 1 item inséré = 2 fantômes créés et persistés.
        $persistedEntities = [];
        $this->em->expects($this->exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persistedEntities): void {
                $persistedEntities[] = $entity;
            });
        $this->uow->expects($this->exactly(2))->method('computeChangeSet');

        $subscriber->onFlush(new OnFlushEventArgs($this->em));

        foreach ($persistedEntities as $persisted) {
            $this->assertInstanceOf(FakeTrajet::class, $persisted);
            $this->assertSame($newItem, $persisted->getParent());
        }
    }

    public function testOnFlushDispatchesGhostAffiliatedEventForEachCreatedGhost(): void
    {
        $subscriber = $this->makeSubscriber();

        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $child = (new FakeTrajet())->setParent($root);
        $newItem = (new FakeTrajet())->setLieuDepart('Lyon');

        $this->uow->method('getScheduledCollectionUpdates')->willReturn([
            $this->makeCollectionStub($root, [$newItem]),
        ]);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([$child]);
        $this->em->method('getRepository')->willReturn($repo);
        $this->em->method('getClassMetadata')->willReturn($this->createMock(ClassMetadata::class));
        $this->em->method('persist');
        $this->uow->method('computeChangeSet');

        $dispatchedEvents = [];
        $this->dispatcher->addListener(
            GhostAffiliatedEvent::class,
            static function (GhostAffiliatedEvent $e) use (&$dispatchedEvents): void {
                $dispatchedEvents[] = $e;
            },
        );

        $subscriber->onFlush(new OnFlushEventArgs($this->em));

        $this->assertCount(1, $dispatchedEvents);
        $this->assertSame($newItem, $dispatchedEvents[0]->parent);
        $this->assertInstanceOf(FakeTrajet::class, $dispatchedEvents[0]->entity);
    }

    public function testOnFlushIgnoresInsertedItemsThatAreNotGhostable(): void
    {
        $subscriber = $this->makeSubscriber();

        $root = (new FakeTrajet())->setLieuDepart('Paris');
        $child = (new FakeTrajet())->setParent($root);

        $this->uow->method('getScheduledCollectionUpdates')->willReturn([
            $this->makeCollectionStub($root, [new \stdClass()]),
        ]);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([$child]);
        $this->em->method('getRepository')->willReturn($repo);

        $this->em->expects($this->never())->method('persist');

        $subscriber->onFlush(new OnFlushEventArgs($this->em));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function makeSubscriber(
        bool $autoPropagateCollections = true,
        string $onRootDelete = 'cascade',
    ): GhostPropagationSubscriber {
        return new GhostPropagationSubscriber(
            incarnator: $this->incarnator,
            eventDispatcher: $this->dispatcher,
            autoPropagateCollections: $autoPropagateCollections,
            onRootDelete: $onRootDelete,
        );
    }

    /**
     * Stub anonyme remplaçant PersistentCollection (final en Doctrine ORM 3.x).
     *
     * Le subscriber appelle getOwner() et getInsertDiff() sur l'objet retourné
     * par getScheduledCollectionUpdates() — ces méthodes ne sont pas sur
     * l'interface Collection mais sur PersistentCollection. Un stub duck-typed
     * suffit ici.
     *
     * @param list<object> $insertDiff
     */
    private function makeCollectionStub(object $owner, array $insertDiff): object
    {
        return new class($owner, $insertDiff) {
            public function __construct(
                private readonly object $owner,
                private readonly array $insertDiff,
            ) {
            }

            public function getOwner(): object
            {
                return $this->owner;
            }

            /** @return list<object> */
            public function getInsertDiff(): array
            {
                return $this->insertDiff;
            }
        };
    }
}
