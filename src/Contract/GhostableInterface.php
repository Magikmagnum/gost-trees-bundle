<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Contract;

/**
 * Contrat des entités supportant le pattern d'arbres fantômes.
 *
 * Une entité fantomisable peut être soit une racine (pas de parent),
 * soit un fantôme (avec un parent). Quand elle est fantôme, ses getters
 * empruntent dynamiquement les valeurs de son parent tant qu'elle
 * n'a pas matérialisé ses propres valeurs.
 *
 * Implémentation recommandée : utiliser GhostNodeTrait qui fournit
 * une implémentation par défaut de toutes ces méthodes.
 */
interface GhostableInterface
{
    /**
     * Retourne le parent direct de cette entité, ou null si elle est racine.
     */
    public function getParent(): ?self;

    /**
     * Définit le parent de cette entité.
     *
     * Garantit : lève GhostCycleException si l'entité se désigne elle-même
     * comme parent direct ($entity->setParent($entity)).
     *
     * Délègue : la validation de profondeur et des cycles indirects est
     * confiée à GhostResolverInterface::assertValidParent(), qui requiert la
     * configuration max_depth et doit être appelé avant la persistence.
     *
     * @throws \EricGansa\GhostTreesBundle\Exception\GhostCycleException
     */
    public function setParent(?self $parent): static;

    /**
     * Indique si cette entité est un fantôme (a un parent),
     * indépendamment de son état de matérialisation.
     */
    public function isGhost(): bool;

    /**
     * Incarne l'entité en racine autonome (opération sans service externe).
     *
     * Pour chaque champ #[GhostField] dont la valeur locale est null,
     * la valeur héritée est matérialisée localement via les getters de la chaîne,
     * puis le lien parent est coupé. L'entité devient une racine indépendante.
     *
     * Si l'entité est déjà une racine, l'opération est silencieuse.
     *
     * Note transactionnelle : cette méthode ne gère pas les transactions Doctrine.
     * Pour l'incarnation atomique ou en batch, utiliser GhostIncarnatorInterface.
     *
     * @throws \EricGansa\GhostTreesBundle\Exception\GhostCycleException si la chaîne est corrompue
     */
    public function incarnate(): static;

    /**
     * Réinitialise tous les champs #[GhostField] locaux à null.
     *
     * Effet :
     *  - Si l'entité a un parent → redevient un fantôme totalement transparent.
     *  - Si l'entité n'a pas de parent → reste une racine, mais sans valeurs locales.
     *
     * Le lien parent n'est PAS modifié par cette méthode.
     *
     * Cas d'usage : annuler des surcharges locales pour reprendre l'héritage parent.
     */
    public function reset(): static;

    /**
     * Fabrique statique : crée un fantôme vierge rattaché à l'entité donnée.
     *
     * Convention par défaut (GhostNodeTrait) : instanciation sans argument,
     * puis setParent($original). Les entités dont le constructeur requiert des
     * arguments DOIVENT surcharger cette méthode.
     *
     * Utilisé par GhostPropagationSubscriber pour créer les fantômes propagés.
     */
    public static function createGhostOf(self $original): static;
}
