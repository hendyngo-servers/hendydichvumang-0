<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;
use Throwable;

/**
 * Small PostgreSQL-backed queue for image edits and music generation.
 * Provider secrets remain server-side in Render Environment.
 */
final class AiMediaService
{
    public static function readiness(): array
    {
        $queueEnabled = Env::bool('AI_MEDIA_QUEUE_ENABLED', false);
        $adminEnabled = (string) (Database::value('SELECT value FROM app_settings WHERE key=?', ['ai_media_enabled']) ?? '0') === '1';
        $musicFlag = (string) (Database::value('SELECT value FROM app_settings WHERE key=?', ['ai_media_music_enabled']) ?? '0') === '1';
        $imageFlag = (string) (Database::value('SELECT value FROM app_settings WHERE key=?', ['ai_media_image_enabled']) ?? '0') === '1';
        $musicKey = trim((string) Env::get('ELEVENLABS_API_KEY', '')) !== '';
        $imageKey = trim((string) Env::get('OPENAI_API_KEY', '')) !== '';
        return [
            'queueConfigured' => $queueEnabled,
            'workerEnabled' => $queueEnabled,
            'adminEnabled' => $adminEnabled,
            'musicKeyConfigured' => $musicKey,
            'imageKeyConfigured' => $imageKey,
            'enabled' => $queueEnabled && $adminEnabled,
            'musicEnabled' => $queueEnabled && $adminEnabled && $musicFlag && $musicKey,
            'imageEnabled' => $queueEnabled && $adminEnabled && $imageFlag && $imageKey,
            'musicFlag' => $musicFlag,
            'imageFlag' => $imageFlag,
        ];
    }

    public static function featureEnabled(string $type): bool
    {
        $status = self::readiness();
        return match ($type) {
            'music' => (bool) $status['musicEnabled'],
            'image' => (bool) $status['imageEnabled'],
            default => false,
        };
    }

