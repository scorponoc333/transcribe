<?php
/**
 * OpenRouter Model Pricing (per 1 million tokens)
 * Used as fallback when OpenRouter generation endpoint doesn't return cost.
 * Prices from OpenRouter as of March 2026.
 */
return [
    'google/gemini-2.5-pro' => [
        'prompt'     => 1.25,
        'completion' => 10.00,
    ],
    'google/gemini-2.5-flash' => [
        'prompt'     => 0.15,
        'completion' => 0.60,
    ],
    'anthropic/claude-sonnet-4' => [
        'prompt'     => 3.00,
        'completion' => 15.00,
    ],
    'anthropic/claude-3.5-sonnet' => [
        'prompt'     => 3.00,
        'completion' => 15.00,
    ],
    'openai/gpt-4o' => [
        'prompt'     => 2.50,
        'completion' => 10.00,
    ],
    'openai/gpt-4o-mini' => [
        'prompt'     => 0.15,
        'completion' => 0.60,
    ],
    'meta-llama/llama-3.3-70b-instruct' => [
        'prompt'     => 0.39,
        'completion' => 0.39,
    ],
    'deepseek/deepseek-chat-v3-0324' => [
        'prompt'     => 0.14,
        'completion' => 0.28,
    ],
];
