<?php

declare(strict_types=1);

/*
 * This file is part of the Uhifadhi core.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Contracts\Access;

/**
 * THE ORDINARY WAY TO DECLARE A CONCERN: construct one.
 *
 * Everything the matrix needs about a row, checked at the moment it is
 * written rather than the moment it is drawn. A declaration that cannot be
 * rendered honestly - no sentence, no verb, an "own" scope the module has no
 * word for - does not construct, which puts the failure in the module's own
 * test run instead of on an administrator's screen.
 */
final readonly class Concern implements ConcernInterface
{
    /** @var list<Verb> */
    private array $verbs;

    /** @var list<ScopeKind> */
    private array $scopeKinds;

    /**
     * @param list<Verb>      $verbs      which of the six this concern supports
     * @param list<ScopeKind> $scopeKinds which placements a grant on it may be exercised at
     * @param string|null     $ownWords   the module's own word for "mine", required
     *                                    when and only when Own is offered
     * @param string|null     $moduleSlug the module that owns it, null for a core bundle
     * @param string|null     $lifts      the rule a grant on it lifts, for an exception;
     *                                    an exception must be sensitive
     */
    public function __construct(
        private string $key,
        private string $label,
        private string $description,
        array $verbs,
        array $scopeKinds,
        private bool $sensitive = false,
        private ?string $ownWords = null,
        private ?string $moduleSlug = null,
        private ?string $lifts = null,
    ) {
        if (1 !== preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('The concern key "%s" is not a slug. Use lowercase letters, digits and hyphens - it is the word a route, a door and a grant all name this concern by.', $key));
        }

        if ('' === trim($description)) {
            throw new \InvalidArgumentException(\sprintf('The concern "%s" was declared without a description. Say in one sentence what it is about - it is printed under the row in the grants matrix.', $key));
        }

        if ([] === $verbs) {
            throw new \InvalidArgumentException(\sprintf('The concern "%s" supports no verb. Declare at least one verb, or do not declare the concern: a row with no cell is a row nobody can grant.', $key));
        }

        if ([] === $scopeKinds) {
            throw new \InvalidArgumentException(\sprintf('The concern "%s" offers no scope. Declare at least one scope kind: a grant that reaches nowhere is a grant that does nothing.', $key));
        }

        foreach ([Verb::class => $verbs, ScopeKind::class => $scopeKinds] as $set) {
            $seen = [];
            foreach ($set as $one) {
                if (isset($seen[$one->value])) {
                    throw new \InvalidArgumentException(\sprintf('The concern "%s" names "%s" twice. Each verb and each scope kind is declared once.', $key, $one->value));
                }
                $seen[$one->value] = true;
            }
        }

        $offersOwn = \in_array(ScopeKind::Own, $scopeKinds, true);

        if ($offersOwn && (null === $ownWords || '' === trim($ownWords))) {
            throw new \InvalidArgumentException(\sprintf('The concern "%s" offers the "own" scope without saying what own means. Give the module\'s own words for it - "own shift", "own team" - because the core has none.', $key));
        }

        if (!$offersOwn && null !== $ownWords) {
            throw new \InvalidArgumentException(\sprintf('The concern "%s" gives words for "own" but does not offer the "own" scope, so the words would name nothing.', $key));
        }

        if (null !== $lifts && '' === trim($lifts)) {
            throw new \InvalidArgumentException(\sprintf('The concern "%s" is declared as an exception without saying which rule it lifts. Name the rule in the product\'s words - "the rank rule" - because the screen that sets it apart prints it.', $key));
        }

        if (null !== $lifts && !$sensitive) {
            throw new \InvalidArgumentException(\sprintf('The concern "%s" lifts %s but is not declared sensitive. A grant that takes a seat out of a rule everybody else is held to is always sensitive.', $key, $lifts));
        }

        $this->verbs = $verbs;
        $this->scopeKinds = $scopeKinds;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function verbs(): array
    {
        return $this->verbs;
    }

    public function scopeKinds(): array
    {
        return $this->scopeKinds;
    }

    public function isSensitive(): bool
    {
        return $this->sensitive;
    }

    public function ownWords(): ?string
    {
        return $this->ownWords;
    }

    public function moduleSlug(): ?string
    {
        return $this->moduleSlug;
    }

    public function lifts(): ?string
    {
        return $this->lifts;
    }

    public function supports(Verb $verb): bool
    {
        return \in_array($verb, $this->verbs, true);
    }

    public function offers(ScopeKind $kind): bool
    {
        return \in_array($kind, $this->scopeKinds, true);
    }
}
