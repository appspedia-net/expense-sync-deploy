<?php
/**
 * OpenAI proxy (chat completions). The API key never leaves the server.
 *
 * Config (ai.*): openai_key, model, vision_model, max_tokens, daily_limit_receipt,
 *                daily_limit_ask, require_pro, timeout.
 */

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class OpenAi
{
    private const URL = 'https://api.openai.com/v1/chat/completions';

    /**
     * Low-level chat completion. Returns the assistant message content (string).
     *
     * @throws RuntimeException
     */
    public static function chat(array $messages, ?string $model = null, bool $jsonMode = false, ?int $maxTokens = null): array
    {
        $key = (string) config('ai.openai_key', '');
        if ($key === '') {
            throw new RuntimeException('AI is not configured on this server');
        }
        $payload = [
            'model' => $model ?: (string) config('ai.model', 'gpt-4o-mini'),
            'messages' => $messages,
            'max_tokens' => $maxTokens ?: (int) config('ai.max_tokens', 800),
            'temperature' => 0.2,
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $res = Http::postJson(self::URL, $payload, ['Authorization: Bearer ' . $key], (int) config('ai.timeout', 60));
        $j = $res['json'];
        if ($res['status'] !== 200 || !is_array($j)) {
            $msg = is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $res['status'] . ' ' . ($res['error'] ?? ''));
            log_msg('ai', 'OpenAI error: ' . $msg);
            throw new RuntimeException('AI provider error: ' . $msg);
        }
        $content = $j['choices'][0]['message']['content'] ?? '';
        return [
            'content' => is_string($content) ? $content : json_encode($content),
            'usage' => $j['usage'] ?? [],
            'model' => $j['model'] ?? $payload['model'],
        ];
    }

    /**
     * Receipt scan: image bytes -> strict JSON {amount, currency, merchant, date, category, items[]}.
     */
    public static function receipt(string $imageBytes, string $mime, ?string $hintCurrency = null, array $categories = []): array
    {
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($imageBytes);
        $cats = $categories ? implode(', ', array_slice($categories, 0, 60)) : 'Food, Groceries, Transport, Shopping, Bills, Health, Entertainment, Travel, Other';
        $system = 'You extract data from receipt photos. Respond with ONLY a JSON object with exactly these keys: '
                . '"amount" (number, the grand total paid), "currency" (ISO 4217 code or null), "merchant" (string or null), '
                . '"date" (YYYY-MM-DD or null), "category" (one of: ' . $cats . '), '
                . '"items" (array of {"name": string, "qty": number, "price": number}), "confidence" (0-1). '
                . 'Use null when a value is not visible. Never invent values.'
                . ($hintCurrency ? ' If the currency is ambiguous assume ' . $hintCurrency . '.' : '');
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Extract this receipt.'],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'auto']],
            ]],
        ];
        $r = self::chat($messages, (string) config('ai.vision_model', 'gpt-4o-mini'), true, 700);
        $parsed = self::parseJson($r['content']);
        if ($parsed === null) {
            throw new RuntimeException('AI returned no usable JSON');
        }
        return [
            'amount' => isset($parsed['amount']) && is_numeric($parsed['amount']) ? round((float) $parsed['amount'], 2) : null,
            'currency' => isset($parsed['currency']) && is_string($parsed['currency']) ? strtoupper(substr($parsed['currency'], 0, 3)) : null,
            'merchant' => isset($parsed['merchant']) && is_string($parsed['merchant']) ? mb_substr($parsed['merchant'], 0, 120) : null,
            'date' => isset($parsed['date']) && is_string($parsed['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed['date']) ? $parsed['date'] : null,
            'category' => isset($parsed['category']) && is_string($parsed['category']) ? mb_substr($parsed['category'], 0, 60) : null,
            'items' => isset($parsed['items']) && is_array($parsed['items']) ? array_values(array_slice($parsed['items'], 0, 100)) : [],
            'confidence' => isset($parsed['confidence']) && is_numeric($parsed['confidence']) ? (float) $parsed['confidence'] : null,
            'usage' => $r['usage'],
        ];
    }

    /** Free-form finance question with optional context (summaries the app computed locally). */
    public static function ask(string $question, $context = null, ?string $locale = null): array
    {
        $system = 'You are a concise personal-finance assistant inside the Expense Manager app. '
                . 'Answer in plain language, with concrete numbers from the provided context when relevant. '
                . 'Do not give investment, tax or legal advice beyond general information. Keep answers under 200 words.'
                . ($locale ? ' Reply in the language of locale "' . $locale . '".' : '');
        $messages = [['role' => 'system', 'content' => $system]];
        if ($context !== null && $context !== '' && $context !== []) {
            $ctx = is_string($context) ? $context : json_encode($context, JSON_UNESCAPED_UNICODE);
            $messages[] = ['role' => 'user', 'content' => 'Context (JSON from the user\'s own data):\n' . mb_substr($ctx, 0, 12000)];
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr($question, 0, 2000)];
        $r = self::chat($messages, null, false);
        return ['answer' => trim($r['content']), 'usage' => $r['usage'], 'model' => $r['model']];
    }

    /** Tolerant JSON extraction (handles ```json fences). */
    private static function parseJson(string $text): ?array
    {
        $text = trim($text);
        $j = json_decode($text, true);
        if (is_array($j)) {
            return $j;
        }
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $j = json_decode($m[0], true);
            if (is_array($j)) {
                return $j;
            }
        }
        return null;
    }
}
