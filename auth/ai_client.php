<?php
/**
 * auth/ai_client.php
 * ─────────────────────────────────────────────────────────────────
 * Thin cURL wrapper around Groq's OpenAI-compatible chat endpoint.
 *
 *   groq_chat()         one request, whole reply at once (used for plans)
 *   groq_chat_stream()  the reply arrives piece by piece (used for chat)
 *
 * Never throws — every failure comes back as ['ok' => false, 'error' => '...']
 * with a message fit to show an admin, because a chat widget that explodes
 * into a stack trace is worse than one that says "try again".
 */

require_once __DIR__ . '/ai_config.php';

if (!function_exists('groq_request_body')) {
    function groq_request_body(array $messages, array $opts): array
    {
        $body = [
            'model'       => $opts['model'] ?? GROQ_MODEL,
            'messages'    => $messages,
            'temperature' => $opts['temperature'] ?? GROQ_TEMPERATURE,
            'max_tokens'  => $opts['max_tokens'] ?? GROQ_MAX_TOKENS,
        ];
        // Only meaningful on reasoning models; harmlessly retried without it
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
        return $body;
    }

    /**
     * Turns a non-200 reply into an admin-readable message.
     *
     * @return array{error:string, retry_after:int, retry_without_effort:bool}
     */
    function groq_map_error(int $code, string $raw, array $body): array
    {
        $decoded = json_decode($raw, true);
        $out = ['error' => '', 'retry_after' => 0, 'retry_without_effort' => false];

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

        $detail = $decoded['error']['message'] ?? substr($raw, 0, 200);
        error_log('[AI] HTTP ' . $code . ': ' . $detail);

        // A model that doesn't know reasoning_effort: drop it and try once more,
        // so swapping GROQ_MODEL never needs a code change.
        if ($code === 400 && isset($body['reasoning_effort']) && stripos($detail, 'reasoning_effort') !== false) {
            $out['retry_without_effort'] = true;
            return $out;
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
}

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

        $body = groq_request_body($messages, $opts);

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

        if ($code !== 200 || !is_array($decoded)) {
            $err = groq_map_error($code, (string) $raw, $body);
            if ($err['retry_without_effort']) {
                return groq_chat($messages, array_merge($opts, ['reasoning_effort' => '']));
            }
            $out['error']       = $err['error'];
            $out['retry_after'] = $err['retry_after'];
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

if (!function_exists('groq_chat_stream')) {
    /**
     * Same request as groq_chat(), but $on_delta(string $text) is called with
     * each piece of the reply as Groq produces it. Only the visible answer is
     * passed on — a reasoning model's hidden thinking arrives in a separate
     * field and is dropped.
     *
     * Not for JSON mode: Groq holds a JSON reply back and sends it in one piece.
     *
     * If the browser disconnects (the admin pressed Stop), the request to Groq is
     * cut off too, and whatever arrived so far comes back with 'aborted' => true.
     *
     * @return array{ok:bool, content:string, usage:array{in:int,out:int}, error:string, retry_after:int, aborted:bool}
     */
    function groq_chat_stream(array $messages, callable $on_delta, array $opts = []): array
    {
        $out = ['ok' => false, 'content' => '', 'usage' => ['in' => 0, 'out' => 0], 'error' => '', 'retry_after' => 0, 'aborted' => false];

        if (GROQ_API_KEY === '') {
            $out['error'] = 'No Groq API key configured.';
            return $out;
        }

        unset($opts['json_mode']);
        $body = groq_request_body($messages, $opts);
        $body['stream'] = true;

        $code       = 0;
        $pending    = '';   // a server-sent line split across two network reads
        $error_body = '';   // a non-200 reply is ordinary JSON, collected whole
        $mid_error  = '';

        $ch = curl_init(GROQ_BASE_URL . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . GROQ_API_KEY,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_TIMEOUT        => $opts['timeout'] ?? GROQ_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$code, &$pending, &$error_body, &$mid_error, &$out, $on_delta): int {
                if ($code === 0) {
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                }
                if ($code !== 200) {
                    $error_body .= $chunk;
                    return strlen($chunk);
                }

                // Server-sent events: one "data: {json}" per line
                $pending .= $chunk;
                while (($nl = strpos($pending, "\n")) !== false) {
                    $line    = rtrim(substr($pending, 0, $nl), "\r");
                    $pending = substr($pending, $nl + 1);
                    if (strncmp($line, 'data:', 5) !== 0) {
                        continue;
                    }
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }
                    $event = json_decode($data, true);
                    if (!is_array($event)) {
                        continue;
                    }
                    if (isset($event['error'])) {
                        $mid_error = (string) ($event['error']['message'] ?? 'stream error');
                        continue;
                    }
                    // Groq reports usage on the last event
                    $usage = $event['x_groq']['usage'] ?? $event['usage'] ?? null;
                    if (is_array($usage)) {
                        $out['usage'] = [
                            'in'  => (int) ($usage['prompt_tokens'] ?? 0),
                            'out' => (int) ($usage['completion_tokens'] ?? 0),
                        ];
                    }
                    $text = $event['choices'][0]['delta']['content'] ?? '';
                    if (is_string($text) && $text !== '') {
                        $out['content'] .= $text;
                        $on_delta($text);
                    }
                }

                // Returning less than was received tells cURL to hang up
                if (connection_aborted()) {
                    $out['aborted'] = true;
                    return 0;
                }
                return strlen($chunk);
            },
        ]);

        $ok   = curl_exec($ch);
        $cerr = curl_error($ch);
        if ($code === 0) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }
        curl_close($ch);

        if ($out['aborted']) {
            return $out;
        }

        if ($code !== 200) {
            if ($ok === false && $code === 0) {
                error_log('[AI] cURL failure: ' . $cerr);
                $out['error'] = 'Could not reach the AI service. Check the server\'s internet connection.';
                return $out;
            }
            $err = groq_map_error($code, $error_body, $body);
            if ($err['retry_without_effort']) {
                return groq_chat_stream($messages, $on_delta, array_merge($opts, ['reasoning_effort' => '']));
            }
            $out['error']       = $err['error'];
            $out['retry_after'] = $err['retry_after'];
            return $out;
        }

        if ($ok === false) {
            // Connection dropped part-way (or timed out) — keep what arrived
            error_log('[AI] stream cut off: ' . $cerr);
            $out['error'] = 'The reply was cut off. Try again.';
            return $out;
        }
        if ($mid_error !== '') {
            error_log('[AI] stream error: ' . $mid_error);
            $out['error'] = 'The AI service stopped part-way through the reply. Try again.';
            return $out;
        }
        if (trim($out['content']) === '') {
            $out['error'] = 'The AI service returned an empty reply. Try rephrasing.';
            return $out;
        }

        $out['ok'] = true;
        return $out;
    }
}
