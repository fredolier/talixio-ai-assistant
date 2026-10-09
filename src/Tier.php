<?php
declare(strict_types=1);
namespace Talixio;

/** A model + effort level. fast: housekeeping and quick lookups; standard: chat; deep: hard reasoning, research. */
final class Tier
{
    public const NAMES = ['fast', 'standard', 'deep'];
    /** How hard the model works: deeper thinking, more tool steps for jobs, higher cost. */
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function __construct(
        public string $name,
        public string $model,
        public int $maxSteps,            // tool-use rounds per answer
        public int $thinkingTokens = 0,  // Claude extended thinking budget (0 = off)
        public string $reasoningEffort = '', // one of EFFORTS ('' = the model's default; OpenAI-compatible servers get low|medium|high)
    ) {}

    public function label(): string { return $this->model !== '' ? $this->model : $this->name; }
}
