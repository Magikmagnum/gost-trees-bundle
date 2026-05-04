<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Tests\Fixtures\Entity;

use EricGansa\GhostTreesBundle\Attribute\Ghostable;
use EricGansa\GhostTreesBundle\Attribute\GhostableField;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Trait\GhostNodeTrait;

/**
 * Entité minimaliste utilisée par les tests.
 *
 * Démontre l'usage SANS Doctrine : aucun accès au parent n'est redéclaré,
 * le trait fournit tout. La classe ne définit que les attributs métier
 * et leurs accesseurs.
 */
final class FakeTrajet implements GhostableInterface
{
    use GhostNodeTrait;

    private ?int $id = null;

    #[Ghostable]
    #[GhostableField(required: true)]
    private ?string $lieuDepart = null;

    #[Ghostable]
    #[GhostableField(required: true)]
    private ?string $lieuArrivee = null;

    #[Ghostable]
    private ?string $moyenTransport = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getLieuDepart(): ?string
    {
        return $this->resolve($this->lieuDepart, 'getLieuDepart');
    }

    public function setLieuDepart(?string $value): self
    {
        $this->lieuDepart = $value;
        return $this;
    }

    public function getLieuArrivee(): ?string
    {
        return $this->resolve($this->lieuArrivee, 'getLieuArrivee');
    }

    public function setLieuArrivee(?string $value): self
    {
        $this->lieuArrivee = $value;
        return $this;
    }

    public function getMoyenTransport(): ?string
    {
        return $this->resolve($this->moyenTransport, 'getMoyenTransport');
    }

    public function setMoyenTransport(?string $value): self
    {
        $this->moyenTransport = $value;
        return $this;
    }
}
