<?php
/**
 * AI proxy (Pro feature).
 *
 *  POST /v1/ai/receipt  multipart field `image` (jpeg/png/webp/heic ≤ 8MB)
 *                       or JSON {image_base64, mime?, currency?, categories?[]}
 *  POST /v1/ai/ask      {question, context?, locale?}
 *
 * Pro check: header X-Pro-Receipt must be present when config ai.require_pro = true.
 * The receipt is trusted (not verified with Apple) — the daily per-user limits in
 * rate_limits + ai_usage keep abuse bounded, as the spec allows.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OpenAi;
use App\Services\RateLimit;
use Throwable;

class AiController extends Controller
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/gif'];

    /** POST /v1/ai/receipt */
    public function receipt(array $params): void
    {
        $u = auth();
        $this->requirePro();
        $this->guardDaily($u['id'], 'receipt', (int) config('ai.daily_limit_receipt', 20));

        [$bytes, $mime] = $this->readImage();
        $in = input();
        $currency = isset($in['currency']) && is_string($in['currency']) ? strtoupper(substr($in['currency'], 0, 3)) : ($u['currency'] ?: null);
        $categories = [];
        if (!empty($in['categories']) && is_array($in['categories'])) {
            $categories = array_values(array_filter(array_map('strval', $in['categories'])));
        } elseif (!empty($_POST['categories']) && is_string($_POST['categories'])) {
            $categories = array_values(array_filter(array_map('trim', explode(',', $_POST['categories']))));
        }

        try {
            $res = OpenAi::receipt($bytes, $mime, $currency, $categories);
        } catch (Throwable $e) {
            fail('ai_failed', $e->getMessage(), 502);
        }
        $this->recordUsage($u['id'], 'receipt', (int) ($res['usage']['total_tokens'] ?? 0));
        unset($res['usage']);
        json(['receipt' => $res]);
    }

    /** POST /v1/ai/ask */
    public function ask(array $params): void
    {
        $u = auth();
        $this->requirePro();
        $this->guardDaily($u['id'], 'ask', (int) config('ai.daily_limit_ask', 30));
        $in = input();
        $question = str_input($in, 'question', 2000, true);
        $context = $in['context'] ?? null;
        $locale = str_input($in, 'locale', 16);
        try {
            $res = OpenAi::ask($question, $context, $locale);
        } catch (Throwable $e) {
            fail('ai_failed', $e->getMessage(), 502);
        }
        $this->recordUsage($u['id'], 'ask', (int) ($res['usage']['total_tokens'] ?? 0));
        json(['answer' => $res['answer'], 'model' => $res['model']]);
    }

    // -------------------------------------------------------------- helpers

    private function requirePro(): void
    {
        if (!config('ai.require_pro', true)) {
            return;
        }
        $receipt = header_value('X-Pro-Receipt');
        if ($receipt === null || strlen($receipt) < 20) {
            fail('pro_required', 'This feature requires Expense Manager Pro', 402);
        }
    }

    private function guardDaily(string $userId, string $kind, int $limit): void
    {
        if (!RateLimit::hit("ai:{$kind}:{$userId}", $limit, 86400)) {
            fail('rate_limited', "Daily AI limit reached ({$limit}/day). Try again tomorrow.", 429, ['limit' => $limit]);
        }
    }

    private function recordUsage(string $userId, string $kind, int $tokens): void
    {
        try {
            $day = gmdate('Y-m-d');
            $st = $this->db->prepare('SELECT id FROM ai_usage WHERE user_id = ? AND day = ? AND kind = ?');
            $st->execute([$userId, $day, $kind]);
            $row = $st->fetch();
            if ($row) {
                $this->db->prepare('UPDATE ai_usage SET count = count + 1, tokens = tokens + ? WHERE id = ?')->execute([$tokens, $row['id']]);
            } else {
                $this->db->prepare('INSERT INTO ai_usage (user_id, day, kind, count, tokens) VALUES (?, ?, ?, 1, ?)')->execute([$userId, $day, $kind, $tokens]);
            }
        } catch (Throwable $e) {
            log_msg('error', 'ai_usage: ' . $e->getMessage());
        }
    }

    /** @return array{0:string,1:string} bytes, mime */
    private function readImage(): array
    {
        $maxBytes = (int) config('ai.max_image_bytes', 8 * 1024 * 1024);
        if (!empty($_FILES['image']) && is_array($_FILES['image'])) {
            $f = $_FILES['image'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                fail('validation', 'Image upload failed (code ' . (int) $f['error'] . ')', 400);
            }
            if ((int) $f['size'] > $maxBytes) {
                fail('validation', 'Image too large', 413);
            }
            $bytes = (string) file_get_contents($f['tmp_name']);
            $mime = $this->sniffMime($bytes) ?: (string) ($f['type'] ?? 'image/jpeg');
            if (!in_array($mime, self::ALLOWED_MIME, true)) {
                fail('validation', 'Unsupported image type ' . $mime, 415);
            }
            return [$bytes, $mime];
        }
        $in = input();
        if (!empty($in['image_base64']) && is_string($in['image_base64'])) {
            $b64 = $in['image_base64'];
            $mime = null;
            if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.*)$#is', $b64, $m)) {
                $mime = strtolower($m[1]);
                $b64 = $m[2];
            }
            $bytes = base64_decode($b64, true);
            if ($bytes === false || $bytes === '') {
                fail('validation', 'image_base64 is not valid base64', 400);
            }
            if (strlen($bytes) > $maxBytes) {
                fail('validation', 'Image too large', 413);
            }
            $mime = $this->sniffMime($bytes) ?: ($mime ?: (string) ($in['mime'] ?? 'image/jpeg'));
            if (!in_array($mime, self::ALLOWED_MIME, true)) {
                fail('validation', 'Unsupported image type ' . $mime, 415);
            }
            return [$bytes, $mime];
        }
        fail('validation', 'Send the receipt as multipart field "image" or JSON image_base64', 400);
        return ['', '']; // unreachable
    }

    private function sniffMime(string $bytes): ?string
    {
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($bytes, "\x89PNG", 4) === 0) {
            return 'image/png';
        }
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (strncmp($bytes, 'GIF8', 4) === 0) {
            return 'image/gif';
        }
        if (substr($bytes, 4, 4) === 'ftyp') {
            $brand = substr($bytes, 8, 4);
            if (in_array($brand, ['heic', 'heix', 'hevc', 'mif1', 'msf1', 'heif'], true)) {
                return 'image/heic';
            }
        }
        return null;
    }
}
