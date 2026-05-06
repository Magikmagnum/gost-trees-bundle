<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Attribute;

use Symfony\Component\Validator\Constraint;

/**
 * @deprecated Utiliser #[RequiredOnRoot] à la place. Sera supprimé en v1.0.
 *
 * Note : le paramètre $required=false est conservé pour BC mais #[RequiredOnRoot]
 * est toujours "required: true" par nature. Un #[GhostableField(required: false)]
 * était un no-op, et reste tel via cet alias.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class GhostableField extends Constraint
{
    public function __construct(
        public readonly bool $required = false,
        public readonly ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);
    }

    public function getDefaultMessage(): string
    {
        return $this->message ?? 'Ce champ est obligatoire sur une entité racine.';
    }

    public function getTargets(): string
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
