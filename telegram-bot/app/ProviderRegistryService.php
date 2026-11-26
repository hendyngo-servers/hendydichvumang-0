<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

/**
 * Admin provider/service control plane.
 *
 * Only provider metadata and Environment variable NAMES are stored in PostgreSQL.
 * Secret values remain in the process environment. A configuration check never
 * claims that a remote API is reachable; provider-specific adapters must perform
 * real authenticated health checks before a provider is treated as verified.
 */
final class ProviderRegistryService
{
    /** @var array<string, array{label:string,group:string,description:string,adminTab:string,paths:list<string>}> */
    private const SERVICES = [
        'telegram_bot' => ['label' => 'Telegram Bot', 'group' => 'Nền tảng', 'description' => 'Webhook, menu, lệnh và xử lý cập nhật Telegram.', 'adminTab' => 'runtime', 'paths' => []],
        'website' => ['label' => 'Website / App Store', 'group' => 'Nền tảng', 'description' => 'Website giới thiệu, danh mục công khai và cổng quản trị.', 'adminTab' => 'settings', 'paths' => []],
        'products' => ['label' => 'Kho sản phẩm & đơn hàng', 'group' => 'Thương mại', 'description' => 'Danh mục, kiểm tra quyền nguồn, tồn kho và tạo đơn.', 'adminTab' => 'products', 'paths' => ['/api/products', '/api/orders']],
        'minigame' => ['label' => 'Mini Game Live', 'group' => 'Dịch vụ thành viên', 'description' => 'Liên kết thương hiệu và tài khoản Mini Game; hiện chỉ lưu tên đăng nhập.', 'adminTab' => 'users', 'paths' => ['/api/linked-accounts']],
        'tools' => ['label' => 'Thuê Tool', 'group' => 'Dịch vụ thành viên', 'description' => 'Gói Tool, thời hạn thuê và quy trình bàn giao.', 'adminTab' => 'products', 'paths' => []],
        'otp' => ['label' => 'OTP SMS', 'group' => 'Dịch vụ thành viên', 'description' => 'Gói OTP; chỉ mở bán khi có nguồn hợp lệ và quy trình bàn giao.', 'adminTab' => 'service-readiness', 'paths' => []],
        'proxy' => ['label' => 'Proxy / HomeProxy', 'group' => 'Dịch vụ thành viên', 'description' => 'Kết nối Key Xoay theo từng thành viên; mã hóa khóa tại máy chủ.', 'adminTab' => 'proxy', 'paths' => ['/api/proxy']],
        'code_telegram' => ['label' => 'Code Telegram', 'group' => 'Dịch vụ thành viên', 'description' => 'Nguồn kênh allow-list, dịch vụ, kho mã và đơn yêu cầu.', 'adminTab' => 'code-telegram', 'paths' => []],
        'code_telegram_ocr' => ['label' => 'OCR Video / Code Telegram', 'group' => 'Tự động hóa', 'description' => 'Job OCR từ nguồn đã duyệt; kết quả mới mặc định chờ kiểm duyệt.', 'adminTab' => 'code-telegram', 'paths' => []],
        'payments' => ['label' => 'Nạp tiền / Đối soát', 'group' => 'Tài chính', 'description' => 'Yêu cầu nạp, webhook ngân hàng và phê duyệt có đối soát.', 'adminTab' => 'payments', 'paths' => ['/api/wallet/payment-requests', '/api/wallet/payment-info']],
        'wallet' => ['label' => 'Ví thành viên', 'group' => 'Tài chính', 'description' => 'Số dư, lịch sử giao dịch và bút toán ví.', 'adminTab' => 'payments', 'paths' => ['/api/wallet']],
        'vip' => ['label' => 'VIP & quyền lợi', 'group' => 'Thành viên', 'description' => 'Cấp VIP, điểm danh và quyền lợi thành viên.', 'adminTab' => 'products', 'paths' => ['/api/vip']],
        'referrals' => ['label' => 'Giới thiệu REF', 'group' => 'Thành viên', 'description' => 'Liên kết giới thiệu và thống kê hoa hồng.', 'adminTab' => 'settings', 'paths' => ['/api/referrals']],
        'support' => ['label' => 'Trung tâm hỗ trợ', 'group' => 'Vận hành', 'description' => 'Ticket CSKH và lịch sử hỗ trợ thành viên.', 'adminTab' => 'tickets', 'paths' => ['/api/support', '/api/customer-center']],
        'vouchers' => ['label' => 'Voucher 72H', 'group' => 'Thành viên', 'description' => 'Tiếp nhận, duyệt và ghi nhận yêu cầu voucher.', 'adminTab' => 'vouchers', 'paths' => ['/api/vouchers']],
        'giftcodes' => ['label' => 'Giftcode', 'group' => 'Thành viên', 'description' => 'Tạo, bật/tắt và đổi mã quà tặng.', 'adminTab' => 'giftcodes', 'paths' => ['/api/giftcodes']],
        'matchmaking' => ['label' => 'Ghép đôi / Đối đầu', 'group' => 'Cộng đồng', 'description' => 'Ghép nhanh, xếp hạng, xác nhận kết quả và xử lý tranh chấp.', 'adminTab' => 'service-readiness', 'paths' => ['/api/matchmaking']],
        'ai_music' => ['label' => 'AI tạo âm nhạc', 'group' => 'AI Studio', 'description' => 'Hàng đợi tác vụ và tích hợp ElevenLabs Music.', 'adminTab' => 'ai-media', 'paths' => []],
        'ai_image' => ['label' => 'AI chỉnh sửa ảnh', 'group' => 'AI Studio', 'description' => 'Chỉnh sửa ảnh qua OpenAI; giới hạn tác vụ và xử lý riêng tư.', 'adminTab' => 'ai-media', 'paths' => []],
        'ai_cover' => ['label' => 'AI Cover / RVC', 'group' => 'AI Studio', 'description' => 'Worker RVC, HMAC callback, kho riêng và hồ sơ cấp phép.', 'adminTab' => 'ai-cover', 'paths' => []],
        'vietsub' => ['label' => 'Vietsub / Video', 'group' => 'Tự động hóa', 'description' => 'Nhận link và xử lý video thông qua worker được cấu hình.', 'adminTab' => 'video-jobs', 'paths' => []],
        'modules' => ['label' => 'Tệp & module', 'group' => 'Quản trị', 'description' => 'Tải vào vùng cách ly, kiểm duyệt và theo dõi artifact.', 'adminTab' => 'modules', 'paths' => []],
    ];

