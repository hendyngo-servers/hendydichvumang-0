<?php
declare(strict_types=1);

namespace Hendy;

/**
 * Admin-only readiness report. Exposes booleans and missing variable NAMES,
 * never secret values, provider tokens, supplier credentials or raw payloads.
 */
final class ServiceReadinessService
{
    private static function httpsConfigured(string $value): bool
    {
        $parts = $value !== '' ? parse_url($value) : false;
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private static function present(string $key): bool
    {
        return trim((string) Env::get($key, '')) !== '';
    }

    private static function service(
        string $key,
        string $label,
        bool $ready,
        bool $enabled,
        array $missing,
        string $note
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'ready' => $ready,
            'enabled' => $enabled,
            'missing' => array_values(array_unique($missing)),
            'note' => $note,
        ];
    }

    private static function tableExists(string $table): bool
    {
        if (!preg_match('/\\A[a-z_][a-z0-9_]*\\z/', $table)) return false;
        try {
            return in_array(Database::value(
                "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema=current_schema() AND table_name=?)",
                [$table]
            ), [true, 1, '1', 't', 'true'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function safeCount(string $table, string $where = ''): int
    {
        if (!self::tableExists($table)) return 0;
        try {
            return max(0, (int) Database::value('SELECT COUNT(*) FROM ' . $table . ($where !== '' ? ' WHERE ' . $where : '')));
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function snapshot(): array
    {
        $predicate = ProductReadinessService::sqlPredicate('p');
        $categories = [];
        $productsTableReady = self::tableExists('products');
        if ($productsTableReady) {
            try {
                $rows = Database::all(
                    "SELECT p.category,
                            COUNT(*) AS total_count,
                            COUNT(*) FILTER (WHERE " . $predicate . " AND (p.stock IS NULL OR p.stock > 0)) AS ready_count
                     FROM products p
                     GROUP BY p.category
                     ORDER BY p.category"
                );
                foreach ($rows as $row) {
                    $categories[(string) $row['category']] = [
                        'total' => (int) $row['total_count'],
                        'ready' => (int) $row['ready_count'],
                        'locked' => max(0, (int) $row['total_count'] - (int) $row['ready_count']),
                    ];
                }
            } catch (\Throwable) {
                $productsTableReady = false;
                $categories = [];
            }
        }

        $settingsTableReady = self::tableExists('app_settings');
        $ai = [
            'queueConfigured' => Env::bool('AI_MEDIA_QUEUE_ENABLED', false),
            'adminEnabled' => false, 'musicKeyConfigured' => false,
            'imageKeyConfigured' => false, 'musicEnabled' => false,
            'imageEnabled' => false, 'musicFlag' => false, 'imageFlag' => false,
        ];
        if ($settingsTableReady) {
            try { $ai = AiMediaService::readiness(); } catch (\Throwable) {}
        }
        $musicMissing = [];
        if (!$ai['queueConfigured']) $musicMissing[] = 'AI_MEDIA_QUEUE_ENABLED';
        if (!$ai['musicKeyConfigured']) $musicMissing[] = 'ELEVENLABS_API_KEY';
        if (!$ai['adminEnabled'] || !$ai['musicFlag']) $musicMissing[] = 'Admin AI Studio / Music toggle';
        $imageMissing = [];
        if (!$ai['queueConfigured']) $imageMissing[] = 'AI_MEDIA_QUEUE_ENABLED';
        if (!$ai['imageKeyConfigured']) $imageMissing[] = 'OPENAI_API_KEY';
        if (!$ai['adminEnabled'] || !$ai['imageFlag']) $imageMissing[] = 'Admin AI Studio / Image toggle';

        $coverTablesReady = $settingsTableReady && self::tableExists('ai_cover_voices');
        $cover = [
            'enabled' => false, 'runtimeEnabled' => false, 'workerEnabled' => false,
            'workerConfigured' => false, 'storageConfigured' => false,
            'callbackSecretConfigured' => false, 'voiceReady' => false, 'ready' => false,
        ];
        if ($coverTablesReady) {
            try { $cover = AiCoverService::readiness(); } catch (\Throwable) {}
        }
        $coverMissing = [];
        if (!Env::bool('AI_COVER_ENABLED', false)) $coverMissing[] = 'AI_COVER_ENABLED';
        if (!$cover['workerEnabled']) $coverMissing[] = 'AI_COVER_WORKER_ENABLED';
        if (!$cover['workerConfigured']) $coverMissing[] = 'AI_COVER_WORKER_URL (HTTPS)';
        if (!$cover['callbackSecretConfigured']) $coverMissing[] = 'AI_COVER_CALLBACK_SECRET (>= 32 chars)';
        if (!$cover['storageConfigured']) $coverMissing[] = 'Neon Object Storage / NEON_STORAGE_FUNCTION_KEY';
        if (!$cover['voiceReady']) $coverMissing[] = 'Preset có giấy phép và đã xác minh trong 30 phút gần nhất';
        if (!$cover['runtimeEnabled']) $coverMissing[] = 'Admin AI Cover toggle';

        $videoUrl = trim((string) Env::get('VIDEO_PROCESSOR_URL', ''));
        $videoUrlReady = self::httpsConfigured($videoUrl);
        $videoSecretReady = strlen(trim((string) Env::get('VIDEO_PROCESSOR_SECRET', ''))) >= 32;
        $appUrl = trim((string) (Env::get('APP_URL') ?: Env::get('RENDER_EXTERNAL_URL', '')));
        $appUrlReady = self::httpsConfigured($appUrl);
        $videoWorkerEnabled = Env::bool('VIDEO_WORKER_ENABLED', false);
        $videoMissing = [];
        if (!$videoWorkerEnabled) $videoMissing[] = 'VIDEO_WORKER_ENABLED=true (khởi chạy worker)';
        if (!$videoUrlReady) $videoMissing[] = 'VIDEO_PROCESSOR_URL (HTTPS)';
        if (!$videoSecretReady) $videoMissing[] = 'VIDEO_PROCESSOR_SECRET (>= 32 chars)';
        if (!$appUrlReady) $videoMissing[] = 'APP_URL hoặc RENDER_EXTERNAL_URL (HTTPS)';

        $proxyTablesReady = self::tableExists('homeproxy_accounts') && self::tableExists('homeproxy_logs');
        $proxy = [
            'enabled' => false, 'encryptionConfigured' => false,
            'connectedMembers' => 0, 'requestsToday' => 0, 'successToday' => 0,
            'failedToday' => 0, 'recentLogs' => [],
        ];
        if ($proxyTablesReady) {
            try { $proxy = ProxyService::adminStatus(); } catch (\Throwable) {}
        }
        $proxyMissing = [];
        if (!$proxyTablesReady) $proxyMissing[] = 'Migration HomeProxy: homeproxy_accounts / homeproxy_logs';
        if (!$proxy['encryptionConfigured']) $proxyMissing[] = 'HOMEPROXY_ENCRYPTION_KEY (>= 32 chars)';
        if (!$proxy['enabled']) $proxyMissing[] = 'Admin HomeProxy toggle';
        $proxyReady = $proxyTablesReady && (bool) $proxy['enabled'] && (bool) $proxy['encryptionConfigured'];

        $codeEnabled = CodeTelegramService::enabled();
        $codeWorkerEnabled = Env::bool('CODE_TELEGRAM_WORKER_ENABLED', false);
        $codeSourcesTableReady = self::tableExists('code_telegram_sources');
        $codeServicesTableReady = self::tableExists('code_telegram_services');
        $codeRowsTableReady = self::tableExists('code_telegram_codes');
        $sourceCount = $codeSourcesTableReady ? self::safeCount('code_telegram_sources', 'is_active = TRUE') : 0;
        $serviceCount = $codeServicesTableReady ? self::safeCount('code_telegram_services', 'is_active = TRUE') : 0;
        $pendingCodes = $codeRowsTableReady ? self::safeCount('code_telegram_codes', "status='pending_review'") : 0;
        $codeMissing = [];
        if (!$codeEnabled) $codeMissing[] = 'CODE_TELEGRAM_ENABLED=true';
        if (!$codeSourcesTableReady) $codeMissing[] = 'Migration bảng code_telegram_sources';
        if (!$codeServicesTableReady) $codeMissing[] = 'Migration bảng code_telegram_services';
        if (!$codeRowsTableReady) $codeMissing[] = 'Migration bảng code_telegram_codes';
        if ($sourceCount < 1) $codeMissing[] = 'Ít nhất một kênh nguồn được Admin duyệt';
        if ($serviceCount < 1) $codeMissing[] = 'Ít nhất một dịch vụ Code Telegram đang hoạt động';
        $ocrMissing = [];
        if (!$codeEnabled) $ocrMissing[] = 'CODE_TELEGRAM_ENABLED=true';
        if (!$codeWorkerEnabled) $ocrMissing[] = 'CODE_TELEGRAM_WORKER_ENABLED=true';
        if (!$codeSourcesTableReady) $ocrMissing[] = 'Migration bảng code_telegram_sources';
        if (!$sourceCount) $ocrMissing[] = 'Kênh nguồn được Admin duyệt';
        $otpProducts = (int) (($categories['otp']['ready'] ?? 0));
        $proxyProducts = (int) (($categories['proxy']['ready'] ?? 0));

        $missingProductColumns = ['products table'];
        if ($productsTableReady) {
            try { $missingProductColumns = ProductReadinessService::missingSchemaColumns(); } catch (\Throwable) {}
        }
        $services = [
            self::service(
                'product_readiness_migration',
                'Database · migration quyền nguồn / nhà cung cấp',
                $missingProductColumns === [],
                $missingProductColumns === [],
                $missingProductColumns === []
                    ? []
                    : ['Chạy telegram-bot/database/migrations/20261113_product_supplier_readiness.sql bằng database owner', 'Cột còn thiếu: ' . implode(', ', $missingProductColumns)],
                $missingProductColumns === []
                    ? 'Schema đã có đủ cột readiness.'
                    : 'Migration chưa áp dụng. Kho sản phẩm được khóa fail-closed; runtime Bot không tự chạy DDL.'
            ),
            self::service(
                'products',
                'Kho sản phẩm / quyền nguồn',
                array_sum(array_column($categories, 'ready')) > 0,
                true,
                [],
                'Mỗi sản phẩm cần quyền nguồn đã xác minh, nhà cung cấp đã xác minh, URL hồ sơ HTTPS và trạng thái kho còn hàng.'
            ),
            self::service(
                'otp',
                'OTP SMS',
                false,
                false,
                ['Nhà cung cấp OTP thật', 'Hợp đồng / quyền phân phối', 'Adapter API chuyên biệt và kiểm thử'],
                $otpProducts > 0
                    ? 'Có ' . $otpProducts . ' sản phẩm OTP thủ công đủ hồ sơ; chưa có adapter OTP API tự động.'
                    : 'Chưa có sản phẩm OTP đủ hồ sơ. Chưa có nhà cung cấp / API adapter tự động.'
            ),
            self::service(
                'proxy',
                'HomeProxy',
                $proxyReady,
                (bool) $proxy['enabled'],
                $proxyMissing,
                'Mỗi thành viên tự kết nối Key Xoay. HENDY mã hóa AES-256-GCM; không dùng một API key chung cho mọi thành viên. Sản phẩm proxy đủ hồ sơ: ' . $proxyProducts . '.'
            ),
            self::service(
                'code_telegram',
                'Code Telegram',
                $codeEnabled && $sourceCount > 0 && $serviceCount > 0,
                $codeEnabled,
                $codeMissing,
                'Nguồn phải được Admin allow-list. Mã đang chờ kiểm duyệt: ' . $pendingCodes . '. Nguồn hoạt động: ' . $sourceCount . '; dịch vụ đang bán: ' . $serviceCount . '.'
            ),
            self::service(
                'code_telegram_ocr',
                'Code Telegram · OCR video',
                $codeEnabled && $codeWorkerEnabled && $sourceCount > 0,
                $codeWorkerEnabled,
                $ocrMissing,
                'OCR chỉ chạy trên video từ nguồn đã duyệt; mã trích xuất mặc định chờ Admin duyệt.'
            ),
            self::service(
                'ai_music',
                'AI tạo âm nhạc',
                (bool) $ai['musicEnabled'],
                (bool) $ai['musicFlag'],
                $musicMissing,
                'Cần AI worker, ELEVENLABS_API_KEY và công tắc Admin; API key chỉ đọc từ Render Environment.'
            ),
            self::service(
                'ai_image',
                'AI chỉnh sửa ảnh',
                (bool) $ai['imageEnabled'],
                (bool) $ai['imageFlag'],
                $imageMissing,
                'Cần AI worker, OPENAI_API_KEY và công tắc Admin; API key chỉ đọc từ Render Environment.'
            ),
            self::service(
                'ai_cover',
                'AI Cover / RVC',
                (bool) $cover['enabled'],
                (bool) $cover['runtimeEnabled'],
                $coverMissing,
                'Cần worker HTTPS riêng, callback HMAC, Neon Storage và preset có giấy phép hợp lệ.'
            ),
            self::service(
                'vietsub',
                'Vietsub / Video Worker',
                $videoWorkerEnabled && $videoUrlReady && $videoSecretReady && $appUrlReady,
                $videoWorkerEnabled,
                $videoMissing,
                'Bot vẫn có thể nhận link và tạo job; kết quả xử lý chỉ chạy khi worker và nhà cung cấp video đã cấu hình.'
            ),
            self::service(
                'matchmaking',
                'Ghép đôi / Đối đầu',
                true,
                true,
                [],
                'Ghép nhanh, xếp hạng, chấp nhận hai bên, hủy hàng chờ, báo cáo tỷ số, xử lý tranh chấp và bảng xếp hạng; không giao dịch tiền.'
            ),
        ];

        return [
            'checkedAt' => gmdate(DATE_ATOM),
            'services' => $services,
            'categories' => $categories,
            'summary' => [
                'serviceCount' => count($services),
                'readyServices' => count(array_filter($services, static fn(array $item): bool => $item['ready'])),
                'lockedServices' => count(array_filter($services, static fn(array $item): bool => !$item['ready'])),
                'readyProducts' => array_sum(array_column($categories, 'ready')),
                'lockedProducts' => array_sum(array_column($categories, 'locked')),
            ],
            'security' => [
                'secretValuesReturned' => false,
                'providerCredentialsStoredInDatabase' => false,
                'apiFulfillmentAllowed' => false,
                'note' => 'Báo cáo chỉ trả trạng thái cấu hình và tên biến cần có; không trả giá trị secret.',
            ],
        ];
    }
}
