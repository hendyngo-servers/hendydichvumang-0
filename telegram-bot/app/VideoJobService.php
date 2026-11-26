<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;
use Throwable;

final class VideoJobService
{
    private const DEFAULT_HOSTS = ['youtube.com', 'youtu.be', 'tiktok.com', 'facebook.com', 'fb.watch'];

    public static function isAllowedMediaUrl(string $url): bool
    {
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        $configured = trim((string) Env::get('MEDIA_ALLOWED_HOSTS', ''));
        $allowed = $configured !== ''
            ? array_values(array_filter(array_map(static fn (string $item): string => strtolower(trim($item)), explode(',', $configured))))
            : self::DEFAULT_HOSTS;
        foreach ($allowed as $domain) {
            $domain = ltrim($domain, '.');
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }
        return false;
    }

    public static function create(string $userId, string $sourceUrl, string $botKey): array
    {
        if (!preg_match('~\A[0-9]{5,20}\z~', $userId) || !in_array($botKey, ['primary', 'secondary'], true)) {
            throw new RuntimeException('Thông tin người dùng hoặc bot không hợp lệ.');
        }
        if (!self::isAllowedMediaUrl($sourceUrl)) {
            throw new RuntimeException('Link không hợp lệ. Chỉ nhận HTTPS từ nền tảng video đã cấu hình.');
        }
        return Database::transaction(function () use ($userId, $sourceUrl, $botKey): array {
            Database::run('INSERT INTO users (user_id) VALUES (?) ON CONFLICT (user_id) DO NOTHING', [$userId]);
            $user = Database::one('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new RuntimeException('Không tìm thấy tài khoản. Hãy gửi /start trước khi tạo video job.');
            }
            $active = (int) Database::value(
                "SELECT COUNT(*) FROM video_jobs WHERE user_id = ? AND status IN ('queued','processing')",
                [$userId]
            );
            if ($active >= 3) {
                throw new RuntimeException('Bạn đang có 3 video jobs chưa hoàn tất. Hãy chờ xử lý xong trước khi gửi thêm.');
            }
            $recent = (int) Database::value(
                "SELECT COUNT(*) FROM video_jobs WHERE user_id = ? AND created_at >= NOW() - INTERVAL '1 hour'",
                [$userId]
            );
            if ($recent >= 10) {
                throw new RuntimeException('Bạn đã đạt giới hạn 10 video jobs trong 1 giờ. Vui lòng thử lại sau.');
            }
            $id = self::uuid();
            $job = Database::one(
                "INSERT INTO video_jobs (id, user_id, bot_key, source_url, status, progress) " .
                "VALUES (?, ?, ?, ?, 'queued', 0) RETURNING id, status, progress, created_at",
                [$id, $userId, $botKey, $sourceUrl]
            );
            if ($job === null) {
                throw new RuntimeException('Không thể tạo video job.');
            }
            return $job;
        });
    }

