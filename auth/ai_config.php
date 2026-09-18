<?php
/**
 * auth/ai_config.php
 * ─────────────────────────────────────────────────────────────────
 * Configuration for the AI nutrition assistant (Groq).
 *
 * Values come from the project-root .env file — see .env.example for the
 * full list and what each one does. Parsed exactly the way
 * auth/mail_config.php does it, so there is one .env and no loader
 * dependency between them.
 *
 * Groq speaks the OpenAI-compatible wire format, so the only things that
 * ever change between models are GROQ_MODEL and the token ceiling.
 */

if (!defined('JOF_AI_CONFIG_LOADED')) {
    define('JOF_AI_CONFIG_LOADED', true);

    $__jof_ai_env = __DIR__ . '/../.env';
    if (is_file($__jof_ai_env)) {
        foreach (file($__jof_ai_env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__line) {
            $__line = trim($__line);
            if ($__line === '' || $__line[0] === '#' || !str_contains($__line, '=')) {
                continue;
            }
            [$__k, $__v] = explode('=', $__line, 2);
            $__k = trim($__k);
            $__v = trim($__v, " \t\"'");
            if ($__k !== '' && getenv($__k) === false) {
                putenv($__k . '=' . $__v);
            }
        }
    }

    define('GROQ_API_KEY',     getenv('GROQ_API_KEY') ?: '');
    define('GROQ_MODEL',       getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b');
    define('GROQ_BASE_URL',    rtrim(getenv('GROQ_BASE_URL') ?: 'https://api.groq.com/openai/v1', '/'));
    define('GROQ_MAX_TOKENS',  (int) (getenv('GROQ_MAX_TOKENS') ?: 4096));
    define('GROQ_TEMPERATURE', (float) (getenv('GROQ_TEMPERATURE') ?: 0.3));
    define('GROQ_TIMEOUT',     (int) (getenv('GROQ_TIMEOUT') ?: 60));

    // Reasoning models (gpt-oss, qwen3) think before answering, and that thinking
    // is billed as output tokens. At the default effort a single plan burns ~5,300
    // tokens and 12s; at "low" it's ~450 tokens and under 2s for the same result.
    define('GROQ_REASONING_EFFORT', getenv('GROQ_REASONING_EFFORT') ?: 'low');

    // Plans need far more room than a chat reply — the reply is a whole day of
    // meals, and truncated JSON is rejected outright by the API.
    define('GROQ_PLAN_MAX_TOKENS', (int) (getenv('GROQ_PLAN_MAX_TOKENS') ?: 8192));

    // Plans run hotter than chat on purpose. At 0.3 the model reproduces the
    // previous phase almost verbatim, since that phase is in the prompt.
    define('GROQ_PLAN_TEMPERATURE', (float) (getenv('GROQ_PLAN_TEMPERATURE') ?: 0.8));

    $__ai_flag = strtolower((string) (getenv('AI_ASSISTANT_ENABLED') ?: 'true'));
    define('AI_ASSISTANT_ENABLED', !in_array($__ai_flag, ['0', 'false', 'no', 'off'], true));
    define('AI_DAILY_REQUEST_CAP', (int) (getenv('AI_DAILY_REQUEST_CAP') ?: 200));
}

if (!function_exists('ai_assistant_enabled')) {
    /**
     * Whether the assistant should be offered at all. False hides the widget
     * rather than showing a button that errors on first use.
     */
    function ai_assistant_enabled(): bool
    {
        return AI_ASSISTANT_ENABLED && GROQ_API_KEY !== '';
    }

    /**
     * Admin-facing explanation when the assistant is unavailable, or '' when
     * everything is configured.
     */
    function ai_config_problem(): string
    {
        if (!AI_ASSISTANT_ENABLED) {
            return 'The assistant is switched off (AI_ASSISTANT_ENABLED=false in .env).';
        }
        if (GROQ_API_KEY === '') {
            return 'No Groq API key found. Add GROQ_API_KEY to your .env file — see .env.example.';
        }
        return '';
    }
}
