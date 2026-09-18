<?php
/**
 * auth/ai_client.php
 * ─────────────────────────────────────────────────────────────────
 * Thin cURL wrapper around Groq's OpenAI-compatible chat endpoint.
 *
 * Never throws — every failure comes back as ['ok' => false, 'error' => '...']
 * with a message fit to show an admin, because a chat widget that explodes
 * into a stack trace is worse than one that says "try again".
 */

require_once __DIR__ . '/ai_config.php';

if (!function_exists('groq_chat')) {
    /**
     * @param array $messages  [['role' => 'system'|'user'|'assistant', 'content' => '...'], ...]
     * @param array $opts      json_mode (bool), max_tokens, temperature, model, timeout
     *
     * @return array{ok:bool, content:string, json:?array, usage:array{in:int,out:int}, error:string, retry_after:int}
     */
    function groq_chat(array $messages, array $opts = []): array
    {
        $out = ['ok' => false, 'content' => '', 'json' => null, 'usage' => ['in' => 0, 'out' => 0], 'error' => '', 'retry_after' => 0];

        if (GROQ_API_KEY === '') {
            $out['error'] = 'No Groq API key configured.';
            return $out;
        }

        $body = [
            'model'       => $opts['model'] ?? GROQ_MODEL,
            'messages'    => $messages,
            'temperature' => $opts['temperature'] ?? GROQ_TEMPERATURE,
            'max_tokens'  => $opts['max_tokens'] ?? GROQ_MAX_TOKENS,
        ];
        // Only meaningful on reasoning models; harmlessly retried without it below
        // if the configured model rejects the parameter.
        $effort = $opts['reasoning_effort'] ?? GROQ_REASONING_EFFORT;
        if ($effort !== '') {
            $body['reasoning_effort'] = $effort;
        }
        // JSON mode: the model is constrained to emit one parseable object.
        // The shape itself is described in the prompt (see ai_prompts.php) —
        // json_object works across every Groq model, json_schema does not.
        if (!empty($opts['json_mode'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $ch = curl_init(GROQ_BASE_URL . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . GROQ_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => $opts['timeout'] ?? GROQ_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('[AI] cURL failure: ' . $cerr);
            $out['error'] = 'Could not reach the AI service. Check the server\'s internet connection.';
            return $out;
        }

        $decoded = json_decode($raw, true);

        if ($code === 429) {
            // Free-tier throttle. Groq reports the wait in the error message.
            $msg = $decoded['error']['message'] ?? '';
            if (preg_match('/try again in ([\d.]+)s/i', $msg, $m)) {
                $out['retry_after'] = (int) ceil((float) $m[1]);
            }
            $out['error'] = $out['retry_after'] > 0
                ? 'Rate limit reached — try again in ' . $out['retry_after'] . ' second(s).'
                : 'Rate limit reached. Wait a moment and try again.';
            return $out;
        }

        if ($code === 401 || $code === 403) {
            $out['error'] = 'Groq rejected the API key. Check GROQ_API_KEY in .env.';
            return $out;
        }

        if ($code !== 200 || !is_array($decoded)) {
            $detail = $decoded['error']['message'] ?? substr((string) $raw, 0, 200);
            error_log('[AI] HTTP ' . $code . ': ' . $detail);

            // A model that doesn't know reasoning_effort: drop it and try once more,
            // so swapping GROQ_MODEL never needs a code change.
            if ($code === 400 && isset($body['reasoning_effort']) && stripos($detail, 'reasoning_effort') !== false) {
                return groq_chat($messages, array_merge($opts, ['reasoning_effort' => '']));
            }

            // Truncated JSON — the reply outgrew max_tokens before it could close.
            if ($code === 400 && stripos($detail, 'failed to validate json') !== false) {
                $out['error'] = 'The plan came back incomplete. Try again, or raise GROQ_PLAN_MAX_TOKENS in .env.';
                return $out;
            }
            // A retired/renamed model is the most common cause here, so name it.
            $out['error'] = $code === 404
                ? 'Model "' . (string) ($body['model']) . '" was not found. Check GROQ_MODEL in .env against the Groq console.'
                : 'The AI service returned an error (HTTP ' . $code . ').';
            return $out;
        }

        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (trim($content) === '') {
            $out['error'] = 'The AI service returned an empty reply. Try rephrasing.';
            return $out;
        }

        $out['ok']      = true;
        $out['content'] = $content;
        $out['usage']   = [
            'in'  => (int) ($decoded['usage']['prompt_tokens'] ?? 0),
            'out' => (int) ($decoded['usage']['completion_tokens'] ?? 0),
        ];

        if (!empty($opts['json_mode'])) {
            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                // Some models wrap JSON in prose or a ```json fence despite json_mode.
                if (preg_match('/\{.*\}/s', $content, $m)) {
                    $parsed = json_decode($m[0], true);
                }
            }
            if (!is_array($parsed)) {
                $out['ok']    = false;
                $out['error'] = 'The AI returned a malformed plan. Try asking again.';
                return $out;
            }
            $out['json'] = $parsed;
        }

        return $out;
    }
}