    public static function forUser(string $userId, int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));
        return Database::all(
            'SELECT id, source_url, status, progress, output_url, error_message, created_at, updated_at, completed_at FROM video_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . $limit,
            [$userId]
        );
    }

    public static function recent(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return Database::all(
            'SELECT j.id, j.user_id, j.bot_key, j.source_url, j.status, j.progress, j.provider_job_id, j.error_message, j.created_at, j.updated_at, u.name, u.username FROM video_jobs j LEFT JOIN users u ON u.user_id = j.user_id ORDER BY j.created_at DESC LIMIT ' . $limit
        );
    }

    public static function stats(): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'queued') AS queued, " .
            "COUNT(*) FILTER (WHERE status = 'processing') AS processing, COUNT(*) FILTER (WHERE status = 'completed') AS completed, " .
            "COUNT(*) FILTER (WHERE status = 'failed') AS failed FROM video_jobs"
        ) ?? [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'queued' => (int) ($row['queued'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    public static function retry(string $jobId): array
    {
        $job = Database::one(
            "UPDATE video_jobs SET status = 'queued', progress = 0, error_message = '', attempts = 0, claimed_at = NULL, updated_at = NOW() " .
            "WHERE id = ? AND status = 'failed' RETURNING id, user_id, bot_key, status, progress, attempts",
            [$jobId]
        );
        if ($job === null) {
            throw new RuntimeException('Chỉ có thể chạy lại job đang ở trạng thái thất bại.');
        }
        return $job;
    }

    public static function updateFromCallback(array $payload): array
    {
        $jobId = trim((string) ($payload['jobId'] ?? ''));
        $status = (string) ($payload['status'] ?? '');
        if (!preg_match('~\A[0-9a-f-]{36}\z~i', $jobId) || !in_array($status, ['processing', 'completed', 'failed'], true)) {
            throw new RuntimeException('Callback video job không hợp lệ.');
        }

        $progress = filter_var($payload['progress'] ?? null, FILTER_VALIDATE_INT);
        $progress = $progress === false ? null : max(0, min(100, $progress));
        $outputUrl = trim((string) ($payload['outputUrl'] ?? ''));
        $errorMessage = mb_substr(trim((string) ($payload['error'] ?? '')), 0, 1000);
        if ($status === 'completed' && !self::isSafeOutputUrl($outputUrl)) {
            throw new RuntimeException('Job hoàn tất phải có outputUrl HTTPS hợp lệ.');
        }

        return Database::transaction(function () use ($jobId, $status, $progress, $outputUrl, $errorMessage, $payload): array {
            $job = Database::one('SELECT id, user_id, bot_key, status FROM video_jobs WHERE id = ? FOR UPDATE', [$jobId]);
            if ($job === null) {
                throw new RuntimeException('Không tìm thấy video job.');
            }
            if (in_array($job['status'], ['completed', 'failed'], true)) {
                if ($job['status'] === $status) {
                    return [...$job, 'duplicate' => true];
                }
                throw new RuntimeException('Video job đã ở trạng thái kết thúc.');
            }

            $finalProgress = $status === 'completed' ? 100 : ($progress ?? (int) Database::value('SELECT progress FROM video_jobs WHERE id = ?', [$jobId]));
            Database::run(
                'UPDATE video_jobs SET status = ?, progress = ?, output_url = ?, error_message = ?, provider_job_id = ?, ' .
                'updated_at = NOW(), completed_at = CASE WHEN ? IN (\'completed\', \'failed\') THEN NOW() ELSE NULL END WHERE id = ?',
                [
                    $status,
                    $finalProgress,
                    $status === 'completed' ? $outputUrl : '',
                    $status === 'failed' ? $errorMessage : '',
                    mb_substr(trim((string) ($payload['providerJobId'] ?? '')), 0, 200),
                    $status,
                    $jobId,
                ]
            );
            return [...$job, 'status' => $status, 'progress' => $finalProgress, 'output_url' => $outputUrl, 'error_message' => $errorMessage];
        });
    }

    /**
     * Recover jobs left in processing after a worker/container restart.
     * The provider must treat jobId as an idempotency key: a retry reuses
     * the same UUID. Attempts are bounded to prevent infinite redispatch.
     */
    public static function recoverStale(?int $staleMinutes = null): array
    {
        $minutes = max(15, min(360, $staleMinutes ?? Env::int('VIDEO_PROCESSING_STALE_MINUTES', 90)));
        $failed = Database::run(
            "UPDATE video_jobs SET status = 'failed', error_message = 'Worker timeout after maximum dispatch attempts.', completed_at = NOW(), updated_at = NOW() " .
            "WHERE status = 'processing' AND claimed_at < NOW() - (?::integer * INTERVAL '1 minute') AND attempts >= 3 RETURNING id",
            [$minutes]
        );
        $requeued = Database::run(
            "UPDATE video_jobs SET status = 'queued', progress = 0, claimed_at = NULL, updated_at = NOW() " .
            "WHERE status = 'processing' AND claimed_at < NOW() - (?::integer * INTERVAL '1 minute') AND attempts < 3 RETURNING id",
            [$minutes]
        );
        return ['failed' => $failed->rowCount(), 'requeued' => $requeued->rowCount(), 'staleMinutes' => $minutes];
    }

    public static function dispatchOne(): ?array
    {
        self::recoverStale();
        $processorUrl = trim((string) Env::get('VIDEO_PROCESSOR_URL', ''));
        $processorSecret = (string) Env::get('VIDEO_PROCESSOR_SECRET', '');
        if ($processorUrl === '' || $processorSecret === '') {
            throw new RuntimeException('Chưa cấu hình VIDEO_PROCESSOR_URL và VIDEO_PROCESSOR_SECRET. Job vẫn nằm trong hàng chờ.');
        }
        if (strlen($processorSecret) < 32) {
            throw new RuntimeException('VIDEO_PROCESSOR_SECRET phải có ít nhất 32 ký tự.');
        }
        $parts = parse_url($processorUrl);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('VIDEO_PROCESSOR_URL phải là HTTPS.');
        }
        $base = rtrim((string) (Env::get('APP_URL') ?: Env::get('RENDER_EXTERNAL_URL', '')), '/');
        $baseParts = $base !== '' ? parse_url($base) : false;
        if (!is_array($baseParts) || strtolower((string) ($baseParts['scheme'] ?? '')) !== 'https' || empty($baseParts['host'])) {
            throw new RuntimeException('APP_URL/RENDER_EXTERNAL_URL HTTPS phải được cấu hình trước khi bật worker.');
        }

        $job = Database::transaction(function (): ?array {
            $next = Database::one(
                "SELECT id, user_id, bot_key, source_url FROM video_jobs WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED"
            );
            if ($next === null) {
                return null;
            }
            Database::run(
                "UPDATE video_jobs SET status = 'processing', progress = 1, claimed_at = NOW(), attempts = attempts + 1, updated_at = NOW() WHERE id = ?",
                [$next['id']]
            );
            return $next;
        });
        if ($job === null) {
            return null;
        }

        $body = json_encode([
            'jobId' => $job['id'],
            'telegramId' => $job['user_id'],
            'sourceUrl' => $job['source_url'],
            'callbackUrl' => $base . '/webhook/video',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            self::markFailed((string) $job['id'], 'Không thể mã hóa dữ liệu job.');
            return [...$job, 'status' => 'failed'];
        }

        $signature = hash_hmac('sha256', $body, $processorSecret);
        $handle = curl_init($processorUrl);
        if ($handle === false) {
            self::markFailed((string) $job['id'], 'Không thể kết nối nhà xử lý video.');
            return [...$job, 'status' => 'failed'];
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Hendy-Video-Signature: sha256=' . $signature],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
            self::markFailed((string) $job['id'], $curlError !== '' ? 'Không kết nối được nhà xử lý video.' : 'Nhà xử lý video trả HTTP ' . $httpStatus . '.');
            return [...$job, 'status' => 'failed'];
        }
        $decoded = json_decode((string) $response, true);
        $providerJobId = is_array($decoded) ? mb_substr(trim((string) ($decoded['jobId'] ?? '')), 0, 200) : '';
        Database::run(
            "UPDATE video_jobs SET provider_job_id = ?, updated_at = NOW() WHERE id = ? AND status = 'processing'",
            [$providerJobId, $job['id']]
        );
        return [...$job, 'status' => 'processing', 'provider_job_id' => $providerJobId];
    }

    public static function markFailed(string $jobId, string $message): void
    {
        Database::run(
            "UPDATE video_jobs SET status = 'failed', error_message = ?, updated_at = NOW(), completed_at = NOW() WHERE id = ? AND status IN ('queued','processing')",
            [mb_substr($message, 0, 1000), $jobId]
        );
    }

    private static function isSafeOutputUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && (!isset($parts['port']) || (int) $parts['port'] === 443);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