    private const ADAPTERS = [
        'manual' => ['label' => 'Bàn giao thủ công', 'implemented' => true],
        'homeproxy' => ['label' => 'HomeProxy tích hợp sẵn', 'implemented' => true],
        'ai_media' => ['label' => 'AI Studio tích hợp sẵn', 'implemented' => true],
        'ai_cover_worker' => ['label' => 'AI Cover Worker', 'implemented' => true],
        'video_worker' => ['label' => 'Video Worker', 'implemented' => true],
        'code_telegram' => ['label' => 'Code Telegram nội bộ', 'implemented' => true],
        'custom_api_pending' => ['label' => 'API riêng (chưa có adapter)', 'implemented' => false],
    ];

    public static function serviceKeys(): array
    {
        return array_keys(self::SERVICES);
    }

    public static function adapterKeys(): array
    {
        return array_keys(self::ADAPTERS);
    }

    public static function validateServiceKey(string $key): string
    {
        if (!isset(self::SERVICES[$key])) {
            throw new RuntimeException('Dịch vụ không nằm trong danh mục HENDY đã đăng ký.');
        }
        return $key;
    }

    public static function validateProviderInput(array $input): array
    {
        $serviceKey = self::validateServiceKey(trim((string) ($input['serviceKey'] ?? $input['service_key'] ?? '')));
        $providerKey = strtolower(trim((string) ($input['providerKey'] ?? $input['provider_key'] ?? '')));
        $name = trim((string) ($input['name'] ?? $input['providerName'] ?? $input['provider_name'] ?? ''));
        $adapterKey = trim((string) ($input['adapterKey'] ?? $input['adapter_key'] ?? 'manual'));
        $endpoint = trim((string) ($input['endpointUrl'] ?? $input['endpoint_url'] ?? ''));
        $envKey = trim((string) ($input['credentialEnvKey'] ?? $input['credential_env_key'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        $priority = filter_var($input['priority'] ?? 100, FILTER_VALIDATE_INT);
        if ($priority === false || $priority < 0 || $priority > 10000) {
            throw new RuntimeException('Độ ưu tiên phải là số nguyên từ 0 đến 10.000.');
        }

        if (!preg_match('/^[a-z][a-z0-9_-]{1,47}$/', $providerKey)) {
            throw new RuntimeException('Mã nhà cung cấp chỉ gồm chữ thường, số, dấu gạch ngang hoặc gạch dưới (2–48 ký tự).');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new RuntimeException('Tên nhà cung cấp phải từ 2 đến 120 ký tự.');
        }
        if (!isset(self::ADAPTERS[$adapterKey])) {
            throw new RuntimeException('Loại adapter không nằm trong danh sách cho phép.');
        }
        $adapterServices = match ($adapterKey) {
            'manual', 'custom_api_pending' => null,
            'homeproxy' => ['proxy'],
            'ai_media' => ['ai_music', 'ai_image'],
            'ai_cover_worker' => ['ai_cover'],
            'video_worker' => ['vietsub'],
            'code_telegram' => ['code_telegram', 'code_telegram_ocr'],
            default => [],
        };
        if (is_array($adapterServices) && !in_array($serviceKey, $adapterServices, true)) {
            throw new RuntimeException('Adapter ' . $adapterKey . ' không tương thích với dịch vụ ' . $serviceKey . '.');
        }
        if ($endpoint !== '') {
            $parts = parse_url($endpoint);
            if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['fragment']) || isset($parts['query']) || filter_var((string) $parts['host'], FILTER_VALIDATE_IP)
                || in_array(strtolower((string) $parts['host']), ['localhost'], true)
                || str_ends_with(strtolower((string) $parts['host']), '.local')
                || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('Endpoint nhà cung cấp phải là URL HTTPS công khai, không chứa thông tin đăng nhập hoặc fragment.');
            }
        }
        if ($adapterKey === 'custom_api_pending' && $endpoint === '') {
            throw new RuntimeException('Adapter tự động cần có Endpoint HTTPS.');
        }
        $credentialEnvKey = $envKey === '' ? '' : ProductReadinessService::validateEnvKey($envKey);
        $requiredEnvKey = self::requiredEnvKey($serviceKey, $adapterKey);
        if ($requiredEnvKey !== '') {
            if ($credentialEnvKey !== '' && $credentialEnvKey !== $requiredEnvKey) {
                throw new RuntimeException('Adapter này chỉ nhận tên biến Environment ' . $requiredEnvKey . '.');
            }
            $credentialEnvKey = $requiredEnvKey;
        }
        if (mb_strlen($notes) > 1200) {
            throw new RuntimeException('Ghi chú nhà cung cấp không được vượt quá 1.200 ký tự.');
        }

        return [
            'service_key' => $serviceKey,
            'provider_key' => $providerKey,
            'provider_name' => $name,
            'adapter_key' => $adapterKey,
            'endpoint_url' => $endpoint,
            'credential_env_key' => $credentialEnvKey,
            'enabled' => filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'priority' => $priority,
            'notes' => $notes,
        ];
    }

    /**
     * Schema status is reported instead of allowing a missing migration to turn
     * the Admin Provider Registry endpoint into an unexplained HTTP 500.
     */
    public static function schemaStatus(): array
    {
        $required = ['service_registry', 'service_providers'];
        try {
            $rows = Database::all(
                "SELECT table_name FROM information_schema.tables
                 WHERE table_schema = current_schema() AND table_name IN (?, ?)",
                $required
            );
            $present = array_map(static fn(array $row): string => (string) $row['table_name'], $rows);
            $missing = array_values(array_diff($required, $present));
            return [
                'ready' => $missing === [],
                'requiredTables' => $required,
                'presentTables' => $present,
                'missingTables' => $missing,
                'checkFailed' => false,
            ];
        } catch (\Throwable $error) {
            error_log('[provider-registry-schema] ' . $error::class);
            return [
                'ready' => false,
                'requiredTables' => $required,
                'presentTables' => [],
                'missingTables' => $required,
                'checkFailed' => true,
            ];
        }
    }

    private static function requireSchemaReady(): void
    {
        $status = self::schemaStatus();
        if (!$status['ready']) {
            throw new RuntimeException(
                'Provider & Service Registry chưa sẵn sàng. Thiếu bảng: ' .
                implode(', ', $status['missingTables']) .
                '. Hãy áp dụng telegram-bot/database/migrations/20261113_provider_service_registry.sql bằng migration runner và database-owner role.'
            );
        }
    }

    public static function snapshot(): array
    {
        $schema = self::schemaStatus();
        if (!$schema['ready']) {
            $readiness = ServiceReadinessService::snapshot();
            $readinessByKey = [];
            foreach (($readiness['services'] ?? []) as $item) {
                $readinessByKey[(string) ($item['key'] ?? '')] = $item;
            }
            $services = [];
            foreach (self::SERVICES as $key => $definition) {
                $report = $readinessByKey[$key] ?? null;
                $services[] = [
                    'key' => $key,
                    'label' => $definition['label'],
                    'group' => $definition['group'],
                    'description' => $definition['description'],
                    'adminTab' => $definition['adminTab'],
                    'readinessKnown' => $report !== null,
                    'ready' => (bool) ($report['ready'] ?? false),
                    'readinessNote' => (string) ($report['note'] ?? 'Chưa kiểm tra được readiness.'),
                    'missing' => array_values($report['missing'] ?? []),
                    'enabled' => true,
                    'maintenanceMode' => false,
                    'maintenanceMessage' => '',
                    'updatedAt' => null,
                    'updatedBy' => '',
                    'providers' => [],
                    'apiAccessControl' => $definition['paths'] !== [],
                ];
            }
            return [
                'checkedAt' => gmdate(DATE_ATOM),
                'schemaReady' => false,
                'missingTables' => $schema['missingTables'],
                'message' => 'Chưa áp dụng migration Provider & Service Registry. Chức năng đọc/ghi cấu hình nhà cung cấp đang tạm khóa; API và dữ liệu hiện hữu không bị thay đổi.',
                'services' => $services,
                'providers' => [],
                'adapters' => array_map(
                    static fn(string $key, array $value): array => ['key' => $key, ...$value],
                    array_keys(self::ADAPTERS),
                    array_values(self::ADAPTERS)
                ),
                'summary' => [
                    'serviceCount' => count($services),
                    'readyServices' => count(array_filter($services, static fn(array $item): bool => $item['ready'])),
                    'disabledServices' => 0,
                    'maintenanceServices' => 0,
                    'providerCount' => 0,
                    'enabledProviders' => 0,
                ],
                'security' => [
                    'secretValuesReturned' => false,
                    'secretValuesStored' => false,
                    'configurationCheckIsLiveApiTest' => false,
                ],
            ];
        }

        $readiness = ServiceReadinessService::snapshot();
        $readinessByKey = [];
        foreach (($readiness['services'] ?? []) as $item) {
            $readinessByKey[(string) ($item['key'] ?? '')] = $item;
        }
        $settings = [];
        foreach (Database::all('SELECT service_key, is_enabled, maintenance_mode, maintenance_message, updated_by, updated_at FROM service_registry ORDER BY service_key') as $row) {
            $settings[(string) $row['service_key']] = $row;
        }
        $providers = [];
        foreach (Database::all('SELECT id, service_key, provider_key, provider_name, adapter_key, endpoint_url, credential_env_key, enabled, priority, notes, last_check_at, last_check_status, last_check_message, created_at, updated_at FROM service_providers ORDER BY service_key, priority, id') as $row) {
            $providers[(string) $row['service_key']][] = self::safeProvider($row);
        }

        $services = [];
        foreach (self::SERVICES as $key => $definition) {
            $setting = $settings[$key] ?? [];
            $report = $readinessByKey[$key] ?? null;
            $services[] = [
                'key' => $key,
                'label' => $definition['label'],
                'group' => $definition['group'],
                'description' => $definition['description'],
                'adminTab' => $definition['adminTab'],
                'readinessKnown' => $report !== null,
                'ready' => (bool) ($report['ready'] ?? false),
                'readinessNote' => (string) ($report['note'] ?? 'Dịch vụ chưa có phép kiểm tra readiness chuyên biệt.'),
                'missing' => array_values($report['missing'] ?? []),
                'enabled' => isset($setting['is_enabled']) ? self::dbBool($setting['is_enabled']) : true,
                'maintenanceMode' => self::dbBool($setting['maintenance_mode'] ?? false),
                'maintenanceMessage' => (string) ($setting['maintenance_message'] ?? ''),
                'updatedAt' => $setting['updated_at'] ?? null,
                'updatedBy' => (string) ($setting['updated_by'] ?? ''),
                'providers' => $providers[$key] ?? [],
                'apiAccessControl' => $definition['paths'] !== [],
            ];
        }

        $flatProviders = [];
        foreach ($providers as $items) {
            foreach ($items as $provider) $flatProviders[] = $provider;
        }
        return [
            'checkedAt' => gmdate(DATE_ATOM),
            'services' => $services,
            'providers' => $flatProviders,
            'adapters' => array_map(
                static fn(string $key, array $value): array => ['key' => $key, ...$value],
                array_keys(self::ADAPTERS),
                array_values(self::ADAPTERS)
            ),
            'summary' => [
                'serviceCount' => count($services),
                'readyServices' => count(array_filter($services, static fn(array $item): bool => $item['ready'])),
                'disabledServices' => count(array_filter($services, static fn(array $item): bool => !$item['enabled'])),
                'maintenanceServices' => count(array_filter($services, static fn(array $item): bool => $item['maintenanceMode'])),
                'providerCount' => count($flatProviders),
                'enabledProviders' => count(array_filter($flatProviders, static fn(array $item): bool => $item['enabled'])),
            ],
            'security' => [
                'secretValuesReturned' => false,
                'secretValuesStored' => false,
                'configurationCheckIsLiveApiTest' => false,
            ],
        ];
    }

    public static function saveService(string $key, array $input, string $adminId): array
    {
        self::requireSchemaReady();
        self::validateServiceKey($key);
        $enabled = filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $maintenance = filter_var($input['maintenanceMode'] ?? $input['maintenance_mode'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $message = trim((string) ($input['maintenanceMessage'] ?? $input['maintenance_message'] ?? ''));
        if (mb_strlen($message) > 500) {
            throw new RuntimeException('Thông báo bảo trì không được vượt quá 500 ký tự.');
        }
        $row = Database::one(
            'INSERT INTO service_registry (service_key, is_enabled, maintenance_mode, maintenance_message, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW()) ' .
            'ON CONFLICT (service_key) DO UPDATE SET is_enabled = EXCLUDED.is_enabled, maintenance_mode = EXCLUDED.maintenance_mode, maintenance_message = EXCLUDED.maintenance_message, updated_by = EXCLUDED.updated_by, updated_at = NOW() ' .
            'RETURNING service_key, is_enabled, maintenance_mode, maintenance_message, updated_by, updated_at',
            [$key, $enabled, $maintenance, $message, $adminId]
        );
        return [
            'key' => (string) $row['service_key'],
            'enabled' => self::dbBool($row['is_enabled']),
            'maintenanceMode' => self::dbBool($row['maintenance_mode']),
            'maintenanceMessage' => (string) $row['maintenance_message'],
            'updatedBy' => (string) $row['updated_by'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    public static function saveProvider(array $input, string $adminId, ?int $id = null): array
    {
        self::requireSchemaReady();
        $data = self::validateProviderInput($input);
        if ($id !== null) {
            $row = Database::one(
                'UPDATE service_providers SET service_key = ?, provider_key = ?, provider_name = ?, adapter_key = ?, endpoint_url = ?, credential_env_key = ?, enabled = ?, priority = ?, notes = ?, last_check_at = NULL, last_check_status = \'not_checked\', last_check_message = \'Cần kiểm tra lại sau khi thay cấu hình.\', updated_by = ?, updated_at = NOW() WHERE id = ? RETURNING id, service_key, provider_key, provider_name, adapter_key, endpoint_url, credential_env_key, enabled, priority, notes, last_check_at, last_check_status, last_check_message, created_at, updated_at',
                [...array_values($data), $adminId, $id]
            );
        } else {
            $row = Database::one(
                'INSERT INTO service_providers (service_key, provider_key, provider_name, adapter_key, endpoint_url, credential_env_key, enabled, priority, notes, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id, service_key, provider_key, provider_name, adapter_key, endpoint_url, credential_env_key, enabled, priority, notes, last_check_at, last_check_status, last_check_message, created_at, updated_at',
                [...array_values($data), $adminId, $adminId]
            );
        }
        if ($row === null) throw new RuntimeException('Không tìm thấy nhà cung cấp cần cập nhật.');
        return self::safeProvider($row);
    }

    public static function deleteProvider(int $id): void
    {
        self::requireSchemaReady();
        $deleted = Database::run('DELETE FROM service_providers WHERE id = ? RETURNING id', [$id]);
        if ($deleted->rowCount() === 0) throw new RuntimeException('Không tìm thấy nhà cung cấp.');
    }

    /**
     * Local configuration check only. It does not send credentials to the
     * provider and does not claim that an external API call succeeded.
     */
    private static function requiredEnvKey(string $serviceKey, string $adapterKey): string
    {
        return match ([$serviceKey, $adapterKey]) {
            ['proxy', 'homeproxy'] => 'HOMEPROXY_ENCRYPTION_KEY',
            ['ai_music', 'ai_media'] => 'ELEVENLABS_API_KEY',
            ['ai_image', 'ai_media'] => 'OPENAI_API_KEY',
            ['ai_cover', 'ai_cover_worker'] => 'AI_COVER_CALLBACK_SECRET',
            ['vietsub', 'video_worker'] => 'VIDEO_PROCESSOR_SECRET',
            default => '',
        };
    }

    private static function requiredEndpointEnvKey(string $serviceKey, string $adapterKey): string
    {
        return match ([$serviceKey, $adapterKey]) {
            ['ai_cover', 'ai_cover_worker'] => 'AI_COVER_WORKER_URL',
            ['vietsub', 'video_worker'] => 'VIDEO_PROCESSOR_URL',
            default => '',
        };
    }

    private static function validHttpsEndpoint(string $value): bool
    {
        $parts = $value !== '' ? parse_url($value) : false;
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || filter_var((string) $parts['host'], FILTER_VALIDATE_IP)
            || in_array(strtolower((string) $parts['host']), ['localhost'], true)
            || str_ends_with(strtolower((string) $parts['host']), '.local')) {
            return false;
        }
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    public static function checkProvider(int $id): array
    {
        self::requireSchemaReady();
        $row = Database::one(
            'SELECT id, service_key, provider_key, provider_name, adapter_key, endpoint_url, credential_env_key, enabled, priority, notes, last_check_at, last_check_status, last_check_message, created_at, updated_at FROM service_providers WHERE id = ?',
            [$id]
        );
        if ($row === null) throw new RuntimeException('Không tìm thấy nhà cung cấp.');
        $adapter = self::ADAPTERS[(string) $row['adapter_key']] ?? ['implemented' => false];
        $envKey = (string) $row['credential_env_key'];
        $requiredEnvKey = self::requiredEnvKey((string) $row['service_key'], (string) $row['adapter_key']);
        $checkedEnvKey = $requiredEnvKey !== '' ? $requiredEnvKey : $envKey;
        $secretConfigured = $checkedEnvKey !== '' && trim((string) Env::get($checkedEnvKey, '')) !== '';
        $requiredEndpointEnvKey = self::requiredEndpointEnvKey((string) $row['service_key'], (string) $row['adapter_key']);
        $endpointValue = trim((string) $row['endpoint_url']);
        if ($endpointValue === '' && $requiredEndpointEnvKey !== '') {
            $endpointValue = trim((string) Env::get($requiredEndpointEnvKey, ''));
        }
        $endpointConfigured = self::validHttpsEndpoint($endpointValue);
        if (!self::dbBool($row['enabled'])) {
            $status = 'disabled';
            $message = 'Nhà cung cấp đang tắt trong Registry.';
        } elseif (!(bool) ($adapter['implemented'] ?? false)) {
            $status = 'adapter_unavailable';
            $message = 'Chưa có adapter được kiểm thử trong mã nguồn; không cho phép thực thi tự động.';
        } elseif ($requiredEnvKey !== '' && $envKey !== $requiredEnvKey) {
            $status = 'missing_environment';
            $message = 'Adapter này yêu cầu đúng tên biến ' . $requiredEnvKey . ' trong Environment máy chủ.';
        } elseif ($checkedEnvKey !== '' && !$secretConfigured) {
            $status = 'missing_environment';
            $message = 'Chưa tìm thấy giá trị của biến ' . $checkedEnvKey . ' trong Environment máy chủ.';
        } elseif ($requiredEndpointEnvKey !== '' && !$endpointConfigured) {
            $status = 'missing_endpoint';
            $message = 'Chưa khai báo URL HTTPS hợp lệ trong biến ' . $requiredEndpointEnvKey . ' hoặc Endpoint của nhà cung cấp.';
        } elseif ($row['adapter_key'] === 'custom_api_pending' && !$endpointConfigured) {
            $status = 'missing_endpoint';
            $message = 'Chưa khai báo Endpoint HTTPS hợp lệ.';
        } else {
            $status = 'configuration_ready';
            $message = 'Cấu hình cục bộ hợp lệ. Đây chưa phải kết quả gọi thử API hoặc xác minh tài khoản nhà cung cấp.';
        }
        $updated = Database::one(
            'UPDATE service_providers SET last_check_at = NOW(), last_check_status = ?, last_check_message = ?, updated_at = NOW() WHERE id = ? RETURNING id, service_key, provider_key, provider_name, adapter_key, endpoint_url, credential_env_key, enabled, priority, notes, last_check_at, last_check_status, last_check_message, created_at, updated_at',
            [$status, $message, $id]
        );
        return self::safeProvider($updated ?? $row);
    }

    public static function blockMessageForPath(string $path, bool $isAdmin): ?string
    {
        if ($isAdmin) return null;
        foreach (self::SERVICES as $key => $definition) {
            foreach ($definition['paths'] as $prefix) {
                if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                    try {
                        $row = Database::one(
                            'SELECT is_enabled, maintenance_mode, maintenance_message FROM service_registry WHERE service_key = ?',
                            [$key]
                        );
                    } catch (\Throwable $error) {
                        // A missing registry migration must not turn normal member
                        // API requests into HTTP 500 responses. Admin gets an explicit
                        // schemaReady=false diagnostic through snapshot().
                        error_log('[provider-registry-access-control] ' . $error::class);
                        return null;
                    }
                    if ($row === null) return null;
                    if (!self::dbBool($row['is_enabled'])) return 'Dịch vụ ' . $definition['label'] . ' hiện đang tạm khóa theo cấu hình quản trị.';
                    if (self::dbBool($row['maintenance_mode'])) {
                        $message = trim((string) $row['maintenance_message']);
                        return 'Dịch vụ ' . $definition['label'] . ' đang bảo trì.' . ($message !== '' ? ' ' . $message : '');
                    }
                    return null;
                }
            }
        }
        return null;
    }

    private static function safeProvider(array $row): array
    {
        $envKey = (string) ($row['credential_env_key'] ?? '');
        $adapterKey = (string) ($row['adapter_key'] ?? 'manual');
        $adapter = self::ADAPTERS[$adapterKey] ?? ['label' => 'Không xác định', 'implemented' => false];
        return [
            'id' => (int) $row['id'],
            'serviceKey' => (string) $row['service_key'],
            'providerKey' => (string) $row['provider_key'],
            'name' => (string) $row['provider_name'],
            'adapterKey' => $adapterKey,
            'adapterLabel' => (string) ($adapter['label'] ?? ''),
            'adapterImplemented' => (bool) ($adapter['implemented'] ?? false),
            'endpointUrl' => (string) $row['endpoint_url'],
            'credentialEnvKey' => $envKey,
            'priority' => (int) ($row['priority'] ?? 100),
            'credentialConfigured' => $envKey !== '' && trim((string) Env::get($envKey, '')) !== '',
            'enabled' => self::dbBool($row['enabled']),
            'notes' => (string) $row['notes'],
            'lastCheckAt' => $row['last_check_at'],
            'lastCheckStatus' => (string) $row['last_check_status'],
            'lastCheckMessage' => (string) $row['last_check_message'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    private static function dbBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
