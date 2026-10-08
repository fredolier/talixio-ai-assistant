<?php
declare(strict_types=1);
namespace Talixio;

/** A model + effort level. fast: housekeeping and quick lookups; standard: chat; deep: hard reasoning, research. */
final class Tier
{
    public const NAMES = ['fast', 'standard', 'deep'];

    public function __construct(
        public string $name,
        public string $model,
        public int $maxSteps,            // tool-use rounds per answer
        public int $thinkingTokens = 0,  // Claude extended thinking budget (0 = off)
        public string $reasoningEffort = '', // OpenAI reasoning models: low|medium|high ('' = not sent)
    ) {}

    public function label(): string { return $this->model !== '' ? $this->model : $this->name; }
}
