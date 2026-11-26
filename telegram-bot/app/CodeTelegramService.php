<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;
use Throwable;

/** Processes channel posts only from admin-approved Telegram channels. */
final class CodeTelegramService
{
    public static function enabled(): bool
    {
        return Env::bool('CODE_TELEGRAM_ENABLED', false);
    }

    public static function ingestChannelPost(array $message, bool $edited = false): bool
    {
        if (!self::enabled()) return false;

        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = trim((string) ($chat['id'] ?? ''));
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($chatId === '' || $messageId < 1 || ($chat['type'] ?? '') !== 'channel') return false;

        $source = Database::one(
            'SELECT * FROM code_telegram_sources WHERE chat_id = ? AND is_active = TRUE LIMIT 1',
            [$chatId]
        );
        if ($source === null) return false;

        $text = trim((string) ($message['text'] ?? ''));
        $caption = trim((string) ($message['caption'] ?? ''));
        $combined = trim(implode("\n\n", array_filter([$text, $caption], static fn(string $value): bool => $value !== '')));

        $media = null;
        if (is_array($message['video'] ?? null)) {
            $media = $message['video'];
        } elseif (is_array($message['document'] ?? null)
            && str_starts_with(strtolower((string) ($message['document']['mime_type'] ?? '')), 'video/')) {
            $media = $message['document'];
        }

        $fileId = is_array($media) ? trim((string) ($media['file_id'] ?? '')) : '';
        $fileSize = is_array($media) ? (int) ($media['file_size'] ?? 0) : 0;
        $mimeType = is_array($media) ? strtolower(trim((string) ($media['mime_type'] ?? 'video/mp4'))) : '';
        $mediaKind = $fileId !== '' ? 'video' : 'text';
        $sourceLink = self::messageLink($source, $messageId);
        $date = isset($message['date']) ? gmdate('c', (int) $message['date']) : gmdate('c');

        $row = Database::one(
            "INSERT INTO code_telegram_posts
                (source_id, telegram_message_id, update_kind, message_date, message_text, source_link,
                 media_kind, media_file_id, media_file_size, media_mime_type, extracted_text, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (source_id, telegram_message_id) DO UPDATE SET
                update_kind = EXCLUDED.update_kind,
                message_date = EXCLUDED.message_date,
                message_text = EXCLUDED.message_text,
                source_link = EXCLUDED.source_link,
                media_kind = EXCLUDED.media_kind,
                media_file_id = EXCLUDED.media_file_id,
                media_file_size = EXCLUDED.media_file_size,
                media_mime_type = EXCLUDED.media_mime_type,
                extracted_text = CASE WHEN EXCLUDED.media_kind = 'text' THEN EXCLUDED.message_text ELSE '' END,
                status = CASE WHEN EXCLUDED.media_kind = 'video' THEN 'queued' ELSE 'processing' END,
                error_message = '',
                attempts = 0,
                updated_at = NOW()
             RETURNING id, status",
            [
                (int) $source['id'], $messageId, $edited ? 'edited_channel_post' : 'channel_post',
                $date, mb_substr($combined, 0, 12000), $sourceLink, $mediaKind, $fileId,
                max(0, $fileSize), $mimeType, $mediaKind === 'text' ? mb_substr($combined, 0, 12000) : '',
                $mediaKind === 'video' ? 'queued' : 'processing',
            ]
        );
        if ($row === null) return false;

        $postId = (int) $row['id'];
        if ($edited) Database::run('DELETE FROM code_telegram_codes WHERE post_id = ?', [$postId]);

        Database::run(
            'UPDATE code_telegram_sources SET last_update_at = NOW(), updated_at = NOW() WHERE id = ?',
            [(int) $source['id']]
        );

        if ($mediaKind === 'video') {
            if ($combined !== '') self::storeCandidates($postId, $combined, $source, true);
            return true;
        }

        $count = self::storeCandidates($postId, $combined, $source, false);
        Database::run(
            "UPDATE code_telegram_posts SET status = 'completed', extracted_count = ?, processed_at = NOW(), updated_at = NOW() WHERE id = ?",
            [$count, $postId]
        );
        return true;
    }

    public static function runOnce(): bool
    {
        if (!self::enabled()) return false;

        $post = Database::transaction(static function (): ?array {
            $row = Database::one(
                "SELECT p.*, s.auto_publish, s.title AS source_title, s.username AS source_username
                 FROM code_telegram_posts p
                 JOIN code_telegram_sources s ON s.id = p.source_id
                 WHERE p.status = 'queued' AND s.is_active = TRUE
                 ORDER BY p.created_at ASC
                 LIMIT 1 FOR UPDATE OF p SKIP LOCKED"
            );
            if ($row === null) return null;
            Database::run(
                "UPDATE code_telegram_posts SET status = 'processing', attempts = attempts + 1, started_at = NOW(), updated_at = NOW() WHERE id = ?",
                [(int) $row['id']]
            );
            return $row;
        });
        if ($post === null) return false;

        $postId = (int) $post['id'];
        try {
            $extracted = trim((string) ($post['extracted_text'] ?? ''));
            if (($post['media_kind'] ?? '') === 'video') {
                $fileId = trim((string) ($post['media_file_id'] ?? ''));
                $fileSize = (int) ($post['media_file_size'] ?? 0);
                $maxBytes = max(1_048_576, min(20_971_520, Env::int('CODE_TELEGRAM_MAX_VIDEO_BYTES', 20_971_520)));
                if ($fileId === '') throw new RuntimeException('Bài đăng không có video file_id hợp lệ.');
                if ($fileSize > $maxBytes) throw new RuntimeException('Video vượt giới hạn 20 MB của Telegram Bot API. Hãy dùng clip ngắn hơn.');
                $api = new TelegramApi(TelegramAuth::token('primary'));
                $download = $api->downloadVideoToTemp($fileId, $maxBytes);
                try {
                    $ocrText = self::extractVideoText($download['path']);
                } finally {
                    if (is_file($download['path'])) @unlink($download['path']);
                }
                $extracted = trim($extracted . "\n\n" . $ocrText);
                Database::run('UPDATE code_telegram_posts SET extracted_text = ?, updated_at = NOW() WHERE id = ?', [mb_substr($extracted, 0, 24000), $postId]);
            }

            $source = [
                'id' => (int) $post['source_id'],
                'auto_publish' => $post['auto_publish'],
                'title' => $post['source_title'],
                'username' => $post['source_username'],
            ];
            $count = self::storeCandidates($postId, $extracted, $source, ($post['media_kind'] ?? '') === 'video');
            Database::run(
                "UPDATE code_telegram_posts SET status = 'completed', processed_at = NOW(), extracted_count = ?, error_message = '', updated_at = NOW() WHERE id = ?",
                [$count, $postId]
            );
            return true;
        } catch (Throwable $error) {
            $message = mb_substr(trim($error->getMessage()), 0, 500);
            Database::run("UPDATE code_telegram_posts SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?", [$message !== '' ? $message : 'Không thể xử lý bài đăng.', $postId]);
            error_log('[code-telegram-worker] post=' . $postId . ' ' . $error::class . ': ' . $message);
            return true;
        }
    }

    private static function extractVideoText(string $videoPath): string
    {
        if (!is_file($videoPath) || !is_readable($videoPath)) throw new RuntimeException('Không đọc được video tạm.');
        if (!function_exists('exec')) throw new RuntimeException('Worker chưa được cấp quyền chạy ffmpeg/tesseract.');
        $directory = sys_get_temp_dir() . '/hendy-code-ocr-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Không tạo được thư mục OCR tạm.');

        try {
            $pattern = $directory . '/frame-%03d.jpg';
            $command = 'timeout 35s ffmpeg -nostdin -hide_banner -loglevel error -ss 0 -t 120 -i '
                . escapeshellarg($videoPath)
                . ' -vf ' . escapeshellarg('fps=1/3,scale=1280:-2:force_original_aspect_ratio=decrease')
                . ' -frames:v 24 -q:v 5 ' . escapeshellarg($pattern) . ' 2>&1';
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);
            $frames = glob($directory . '/frame-*.jpg') ?: [];
            if ($frames === []) throw new RuntimeException('Không trích xuất được khung hình. Hỗ trợ video MP4/MOV/WEBM.');

            $chunks = [];
            foreach (array_slice($frames, 0, 24) as $frame) {
                $ocrCommand = 'timeout 8s tesseract ' . escapeshellarg($frame) . ' stdout -l vie+eng --psm 6 2>/dev/null';
                $recognized = [];
                $ocrExit = 0;
                exec($ocrCommand, $recognized, $ocrExit);
                if ($ocrExit === 0 && $recognized !== []) $chunks[] = implode("\n", $recognized);
            }
            return mb_substr(trim(implode("\n\n", $chunks)), 0, 16000);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file)) @unlink($file);
            @rmdir($directory);
        }
    }

    private static function storeCandidates(int $postId, string $text, array $source, bool $fromVideo): int
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (trim($text) === '') return 0;
        $candidates = self::extractCandidates($text);

        $autoPublish = filter_var($source['auto_publish'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $status = $autoPublish && !$fromVideo ? 'active' : 'pending_review';
        $category = self::classify($text);
        $context = mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, 500);
        $count = 0;
        foreach (array_keys($candidates) as $code) {
            $duplicate = Database::one(
                "SELECT id FROM code_telegram_codes
                 WHERE normalized_code = ? AND post_id <> ? AND status IN ('pending_review','active','used')
                 LIMIT 1",
                [$code, $postId]
            );
            if ($duplicate !== null) {
                continue;
            }
            Database::run(
                "INSERT INTO code_telegram_codes
                    (post_id, source_id, code_value, normalized_code, category, context_text, confidence, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (post_id, normalized_code) DO UPDATE SET
                    context_text = EXCLUDED.context_text,
                    confidence = EXCLUDED.confidence,
                    status = CASE WHEN code_telegram_codes.status IN ('used', 'rejected') THEN code_telegram_codes.status ELSE EXCLUDED.status END,
                    updated_at = NOW()",
                [$postId, (int) $source['id'], $code, $code, $category, $context, $fromVideo ? 0.72 : 0.92, $status]
            );
            $count++;
        }
        return $count;
    }

    /** Return unique, normalized code-like tokens from a text/caption/OCR transcript. */
    public static function extractCandidates(string $text): array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (trim($text) === '') return [];
        $candidates = [];
        $patterns = [
            '/(?:gift[\s_-]?code|promo(?:tional)?[\s_-]?code|code\s*(?:game|tele|màn(?:\s*hình)?|phần\s*mềm)|code|mã\s*(?:khuyến\s*mãi|game|phần\s*mềm|tele|màn|code)?|mã\s*quà)\s*[:：=#-]?\s*[\'"“”]*([A-Z0-9][A-Z0-9_-]{4,31})/iu',
            '/(?:^|\n)\s*[*#>\- ]*([A-Z0-9][A-Z0-9_-]{5,23})\s*[* ]*(?=$|\n)/m',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches) > 0) {
                foreach ($matches[1] as $candidate) {
                    $candidate = strtoupper(trim((string) $candidate, " \t\n\r\0\x0B'\".,:;"));
                    if (self::isLikelyCode($candidate)) $candidates[$candidate] = true;
                }
            }
        }
        return array_keys($candidates);
    }

    private static function isLikelyCode(string $candidate): bool
    {
        $length = strlen($candidate);
        if ($length < 5 || $length > 32) return false;
        if (!preg_match('/[A-Z]/', $candidate) || !preg_match('/[0-9]/', $candidate)) return false;
        if (preg_match('/^(.)\1{5,}$/', $candidate)) return false;
        return true;
    }

    private static function classify(string $text): string
    {
        $value = mb_strtolower($text, 'UTF-8');
        if (preg_match('/(?:code\s*màn|mã\s*màn|màn\s*hình|screen\s*code)/u', $value)) return 'screen';
        if (preg_match('/(?:telegram|code\s*tele|mã\s*tele|premium|bot\s*tele)/u', $value)) return 'telegram';
        if (preg_match('/(?:phần\s*mềm|software|license|tool|ứng\s*dụng|app\s*code)/u', $value)) return 'software';
        if (preg_match('/(?:game|trò\s*chơi|minigame|qq88|78win|sc88|c168|cm88|f8bet)/u', $value)) return 'game';
        return 'promo';
    }

    private static function messageLink(array $source, int $messageId): string
    {
        $username = trim((string) ($source['username'] ?? ''));
        if ($username !== '') return 'https://t.me/' . rawurlencode(ltrim($username, '@')) . '/' . $messageId;
        $chatId = (string) ($source['chat_id'] ?? '');
        if (preg_match('/^-100(\d+)$/', $chatId, $match)) return 'https://t.me/c/' . $match[1] . '/' . $messageId;
        return '';
    }

    public static function recentCodes(int $limit = 10, string $search = ''): array
    {
        $limit = max(1, min(50, $limit));
        $parameters = [];
        $where = "c.status = 'active'";
        if ($search !== '') {
            $where .= ' AND (c.code_value ILIKE ? OR c.context_text ILIKE ? OR s.title ILIKE ?)';
            $query = '%' . mb_substr($search, 0, 80) . '%';
            $parameters = [$query, $query, $query];
        }
        $parameters[] = $limit;
        return Database::all(
            "SELECT c.id, c.code_value, c.category, c.context_text, c.confidence, c.created_at,
                    s.title AS source_title, p.source_link
             FROM code_telegram_codes c
             JOIN code_telegram_sources s ON s.id = c.source_id
             JOIN code_telegram_posts p ON p.id = c.post_id
             WHERE {$where}
             ORDER BY c.created_at DESC LIMIT ?",
            $parameters
        );
    }

    public static function messageLinkForSource(array $source, int $messageId): string
    {
        return self::messageLink($source, $messageId);
    }
}
