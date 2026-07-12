<?php

namespace App\Service;

/**
 * Spirit-to-Spirit call context / guard.
 *
 * Tracks the chain of Spirits involved in a nested `callSpirit` chain within a
 * single top-level turn, and enforces the safeguards documented in
 * /docs/features/Spirit2SpiritChat.md:
 *   - depth cap        (how deep nested consultations may go)
 *   - cycle guard      (block A -> B -> A)
 *   - per-turn budget  (max number of callSpirit calls per top-level turn)
 *
 * A Spirit Chat turn runs in its own detached CLI worker process, so a single
 * shared instance is effectively per-turn. `begin()` (re)initialises it, making
 * the guard safe even if reused within one process.
 */
class SpiritCallContext
{
    public const MAX_DEPTH = 2;
    public const MAX_CALLS_PER_TURN = 5;

    /** @var string[] Ordered ancestry of spirit ids in the current call chain */
    private array $chain = [];

    private bool $initialised = false;
    private int $callsRemaining = self::MAX_CALLS_PER_TURN;

    /**
     * Initialise (or reset) the context at the start of a top-level turn.
     */
    public function begin(string $rootSpiritId, int $maxCalls = self::MAX_CALLS_PER_TURN): void
    {
        $this->chain = [$rootSpiritId];
        $this->callsRemaining = $maxCalls;
        $this->initialised = true;
    }

    public function isInitialised(): bool
    {
        return $this->initialised;
    }

    /**
     * Current nesting depth (0 = top-level human turn).
     */
    public function depth(): int
    {
        return max(0, count($this->chain) - 1);
    }

    public function chain(): array
    {
        return $this->chain;
    }

    public function callsRemaining(): int
    {
        return $this->callsRemaining;
    }

    /**
     * Validate that a consultation of $calleeSpiritId is currently allowed.
     * Returns null when allowed, or a human/AI-readable error string otherwise.
     * Does NOT mutate state — call enter() to actually descend.
     */
    public function validateCall(string $calleeSpiritId): ?string
    {
        if (!$this->initialised) {
            // Defensive: treat an uninitialised context as a single-spirit root.
            return null;
        }
        if ($this->callsRemaining <= 0) {
            return 'Spirit consultation budget for this turn is exhausted.';
        }
        if ($this->depth() >= self::MAX_DEPTH) {
            return 'Maximum Spirit consultation depth reached; cannot consult further.';
        }
        $current = end($this->chain) ?: null;
        if ($calleeSpiritId === $current) {
            return 'A Spirit cannot consult itself.';
        }
        if (in_array($calleeSpiritId, $this->chain, true)) {
            return 'This Spirit is already part of the current consultation chain (cycle blocked).';
        }
        return null;
    }

    /**
     * Descend into a consultation of $calleeSpiritId. Consumes one call from the
     * per-turn budget and pushes the callee onto the chain.
     */
    public function enter(string $calleeSpiritId): void
    {
        $this->chain[] = $calleeSpiritId;
        $this->callsRemaining--;
    }

    /**
     * Return from a consultation (pop the last callee off the chain).
     */
    public function leave(): void
    {
        if (count($this->chain) > 1) {
            array_pop($this->chain);
        }
    }
}
