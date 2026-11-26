<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;
use Throwable;

/**
 * AI Cover queue using the existing primary Telegram bot, PostgreSQL and
 * private Neon Object Storage. No extra bot token or public voice catalog.
 */
final class AiCoverService
{
    public const TERMS_VERSION = '2026-11-13-v1';

    public static function enabled(): bool
    {
        return self::infrastructureReady() && self::runtimeEnabled() && self::hasReadyVoice();
    }

    private static function hasReadyVoice(): bool
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM ai_cover_voices
             WHERE enabled=TRUE AND voice_owner_consent=TRUE
               AND length(trim(rights_holder))>0
               AND length(trim(license_name))>0
               AND length(trim(authorization_reference))>0
               AND available=TRUE AND checked_at > NOW() - INTERVAL '30 minutes'"
        ) > 0;
    }

    private static function infrastructureReady(): bool
    {
        if (!Env::bool('AI_COVER_ENABLED', false) || !Env::bool('AI_COVER_WORKER_ENABLED', false)) return false;
        try {
            return NeonStorage::configured()
                && self::workerBaseUrl() !== ''
                && strlen(trim((string) Env::get('AI_COVER_CALLBACK_SECRET', ''))) >= 32;
        } catch (Throwable) {
            return false;
        }
    }

    public static function runtimeEnabled(): bool
    {
        return (string) (Database::value('SELECT value FROM app_settings WHERE key=?', ['ai_cover_enabled']) ?? '0') === '1';
    }

    public static function setServiceEnabled(bool $enabled, string $adminId): array
    {
        if ($enabled && !self::infrastructureReady()) {
            throw new RuntimeException('AI Cover chưa đủ cấu hình. Cần bật AI_COVER_ENABLED và AI_COVER_WORKER_ENABLED, cấu hình worker HTTPS, callback secret và Neon Object Storage trước.');
        }
        if ($enabled && !self::hasReadyVoice()) {
            throw new RuntimeException('Chưa có preset AI Cover nào được cấp phép và xác minh khả dụng trong 30 phút gần nhất. Hãy thêm preset, lưu hồ sơ đồng ý và bấm Kiểm tra model trước khi bật.');
        }
        Database::run(
            'INSERT INTO app_settings(key,value,is_public,updated_at) VALUES(?,?,FALSE,NOW()) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value,is_public=FALSE,updated_at=NOW()',
            ['ai_cover_enabled', $enabled ? '1' : '0']
        );
        return [
            'enabled' => self::enabled(),
            'runtimeEnabled' => self::runtimeEnabled(),
            'adminId' => $adminId,
        ];
    }

    public static function readiness(): array
    {
        $storage = false;
        try { $storage = NeonStorage::configured(); } catch (Throwable) {}
        $secretConfigured = strlen(trim((string) Env::get('AI_COVER_CALLBACK_SECRET', ''))) >= 32;
        $workerUrl = self::workerBaseUrl();
        $infrastructureReady = self::infrastructureReady();
        $voiceReady = self::hasReadyVoice();
        return [
            'enabled' => self::enabled(),
            'runtimeEnabled' => self::runtimeEnabled(),
            'infrastructureReady' => $infrastructureReady,
            'voiceReady' => $voiceReady,
            'workerEnabled' => Env::bool('AI_COVER_WORKER_ENABLED', false),
            'workerConfigured' => $workerUrl !== '',
            'storageConfigured' => $storage,
            'callbackSecretConfigured' => $secretConfigured,
            'ready' => $infrastructureReady && $voiceReady,
        ];
    }

    public static function session(string $userId): ?array
    {
        return Database::one(
            'SELECT telegram_user_id, chat_id, selected_voice_id, terms_version, terms_accepted_at ' .
            'FROM ai_cover_sessions WHERE telegram_user_id = ?',
            [$userId]
        );
    }

    public static function acceptTerms(string $userId, string $chatId): void
    {
        self::assertEnabled();
        if (!preg_match('/\A[0-9]{5,20}\z/', $userId) || $chatId === '') {
            throw new RuntimeException('Tài khoản Telegram không hợp lệ.');
        }
        Database::run(
            "INSERT INTO ai_cover_sessions(telegram_user_id,chat_id,terms_version,terms_accepted_at,last_seen_at)
             VALUES(?,?,?,NOW(),NOW())
             ON CONFLICT(telegram_user_id) DO UPDATE SET
               chat_id=EXCLUDED.chat_id, terms_version=EXCLUDED.terms_version,
               terms_accepted_at=CASE WHEN ai_cover_sessions.terms_version=EXCLUDED.terms_version THEN COALESCE(ai_cover_sessions.terms_accepted_at,NOW()) ELSE NOW() END,
               last_seen_at=NOW(), updated_at=NOW()",
            [$userId, $chatId, self::TERMS_VERSION]
        );
    }

    public static function availableVoices(): array
    {
        self::assertEnabled();
        return Database::all(
            "SELECT id, name, description
             FROM ai_cover_voices
             WHERE enabled=TRUE AND voice_owner_consent=TRUE
               AND length(trim(rights_holder))>0
               AND length(trim(license_name))>0
               AND length(trim(authorization_reference))>0
               AND available=TRUE AND checked_at > NOW() - INTERVAL '30 minutes'
             ORDER BY name ASC LIMIT 20"
        );
    }

    public static function chooseVoice(string $userId, string $chatId, string $voiceId): array
    {
        self::assertEnabled();
        if (!preg_match('/\A[a-z0-9][a-z0-9_-]{1,47}\z/', $voiceId)) {
            throw new RuntimeException('Preset giọng không hợp lệ.');
        }
        $session = self::session($userId);
        if ($session === null || empty($session['terms_accepted_at']) || ($session['terms_version'] ?? '') !== self::TERMS_VERSION) {
            throw new RuntimeException('Bạn cần xác nhận điều khoản AI Cover trước.');
        }
        $voice = Database::one(
            "SELECT id,name FROM ai_cover_voices
             WHERE id=? AND enabled=TRUE AND voice_owner_consent=TRUE AND available=TRUE
               AND checked_at > NOW() - INTERVAL '30 minutes'
             LIMIT 1",
            [$voiceId]
        );
        if ($voice === null) {
            throw new RuntimeException('Preset hiện chưa sẵn sàng. Vui lòng chọn giọng khác hoặc thử lại sau.');
        }
        Database::run(
            'UPDATE ai_cover_sessions SET chat_id=?, selected_voice_id=?, last_seen_at=NOW(), updated_at=NOW() WHERE telegram_user_id=?',
            [$chatId, $voiceId, $userId]
        );
        return $voice;
    }

    /**
     * Download one Telegram audio file, store it privately, then enqueue it.
     * Files are never processed synchronously in the webhook request.
     */
    public static function queueAudio(
        string $userId,
        string $chatId,
        int $updateId,
        array $message,
        TelegramApi $telegram
    ): array {
        self::assertEnabled();
        if (!preg_match('/\A[0-9]{5,20}\z/', $userId) || $chatId === '' || $updateId < 1) {
            throw new RuntimeException('Thông tin yêu cầu AI Cover không hợp lệ.');
        }
        $session = self::session($userId);
        if ($session === null || empty($session['terms_accepted_at'])
            || ($session['terms_version'] ?? '') !== self::TERMS_VERSION
            || empty($session['selected_voice_id'])) {
            throw new RuntimeException('Bạn cần xác nhận điều khoản và chọn preset giọng trước khi gửi audio.');
        }

        $voice = Database::one(
            "SELECT id,name FROM ai_cover_voices
             WHERE id=? AND enabled=TRUE AND voice_owner_consent=TRUE AND available=TRUE
               AND checked_at > NOW() - INTERVAL '30 minutes'
             LIMIT 1",
            [(string) $session['selected_voice_id']]
        );
        if ($voice === null) {
            throw new RuntimeException('Preset đã chọn không còn khả dụng. Hãy mở lại AI Cover và chọn giọng khác.');
        }

        $media = self::audioMedia($message);
        if ($media === null) {
            throw new RuntimeException('Hãy gửi tệp MP3, WAV, FLAC, M4A, AAC, OGG hoặc OPUS.');
        }
        $maxBytes = max(1, min(20, Env::int('AI_COVER_MAX_AUDIO_SIZE_MB', 20))) * 1024 * 1024;
        $maxDuration = max(15, min(300, Env::int('AI_COVER_MAX_AUDIO_DURATION_SECONDS', 180)));
        if ($media['size'] > $maxBytes) {
            throw new RuntimeException('Audio vượt giới hạn ' . (int) floor($maxBytes / 1024 / 1024) . ' MB.');
        }
        if ($media['duration'] > $maxDuration) {
            throw new RuntimeException('Audio vượt giới hạn ' . $maxDuration . ' giây.');
        }

        $temporary = $telegram->downloadAudioToTemp($media['fileId'], $maxBytes);
        $sourceKey = '';
        $jobId = self::uuid();
        try {
            $mime = (string) $temporary['mime'];
            $extension = self::extensionForMime($mime, (string) $media['filename']);
            $sourceKey = 'ai-cover/sources/' . $jobId . '/source.' . $extension;
            NeonStorage::uploadFile((string) $temporary['path'], $sourceKey, $mime);

            try {
                $row = Database::transaction(function () use ($userId, $chatId, $updateId, $media, $voice, $sourceKey, $mime, $maxBytes): array {
                    Database::run('SELECT pg_advisory_xact_lock(hashtext(?))', ['ai-cover-user:' . $userId]);
                    $user = Database::one('SELECT user_id FROM users WHERE user_id=? FOR UPDATE', [$userId]);
                    if ($user === null) throw new RuntimeException('Không tìm thấy tài khoản. Hãy gửi /start trước.');
                    $existing = Database::one('SELECT id,status FROM ai_cover_jobs WHERE telegram_update_id=?', [$updateId]);
                    if ($existing !== null) return $existing;

                    $voiceStillAvailable = Database::one(
                        "SELECT id FROM ai_cover_voices
                         WHERE id=? AND enabled=TRUE AND voice_owner_consent=TRUE AND available=TRUE
                           AND checked_at > NOW() - INTERVAL '30 minutes' FOR SHARE",
                        [(string) $voice['id']]
                    );
                    if ($voiceStillAvailable === null) throw new RuntimeException('Preset vừa tạm ngừng. Hãy chọn lại giọng.');

                    $activeLimit = max(1, min(3, Env::int('AI_COVER_MAX_ACTIVE_JOBS', 1)));
                    $active = (int) Database::value(
                        "SELECT COUNT(*) FROM ai_cover_jobs WHERE telegram_user_id=? AND status IN ('queued','processing')",
                        [$userId]
                    );
                    if ($active >= $activeLimit) {
                        throw new RuntimeException('Bạn đang có ' . $activeLimit . ' tác vụ AI Cover chưa hoàn tất. Hãy đợi kết quả trước.');
                    }

                    $dailyLimit = max(1, min(10, Env::int('AI_COVER_MAX_PER_DAY', 3)));
                    $today = (int) Database::value(
                        "SELECT COUNT(*) FROM ai_cover_jobs
                         WHERE telegram_user_id=?
                           AND created_at >= (date_trunc('day', NOW() AT TIME ZONE 'Asia/Ho_Chi_Minh') AT TIME ZONE 'Asia/Ho_Chi_Minh')
                           AND status <> 'cancelled'",
                        [$userId]
                    );
                    if ($today >= $dailyLimit) {
                        throw new RuntimeException('Bạn đã đạt giới hạn ' . $dailyLimit . ' tác vụ AI Cover/ngày.');
                    }

                    $id = self::uuid();
                    $job = Database::one(
                        "INSERT INTO ai_cover_jobs
                           (id,telegram_user_id,chat_id,telegram_update_id,telegram_file_unique_id,
                            source_key,source_filename,source_mime_type,voice_id,voice_name,max_attempts,status)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,'queued')
                         RETURNING id,status,created_at",
                        [
                            $id, $userId, $chatId, $updateId, $media['uniqueId'] ?: null,
                            $sourceKey, $media['filename'], $mime, (string) $voice['id'],
                            (string) $voice['name'], max(1, min(5, Env::int('AI_COVER_MAX_ATTEMPTS', 3))),
                        ]
                    );
                    if ($job === null) throw new RuntimeException('Không thể tạo tác vụ AI Cover.');
                    Database::run(
                        "INSERT INTO ai_cover_events(job_id,event_type,details)
                         VALUES(?, 'queued', jsonb_build_object('file_bytes',?,'voice_id',?))",
                        [$id, (int) $media['size'], (string) $voice['id']]
                    );
                    Database::run(
                        'UPDATE ai_cover_sessions SET selected_voice_id=NULL, last_seen_at=NOW(), updated_at=NOW() WHERE telegram_user_id=?',
                        [$userId]
                    );
                    return $job;
                });
                return $row;
            } catch (Throwable $error) {
                if ($sourceKey !== '') {
                    try { NeonStorage::deleteObject($sourceKey); } catch (Throwable $deleteError) {
                        error_log('AI Cover orphan cleanup failed: ' . $deleteError::class);
                    }
                }
                throw $error;
            }
        } finally {
            if (isset($temporary['path']) && is_file((string) $temporary['path'])) @unlink((string) $temporary['path']);
        }
    }

    /** Validate the configured worker URL; only HTTPS endpoints are accepted. */
    private static function workerBaseUrl(): string
    {
        $url = rtrim(trim((string) Env::get('AI_COVER_WORKER_URL', '')), '/');
        if ($url === '') return '';
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        return $url;
    }

    /** Signed JSON request to the private RVC worker. */
    private static function workerRequest(string $path, array $payload): array
    {
        $base = self::workerBaseUrl();
        $secret = trim((string) Env::get('AI_COVER_CALLBACK_SECRET', ''));
        if ($base === '' || strlen($secret) < 32) {
            throw new RuntimeException('AI Cover worker chưa được cấu hình.');
        }
        if (!in_array($path, ['jobs', 'voices/verify'], true)) {
            throw new RuntimeException('Đường dẫn worker không hợp lệ.');
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);
        $curl = curl_init($base . '/' . $path);
        if ($curl === false) throw new RuntimeException('Không khởi tạo được kết nối AI Cover worker.');
        try {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Hendy-AI-Cover-Signature: ' . $signature,
                ],
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => max(10, min(300, Env::int('AI_COVER_WORKER_TIMEOUT_SECONDS', 60))),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            if ($response === false || $status < 200 || $status >= 300
                || !is_string($response) || strlen($response) > 262144) {
                throw new RuntimeException(
                    'AI Cover worker không phản hồi hợp lệ (HTTP ' . $status . ')' .
                    ($error !== '' ? ': ' . $error : '.')
                );
            }
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('AI Cover worker trả về JSON không hợp lệ.');
            return $decoded;
        } catch (\JsonException $error) {
            throw new RuntimeException('AI Cover worker trả về JSON không hợp lệ.', 0, $error);
        } finally {
            curl_close($curl);
        }
    }

    /** Verify that the RVC worker can load a licensed preset and its private model files. */
    public static function verifyVoice(string $voiceId): array
    {
        self::assertStorageReady();
        if (!preg_match('/\A[a-z0-9][a-z0-9_-]{1,47}\z/', $voiceId)) {
            throw new RuntimeException('Preset ID không hợp lệ.');
        }
        $voice = Database::one(
            'SELECT id,name,model_key,index_key,speaker_id,pitch,index_rate,f0_method,voice_owner_consent,rights_holder,license_name,authorization_reference FROM ai_cover_voices WHERE id=?',
            [$voiceId]
        );
        if ($voice === null) throw new RuntimeException('Không tìm thấy preset AI Cover.');
        if (empty($voice['voice_owner_consent']) || trim((string) $voice['rights_holder']) === ''
            || trim((string) $voice['license_name']) === '' || trim((string) $voice['authorization_reference']) === '') {
            throw new RuntimeException('Preset chưa có hồ sơ đồng ý/cấp phép hợp lệ.');
        }

        try {
            $result = self::workerRequest('voices/verify', [
                'voiceId' => $voiceId,
                'name' => (string) $voice['name'],
                'modelUrl' => NeonStorage::presignedDownloadUrl((string) $voice['model_key'], 300),
                'indexUrl' => !empty($voice['index_key'])
                    ? NeonStorage::presignedDownloadUrl((string) $voice['index_key'], 300)
                    : null,
                'config' => [
                    'speakerId' => (int) $voice['speaker_id'],
                    'pitch' => (int) $voice['pitch'],
                    'indexRate' => (float) $voice['index_rate'],
                    'f0Method' => (string) $voice['f0_method'],
                ],
            ]);
            $available = filter_var($result['available'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $message = mb_substr(trim((string) ($result['message'] ?? '')), 0, 500);
            if (!$available && $message === '') $message = 'Worker chưa xác nhận được model/index.';
            Database::run(
                'UPDATE ai_cover_voices SET available=?,checked_at=NOW(),last_error=?,updated_at=NOW() WHERE id=?',
                [$available, $available ? '' : $message, $voiceId]
            );
            return [
                'id' => $voiceId,
                'available' => $available,
                'message' => $message !== '' ? $message : ($available ? 'Worker đã tải được model và preset sẵn sàng.' : 'Preset chưa sẵn sàng.'),
            ];
        } catch (Throwable $error) {
            $message = mb_substr($error->getMessage(), 0, 500);
            Database::run(
                'UPDATE ai_cover_voices SET available=FALSE,checked_at=NOW(),last_error=?,updated_at=NOW() WHERE id=?',
                [$message, $voiceId]
            );
            throw new RuntimeException('Không thể xác minh preset AI Cover: ' . $message, 0, $error);
        }
    }

    /** Claim and dispatch one queued job to the configured external RVC worker. */
    public static function dispatchOnce(): bool
    {
        self::assertEnabled();
        $workerId = trim((string) Env::get('RENDER_INSTANCE_ID', '')) ?: 'hendy-ai-cover-dispatcher';
        $job = Database::transaction(static function () use ($workerId): ?array {
            $row = Database::one(
                "SELECT j.id,j.telegram_user_id,j.chat_id,j.source_key,j.source_filename,j.source_mime_type,
                        j.voice_id,j.attempts,j.max_attempts,v.name AS voice_name,v.model_key,v.index_key,
                        v.speaker_id,v.pitch,v.index_rate,v.f0_method
                 FROM ai_cover_jobs j
                 JOIN ai_cover_voices v ON v.id=j.voice_id
                 WHERE j.status='queued' AND j.available_at<=NOW()
                   AND v.enabled=TRUE AND v.voice_owner_consent=TRUE AND v.available=TRUE
                   AND v.checked_at>NOW()-INTERVAL '30 minutes'
                 ORDER BY j.available_at ASC,j.created_at ASC
                 LIMIT 1 FOR UPDATE OF j SKIP LOCKED"
            );
            if ($row === null) return null;
            $claimed = Database::one(
                "UPDATE ai_cover_jobs
                 SET status='processing',attempts=attempts+1,worker_id=?,started_at=NOW(),updated_at=NOW()
                 WHERE id=? AND status='queued'
                 RETURNING attempts,max_attempts",
                [$workerId, $row['id']]
            );
            if ($claimed === null) return null;
            $row['attempts'] = (int) $claimed['attempts'];
            $row['max_attempts'] = (int) $claimed['max_attempts'];
            Database::run(
                "INSERT INTO ai_cover_events(job_id,event_type,details)
                 VALUES(?,'dispatch.claimed',jsonb_build_object('attempt',?,'worker',?))",
                [$row['id'], $row['attempts'], $workerId]
            );
            return $row;
        });
        if ($job === null) return false;

        $jobId = (string) $job['id'];
        try {
            $resultKey = 'ai-cover/results/' . $jobId . '/cover.mp3';
            $callbackBase = rtrim((string) (Env::get('APP_URL') ?: Env::get('RENDER_EXTERNAL_URL', '')), '/');
            $callbackParts = parse_url($callbackBase);
            if (!is_array($callbackParts) || strtolower((string) ($callbackParts['scheme'] ?? '')) !== 'https'
                || empty($callbackParts['host']) || isset($callbackParts['user']) || isset($callbackParts['pass'])) {
                throw new RuntimeException('APP_URL/RENDER_EXTERNAL_URL HTTPS chưa được cấu hình cho callback AI Cover.');
            }
            $indexUrl = !empty($job['index_key'])
                ? NeonStorage::presignedDownloadUrl((string) $job['index_key'], 300)
                : null;
            $upload = NeonStorage::presignedUploadRequest($resultKey, 'audio/mpeg', 300);
            $payload = [
                'jobId' => $jobId,
                'source' => [
                    'url' => NeonStorage::presignedDownloadUrl((string) $job['source_key'], 300),
                    'filename' => (string) $job['source_filename'],
                    'mimeType' => (string) $job['source_mime_type'],
                ],
                'voice' => [
                    'id' => (string) $job['voice_id'],
                    'name' => (string) $job['voice_name'],
                    'modelUrl' => NeonStorage::presignedDownloadUrl((string) $job['model_key'], 300),
                    'indexUrl' => $indexUrl,
                    'config' => [
                        'speakerId' => (int) $job['speaker_id'],
                        'pitch' => (int) $job['pitch'],
                        'indexRate' => (float) $job['index_rate'],
                        'f0Method' => (string) $job['f0_method'],
                    ],
                ],
                'result' => [
                    'key' => $resultKey,
                    'upload' => $upload,
                    'contentType' => 'audio/mpeg',
                    'maxBytes' => 50000000,
                ],
                'callback' => [
                    'url' => $callbackBase . '/internal/ai-cover/jobs/' . $jobId . '/complete',
                    'signatureHeader' => 'X-Hendy-AI-Cover-Signature',
                    'signatureFormat' => 'sha256=<hex-hmac-sha256-of-raw-json-body>',
                ],
                'limits' => [
                    'maxDurationSeconds' => max(15, min(600, Env::int('AI_COVER_MAX_AUDIO_DURATION_SECONDS', 180))),
                    'maxOutputBytes' => 50000000,
                ],
            ];
            $accepted = self::workerRequest('jobs', $payload);
            if (!filter_var($accepted['accepted'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw new RuntimeException((string) ($accepted['message'] ?? 'Worker không tiếp nhận tác vụ.'));
            }
            $providerId = trim((string) ($accepted['jobId'] ?? $accepted['id'] ?? ''));
            if ($providerId === '' || mb_strlen($providerId) > 160) {
                throw new RuntimeException('Worker không trả về mã tác vụ hợp lệ.');
            }
            Database::run(
                "UPDATE ai_cover_jobs SET provider_job_id=?,updated_at=NOW() WHERE id=? AND status='processing'",
                [$providerId, $jobId]
            );
            Database::run(
                "INSERT INTO ai_cover_events(job_id,event_type,details)
                 VALUES(?,'dispatch.accepted',jsonb_build_object('provider_job_id',?))",
                [$jobId, $providerId]
            );
            return true;
        } catch (Throwable $error) {
            $message = mb_substr(trim($error->getMessage()), 0, 1500);
            $attempts = (int) $job['attempts'];
            $maxAttempts = (int) $job['max_attempts'];
            $retry = $attempts < $maxAttempts;
            $delay = min(300, 15 * (2 ** max(0, $attempts - 1)));
            Database::run(
                "UPDATE ai_cover_jobs
                 SET status=?,available_at=NOW()+(? * INTERVAL '1 second'),
                     completed_at=CASE WHEN ? THEN NULL ELSE NOW() END,
                     last_error=?,worker_id=NULL,updated_at=NOW()
                 WHERE id=? AND status='processing'",
                [$retry ? 'queued' : 'failed', $retry ? $delay : 0, $retry, $message, $jobId]
            );
            Database::run(
                "INSERT INTO ai_cover_events(job_id,event_type,details)
                 VALUES(?,?::varchar,jsonb_build_object('error',?,'retry',?,'delay_seconds',?))",
                [$jobId, $retry ? 'dispatch.retry' : 'dispatch.failed', $message, $retry, $retry ? $delay : 0]
            );
            error_log('[ai-cover-dispatch] job=' . $jobId . ' ' . $message);
            return true;
        }
    }

    /** Verify a signed callback and mark a processing job completed or failed exactly once. */
    public static function completeFromWorker(string $jobId, string $rawBody, string $signature): array
    {
        $secret = trim((string) Env::get('AI_COVER_CALLBACK_SECRET', ''));
        if (strlen($secret) < 32) throw new RuntimeException('AI_COVER_CALLBACK_SECRET chưa được cấu hình.');
        if (strlen($rawBody) < 2 || strlen($rawBody) > 32768) throw new RuntimeException('Payload callback AI Cover không hợp lệ.');
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        if ($signature === '' || !hash_equals($expected, $signature)) throw new RuntimeException('Chữ ký callback AI Cover không hợp lệ.');
        if (!preg_match('/\A[0-9a-f-]{36}\z/i', $jobId)) throw new RuntimeException('Mã job AI Cover không hợp lệ.');

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !hash_equals($jobId, (string) ($payload['jobId'] ?? ''))) {
            throw new RuntimeException('Callback không khớp mã tác vụ.');
        }
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        if (!in_array($status, ['completed', 'failed'], true)) throw new RuntimeException('Trạng thái callback AI Cover không hợp lệ.');

        $existing = Database::one(
            'SELECT status,provider_job_id,result_key FROM ai_cover_jobs WHERE id=?',
            [$jobId]
        );
        if ($existing === null) throw new RuntimeException('Không tìm thấy tác vụ AI Cover.');
        if (in_array((string) $existing['status'], ['completed', 'failed'], true)) {
            return ['jobId' => $jobId, 'status' => $existing['status'], 'duplicate' => true];
        }
        if ((string) $existing['status'] !== 'processing') throw new RuntimeException('Tác vụ AI Cover không ở trạng thái xử lý.');

        $providerId = trim((string) ($payload['workerJobId'] ?? ''));
        if ((string) $existing['provider_job_id'] !== ''
            && ($providerId === '' || !hash_equals((string) $existing['provider_job_id'], $providerId))) {
            throw new RuntimeException('Mã job từ worker không khớp.');
        }

        $resultKey = null;
        $duration = null;
        $outputBytes = null;
        $errorMessage = '';
        if ($status === 'completed') {
            $resultKey = (string) ($payload['resultKey'] ?? '');
            $expectedKey = 'ai-cover/results/' . $jobId . '/cover.mp3';
            $durationValue = filter_var($payload['durationSeconds'] ?? null, FILTER_VALIDATE_FLOAT);
            $bytesValue = filter_var($payload['outputSizeBytes'] ?? null, FILTER_VALIDATE_INT);
            if (!hash_equals($expectedKey, $resultKey) || $durationValue === false
                || $durationValue < 1 || $durationValue > 600
                || $bytesValue === false || $bytesValue < 128 || $bytesValue > 50000000) {
                throw new RuntimeException('Thông tin tệp kết quả AI Cover không hợp lệ.');
            }
            $duration = (float) $durationValue;
            $outputBytes = (int) $bytesValue;
        } else {
            $errorMessage = mb_substr(trim((string) ($payload['errorMessage'] ?? 'Worker báo không thể xử lý audio.')), 0, 1500);
        }

        $row = Database::one(
            "UPDATE ai_cover_jobs
             SET status=?,result_key=?,duration_seconds=?,output_size_bytes=?,last_error=?,
                 completed_at=NOW(),updated_at=NOW(),worker_id=NULL
             WHERE id=? AND status='processing'
             RETURNING id,status,telegram_user_id,voice_name",
            [$status, $resultKey, $duration, $outputBytes, $errorMessage, $jobId]
        );
        if ($row === null) throw new RuntimeException('Tác vụ đã được cập nhật bởi một callback khác.');
        Database::run(
            "INSERT INTO ai_cover_events(job_id,event_type,details) VALUES(?,?,?::jsonb)",
            [
                $jobId,
                'worker.' . $status,
                json_encode([
                    'worker_job_id' => $providerId,
                    'duration_seconds' => $duration,
                    'output_size_bytes' => $outputBytes,
                    'error' => $errorMessage,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        return [
            'jobId' => $jobId,
            'status' => $status,
            'duplicate' => false,
            'userId' => (string) $row['telegram_user_id'],
        ];
    }

    public static function retryJob(string $jobId, string $adminId): array
    {
        if (!preg_match('/\A[0-9a-f-]{36}\z/i', $jobId)) throw new RuntimeException('Mã tác vụ AI Cover không hợp lệ.');
        $row = Database::one(
            "UPDATE ai_cover_jobs
             SET status='queued',attempts=0,available_at=NOW(),provider_job_id='',result_key=NULL,
                 duration_seconds=NULL,output_size_bytes=NULL,last_error=NULL,completed_at=NULL,
                 delivery_claimed_at=NULL,delivery_sent_at=NULL,delivery_attempts=0,
                 worker_id=NULL,updated_at=NOW()
             WHERE id=? AND status='failed'
             RETURNING id,telegram_user_id,status",
            [$jobId]
        );
        if ($row === null) throw new RuntimeException('Chỉ có thể chạy lại tác vụ AI Cover đang ở trạng thái lỗi.');
        Database::run(
            "INSERT INTO ai_cover_events(job_id,event_type,details)
             VALUES(?,'admin.retry',jsonb_build_object('admin_id',?))",
            [$jobId, $adminId]
        );
        return $row;
    }

    public static function history(string $userId, int $limit = 10): array
    {
        return Database::all(
            "SELECT id,voice_name,status,duration_seconds,created_at,completed_at,last_error
             FROM ai_cover_jobs WHERE telegram_user_id=?
             ORDER BY created_at DESC LIMIT " . max(1, min(20, $limit)),
            [$userId]
        );
    }

    public static function deliverJob(string $jobId, string $receivedSecret, TelegramApi $telegram): array
    {
        $expected = trim((string) Env::get('AI_COVER_CALLBACK_SECRET', ''));
        if (strlen($expected) < 32 || !hash_equals($expected, $receivedSecret)) {
            throw new RuntimeException('Callback secret không hợp lệ.');
        }
        if (!preg_match('/\A[0-9a-f-]{36}\z/i', $jobId)) {
            throw new RuntimeException('Mã tác vụ AI Cover không hợp lệ.');
        }
        $job = Database::one(
            "UPDATE ai_cover_jobs SET delivery_claimed_at=NOW(), delivery_attempts=delivery_attempts+1, updated_at=NOW()
             WHERE id=? AND delivery_sent_at IS NULL
               AND (delivery_claimed_at IS NULL OR delivery_claimed_at < NOW() - INTERVAL '10 minutes')
             RETURNING id,chat_id,telegram_user_id,voice_name,status,result_key,delivery_attempts",
            [$jobId]
        );
        if ($job === null) {
            $current = Database::one('SELECT status,delivery_sent_at FROM ai_cover_jobs WHERE id=?', [$jobId]);
            if ($current === null) throw new RuntimeException('Không tìm thấy tác vụ AI Cover.');
            return ['status' => !empty($current['delivery_sent_at']) ? 'already_delivered' : 'delivery_in_progress'];
        }
        if (!in_array((string) $job['status'], ['completed','failed'], true)) {
            Database::run('UPDATE ai_cover_jobs SET delivery_claimed_at=NULL WHERE id=?', [$jobId]);
            throw new RuntimeException('Tác vụ AI Cover chưa hoàn tất.');
        }

        $temporary = null;
        try {
            if ($job['status'] === 'completed' && is_string($job['result_key']) && $job['result_key'] !== '') {
                $temporary = NeonStorage::downloadToTemp((string) $job['result_key'], 50_000_000);
                $telegram->sendAudioFile(
                    (string) $job['chat_id'],
                    (string) $temporary['path'],
                    '🎤 <b>HENDY AI COVER ĐÃ HOÀN TẤT</b>' . "\n" .
                    'Preset: <b>' . self::escape((string) $job['voice_name']) . '</b>' . "\n" .
                    'Mã tác vụ: <code>' . self::escape(substr((string) $job['id'], 0, 8)) . '</code>'
                );
            } else {
                $telegram->sendMessage(
                    (string) $job['chat_id'],
                    '❌ <b>HENDY AI COVER CHƯA THỂ HOÀN TẤT</b>' . "\n" .
                    'Mã tác vụ: <code>' . self::escape(substr((string) $job['id'], 0, 8)) . '</code>' . "\n" .
                    'Hệ thống đã ghi nhận lỗi. Vui lòng thử bản audio ngắn hơn hoặc liên hệ CSKH.'
                );
            }
            Database::run(
                'UPDATE ai_cover_jobs SET delivery_sent_at=NOW(),delivery_claimed_at=NULL,updated_at=NOW() WHERE id=?',
                [$jobId]
            );
            return ['status' => 'delivered'];
        } catch (Throwable $error) {
            Database::run(
                "UPDATE ai_cover_jobs SET delivery_claimed_at=NULL,
                  delivery_retry_at=NOW() + LEAST(900,15*GREATEST(1,delivery_attempts))*INTERVAL '1 second',
                  last_error=COALESCE(last_error,'') || ' [delivery retry]', updated_at=NOW() WHERE id=?",
                [$jobId]
            );
            throw $error;
        } finally {
            if (is_array($temporary) && isset($temporary['path']) && is_file((string) $temporary['path'])) {
                @unlink((string) $temporary['path']);
            }
        }
    }

    public static function adminSnapshot(): array
    {
        return [
            ...self::readiness(),
            'voices' => Database::all(
                "SELECT id,name,description,enabled,speaker_id,pitch,index_rate,f0_method,
                        rights_holder,license_name,authorization_reference,voice_owner_consent,commercial_use_allowed,
                        available,checked_at,last_error,created_at,updated_at
                 FROM ai_cover_voices ORDER BY created_at DESC LIMIT 100"
            ),
            'jobs' => Database::all(
                "SELECT id,telegram_user_id,voice_name,status,attempts,max_attempts,duration_seconds,output_size_bytes,
                        last_error,delivery_sent_at,created_at,completed_at
                 FROM ai_cover_jobs ORDER BY created_at DESC LIMIT 100"
            ),
            'stats' => [
                'voices' => (int) Database::value('SELECT COUNT(*) FROM ai_cover_voices'),
                'availableVoices' => (int) Database::value('SELECT COUNT(*) FROM ai_cover_voices WHERE enabled=TRUE AND available=TRUE'),
                'queued' => (int) Database::value("SELECT COUNT(*) FROM ai_cover_jobs WHERE status='queued'"),
                'processing' => (int) Database::value("SELECT COUNT(*) FROM ai_cover_jobs WHERE status='processing'"),
                'completed' => (int) Database::value("SELECT COUNT(*) FROM ai_cover_jobs WHERE status='completed'"),
                'failed' => (int) Database::value("SELECT COUNT(*) FROM ai_cover_jobs WHERE status='failed'"),
            ],
        ];
    }

    /** Save one licensed preset and optionally upload a replacement model/index. */
    public static function saveVoice(array $input, array $files, string $adminId): array
    {
        self::assertStorageReady();
        $id = strtolower(trim((string) ($input['id'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $rightsHolder = trim((string) ($input['rightsHolder'] ?? ''));
        $license = trim((string) ($input['license'] ?? ''));
        $authorization = trim((string) ($input['authorizationReference'] ?? ''));
        $consent = filter_var($input['voiceOwnerConsent'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $commercial = filter_var($input['commercialUseAllowed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $speakerId = filter_var($input['speakerId'] ?? 0, FILTER_VALIDATE_INT);
        $pitch = filter_var($input['pitch'] ?? 0, FILTER_VALIDATE_INT);
        $indexRate = filter_var($input['indexRate'] ?? 0.65, FILTER_VALIDATE_FLOAT);
        $f0 = trim((string) ($input['f0Method'] ?? 'rmvpe'));

        if (!preg_match('/\A[a-z0-9][a-z0-9_-]{1,47}\z/', $id)
            || mb_strlen($name) < 1 || mb_strlen($name) > 50
            || mb_strlen($description) > 300
            || $rightsHolder === '' || mb_strlen($rightsHolder) > 160
            || $license === '' || mb_strlen($license) > 160
            || $authorization === '' || mb_strlen($authorization) > 200
            || !$consent
            || !is_int($speakerId) || $speakerId < 0 || $speakerId > 109
            || !is_int($pitch) || $pitch < -12 || $pitch > 12
            || !is_float($indexRate) || $indexRate < 0 || $indexRate > 1
            || !in_array($f0, ['rmvpe','pm'], true)) {
            throw new RuntimeException('Thông tin preset hoặc hồ sơ đồng ý/cấp phép không hợp lệ.');
        }

        $existing = Database::one('SELECT model_key,index_key FROM ai_cover_voices WHERE id=?', [$id]);
        $modelKey = (string) ($existing['model_key'] ?? '');
        $indexKey = (string) ($existing['index_key'] ?? '');
        $uploadedKeys = [];
        $totalUploadBytes = 0;

        foreach (['model' => 'voice.pth', 'index' => 'voice.index'] as $field => $filename) {
            $file = $files[$field] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $fileSize = (int) ($file['size'] ?? 0);
            $fieldLimit = $field === 'model' ? 200_000_000 : 50_000_000;
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))
                || $fileSize < 16
                || $fileSize > $fieldLimit) {
                throw new RuntimeException($field === 'model'
                    ? 'Tệp model .pth phải nằm trong giới hạn 200 MB.'
                    : 'Tệp index phải nằm trong giới hạn 50 MB.');
            }
            $totalUploadBytes += $fileSize;
            if ($totalUploadBytes > 250_000_000) {
                throw new RuntimeException('Tổng dung lượng model và index không được vượt 250 MB.');
            }
            $original = (string) ($file['name'] ?? '');
            $expectedExtension = $field === 'model' ? 'pth' : 'index';
            if (strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== $expectedExtension) {
                throw new RuntimeException('Tệp ' . $field . ' phải có phần mở rộng .' . $expectedExtension . '.');
            }
            $extension = $field === 'model' ? 'pth' : 'index';
            $objectKey = 'ai-cover/models/' . $id . '/voice-' . bin2hex(random_bytes(8)) . '.' . $extension;
            NeonStorage::uploadFile((string) $file['tmp_name'], $objectKey, 'application/octet-stream');
            $uploadedKeys[] = $objectKey;
            if ($field === 'model') $modelKey = $objectKey;
            else $indexKey = $objectKey;
        }
        if ($modelKey === '') {
            throw new RuntimeException('Cần tải lên model .pth trước khi lưu preset.');
        }

        $row = Database::one(
            "INSERT INTO ai_cover_voices
              (id,name,description,enabled,model_key,index_key,speaker_id,pitch,index_rate,f0_method,
               rights_holder,license_name,authorization_reference,voice_owner_consent,commercial_use_allowed,
               consent_reviewed_by,consent_reviewed_at,available,checked_at,last_error,created_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,NOW(),FALSE,NULL,'',?)
             ON CONFLICT(id) DO UPDATE SET
               name=EXCLUDED.name,description=EXCLUDED.description,enabled=EXCLUDED.enabled,
               model_key=EXCLUDED.model_key,index_key=EXCLUDED.index_key,
               speaker_id=EXCLUDED.speaker_id,pitch=EXCLUDED.pitch,index_rate=EXCLUDED.index_rate,
               f0_method=EXCLUDED.f0_method,rights_holder=EXCLUDED.rights_holder,
               license_name=EXCLUDED.license_name,authorization_reference=EXCLUDED.authorization_reference,
               voice_owner_consent=EXCLUDED.voice_owner_consent,commercial_use_allowed=EXCLUDED.commercial_use_allowed,
               consent_reviewed_by=EXCLUDED.consent_reviewed_by,consent_reviewed_at=NOW(),
               available=FALSE,checked_at=NULL,last_error='',updated_at=NOW()
             RETURNING id,name,enabled,available",
            [
                $id,$name,$description,$enabled,$modelKey,$indexKey !== '' ? $indexKey : null,
                $speakerId,$pitch,$indexRate,$f0,$rightsHolder,$license,$authorization,$consent,$commercial,
                $adminId,$adminId,
            ]
        );
        if ($row === null) {
            foreach ($uploadedKeys as $key) { try { NeonStorage::deleteObject($key); } catch (Throwable) {} }
            throw new RuntimeException('Không thể lưu preset AI Cover.');
        }
        return $row;
    }

    public static function setVoiceEnabled(string $voiceId, bool $enabled, string $adminId): void
    {
        if (!preg_match('/\A[a-z0-9][a-z0-9_-]{1,47}\z/', $voiceId)) {
            throw new RuntimeException('Preset ID không hợp lệ.');
        }
        $row = Database::one(
            "UPDATE ai_cover_voices SET enabled=?, available=FALSE, checked_at=NULL,
               consent_reviewed_by=CASE WHEN ? THEN ? ELSE consent_reviewed_by END,
               consent_reviewed_at=CASE WHEN ? THEN NOW() ELSE consent_reviewed_at END,
               updated_at=NOW()
             WHERE id=? RETURNING id",
            [$enabled,$enabled,$adminId,$enabled,$voiceId]
        );
        if ($row === null) throw new RuntimeException('Không tìm thấy preset AI Cover.');
    }

    public static function isAudioMessage(array $message): bool
    {
        return self::audioMedia($message) !== null;
    }

    public static function clearSelection(string $userId): void
    {
        Database::run(
            'UPDATE ai_cover_sessions SET selected_voice_id=NULL,updated_at=NOW() WHERE telegram_user_id=?',
            [$userId]
        );
    }

    private static function audioMedia(array $message): ?array
    {
        $media = null;
        $filename = 'audio-upload.mp3';
        $mime = 'audio/mpeg';
        if (is_array($message['audio'] ?? null)) {
            $media = $message['audio'];
            $filename = (string) ($media['file_name'] ?? 'telegram-audio.mp3');
            $mime = (string) ($media['mime_type'] ?? 'audio/mpeg');
        } elseif (is_array($message['voice'] ?? null)) {
            $media = $message['voice'];
            $filename = 'telegram-voice.ogg';
            $mime = (string) ($media['mime_type'] ?? 'audio/ogg');
        } elseif (is_array($message['document'] ?? null)) {
            $document = $message['document'];
            $filename = (string) ($document['file_name'] ?? 'audio-upload');
            $mime = (string) ($document['mime_type'] ?? '');
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!str_starts_with(strtolower($mime), 'audio/')
                && !in_array($extension, ['mp3','wav','flac','m4a','aac','ogg','opus'], true)) {
                return null;
            }
            $media = $document;
        }
        if (!is_array($media) || (string) ($media['file_id'] ?? '') === '') return null;
        return [
            'fileId' => (string) $media['file_id'],
            'uniqueId' => (string) ($media['file_unique_id'] ?? ''),
            'size' => max(0, (int) ($media['file_size'] ?? 0)),
            'duration' => max(0, (int) ($media['duration'] ?? 0)),
            'filename' => self::safeFilename($filename),
            'mime' => strtolower(trim(explode(';', $mime)[0])),
        ];
    }

    private static function extensionForMime(string $mime, string $filename): string
    {
        $known = [
            'audio/mpeg'=>'mp3','audio/mp3'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav',
            'audio/flac'=>'flac','audio/mp4'=>'m4a','audio/aac'=>'aac','audio/ogg'=>'ogg',
            'audio/opus'=>'opus','application/octet-stream'=>strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
        ];
        $ext = $known[$mime] ?? '';
        if (!in_array($ext, ['mp3','wav','flac','m4a','aac','ogg','opus'], true)) {
            throw new RuntimeException('Định dạng audio chưa được hỗ trợ.');
        }
        return $ext;
    }

    private static function assertStorageReady(): void
    {
        if (!NeonStorage::configured()) {
            throw new RuntimeException('Neon Object Storage chưa được cấu hình để lưu model/audio AI Cover.');
        }
    }

    private static function assertEnabled(): void
    {
        if (!self::enabled()) {
            throw new RuntimeException('AI Cover đang tạm đóng. Admin cần bật worker, cấu hình Neon Storage và endpoint RVC trước.');
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    private static function safeFilename(string $filename): string
    {
        $base = basename(str_replace('\\','/',$filename));
        $safe = preg_replace('/[^A-Za-z0-9._-]/','_',$base) ?: 'audio-upload';
        return mb_substr($safe,0,120);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
