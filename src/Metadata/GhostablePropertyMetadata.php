<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Metadata;

/**
 * Métadonnées d'une propriété fantomisable, extraites une fois pour toutes
 * et conservées en cache par GhostMetadata.
 *
 * SÉCURITÉ — accès par réflexion : readValue() et writeValue() opèrent
 * directement sur la propriété PHP sans passer par les getters/setters.
 * writeValue() court-circuite donc les validations portées par les setters.
 * Ce comportement est intentionnel (incarnation atomique), mais les
 * appelants doivent être conscients qu'aucune contrainte de setter
 * n'est déclenchée lors de l'écriture.
 *
 * La propriété $reflectionProperty est privée pour éviter que du code
 * externe ne manipule directement la reflection sans passer par l'API
 * publique (readValue / writeValue).
 */
final class GhostablePropertyMetadata
{
    public function __construct(
        public readonly string $name,
        private readonly \ReflectionProperty $reflectionProperty,
        public readonly string $getter,
    ) {
    }

    public function readValue(object $entity): mixed
    {
        return $this->reflectionProperty->getValue($entity);
    }

    /**
     * Écrit une valeur directement via réflexion, en bypassant les setters.
     * Utiliser uniquement dans le contexte d'incarnation ou de migration.
     */
    public function writeValue(object $entity, mixed $value): void
    {
        $this->reflectionProperty->setValue($entity, $value);
    }
}