    public static function setFeatures(array $input): array
    {
        $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $music = filter_var($input['musicEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $image = filter_var($input['imageEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // The global kill-switch always wins and must remain usable if provider keys were revoked.
        if (!$enabled) {
            $music = false;
            $image = false;
        }
        $queueConfigured = Env::bool('AI_MEDIA_QUEUE_ENABLED', false);
        $musicKeyConfigured = trim((string) Env::get('ELEVENLABS_API_KEY', '')) !== '';
        $imageKeyConfigured = trim((string) Env::get('OPENAI_API_KEY', '')) !== '';
        if ($enabled && !$queueConfigured) {
            throw new RuntimeException('AI_MEDIA_QUEUE_ENABLED chưa bật trong Render Environment; chưa thể khởi chạy AI Worker.');
        }
        if ($music && (!$enabled || !$queueConfigured || !$musicKeyConfigured)) {
            throw new RuntimeException('Không thể bật Tạo âm nhạc AI: cần AI Studio tổng, AI worker và ELEVENLABS_API_KEY.');
        }
        if ($image && (!$enabled || !$queueConfigured || !$imageKeyConfigured)) {
            throw new RuntimeException('Không thể bật Chỉnh sửa ảnh AI: cần AI Studio tổng, AI worker và OPENAI_API_KEY.');
        }
        Database::transaction(static function () use ($enabled, $music, $image): void {
            foreach ([
                'ai_media_enabled' => $enabled ? '1' : '0',
                'ai_media_music_enabled' => $music ? '1' : '0',
                'ai_media_image_enabled' => $image ? '1' : '0',
            ] as $key => $value) {
                Database::run(
                    'INSERT INTO app_settings(key,value,is_public,updated_at) VALUES(?,?,FALSE,NOW()) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value,is_public=FALSE,updated_at=NOW()',
                    [$key, $value]
                );
            }
        });
        return self::adminSnapshot();
    }

    public static function adminSnapshot(): array
    {
        return [
            ...self::readiness(),
            'stats' => [
                'queued' => (int) Database::value("SELECT COUNT(*) FROM ai_media_jobs WHERE status='queued'"),
                'processing' => (int) Database::value("SELECT COUNT(*) FROM ai_media_jobs WHERE status='processing'"),
                'completed' => (int) Database::value("SELECT COUNT(*) FROM ai_media_jobs WHERE status='completed'"),
                'failed' => (int) Database::value("SELECT COUNT(*) FROM ai_media_jobs WHERE status='failed'"),
                'today' => (int) Database::value("SELECT COUNT(*) FROM ai_media_jobs WHERE created_at >= (date_trunc('day', NOW() AT TIME ZONE 'Asia/Ho_Chi_Minh') AT TIME ZONE 'Asia/Ho_Chi_Minh')"),
            ],
            'jobs' => Database::all(
                'SELECT id,user_id,job_type,status,attempts,error_message,created_at,completed_at FROM ai_media_jobs ORDER BY created_at DESC LIMIT 50'
            ),
        ];
    }

    public static function create(string $userId, string $botKey, string $type, string $prompt, string $sourceFileId = ''): array
    {
        if (!in_array($type, ['music', 'image'], true) || !self::featureEnabled($type)) {
            throw new RuntimeException('Công cụ AI này đang tạm tắt hoặc chưa đủ cấu hình. Vui lòng quay lại sau.');
        }
        if (!preg_match('/\A[0-9]{5,20}\z/', $userId) || $botKey !== 'primary') {
            throw new RuntimeException('Tài khoản hoặc Bot không hợp lệ.');
        }
        if (!in_array($type, ['music', 'image'], true)) {
            throw new RuntimeException('Loại tác vụ AI không hợp lệ.');
        }
        $prompt = trim($prompt);
        $maxPrompt = $type === 'music' ? 500 : 1000;
        if (mb_strlen($prompt) < 5 || mb_strlen($prompt) > $maxPrompt) {
            throw new RuntimeException('Mô tả tác vụ AI không đúng độ dài cho phép.');
        }
        if ($type === 'music' && !self::featureEnabled('music')) {
            throw new RuntimeException('Tạo âm nhạc AI chưa được Admin mở hoặc chưa có API key hợp lệ.');
        }
        if ($type === 'image' && (!self::featureEnabled('image') || $sourceFileId === '')) {
            throw new RuntimeException('Chỉnh sửa ảnh AI cần được Admin mở, có OPENAI_API_KEY và ảnh đầu vào.');
        }

        $dailyLimit = max(1, min(10, Env::int('AI_MEDIA_MAX_PER_DAY', 3)));
        return Database::transaction(function () use ($userId, $botKey, $type, $prompt, $sourceFileId, $dailyLimit): array {
            $user = Database::one('SELECT user_id FROM users WHERE user_id=? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new RuntimeException('Không tìm thấy tài khoản. Hãy gửi /start trước.');
            }
            $active = (int)Database::value(
                "SELECT COUNT(*) FROM ai_media_jobs WHERE user_id=? AND status IN ('queued','processing')",
                [$userId]
            );
            if ($active >= 1) {
                throw new RuntimeException('Bạn đang có một tác vụ AI chưa hoàn tất. Vui lòng đợi kết quả trước.');
            }
            $today = (int)Database::value(
                "SELECT COUNT(*) FROM ai_media_jobs WHERE user_id=? AND created_at >= (date_trunc('day', NOW() AT TIME ZONE 'Asia/Ho_Chi_Minh') AT TIME ZONE 'Asia/Ho_Chi_Minh')",
                [$userId]
            );
            if ($today >= $dailyLimit) {
                throw new RuntimeException('Bạn đã đạt giới hạn ' . $dailyLimit . ' tác vụ AI/ngày.');
            }
            $id = self::uuid();
            $row = Database::one(
                "INSERT INTO ai_media_jobs(id,user_id,bot_key,job_type,prompt,source_file_id,status) " .
                "VALUES(?,?,?,?,?,?,'queued') RETURNING id,status,created_at",
                [$id,$userId,$botKey,$type,$prompt,$sourceFileId]
            );
            if ($row === null) throw new RuntimeException('Không thể tạo tác vụ AI.');
            return $row;
        });
    }

    /** Retry one failed AI Studio job after an Admin has reviewed its error. */
    public static function retryJob(string $jobId): array
    {
        if (!preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/i', $jobId)) {
            throw new RuntimeException('Mã AI job không hợp lệ.');
        }
        $job = Database::one(
            "UPDATE ai_media_jobs
                SET status='queued', attempts=0, claimed_at=NULL, completed_at=NULL,
                    error_message='', updated_at=NOW()
              WHERE id=? AND status='failed'
          RETURNING id,status,job_type,user_id",
            [$jobId]
        );
        if ($job === null) {
            throw new RuntimeException('Chỉ có thể chạy lại AI job đang ở trạng thái thất bại.');
        }
        return $job;
    }

    /** Recover jobs left in processing when a Render worker/container restarted. */
    private static function recoverStaleJobs(): void
    {
        $staleMinutes = max(10, min(60, Env::int('AI_MEDIA_STALE_MINUTES', 15)));
        Database::run(
            "UPDATE ai_media_jobs
                SET status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'queued' END,
                    error_message = CASE
                        WHEN attempts >= 3 THEN 'Worker stopped unexpectedly after the maximum number of attempts. Admin can retry this job.'
                        ELSE 'Worker restarted; this job was safely returned to the queue.'
                    END,
                    claimed_at=NULL,
                    completed_at=CASE WHEN attempts >= 3 THEN NOW() ELSE NULL END,
                    updated_at=NOW()
              WHERE status='processing'
                AND COALESCE(claimed_at, updated_at) < NOW() - make_interval(mins => ?)",
            [$staleMinutes]
        );
    }
    /**
     * Claim and process exactly one job. The queue uses SKIP LOCKED so multiple
     * workers can safely coexist. A failed job is recorded and notified once.
     */
    public static function runOnce(): bool
    {
        self::recoverStaleJobs();
        $job = Database::transaction(function (): ?array {
            $next = Database::one(
                "SELECT id,user_id,bot_key,job_type,prompt,source_file_id FROM ai_media_jobs " .
                "WHERE status='queued' AND ((job_type='music' AND ?=TRUE) OR (job_type='image' AND ?=TRUE)) " .
                "ORDER BY created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED",
                [self::featureEnabled('music'), self::featureEnabled('image')]
            );
            if ($next === null) return null;
            Database::run(
                "UPDATE ai_media_jobs SET status='processing',attempts=attempts+1,claimed_at=NOW(),updated_at=NOW() WHERE id=?",
                [$next['id']]
            );
            return $next;
        });
        if ($job === null) return false;

        $token = TelegramAuth::token('primary');
        $telegram = $token !== '' ? new TelegramApi($token) : null;
        $outputPath = null;
        $input = null;
        try {
            if ($telegram === null) throw new RuntimeException('Bot token chưa sẵn sàng.');
            if ((string)$job['job_type'] === 'music') {
                $outputPath = self::generateMusic((string)$job['prompt']);
                $telegram->sendAudioFile(
                    (string)$job['user_id'],
                    $outputPath,
                    '🎵 <b>NHẠC AI HENDY ĐÃ HOÀN TẤT</b>' . "\n" . 'Mã tác vụ: <code>' . self::escape(substr((string)$job['id'],0,8)) . '</code>'
                );
            } elseif ((string)$job['job_type'] === 'image') {
                $input = $telegram->downloadImageToTemp((string)$job['source_file_id']);
                $outputPath = self::editImage($input['path'], $input['mime'], (string)$job['prompt']);
                $telegram->sendPhotoFile(
                    (string)$job['user_id'],
                    $outputPath,
                    '🖼️ <b>ẢNH AI HENDY ĐÃ CHỈNH SỬA</b>' . "\n" . 'Mã tác vụ: <code>' . self::escape(substr((string)$job['id'],0,8)) . '</code>'
                );
            } else {
                throw new RuntimeException('Loại tác vụ AI không được hỗ trợ.');
            }
            Database::run(
                "UPDATE ai_media_jobs SET status='completed',claimed_at=NULL,completed_at=NOW(),updated_at=NOW(),error_message='' WHERE id=?",
                [$job['id']]
            );
        } catch (Throwable $error) {
            Database::run(
                "UPDATE ai_media_jobs SET status='failed',claimed_at=NULL,completed_at=NOW(),updated_at=NOW(),error_message=? WHERE id=?",
                [mb_substr($error->getMessage(),0,500),(string)$job['id']]
            );
            if ($telegram !== null) {
                try {
                    $telegram->sendMessage(
                        (string)$job['user_id'],
                        '❌ <b>TÁC VỤ AI CHƯA HOÀN TẤT</b>' . "\n" .
                        'Mã tác vụ: <code>' . self::escape(substr((string)$job['id'],0,8)) . '</code>' . "\n" .
                        'Hệ thống đã ghi nhận lỗi. Vui lòng liên hệ CSKH nếu cần kiểm tra.'
                    );
                } catch (Throwable $notifyError) {
                    error_log('AI media job notice failed: ' . $notifyError::class);
                }
            }
            error_log('AI media job failed (' . substr((string)$job['id'],0,8) . '): ' . $error->getMessage());
        } finally {
            if (is_array($input) && isset($input['path']) && is_file($input['path'])) @unlink($input['path']);
            if (is_string($outputPath) && is_file($outputPath)) @unlink($outputPath);
        }
        return true;
    }

    private static function generateMusic(string $prompt): string
    {
        $apiKey = trim((string)Env::get('ELEVENLABS_API_KEY', ''));
        if ($apiKey === '') throw new RuntimeException('Thiếu ELEVENLABS_API_KEY.');
        $model = trim((string)Env::get('ELEVENLABS_MUSIC_MODEL', 'music_v2_5'));
        if (!preg_match('/\A[A-Za-z0-9_.-]{2,80}\z/', $model)) $model = 'music_v2_5';
        $body = json_encode([
            'prompt' => $prompt,
            'model_id' => $model,
            'music_length_ms' => 30_000,
            'force_instrumental' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $handle = curl_init('https://api.elevenlabs.io/v1/music?output_format=mp3_48000_192');
        if ($handle === false) throw new RuntimeException('Không khởi tạo được kết nối tạo nhạc.');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: audio/mpeg','xi-api-key: '.$apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 240,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $bytes = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $mime = strtolower(trim(explode(';',(string)curl_getinfo($handle,CURLINFO_CONTENT_TYPE))[0]));
        curl_close($handle);
        if ($bytes === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Nhà cung cấp âm nhạc AI trả về HTTP '.$status.'.');
        }
        if (!is_string($bytes) || strlen($bytes) < 1024 || strlen($bytes) > 25_000_000
            || !in_array($mime,['audio/mpeg','audio/mp3','application/octet-stream'],true)) {
            throw new RuntimeException('Nhà cung cấp trả về tệp âm thanh không hợp lệ hoặc vượt 25 MB.');
        }
        $path = tempnam(sys_get_temp_dir(),'hendy-ai-music-');
        if ($path === false || file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)) {
            if (is_string($path) && is_file($path)) @unlink($path);
            throw new RuntimeException('Không lưu được tệp nhạc tạm.');
        }
        return $path;
    }

    private static function editImage(string $inputPath, string $mime, string $prompt): string
    {
        $apiKey = trim((string)Env::get('OPENAI_API_KEY', ''));
        if ($apiKey === '') throw new RuntimeException('Thiếu OPENAI_API_KEY.');
        $model = trim((string)Env::get('OPENAI_IMAGE_MODEL', 'gpt-image-2.5-sunburst'));
        if (!preg_match('/\A[A-Za-z0-9_.-]{3,100}\z/', $model)) $model = 'gpt-image-2.5-sunburst';
        $fields = [
            'model' => $model,
            'prompt' => $prompt,
            'image' => new \CURLFile($inputPath,$mime,basename($inputPath)),
            'size' => '1024x1024',
            'quality' => 'medium',
            'output_format' => 'png',
            'n' => '1',
        ];
        $handle = curl_init('https://api.openai.com/v1/images/edits');
        if ($handle === false) throw new RuntimeException('Không khởi tạo được kết nối chỉnh ảnh AI.');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$apiKey,'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 240,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Nhà cung cấp chỉnh ảnh AI trả về HTTP '.$status.'.');
        }
        if (!is_string($response) || strlen($response)>40_000_000) throw new RuntimeException('Phản hồi ảnh AI vượt giới hạn.');
        $decoded = json_decode($response,true);
        $encoded = is_array($decoded) ? (string)($decoded['data'][0]['b64_json'] ?? '') : '';
        $bytes = $encoded !== '' ? base64_decode($encoded,true) : false;
        if (!is_string($bytes) || strlen($bytes)<16 || strlen($bytes)>30_000_000) {
            throw new RuntimeException('Nhà cung cấp không trả về ảnh PNG hợp lệ.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info===false || ($info['mime']??'')!=='image/png') throw new RuntimeException('Kết quả chỉnh ảnh không phải PNG hợp lệ.');
        $path = tempnam(sys_get_temp_dir(),'hendy-ai-image-');
        if ($path===false || file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)) {
            if(is_string($path)&&is_file($path))@unlink($path);
            throw new RuntimeException('Không lưu được ảnh AI tạm.');
        }
        return $path;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
