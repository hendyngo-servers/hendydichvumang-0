<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/NeonStorage.php';
require dirname(__DIR__) . '/app/ModuleRegistry.php';
require dirname(__DIR__) . '/app/ProductReadinessService.php';
require dirname(__DIR__) . '/app/ServiceReadinessService.php';
require dirname(__DIR__) . '/app/ProviderRegistryService.php';
require dirname(__DIR__) . '/app/SourceControlService.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/TelegramLink.php';
require dirname(__DIR__) . '/app/BotMenuDefinition.php';
require dirname(__DIR__) . '/app/WebhookSecurity.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/PaymentService.php';
require dirname(__DIR__) . '/app/VideoJobService.php';
require dirname(__DIR__) . '/app/CodeTelegramService.php';
require dirname(__DIR__) . '/app/WebServiceContract.php';
require dirname(__DIR__) . '/app/WebServiceKernel.php';
require dirname(__DIR__) . '/app/SystemWebService.php';
require dirname(__DIR__) . '/app/OperationsService.php';
require dirname(__DIR__) . '/app/MatchmakingService.php';
require dirname(__DIR__) . '/app/ProxyService.php';
require dirname(__DIR__) . '/app/MultiAgentService.php';

use Hendy\VideoJobService;
use Hendy\CodeTelegramService;
use Hendy\TelegramAuth;
use Hendy\TelegramLink;
use Hendy\BotMenuDefinition;
use Hendy\WebhookSecurity;
use Hendy\WebServiceKernel;
use Hendy\ProductReadinessService;
use Hendy\ServiceReadinessService;
use Hendy\ProviderRegistryService;
use Hendy\SourceControlService;
use Hendy\MultiAgentService;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Test thất bại: ' . $message);
    }
};

$schemaSource = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$databaseRunnerSource = file_get_contents(dirname(__DIR__) . '/app/Database.php');
$migrationCliSource = file_get_contents(dirname(__DIR__) . '/bin/migrate.php');
$databaseDoctorSource = file_get_contents(dirname(__DIR__) . '/bin/database-doctor.php');
$startupScriptSource = file_get_contents(dirname(__DIR__) . '/docker/start.sh');
$configValidatorSource = file_get_contents(dirname(__DIR__) . '/bin/validate-config.php');
$providerRegistryUiSource = file_get_contents(dirname(__DIR__, 2) . '/website/telegram/admin-dashboard/index.html');
$providerRegistrySource = file_get_contents(dirname(__DIR__) . '/app/ProviderRegistryService.php');

$sourceControlWorkflowSource = file_get_contents(dirname(__DIR__) . '/app/SourceControlService.php');
$assert(str_contains($sourceControlWorkflowSource, 'function dispatchWorkflow') && str_contains($sourceControlWorkflowSource, '/actions/workflows/hendy-monorepo-ci.yml/dispatches') && str_contains($sourceControlWorkflowSource, "'Actions: write'"), 'Admin CI dispatch is fixed to the HENDY workflow and requires Actions write permission');
$assert(str_contains($sourceControlWorkflowSource, '$status === 204') && str_contains($sourceControlWorkflowSource, "return [];"), 'GitHub workflow_dispatch HTTP 204 responses are handled correctly');
$assert(str_contains($sourceControlWorkflowSource, 'function workflowRuns') && str_contains($sourceControlWorkflowSource, '/actions/runs?per_page=') && str_contains($sourceControlWorkflowSource, "'Actions: read'"), 'GitHub Actions monitor returns only a bounded, allow-listed read-only run summary');
$assert(str_contains($sourceControlWorkflowSource, "html_url") && str_contains($sourceControlWorkflowSource, "head_sha") && str_contains($sourceControlWorkflowSource, "conclusion"), 'GitHub Actions monitor exposes safe run metadata without tokens or raw API payloads');

$productReadinessBase = [
    'is_active' => true, 'stock' => 3,
    'supplier_name' => 'HENDY inventory',
    'supplier_url' => 'https://supplier.example',
    'source_url' => 'https://supplier.example/terms',
    'source_permission_status' => 'authorized',
    'supplier_access_status' => 'verified',
    'fulfillment_mode' => 'manual',
];
$assert(ProductReadinessService::isSellable($productReadinessBase), 'product can sell only after source permission and supplier access verification');
$sourcePhpPath = SourceControlService::pathPolicy('telegram-bot/app/ApiController.php');
$assert($sourcePhpPath['visible'] && $sourcePhpPath['editable'], 'source editor permits normal PHP source files');
$sourceProcfile = SourceControlService::pathPolicy('Procfile');
$assert($sourceProcfile['editable'], 'source editor permits common extensionless text source files');
$sourceEnv = SourceControlService::pathPolicy('.env');
$sourceEnvExample = SourceControlService::pathPolicy('telegram-bot/.env.example');
$assert(!$sourceEnv['visible'] && !$sourceEnvExample['visible'], 'source editor never reveals environment files');
$sourcePrivateKey = SourceControlService::pathPolicy('config/server.pem');
$assert(!$sourcePrivateKey['visible'], 'source editor never reveals private key and certificate files');
$sourceWorkflow = SourceControlService::pathPolicy('.github/workflows/hendy-monorepo-ci.yml');
$assert($sourceWorkflow['visible'] && !$sourceWorkflow['editable'], 'source editor keeps CI workflows read-only');
$sourceTraversal = SourceControlService::pathPolicy('telegram-bot/app/../../.env');
$assert(!$sourceTraversal['visible'], 'source editor rejects path traversal');
$sourceMigration = SourceControlService::pathPolicy('telegram-bot/database/migrations/20261120_feature.sql');
$assert($sourceMigration['editable'] && $sourceMigration['requiresConfirmation'], 'source editor requires an explicit confirmation for SQL and migrations');
$sourceDeployConfig = SourceControlService::pathPolicy('render.yaml');
$assert($sourceDeployConfig['editable'] && $sourceDeployConfig['requiresConfirmation'], 'source editor requires explicit confirmation for deployment configuration');
$sampleTelegramToken = '123456:' . str_repeat('A', 36);
$sampleGithubToken = 'ghp_' . str_repeat('B', 32);
$samplePrivateKey = '-----BEGIN ' . 'RSA PRIVATE KEY-----';
$assert(SourceControlService::containsSecret($sampleTelegramToken) && SourceControlService::containsSecret($sampleGithubToken) && SourceControlService::containsSecret($samplePrivateKey), 'source editor rejects common Telegram/GitHub tokens and private keys');
$assert(!SourceControlService::containsSecret('HENDY_SOURCE_CONTROL_BASE_BRANCH=main'), 'source editor permits ordinary non-secret configuration');
$assert(class_exists(ServiceReadinessService::class), 'Admin service readiness report is registered without exposing provider secret values');
$serviceReadinessSource = file_get_contents(dirname(__DIR__) . '/app/ServiceReadinessService.php');
$assert(str_contains($serviceReadinessSource, "'secretValuesReturned' => false") && str_contains($serviceReadinessSource, "'providerCredentialsStoredInDatabase' => false"), 'service readiness exposes configuration status only, never secret values');
$assert(str_contains($databaseRunnerSource, 'current_database()') && str_contains($databaseRunnerSource, 'current_user') && str_contains($databaseRunnerSource, 'schema_migrations') && str_contains($databaseRunnerSource, 'hash_file(\'sha256\''), 'Database doctor and migration plan identify the connected database/role and track migration checksums');
$assert(str_contains($databaseRunnerSource, 'hash_equals($expectedDatabase') && str_contains($databaseRunnerSource, '-pooler') && str_contains($databaseRunnerSource, 'hendy_bot_app') && str_contains($databaseRunnerSource, 'pg_advisory_lock'), 'Migration runner refuses the wrong database, pooled Neon connections and the restricted runtime role, with a concurrency lock');
$assert(str_contains($databaseRunnerSource, 'beginTransaction()') && str_contains($databaseRunnerSource, 'rollBack()') && str_contains($databaseRunnerSource, 'Checksum migration không khớp'), 'Each additive migration is transactional and checksum drift is rejected');
$assert(str_contains($migrationCliSource, '$apply = in_array(\'--apply\', $argv, true)') && str_contains($migrationCliSource, '--expect-database=') && str_contains($migrationCliSource, 'MIGRATION_DATABASE_URL') && str_contains($migrationCliSource, 'DRY RUN'), 'Migration CLI defaults to a read-only dry-run and requires an explicit expected database before apply');
$assert(str_contains($databaseDoctorSource, 'Database::migrationPlan()') && str_contains($databaseDoctorSource, 'READ ONLY') && !str_contains($databaseDoctorSource, 'Database::migrate('), 'Database doctor is read-only and never applies schema changes');
$assert(str_contains($startupScriptSource, 'RUN_MIGRATIONS_ON_START:-false') && str_contains($startupScriptSource, 'local|development|test') && str_contains($startupScriptSource, 'Chỉ cho phép tự động migration trong môi trường local/development/test'), 'Render production/staging startup refuses automatic DDL; only local, development and test may opt in');
$assert(str_contains($startupScriptSource, 'if php bin/multi-agent-preflight.php; then') && str_contains($startupScriptSource, 'Bot/API vẫn tiếp tục'), 'a disabled or misconfigured optional Multi-Agent worker cannot prevent the main Bot/API service from starting');
$assert(str_contains($startupScriptSource, 'MIGRATION_EXPECTED_DATABASE') && str_contains($startupScriptSource, '--expect-database=') && str_contains($startupScriptSource, 'MIGRATION_DATABASE_URL'), 'Non-production startup migration requires a confirmed DB name and a dedicated migration URL');
$assert(!str_contains($configValidatorSource, "TelegramAuth::token('secondary')") && str_contains($configValidatorSource, 'một bot chính (BOT1_TOKEN)') && str_contains($configValidatorSource, 'BOT2 cũ'), 'Configuration validator matches the one-bot deployment and warns about unused legacy BOT2 settings');
$assert(str_contains($providerRegistrySource, "'schemaReady' => false") && str_contains($providerRegistrySource, 'requireSchemaReady') && str_contains($providerRegistrySource, 'Thiếu bảng:'), 'Provider Registry returns an actionable schema-not-ready state and guards writes until migration');
$assert(str_contains($providerRegistryUiSource, 'Database chưa sẵn sàng cho Provider Registry') && str_contains($providerRegistryUiSource, 'missingTables'), 'Admin dashboard explains which Provider Registry tables are missing');
$assert(str_contains($providerRegistrySource, 'catch (\\Throwable $error)') && str_contains($providerRegistrySource, 'provider-registry-access-control'), 'Missing registry tables cannot crash normal member API access');
$multiAgentSource=file_get_contents(dirname(__DIR__).'/app/MultiAgentService.php');
$assert(MultiAgentService::workflowForTaskType('content')===['research','content'],'content work is handed from Research to Content');
$assert(MultiAgentService::workflowForTaskType('video')===['research','content','video'],'video workflow flows through specialist agents');
$assert(MultiAgentService::workflowForTaskType('support')===['support'],'support task uses the support specialist');
$assert(MultiAgentService::workflowForTaskType('custom','my_agent')===['my_agent'],'custom task can select a specialist');
$assert(MultiAgentService::isValidAgentKey('content_agent')&&!MultiAgentService::isValidAgentKey('../admin'),'Agent keys reject unsafe identifiers');
$assert(MultiAgentService::validModelName('gpt-6.2-astra')&&!MultiAgentService::validModelName('model name with spaces'),'model override accepts only safe model IDs');
$invalidWorkflow=false;try{MultiAgentService::workflowForTaskType('unknown');}catch(RuntimeException){$invalidWorkflow=true;}
$assert($invalidWorkflow,'unknown Multi-Agent workflow is rejected');
$assert(str_contains($multiAgentSource,"'tool_choice'=>'none'")&&str_contains($multiAgentSource,"'store'=>false"),'Multi-Agent request disables tool execution and response storage');
$assert(str_contains($multiAgentSource,"status='awaiting_approval'")&&str_contains($multiAgentSource,'task.approved')&&str_contains($multiAgentSource,'task.rejected'),'Multi-Agent output requires human approval');
$assert(str_contains($multiAgentSource,'FOR UPDATE OF t SKIP LOCKED')&&str_contains($multiAgentSource,'recoverStaleTasks'),'worker uses collision-safe queue claims and stale-task recovery');
$migrationFiles = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
$assert(count($migrationFiles) >= 9, 'All additive SQL migrations are included in the migration directory');
foreach ($migrationFiles as $migrationFile) {
    $migrationSql = (string) file_get_contents($migrationFile);
    $migrationName = basename($migrationFile);
    $destructive = preg_match('/^\\s*(?:DROP\\s+(?:TABLE|COLUMN|SCHEMA|INDEX)|TRUNCATE\\b|DELETE\\s+FROM)\\b/im', $migrationSql) === 1;
    $assert(!$destructive, 'Migration does not delete existing customer/business data: ' . $migrationName);
    $nonRepeatableCreate = preg_match('/^\\s*CREATE\\s+(?:UNIQUE\\s+)?(?:TABLE|INDEX)\\s+(?!IF\\s+NOT\\s+EXISTS)\\S+/im', $migrationSql) === 1;
    $nonRepeatableColumn = preg_match('/^\\s*ADD\\s+COLUMN\\s+(?!IF\\s+NOT\\s+EXISTS)\\S+/im', $migrationSql) === 1;
    $assert(!$nonRepeatableCreate && !$nonRepeatableColumn, 'Migration uses repeatable table/index/column DDL: ' . $migrationName);
}


$assert(!ProductReadinessService::isSellable([...$productReadinessBase, 'supplier_access_status' => 'pending']), 'unverified supplier keeps product locked');
$assert(!ProductReadinessService::isSellable([...$productReadinessBase, 'source_permission_status' => 'pending']), 'unverified source permission keeps product locked');
$assert(!ProductReadinessService::isSellable([...$productReadinessBase, 'fulfillment_mode' => 'api']), 'generic API fulfillment stays locked without a provider adapter');
$assert(ProductReadinessService::validateEnvKey('OTP_PROVIDER_API_KEY') === 'OTP_PROVIDER_API_KEY', 'credential field accepts an Environment variable name only');
$invalidEnvRejected = false;
try { ProductReadinessService::validateEnvKey('real-secret-value'); } catch (RuntimeException) { $invalidEnvRejected = true; }
$assert($invalidEnvRejected, 'credential field rejects an actual secret value');

$assert(count(ProviderRegistryService::serviceKeys()) >= 20, 'Provider Registry chứa danh mục quản lý cho toàn bộ nhóm dịch vụ HENDY');
$assert(in_array('custom_api_pending', ProviderRegistryService::adapterKeys(), true), 'API tùy chỉnh không có adapter được giữ ở trạng thái chưa hỗ trợ');
$validProvider = ProviderRegistryService::validateProviderInput([
    'serviceKey' => 'otp',
    'providerKey' => 'example-otp',
    'name' => 'Nhà cung cấp OTP thử nghiệm',
    'adapterKey' => 'custom_api_pending',
    'endpointUrl' => 'https://provider.example/api',
    'credentialEnvKey' => 'OTP_PROVIDER_API_KEY',
    'enabled' => true,
    'priority' => 25,
]);
$assert($validProvider['credential_env_key'] === 'OTP_PROVIDER_API_KEY' && $validProvider['enabled'] && $validProvider['priority'] === 25, 'Provider Registry chỉ ghi tên Environment và xác thực input hợp lệ');
$builtInProvider = ProviderRegistryService::validateProviderInput([
    'serviceKey' => 'proxy', 'providerKey' => 'homeproxy-main', 'name' => 'HomeProxy tích hợp',
    'adapterKey' => 'homeproxy', 'credentialEnvKey' => 'HOMEPROXY_ENCRYPTION_KEY',
]);
$assert($builtInProvider['endpoint_url'] === '' && $builtInProvider['priority'] === 100, 'Adapter tích hợp sẵn có thể dùng endpoint được quản lý qua Environment hoặc cấu hình nội bộ');
$assert($builtInProvider['credential_env_key'] === 'HOMEPROXY_ENCRYPTION_KEY', 'Adapter HomeProxy tự ràng buộc đúng tên biến khóa mã hóa máy chủ');
$invalidAdapterPairRejected = false;
try {
    ProviderRegistryService::validateProviderInput([
        'serviceKey' => 'otp', 'providerKey' => 'wrong-adapter', 'name' => 'OTP dùng nhầm adapter',
        'adapterKey' => 'homeproxy', 'credentialEnvKey' => 'HOMEPROXY_ENCRYPTION_KEY',
    ]);
} catch (RuntimeException) { $invalidAdapterPairRejected = true; }
$assert($invalidAdapterPairRejected, 'Provider Registry không cho ghép adapter HomeProxy vào dịch vụ OTP');
$invalidPriorityRejected = false;
try {
    ProviderRegistryService::validateProviderInput([
        'serviceKey' => 'otp', 'providerKey' => 'priority-provider', 'name' => 'Provider sai ưu tiên',
        'adapterKey' => 'manual', 'priority' => 10001,
    ]);
} catch (RuntimeException) { $invalidPriorityRejected = true; }
$assert($invalidPriorityRejected, 'Provider Registry giới hạn độ ưu tiên trong khoảng 0–10.000');
$providerSource = file_get_contents(dirname(__DIR__) . '/app/ProviderRegistryService.php');
$assert(str_contains($providerSource, 'AI_COVER_WORKER_URL') && str_contains($providerSource, 'VIDEO_PROCESSOR_URL') && str_contains($providerSource, 'validHttpsEndpoint'), 'Worker endpoint được kiểm tra qua HTTPS URL và Environment theo adapter');
$invalidProviderUrlRejected = false;
try {
    ProviderRegistryService::validateProviderInput([
        'serviceKey' => 'otp', 'providerKey' => 'bad-provider', 'name' => 'Provider không an toàn',
        'adapterKey' => 'custom_api_pending', 'endpointUrl' => 'http://127.0.0.1/admin',
    ]);
} catch (RuntimeException) { $invalidProviderUrlRejected = true; }
$assert($invalidProviderUrlRejected, 'Provider Registry từ chối endpoint HTTP và IP nội bộ');
$providerQueryRejected = false;
try {
    ProviderRegistryService::validateProviderInput([
        'serviceKey' => 'otp', 'providerKey' => 'query-provider', 'name' => 'Provider chứa query',
        'adapterKey' => 'custom_api_pending', 'endpointUrl' => 'https://provider.example/api?api_key=do-not-store',
    ]);
} catch (RuntimeException) { $providerQueryRejected = true; }
$assert($providerQueryRejected, 'Provider Registry từ chối query string để tránh lưu token trong URL');
$invalidProviderSecretRejected = false;
try {
    ProviderRegistryService::validateProviderInput([
        'serviceKey' => 'otp', 'providerKey' => 'secret-provider', 'name' => 'Provider secret',
        'adapterKey' => 'manual', 'credentialEnvKey' => 'actual-secret-value',
    ]);
} catch (RuntimeException) { $invalidProviderSecretRejected = true; }
$assert($invalidProviderSecretRejected, 'Provider Registry không chấp nhận giá trị secret thay cho tên biến Environment');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/app/ProviderRegistryService.php'), "'configurationCheckIsLiveApiTest' => false"), 'Kiểm tra cấu hình không giả mạo kết quả kết nối API thật');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/app/ApiController.php'), 'ProviderRegistryService::blockMessageForPath'), 'Cờ tạm khóa dịch vụ được áp dụng lên các API path đã đăng ký');
$assert(str_contains($schemaSource, 'CREATE TABLE IF NOT EXISTS service_registry') && str_contains($schemaSource, 'CREATE TABLE IF NOT EXISTS service_providers'), 'Fresh schema tạo bảng quản lý dịch vụ và nhà cung cấp');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/database/migrations/20261113_provider_service_registry.sql'), 'GRANT SELECT, INSERT, UPDATE, DELETE ON service_registry, service_providers TO hendy_bot_app'), 'Migration cấp đúng quyền DML cho runtime role');
$assert(TelegramAuth::safeEquals('same-secret', 'same-secret'), 'so sánh chuỗi đúng');
$assert(!TelegramAuth::safeEquals('different', 'same-secret'), 'so sánh chuỗi khác');
$assert(!TelegramAuth::safeEquals('', ''), 'secret rỗng bị từ chối');
$telegramAuthSource = file_get_contents(dirname(__DIR__) . '/app/TelegramAuth.php');
$dockerfileSource = file_get_contents(dirname(__DIR__) . '/Dockerfile');
$assert(str_contains($dockerfileSource, "printf '%s\\n'") && str_contains($dockerfileSource, "'upload_max_filesize=205M'") && str_contains($dockerfileSource, "'post_max_size=260M'"), 'Docker writes PHP upload limits as separate real INI lines, not literal backslash-n text');
$assert(str_contains($telegramAuthSource, 'ON CONFLICT (bot_key) DO UPDATE SET') && str_contains($telegramAuthSource, 'RETURNING secret_value'), 'webhook/session secrets persist using atomic PostgreSQL upserts');
$assert(!str_contains($telegramAuthSource, 'pg_advisory_xact_lock'), 'webhook/session secret startup does not rely on multi-statement advisory-lock transactions');
$assert(str_contains($telegramAuthSource, 'char_length(telegram_webhook_secrets.secret_value) BETWEEN 32 AND 256') && str_contains($telegramAuthSource, 'secret_value !~'), 'PostgreSQL-safe secret validation combines character allowlist and length bounds');
$assert(str_contains($schemaSource, 'CREATE TABLE IF NOT EXISTS website_session_secrets'), 'database schema creates the website session secret table used by authentication');
$aiCoverSource = file_get_contents(dirname(__DIR__) . '/app/AiCoverService.php');
$assert(str_contains($aiCoverSource, 'public static function runtimeEnabled()') && str_contains($aiCoverSource, 'public static function setServiceEnabled') && str_contains($aiCoverSource, 'hasReadyVoice()') && str_contains($aiCoverSource, 'voiceReady'), 'AI Cover member access requires a recent licensed preset and an Admin runtime toggle');
$assert(TelegramLink::normalize('https://t.me/HendySupportGroup') === 'https://t.me/HendySupportGroup', 'chấp nhận liên kết Telegram HTTPS hợp lệ');
$assert(TelegramLink::normalize('https://t.me/+Invite_Code-123') === 'https://t.me/+Invite_Code-123', 'chấp nhận liên kết mời Telegram');
$assert(TelegramLink::normalize('http://t.me/HendySupportGroup') === '', 'từ chối liên kết Telegram không HTTPS');
$assert(TelegramLink::normalize('https://t.me.evil.example/HendySupportGroup') === '', 'từ chối domain Telegram giả mạo');
$assert(TelegramLink::normalize('https://user@t.me/HendySupportGroup') === '', 'từ chối URL Telegram có userinfo');
$assert(TelegramLink::normalize('https://t.me/hendy_cyber_bot?start=old') === 'https://t.me/hendy_cyber_bot', 'chuẩn hóa public URL bot và loại query có sẵn');
$assert(TelegramLink::normalize('https://example.com/hendy_cyber_bot') === '', 'từ chối URL public bot ngoài Telegram');

$assert(array_column(BotMenuDefinition::ITEMS, 'action') === ['live','tools','codes','otp','proxy','vip','customer','vietsub','gift','ref','ai-features'], 'menu chính giữ các dịch vụ được chọn và thêm Trung tâm khách hàng HENDY');
$assert(BotMenuDefinition::LAYOUT === [['live','tools'],['codes'],['otp','proxy'],['vip'],['customer'],['vietsub','gift'],['ref','ai-features']], 'layout menu chính so le 2-1 và đặt Trung tâm khách hàng HENDY riêng một hàng');
$assert(!array_intersect(['matchmaking','orders','history','home'], array_column(BotMenuDefinition::ITEMS, 'action')), 'bốn nút Ghép đôi, Đơn hàng, Lịch sử và Menu chính không xuất hiện trên menu gốc');
$assert(in_array('customer', array_column(BotMenuDefinition::ITEMS, 'action'), true), 'Trung tâm khách hàng HENDY được đăng ký trên menu gốc');
$styles = array_column(BotMenuDefinition::ITEMS, 'style');
$assert(count($styles) === count(BotMenuDefinition::ITEMS) && count(array_diff($styles, ['primary','success','danger'])) === 0, 'mọi nút menu chính đều có màu Telegram hợp lệ');
$botServiceSource = file_get_contents(dirname(__DIR__) . '/app/BotService.php');
$assert(str_contains($botServiceSource, '$widths = [2, 1]') && str_contains($botServiceSource, '$button[\'style\'] = $style;') && str_contains($botServiceSource, "member:home"), 'mọi menu con được sắp so le và tự gán màu từng nút');
$startupSource = file_get_contents(dirname(__DIR__) . '/docker/start.sh');
$webhookRegistrationSource = file_get_contents(dirname(__DIR__) . '/bin/register-webhook.php');
$assert(str_contains($startupSource, 'php bin/register-webhook.php') && str_contains($webhookRegistrationSource, '$telegram->setWebhook($webhookUrl, $secret)') && str_contains($webhookRegistrationSource, 'getWebhookInfo'), 'Render startup đăng ký và xác minh webhook Telegram chính');
$assert(str_contains($startupSource, 'VIDEO_WORKER_ENABLED') && str_contains($startupSource, 'php bin/video-worker.php') && str_contains($startupSource, 'VIDEO_PROCESSOR_SECRET'), 'Vietsub worker starts only when its HTTPS provider URL and secret are configured');

$assert(str_contains($botServiceSource, "'menu_mode' => 'inline_keyboard'"), 'menu mặc định là Inline Keyboard');
$assert(str_contains($botServiceSource, "return ['inline_keyboard' => \$rows];"), 'menu luôn gửi Inline Keyboard, kể cả cấu hình cũ');
$assert(str_contains($botServiceSource, "if (\$item['action'] === 'miniapp')") && str_contains($botServiceSource, "'web_app' => ['url' => \$url]"), 'nút Mở Mini App dùng Telegram Web App trực tiếp');
$assert(str_contains($botServiceSource, '$this->telegram->answerCallback($callbackId);') && !str_contains($botServiceSource, 'Đã nhận, đang xử lý…'), 'callback được xác nhận ngầm, không hiện thông báo kỹ thuật');
$assert(str_contains($botServiceSource, '👋 Chào mừng bạn đến với HENDY BOT!') && !str_contains($botServiceSource, '♻️ Menu HENDY đã chuyển sang Inline Menu mới.'), 'gỡ Reply Keyboard bằng lời chào tự nhiên thay cho thông báo kỹ thuật');
$assert(str_contains($botServiceSource, 'https://i.postimg.cc/HY1LRby9/hendy-home-banner.jpg?dl=1') && str_contains($botServiceSource, '$this->telegram->sendPhotoFromUrl'), 'banner Telegram được tải ở backend và upload multipart thay vì Telegram tự fetch URL');
$telegramApiSource = file_get_contents(dirname(__DIR__) . '/app/TelegramApi.php');
$codeTelegramSource = file_get_contents(dirname(__DIR__) . '/app/CodeTelegramService.php');
$codeTelegramSchema = file_get_contents(dirname(__DIR__) . '/database/migrations/20261112_code_telegram.sql');
$assert(str_contains($telegramApiSource, "'channel_post', 'edited_channel_post'") && str_contains($telegramApiSource, 'downloadVideoToTemp'), 'webhook subscribes to channel posts and supports secure video download');
$assert(str_contains($codeTelegramSource, 'WHERE chat_id = ? AND is_active = TRUE') && str_contains($codeTelegramSource, "'pending_review'") && str_contains($codeTelegramSource, 'tesseract'), 'Code Telegram is allow-listed, OCR candidates require moderation by default');
$assert(str_contains($codeTelegramSchema, 'CREATE TABLE IF NOT EXISTS code_telegram_sources') && str_contains($codeTelegramSchema, 'CREATE TABLE IF NOT EXISTS code_telegram_posts') && str_contains($codeTelegramSchema, 'CREATE TABLE IF NOT EXISTS code_telegram_codes'), 'Code Telegram uses additive PostgreSQL tables');
$parsedCodeTelegram = CodeTelegramService::extractCandidates("Mã khuyến mãi: HENDY2026\nCode Tele: TELE2026\nPromo code: FREE999\nHENDY2026");
$assert(in_array('HENDY2026', $parsedCodeTelegram, true) && in_array('TELE2026', $parsedCodeTelegram, true) && in_array('FREE999', $parsedCodeTelegram, true), 'Code Telegram extracts Vietnamese promo, Telegram and English promo codes');
$assert(count($parsedCodeTelegram) === count(array_unique($parsedCodeTelegram)), 'Code Telegram parser removes duplicate candidates from the same message');
$assert(str_contains($botServiceSource, 'code-telegram:recent') && str_contains($botServiceSource, 'case \'code_telegram_search\':' ) && str_contains($botServiceSource, 'CodeTelegramService::ingestChannelPost'), 'Bot menu supports Code Telegram browse, search and channel ingestion');
$assert(str_contains($botServiceSource, "case 'ai-cover':") && str_contains($botServiceSource, 'showAiCoverMenu($chatId, $userId)'), 'AI Cover submenu button dispatches to the licensed preset flow');
$assert(str_contains($botServiceSource, 'showMatchmakingMenu') && str_contains($botServiceSource, 'matchmaking-join-ranked') && str_contains($botServiceSource, 'matchmaking_result'), 'Telegram menu connects matchmaking queue, accept/decline, score confirmation and ranking');
$assert(str_contains($botServiceSource, 'showUpcomingServices') && str_contains($botServiceSource, 'member:matchmaking') && str_contains($botServiceSource, 'member:vietsub') && str_contains($botServiceSource, 'member:history'), 'upcoming services hub exposes functional actions instead of a dead-end notice');
$assert(str_contains($botServiceSource, 'ProductReadinessService::sqlPredicate()'), 'Telegram product list and order paths require supplier/source readiness');
$assert(str_contains($botServiceSource, 'private function showMinigameProduct') && str_contains($botServiceSource, 'live-buy:') && str_contains($botServiceSource, 'MINIGAME LIVE'), 'MiniGame service list opens a payment confirmation flow');
$assert(str_contains($botServiceSource, 'FOR UPDATE') && str_contains($botServiceSource, "category = 'minigame'") && str_contains($botServiceSource, 'Ví Chính'), 'MiniGame checkout validates and locks a catalog product before paying from the Main wallet');

$codeTelegramServicesSchema = file_get_contents(dirname(__DIR__) . '/database/migrations/20261112_code_telegram_services.sql');
$apiControllerSource = file_get_contents(dirname(__DIR__) . '/app/ApiController.php');
$adminDashboardSource = file_get_contents(dirname(__DIR__, 2) . '/website/telegram/admin-dashboard/index.html');
$customerCenterSource = file_get_contents(dirname(__DIR__, 2) . '/website/public/miniapp/app.js');
$publicWebsiteSource = file_get_contents(dirname(__DIR__, 2) . '/website/public/index.html');
$mainPublicRouterSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$telegramAuthSource = file_get_contents(dirname(__DIR__) . '/app/TelegramAuth.php');
$websiteSessionMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/20261113_website_session_secrets.sql');
$userAccessMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/20261113_user_access_controls.sql');
$productReadinessMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/20261113_product_supplier_readiness.sql');
$productReadinessSource = file_get_contents(dirname(__DIR__) . '/app/ProductReadinessService.php');
$assert(str_contains($productReadinessMigration, "DEFAULT 'pending'") && str_contains($productReadinessMigration, 'credential_env_key') && str_contains($productReadinessMigration, 'No wallet'), 'supplier readiness migration is additive and defaults all catalog items to locked');
$assert(str_contains($productReadinessSource, 'source_permission_status') && str_contains($productReadinessSource, 'supplier_access_status') && str_contains($productReadinessSource, "COALESCE(") && str_contains($productReadinessSource, "to_jsonb(") && str_contains($productReadinessSource, "'manual'"), 'single product readiness policy verifies supplier, rights and manual fulfillment while tolerating unapplied schema');
$assert(str_contains($productReadinessSource, 'function schemaStatus()') && str_contains($productReadinessSource, 'missingColumns') && str_contains($productReadinessSource, 'checkFailed'), 'Supplier Readiness exposes fail-closed, read-only schema diagnostics');
$assert(str_contains($databaseRunnerSource, 'function connectionInfo()') && str_contains($databaseRunnerSource, 'SELECT current_database()') && str_contains($databaseRunnerSource, 'SELECT current_user'), 'Admin database identity uses only the runtime connection identity, without requiring migration-ledger owner privileges');
$assert(str_contains($apiControllerSource, 'productProviderInput') && str_contains($apiControllerSource, 'ProductReadinessService::sqlPredicate()') && str_contains($apiControllerSource, 'adminProduct($product)'), 'Admin product CRUD records supplier evidence and checkout is gated');
$assert(str_contains($apiControllerSource, '/api/admin/database/readiness') && str_contains($apiControllerSource, 'Database::connectionInfo()') && str_contains($apiControllerSource, 'ProductReadinessService::schemaStatus()') && str_contains($apiControllerSource, 'ProviderRegistryService::schemaStatus()'), 'Admin database readiness endpoint reports the actual runtime target and both feature schemas without applying migrations');
$sourceControlServiceSource = file_get_contents(dirname(__DIR__) . '/app/SourceControlService.php');
$assert(str_contains($apiControllerSource, '/api/admin/source-control/status') && str_contains($apiControllerSource, '/api/admin/source-control/tree') && str_contains($apiControllerSource, '/api/admin/source-control/file') && str_contains($apiControllerSource, '/api/admin/source-control/commit'), 'Admin source editor uses protected backend routes for GitHub repository reads and direct main commit');
$assert(str_contains($sourceControlServiceSource, 'Authorization: Bearer ') && str_contains($sourceControlServiceSource, 'HENDY_GITHUB_TOKEN') && str_contains($sourceControlServiceSource, "'force' => false"), 'GitHub token stays on the server and source edits are submitted as direct main commits');
$assert(str_contains($sourceControlServiceSource, 'expectedBaseSha') && str_contains($sourceControlServiceSource, 'expectedFileSha') && str_contains($sourceControlServiceSource, 'MAX_BATCH_BYTES'), 'source editor detects stale branches/files and limits payload size');
$assert(str_contains($sourceControlServiceSource, '-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----') && str_contains($sourceControlServiceSource, '.github/workflows/'), 'source editor applies secret scanning and protects CI workflow files');
$assert(str_contains($adminDashboardSource, 'data-admin-tab="source-control"') && str_contains($adminDashboardSource, 'function adminSourceControl()') && str_contains($adminDashboardSource, 'source-commit-form'), 'Admin dashboard provides a source tree, editor, staging list and Draft PR form');
$assert(str_contains($apiControllerSource, '/api/admin/service-readiness') && str_contains($apiControllerSource, 'ServiceReadinessService::snapshot'), 'Admin has a protected endpoint for service and supplier readiness');
$assert(str_contains($adminDashboardSource, 'data-admin-tab="service-readiness"') && str_contains($adminDashboardSource, 'adminServiceReadiness()') && str_contains($adminDashboardSource, '/api/admin/service-readiness'), 'Admin Dashboard exposes provider readiness and missing configuration names');
$assert(str_contains($adminDashboardSource, 'data-admin-tab="provider-registry"') && str_contains($adminDashboardSource, 'adminProviderRegistry()') && str_contains($adminDashboardSource, '/api/admin/provider-registry'), 'Existing Admin Dashboard includes the Provider & Service Registry tab');
$assert(str_contains($adminDashboardSource, '/api/admin/database/readiness') && str_contains($adminDashboardSource, 'data-database-readiness-refresh') && str_contains($adminDashboardSource, 'MIGRATION_DATABASE_URL') && str_contains($adminDashboardSource, 'Chờ đồng bộ database'), 'Product Admin displays live database/schema status, gives safe migration steps and disables writes until the schema is ready');
$assert(str_contains($adminDashboardSource, 'data-provider-edit') && str_contains($adminDashboardSource, 'data-provider-check') && str_contains($adminDashboardSource, 'data-provider-delete') && str_contains($adminDashboardSource, 'data-registry-service-form'), 'Provider Registry UI supports edit, configuration check, delete and service maintenance controls');
$assert(str_contains($apiControllerSource, '/api/admin/provider-registry/providers') && str_contains($apiControllerSource, '/api/admin/provider-registry/services/') && str_contains($apiControllerSource, 'ProviderRegistryService::saveProvider'), 'Admin API exposes protected provider CRUD and service-state endpoints');



$assert(str_contains($userAccessMigration, 'ADD COLUMN IF NOT EXISTS is_blocked') && str_contains($userAccessMigration, 'ADD COLUMN IF NOT EXISTS blocked_reason') && str_contains($schemaSource, 'blocked_by TEXT'), 'member access controls have an additive database migration and complete schema');
$assert(str_contains($apiControllerSource, 'blocked_reason') && str_contains($apiControllerSource, 'user.access') && str_contains($apiControllerSource, 'DELETE FROM legacy_login_tickets WHERE user_id = ?'), 'blocking a member revokes pending login tickets and records an Admin audit event without deleting wallet data');
$assert(str_contains($adminDashboardSource, 'data-user-access-form') && str_contains($adminDashboardSource, '/access') && str_contains($adminDashboardSource, 'Khóa quyền sử dụng Bot và Website'), 'Admin Dashboard can block and restore a member account with a required reason');
$assert(str_contains($botServiceSource, '$configuredLabel = $action === \'codes\'') && str_contains($botServiceSource, 'private function showCodeTelegramService') && str_contains($botServiceSource, "case 'code_telegram_accounts':"), 'CODE TELEGRAM label cannot be overridden by stale Admin config and opens the username intake flow');
$assert(str_contains($botServiceSource, 'code_telegram_services') && str_contains($botServiceSource, 'max_accounts') && str_contains($botServiceSource, 'Chỉ tính phí khi xử lý thành công.'), 'CODE TELEGRAM menu prices and account limits come from PostgreSQL services');
$assert(str_contains($codeTelegramServicesSchema, 'CREATE TABLE IF NOT EXISTS code_telegram_services') && str_contains($codeTelegramServicesSchema, 'CREATE TABLE IF NOT EXISTS code_telegram_requests') && str_contains($codeTelegramServicesSchema, 'CREATE TABLE IF NOT EXISTS code_telegram_request_accounts'), 'Code Telegram catalog and username request queue use additive PostgreSQL tables');
$assert(str_contains($apiControllerSource, '/api/admin/code-telegram/accounts/') && str_contains($apiControllerSource, 'code-telegram:account:') && str_contains($apiControllerSource, 'status = \'completed\''), 'Admin charges a Code Telegram username only after a success status is confirmed');
$assert(str_contains($adminDashboardSource, 'Yêu cầu tài khoản Code Telegram') && str_contains($adminDashboardSource, 'data-code-account-success'), 'Admin Dashboard displays and processes Code Telegram account requests');
$assert(str_contains($apiControllerSource, '/api/code-telegram/portal') && str_contains($apiControllerSource, '/api/code-telegram/requests') && str_contains($apiControllerSource, 'pg_advisory_xact_lock'), 'Website Code Telegram API validates and serializes member order creation');
$assert(str_contains($apiControllerSource, 'username_normalized') && str_contains($apiControllerSource, "'charged' => false") && str_contains($apiControllerSource, 'Chưa trừ tiền'), 'Code Telegram web orders prevent same-day duplicate usernames and do not charge on submission');
$assert(str_contains($customerCenterSource, 'Tên TK CODE TELEGRAM') && str_contains($customerCenterSource, 'code-telegram-order-form') && str_contains($customerCenterSource, '/api/code-telegram/requests') && str_contains($customerCenterSource, 'ĐƠN CODE TELEGRAM CỦA BẠN'), 'Customer Center can create and track Code Telegram orders per username');
$assert(str_contains($adminDashboardSource, 'Tên TK CODE TELEGRAM') && str_contains($adminDashboardSource, 'CT'), 'Admin request cards clearly label Code Telegram account names and order IDs');
$publicSiteScript = file_get_contents(dirname(__DIR__, 2) . '/website/public/assets/site.js');
$assert(str_contains($publicWebsiteSource, 'id="server-status"') && str_contains($publicWebsiteSource, '/assets/site.js') && str_contains($publicSiteScript, "apiBase + '/api/health'") && str_contains($publicSiteScript, 'service-state online'), 'Public Website checks live management API health using the separated JavaScript asset');
$assert(str_contains($mainPublicRouterSource, "'database' => " . '$databaseReady' . " ? 'ok' : 'unavailable'") && str_contains($mainPublicRouterSource, '$databaseReady ? 200 : 503'), 'Health endpoint reports PostgreSQL failure rather than returning a false healthy status');
$assert(str_contains($mainPublicRouterSource, "Database::check()") && str_contains($mainPublicRouterSource, "'database' => \$databaseReady ? 'ok' : 'unavailable'") && str_contains($mainPublicRouterSource, "\$databaseReady ? 200 : 503"), 'Public API health verifies PostgreSQL instead of returning an unconditional healthy response');
$assert(str_contains($mainPublicRouterSource, 'secondary_bot_retired') && str_contains($mainPublicRouterSource, 'if ($method === \'POST\' && $path === \'/webhook/telegram/secondary\')'), 'Retired secondary webhook is acknowledged without executing a secondary bot');
$assert(str_contains($adminDashboardSource, 'new AbortController()') && str_contains($adminDashboardSource, 'timeoutMs = 20000') && str_contains($adminDashboardSource, 'admin-retry-start') && str_contains($adminDashboardSource, 'showStartupFailure(error)'), 'Admin startup has bounded API timeouts and visible recovery actions');
$assert(str_contains($websiteSessionMigration, 'CREATE TABLE IF NOT EXISTS website_session_secrets') && str_contains($websiteSessionMigration, 'CREATE TABLE IF NOT EXISTS legacy_login_tickets') && str_contains($websiteSessionMigration, 'GRANT SELECT, INSERT, UPDATE, DELETE'), 'Website session and one-time login ticket migration provisions runtime access');
$assert(str_contains($schemaSource, 'ON website_session_secrets, legacy_login_tickets TO hendy_bot_app'), 'Fresh database schema grants runtime access to browser login session tables');
$assert(str_contains($telegramAuthSource, 'SELECT secret_value FROM website_session_secrets WHERE bot_key = ?') && str_contains($telegramAuthSource, 'if (is_string($existing)') && str_contains($telegramAuthSource, 'ON CONFLICT (bot_key) DO UPDATE'), 'Website session signing key is read without a write on every API request and is created atomically when missing');


$aiCoverSource = file_get_contents(dirname(__DIR__) . '/app/AiCoverService.php');
$aiCoverStorageSource = file_get_contents(dirname(__DIR__) . '/app/NeonStorage.php');
$aiCoverFunctionSource = file_get_contents(dirname(__DIR__, 2) . '/neon-functions/hendystorage/index.mjs');
$aiCoverPublicRouterSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$aiCoverMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/20261113_ai_cover.sql');
$aiCoverWorker = file_get_contents(dirname(__DIR__) . '/bin/ai-cover-dispatcher.php');
$aiCoverStartup = file_get_contents(dirname(__DIR__) . '/docker/start.sh');
$aiCoverEnvExample = file_get_contents(dirname(__DIR__) . '/.env.example');
$assert(str_contains($aiCoverSource, 'function dispatchOnce') && str_contains($aiCoverSource, 'function completeFromWorker') && str_contains($aiCoverSource, 'function verifyVoice'), 'AI Cover has a real worker dispatch, signed completion callback and preset validation flow');
$assert(str_contains($aiCoverSource, 'X-Hendy-AI-Cover-Signature') && str_contains($aiCoverSource, 'provider_job_id') && str_contains($aiCoverSource, 'ai-cover/results/'), 'AI Cover worker requests/callbacks are HMAC signed and output objects are scoped per job');
$assert(str_contains($aiCoverStorageSource, '/ai-cover/presign') && str_contains($aiCoverStorageSource, 'presignedUploadRequest') && str_contains($aiCoverFunctionSource, '/ai-cover/presign'), 'AI Cover audio/model storage uses the private AI Cover signing endpoint');
$assert(str_contains($schemaSource, 'provider_job_id VARCHAR(160)') && str_contains($aiCoverMigration, 'ADD COLUMN IF NOT EXISTS provider_job_id'), 'AI Cover schema tracks external inference job identifiers additively');
$assert(str_contains($adminDashboardSource, 'data-admin-tab="ai-cover"') && str_contains($adminDashboardSource, 'adminAiCover()') && str_contains($adminDashboardSource, 'admin-ai-cover-voice-form'), 'Admin Dashboard manages AI Cover licensing, models, voice verification and jobs');
$assert(str_contains($apiControllerSource, '/api/admin/ai-cover') && str_contains($aiCoverPublicRouterSource, '/complete') && str_contains($aiCoverPublicRouterSource, 'uploadAiCoverVoice'), 'AI Cover Admin APIs and multipart/callback routes are connected to the backend');
$assert(str_contains($aiCoverWorker, 'AiCoverService::dispatchOnce') && str_contains($aiCoverStartup, 'php bin/ai-cover-dispatcher.php') && str_contains($aiCoverEnvExample, 'AI_COVER_WORKER_ENABLED=false'), 'AI Cover dispatcher is optional and remains disabled until its real HTTPS worker is configured');
$assert(str_contains($telegramApiSource, 'BOT_HOME_PHOTO_ALLOWED_HOSTS') && str_contains($telegramApiSource, 'sendPhotoFile') && str_contains($telegramApiSource, 'CURLOPT_SSL_VERIFYPEER'), 'banner download validates HTTPS host, MIME and uploads bytes securely');
$aiServiceSource = file_get_contents(dirname(__DIR__) . '/app/AiMediaService.php');
$aiMediaMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/20261113_ai_media.sql');
$assert(str_contains($aiMediaMigration, 'CREATE TABLE IF NOT EXISTS ai_media_jobs') && str_contains($aiMediaMigration, 'GRANT SELECT, INSERT, UPDATE, DELETE ON ai_media_jobs TO hendy_bot_app') && str_contains($schemaSource, 'GRANT SELECT, INSERT, UPDATE, DELETE ON ai_media_jobs TO hendy_bot_app'), 'AI Studio queue has a repeatable migration and runtime database privileges');
$assert(str_contains($aiServiceSource, 'public static function readiness()') && str_contains($aiServiceSource, 'public static function setFeatures') && str_contains($aiServiceSource, 'ai_media_music_enabled') && str_contains($aiServiceSource, 'ai_media_image_enabled'), 'AI Studio has readiness checks and separate Music/Image runtime flags');
$assert(str_contains($aiServiceSource, "WHERE status='queued' AND ((job_type='music'") && str_contains($aiServiceSource, 'featureEnabled'), 'AI worker only claims jobs for features enabled by both runtime settings and provider secrets');
$assert(str_contains($aiServiceSource, 'public static function retryJob') && str_contains($aiServiceSource, 'private static function recoverStaleJobs') && str_contains($aiServiceSource, 'COALESCE(claimed_at, updated_at)') && str_contains($aiServiceSource, 'attempts >= 3'), 'AI Studio recovers worker-crash jobs and safely limits stale retries');
$assert(str_contains($apiControllerSource, '/api/admin/ai-media/jobs/') && str_contains($apiControllerSource, 'AiMediaService::retryJob') && str_contains($apiControllerSource, "ai_media.job.retry"), 'Admin API can retry a failed AI Studio job and audit the action');
$assert(str_contains($adminDashboardSource, 'data-ai-media-retry') && str_contains($adminDashboardSource, '/api/admin/ai-media/jobs/'), 'Admin Dashboard exposes a retry action for failed AI Studio jobs');
$assert(str_contains($apiControllerSource, '/api/admin/ai-media') && str_contains($apiControllerSource, '/api/admin/ai-cover/settings') && str_contains($adminDashboardSource, 'data-admin-tab="ai-media"') && str_contains($adminDashboardSource, 'adminAiMedia()'), 'Admin Dashboard can manage AI Studio and AI Cover service access');
$assert(str_contains($apiControllerSource, '$operation = OperationsService::forBot($this->botKey)') && str_contains($apiControllerSource, "strtoupper(\$method) !== 'GET'") && str_contains($apiControllerSource, "'operations' => " . '$operation'), 'maintenance mode blocks customer API mutations while reporting operational status in bootstrap');
$assert(str_contains($aiServiceSource, 'AI_MEDIA_QUEUE_ENABLED') && str_contains($aiServiceSource, 'ELEVENLABS_API_KEY') && str_contains($aiServiceSource, 'OPENAI_API_KEY'), 'AI generation uses server-side secrets and an explicit queue toggle');
$assert(str_contains($botServiceSource, "case 'ai-features':") && str_contains($botServiceSource, 'private function showAiFeaturesMenu') && str_contains($botServiceSource, "'member:music-ai'") && str_contains($botServiceSource, "'member:image-ai'") && str_contains($botServiceSource, "'member:ai-cover'"), 'menu TÍNH NĂNG AI mở đủ ba công cụ AI');
$assert(str_contains($botServiceSource, "'infrastructureReady'") && str_contains($botServiceSource, 'Worker chưa cấu hình') && str_contains($botServiceSource, 'preset được cấp phép'), 'AI submenu explains why a feature is not yet ready without exposing provider secrets');
$assert(str_contains($botServiceSource, 'private function startAiMusic') && str_contains($botServiceSource, 'private function startAiImage') && str_contains($botServiceSource, 'private function handleAiImagePhoto') && str_contains($botServiceSource, "case 'ai_music_prompt':") && str_contains($botServiceSource, "case 'ai_image_prompt':"), 'AI Music and Image prompts connect to real job queue handlers');
$assert(str_contains($botServiceSource, '/cancel(?:@') && str_contains($botServiceSource, 'aifeatures'), 'Bot supports /cancel and /ai shortcuts for pending prompts and AI submenu');
$welcomeStart = strpos($botServiceSource, '$lines = [', strpos($botServiceSource, 'private function sendHome'));
$welcomeEnd = $welcomeStart === false ? false : strpos($botServiceSource, '];', $welcomeStart);
$welcomeBlock = ($welcomeStart !== false && $welcomeEnd !== false) ? substr($botServiceSource, $welcomeStart, $welcomeEnd - $welcomeStart) : '';
$assert(str_contains($welcomeBlock, "'✌️ Chúc ' . \$greeting . ' vui vẻ, <b>'") && str_contains($welcomeBlock, 'Ví NYC:') && !str_contains($welcomeBlock, 'Ví Voucher:') && !str_contains($welcomeBlock, 'Tổng nạp:') && !str_contains($welcomeBlock, '👑 Thành viên:'), 'welcome caption uses requested greeting and only the two wallet balances');
$assert(str_contains($botServiceSource, '🚧 TÍNH NĂNG ĐANG PHÁT TRIỂN / CHƯA MỞ BÁN') && str_contains($botServiceSource, "private function sendUnavailableNotice(string \$chatId): void") && !str_contains($botServiceSource, 'Danh mục và giá được lấy từ kho sản phẩm PostgreSQL:'), 'unreleased actions and empty catalog return one status line only');
$assert(str_contains($botServiceSource, 'OperationsService::forBot') && str_contains($botServiceSource, '/status'), 'bot kiểm tra trạng thái bảo trì/nâng cấp và cho phép xem /status');
$handleMessageStart = strpos($botServiceSource, 'private function handleMessage');
$aiShortcutPosition = strpos($botServiceSource, 'aifeatures', $handleMessageStart === false ? 0 : $handleMessageStart);
$maintenanceGuardPosition = strpos($botServiceSource, 'OperationsService::forBot', $handleMessageStart === false ? 0 : $handleMessageStart);
$assert($handleMessageStart !== false && $maintenanceGuardPosition !== false && $aiShortcutPosition !== false && $maintenanceGuardPosition < $aiShortcutPosition, 'maintenance guard runs before AI/Code shortcuts for non-admin Telegram users');
$operationsSource = file_get_contents(dirname(__DIR__) . '/app/OperationsService.php');
$assert(str_contains($operationsSource, 'maintenance') && str_contains($operationsSource, 'upgrade') && str_contains($operationsSource, 'Asia/Ho_Chi_Minh'), 'dịch vụ vận hành hỗ trợ bảo trì, nâng cấp và giờ Việt Nam');
$assert(str_contains($operationsSource, '$effectiveMode = $active ? $mode : \'off\'') && str_contains($operationsSource, '$effectiveUntil = $active'), 'chế độ đã hết hạn trở lại trạng thái hoạt động và xóa lịch cũ trên giao diện');
$proxyServiceSource = file_get_contents(dirname(__DIR__) . '/app/ProxyService.php');
$assert(str_contains($proxyServiceSource, 'aes-256-gcm') && str_contains($proxyServiceSource, 'checkOnly') && str_contains($proxyServiceSource, 'rotatev2'), 'HomeProxy dùng AES-256-GCM, checkOnly và API xoay IP');
$customerUiSource = file_get_contents(dirname(__DIR__, 2) . '/website/public/miniapp/index.html');
$customerUiScript = file_get_contents(dirname(__DIR__, 2) . '/website/public/miniapp/app.js');
$customerUiCss = file_get_contents(dirname(__DIR__, 2) . '/website/public/miniapp/style.css');
$assert(str_contains($customerUiScript, 'cc-dashboard') && str_contains($customerUiScript, 'cc-order-modal'), 'Mini App renders the authenticated customer dashboard and order details');
$assert(str_contains($customerUiScript, 'state.boot.operations') && str_contains($customerUiScript, 'HENDY ĐANG BẢO TRÌ') && str_contains($customerUiScript, 'operations.message'), 'Customer Center shows maintenance status and expected end time');
$assert(str_contains($customerUiSource, 'name="hendy-build" content="2026.11.26.1"') && str_contains($customerUiScript, 'const MINI_APP_ROUTE'), 'Mini App keeps hidden release metadata and route detection');
$assert(!str_contains($customerUiSource, 'build-label') && !str_contains($customerUiSource, 'BUILD 2026.11.07.3'), 'không hiển thị nhãn build trên giao diện thành viên');
$assert(str_contains($customerUiScript, "if(MINI_APP_ROUTE)") && str_contains($customerUiScript, "MINI_APP_ROUTE?'':'<div class=\"cc-page-heading\""), 'Mini App mode hides Customer Center heading and browser login shell');
$assert(str_contains($customerUiScript, "const accountAndStats=MINI_APP_ROUTE?'':'<section class=\"cc-summary\"") && str_contains($customerUiScript, "linkedAccountsRequest=MINI_APP_ROUTE?Promise.resolve"), 'Mini App hides linked-game account management and avoids that API request');
$assert(str_contains($customerUiScript, "api('/api/customer-center')") && str_contains($customerUiScript, "api('/api/wallet/payment-requests')") && str_contains($customerUiScript, "api('/api/vouchers')"), 'Customer Center loads profile, wallet and voucher data from live APIs');
$assert(str_contains($customerUiScript, 'fetch(apiBase + path') && str_contains($customerUiScript, "'tma '+tg.initData"), 'Mini App uses same-origin or legacy API routing and keeps Telegram initData authentication');
$assert(str_contains($customerUiCss, '.cc-profile') && str_contains($customerUiCss, '@media(max-width:760px)') && str_contains($customerUiCss, 'prefers-reduced-motion'), 'Mini App has refreshed responsive CSS and reduced-motion support');
$assert(!preg_match('/<input[^>]+type=["\']password/i', $customerUiSource . $customerUiScript) && !str_contains($customerUiScript, 'name="abuse_account_name"'), 'Customer Center does not collect game passwords');
$assert(!str_contains($customerUiScript, '8639286417') && !str_contains($customerUiScript, 'Hendyngok') && !str_contains($customerUiScript, 'AVATAR/5.gif'), 'Customer Center does not hardcode personal Telegram information');
$frontControllerSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$pollingSource = file_get_contents(dirname(__DIR__) . '/bin/polling.php');
$assert(str_contains($frontControllerSource, "'/app/OperationsService.php'") && str_contains($frontControllerSource, "'/app/ProxyService.php'"), 'HTTP front controller explicitly loads operations and proxy services');
$assert(str_contains($pollingSource, "'/app/OperationsService.php'"), 'polling entrypoint explicitly loads maintenance service');
$adminUiSource = file_get_contents(dirname(__DIR__, 2) . '/website/telegram/admin-dashboard/index.html');
$assert(!str_contains($adminUiSource, 'telegram-web-app.js'), 'Admin không tải Telegram Mini App SDK');
$assert(str_contains($adminUiSource, 'value="inline_keyboard"'), 'trình quản lý menu chỉ lưu Inline Keyboard');
$assert(str_contains($adminUiSource, 'name="hendy-build" content="2026.11.26.1"') && str_contains($adminUiSource, '--primary:#ff315d'), 'Admin có release marker và giao diện HENDY đỏ đen');
$assert(str_contains($adminUiSource, 'data-admin-tab="operations"') && str_contains($adminUiSource, 'adminOperations()'), 'Admin có bảng điều khiển bảo trì/nâng cấp cho từng bot');
$assert(str_contains($adminUiSource, 'data-admin-tab="ai-media"') && str_contains($adminUiSource, 'adminAiMedia()') && str_contains($adminUiSource, 'data-ai-cover-service') && str_contains($adminUiSource, '/api/admin/ai-media'), 'Admin có bảng AI Studio và công tắc dịch vụ AI Cover');
$assert(str_contains($adminUiSource, 'data-admin-tab="proxy"') && str_contains($adminUiSource, 'adminProxy()'), 'Admin có trang quản lý HomeProxy');
$assert(str_contains($adminUiSource, 'homeproxy-connect-form') && str_contains($adminUiSource, 'data-homeproxy-action="check"') && str_contains($adminUiSource, 'data-homeproxy-action="rotate"'), 'Mini App có luồng kết nối, kiểm tra và xoay IP HomeProxy');
$landingUiSource = file_get_contents(dirname(__DIR__, 2) . '/website/public/index.html');
$assert(str_contains($landingUiSource, 'HENDY DIGITAL') && str_contains($landingUiSource, 'href="/app-store/"') && str_contains($landingUiSource, 'href="/miniapp/"'), 'New homepage links directly to App Store and Mini App');
$assert(str_contains($landingUiSource, 'href="https://t.me/hendy_dichvu_BOT"') && str_contains($landingUiSource, 'href="/privacy/"') && str_contains($landingUiSource, 'href="/terms/"'), 'New homepage keeps Telegram Bot and legal navigation');
$assert(str_contains($landingUiSource, 'HENDY App Store') && str_contains($landingUiSource, 'Khu vực thành viên') && str_contains($landingUiSource, '/assets/site.css') && str_contains($landingUiSource, '/assets/site.js'), 'New homepage separates HTML, CSS and JavaScript and links member and store surfaces');
$assert(!str_contains($landingUiSource, 'nav-admin') && !str_contains($landingUiSource, 'ADMIN RELEASE STUDIO') && !str_contains($landingUiSource, 'onrender.com/admin'), 'Public homepage hides private Admin and source-management entry points');
$assert(str_contains($adminUiSource, 'HENDY COMMERCE WORKSPACE') && str_contains($adminUiSource, 'PRIVATE OPERATIONS WORKSPACE') && str_contains($adminUiSource, '@media(max-width:520px)') && str_contains($adminUiSource, 'grid-template-columns:1fr'), 'Private Admin has the redesigned commerce workspace and responsive mobile layouts');
$publicRouterSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$assert(str_contains($publicRouterSource, "header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');"), 'trang chủ không giữ HTML cũ trong cache');
$assert(str_contains($publicRouterSource, '/website/public/miniapp/index.html') && str_contains($publicRouterSource, '/miniapp/style.css') && str_contains($publicRouterSource, '/miniapp/app.js'), 'Bot-hosted Mini App route serves the new canonical HTML, CSS and JavaScript');
$assert(str_contains($apiControllerSource, 'ProductReadinessService::sqlPredicate()') && str_contains($apiControllerSource, "'/api/public/catalog'"), 'public Website catalog uses the fail-closed supplier readiness predicate');
$assert(str_contains($apiControllerSource, "'/api/public/catalog'") && str_contains($apiControllerSource, "'/api/public/config'") && str_contains($apiControllerSource, "'downloadUrl' => \$downloadUrl") && str_contains($apiControllerSource, "'checksumSha256' => \$checksum"), 'public catalog API returns curated product/release metadata and non-secret Bot config only');
$siteScript = file_get_contents(dirname(__DIR__, 2) . '/website/public/assets/site.js');
$assert(str_contains($siteScript, "apiBase + '/api/health'") && str_contains($siteScript, 'service-state online'), 'Homepage reports live API health without inventing release entries');
$productionHost = 'https://hendydichvumang-telegram-bot.onrender.com';
$websiteDockerfile = file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
$websiteNginx = file_get_contents(dirname(__DIR__, 2) . '/deploy/nginx-website.conf');
$repoRoot = dirname(__DIR__, 2);
$landingSource = file_get_contents(dirname(__DIR__, 2) . '/website/public/index.html');
$privacySource = file_get_contents(dirname(__DIR__, 2) . '/website/public/privacy/index.html');
$termsSource = file_get_contents(dirname(__DIR__, 2) . '/website/public/terms/index.html');
$renderSource = file_get_contents($repoRoot . '/render.yaml');
$envManifest = file_get_contents(dirname(__DIR__) . '/config/env.schema.json');
$runtimeWorkflow = file_get_contents($repoRoot . '/.github/workflows/hendy-monorepo-ci.yml');
$sitemapSource = file_get_contents(dirname(__DIR__, 2) . '/website/public/sitemap.xml');
$robotsSource = file_get_contents(dirname(__DIR__, 2) . '/website/public/robots.txt');
$databaseSchema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$neonStorageSource = file_get_contents(dirname(__DIR__) . '/app/NeonStorage.php');
$moduleRegistrySource = file_get_contents(dirname(__DIR__) . '/app/ModuleRegistry.php');
$neonFunctionSource = file_get_contents($repoRoot . '/neon-functions/hendystorage/index.mjs');
$neonConfigSource = file_get_contents($repoRoot . '/neon.ts');
$deployScript = file_get_contents(dirname(__DIR__) . '/scripts/deploy-verify-render.sh');
$syncScript = file_get_contents(dirname(__DIR__) . '/scripts/sync-render-env.sh');
$websiteDbBootstrap = file_get_contents($repoRoot . '/website/public/index.html');
$websiteHeader = file_get_contents($repoRoot . '/website/public/index.html');
$websiteRootHtaccess = file_get_contents($repoRoot . '/website/public/robots.txt');
$websiteStoreHtaccess = file_get_contents($repoRoot . '/website/README.md');
$assert(str_contains($websiteRootHtaccess, 'Disallow: /admin/'), 'Website blocks direct access to monorepo source and build files');
$assert(str_contains($websiteStoreHtaccess, 'Only `public/` is published'), 'Website blocks direct HTTP access to shared PHP include files');
$assert(str_contains($landingSource, 'https://hendydichvumang-szad.onrender.com/') && !str_contains($landingSource, $productionHost) && !str_contains($landingSource, '/admin') && str_contains($privacySource, 'Chính sách quyền riêng tư') && str_contains($termsSource, 'Điều khoản sử dụng'), 'Static Website keeps its canonical URL and hides all Admin/backend routes from public navigation');
$assert(str_contains($websiteDockerfile, 'FROM nginx:1.27-alpine') && str_contains($websiteNginx, 'proxy_pass https://$hendy_backend$request_uri') && str_contains($websiteNginx, 'proxy_set_header Origin ""'), 'Reusable website service builds the static frontend and proxies API requests same-origin');
$assert(str_contains($renderSource, 'name: hendydichvumang-telegram-bot') && str_contains($renderSource, 'dockerfilePath: ./telegram-bot/Dockerfile') && str_contains($renderSource, 'dockerContext: .') && preg_match('/^\s*name:\s*hendydichvumang\s*$/m', $renderSource) === 1 && str_contains($renderSource, 'runtime: static') && str_contains($renderSource, 'staticPublishPath: ./website/public') && !str_contains($renderSource, 'name: hendydichvumang-website'), 'Render Blueprint defines one named Static Site and the isolated Bot backend');
$assert(!str_contains($renderSource, 'BOT2_TOKEN') && str_contains($renderSource, 'BOT1_TOKEN') && str_contains($renderSource, 'HOMEPROXY_ENCRYPTION_KEY'), 'production Blueprint documents the single bot and existing private secrets without copying secret values');
$assert(!str_contains($envManifest, 'BOT2_TOKEN') && !str_contains($envManifest, 'BOT2_PUBLIC_URL') && !str_contains($envManifest, 'TELEGRAM_SECOND_WEBHOOK_SECRET'), 'runtime environment manifest contains no secondary-bot credentials');
$assert(str_contains($renderSource, 'HOMEPROXY_ENCRYPTION_KEY') && str_contains($envManifest, 'HOMEPROXY_ENCRYPTION_KEY'), 'HomeProxy encryption secret remains documented in Blueprint and env manifest');
$assert(str_contains($renderSource, 'RUN_MIGRATIONS_ON_START') && str_contains($renderSource, 'value: "false"') && str_contains($renderSource, 'VIDEO_WORKER_ENABLED'), 'production keeps DDL outside the Bot runtime while documenting the optional video worker');
$assert(str_contains($renderSource, 'VIDEO_WORKER_ENABLED') && str_contains($envManifest, 'VIDEO_WORKER_ENABLED') && str_contains($envManifest, 'ELEVENLABS_API_KEY'), 'environment manifest documents the optional video worker and AI music provider credential name');

$assert(str_contains($runtimeWorkflow, 'working-directory: telegram-bot') && str_contains($runtimeWorkflow, 'composer test'), 'monorepo CI runs Bot tests from the isolated application directory');
$assert(str_contains($sitemapSource, 'hendydichvumang-szad.onrender.com') && str_contains($robotsSource, 'hendydichvumang-szad.onrender.com'), 'sitemap and robots reference the new Static Site hostname');
$assert(str_contains($databaseSchema, 'https://hendydichvumang-szad.onrender.com') && str_contains($databaseSchema, "'https://hendy-bot-tele.onrender.com'"), 'Bot PostgreSQL migration seeds the new Static Site URL and keeps legacy URL migration rules');
$assert(str_contains($deployScript, '${APP_URL:?') && str_contains($syncScript, 'RENDER_EXTERNAL_URL') && !str_contains($syncScript, 'hendy-bot-cybertech-1.onrender.com'), 'operational scripts resolve the active Render URL instead of hardcoding the former service');
$assert(str_contains($botServiceSource, 'https://hendydichvumang-szad.onrender.com/') && !str_contains($botServiceSource, 'https://hendydichvumang-telegram-bot.onrender.com/'), 'Bot welcome links fall back to the verified public Static Site rather than the Bot API host');
$assert(str_contains($websiteDbBootstrap, 'DIGITAL ECOSYSTEM') && stripos($websiteDbBootstrap, 'Code Telegram') !== false, 'New Static Website remains public-facing and links the customer feature set');
$assert(!str_contains($websiteHeader, 'Quản trị ↗') && !str_contains($websiteHeader, '/admin'), 'Website does not expose Admin links or backend routes in its public interface');
$publicRouterSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$registerWebhookSource = file_get_contents(dirname(__DIR__) . '/bin/register-webhooks.php');
$assert(str_contains($publicRouterSource, "SELECT value FROM app_settings WHERE key = ? AND is_public = TRUE"), 'public Bot config uses the cached URL discovered from getMe');
$assert(str_contains($registerWebhookSource, "call('getMe'") && str_contains($registerWebhookSource, "'primary_bot_url'"), 'Bot startup discovers and caches its public username through Telegram getMe');
$assert(str_contains($botServiceSource, "key = 'primary_bot_url'") && str_contains($botServiceSource, "Env::get('BOT1_PUBLIC_URL'"), 'Referral links prefer the optional override and otherwise use the getMe-discovered Bot URL');
$assert(str_contains($moduleRegistrySource, 'NeonStorage::uploadFile') && str_contains($moduleRegistrySource, 'sha256'), 'module uploads use private Neon Object Storage and preserve SHA-256 validation');
$assert(str_contains($neonStorageSource, 'NEON_STORAGE_FUNCTION_KEY') && str_contains($neonStorageSource, 'CURLOPT_SSL_VERIFYPEER'), 'storage signing key stays server-side and HTTPS verification remains enabled');
$assert(str_contains($neonFunctionSource, 'timingSafeEqual') && str_contains($neonFunctionSource, "const BUCKET = 'uploads'"), 'Neon Function authenticates internal requests and restricts uploads to private bucket');
$releaseControllerSource = $apiControllerSource;
$releaseUiSource = file_get_contents(dirname(__DIR__, 2) . '/website/telegram/admin-dashboard/index.html');
$releaseWebsiteSource = file_get_contents(dirname(__DIR__, 2) . '/website/public/index.html');
$releaseRouterSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$assert(str_contains($releaseControllerSource, 'function uploadProductRelease') && str_contains($releaseControllerSource, 'NeonStorage::uploadFile') && str_contains($releaseControllerSource, 'hash_file(\'sha256\'') && str_contains($releaseControllerSource, "'published' => false"), 'HENDY App Store uploads private release binaries, calculates SHA-256 and defaults each upload to draft');
$assert(str_contains($releaseControllerSource, 'validReleaseSignature') && str_contains($releaseControllerSource, 'storeReleaseHistory') && str_contains($releaseControllerSource, 'array_slice($history, 0, 10)'), 'release upload validates basic file signatures and preserves the previous ten release records');
$assert(str_contains($releaseRouterSource, '/release-upload') && str_contains($releaseRouterSource, 'uploadProductRelease') && str_contains($releaseControllerSource, 'TelegramAuth::authenticateRequest()') && str_contains($releaseControllerSource, '$this->requireAdmin()'), 'binary upload is routed through Admin authentication rather than the insecure public downloader upload endpoint');
$assert(str_contains($releaseControllerSource, "'/api/public/releases'") && str_contains($releaseControllerSource, "'/api/public/releases/check'") && str_contains($releaseControllerSource, 'version_compare($latest, $current, \'>\')'), 'HENDY exposes public release catalogue and app/platform version check endpoints');
$assert(str_contains($releaseControllerSource, 'NeonStorage::presignedDownloadUrl') && str_contains($releaseControllerSource, 'safePublicHttpsUrl') && str_contains($releaseControllerSource, "'sha256' => $sha256"), 'public release responses expose only HTTPS signed downloads and a verified SHA-256 checksum');
$assert(str_contains($releaseControllerSource, 'storeReleasePublished') && str_contains($releaseControllerSource, 'ProductReadinessService::isSellable($candidate)') && str_contains($releaseControllerSource, 'store.release.auto_unpublish'), 'an Admin cannot publish a release before supplier readiness and releases are hidden when a product becomes unsellable');
$assert(str_contains($neonStorageSource, 'store-releases/') && str_contains($neonStorageSource, '/store-release/presign') && str_contains($neonStorageSource, 'application/vnd.android.package-archive'), 'Neon PHP storage bridge accepts only the dedicated private release namespace and platform binary MIME types');
$assert(str_contains($neonFunctionSource, '/store-release/presign') && str_contains($neonFunctionSource, 'storeRelease') && str_contains($neonFunctionSource, 'application/vnd.android.package-archive'), 'Neon Function signs release object URLs through a separate prefix-restricted route');
$assert(str_contains($releaseControllerSource, '/api/admin/source-control/workflows/dispatch') && str_contains($releaseUiSource, 'data-store-release-dispatch') && str_contains($releaseUiSource, 'Chạy CI ngay'), 'Admin can dispatch the fixed HENDY CI workflow through authenticated backend code');
$assert(str_contains($releaseControllerSource, '/api/admin/source-control/workflows') && str_contains($releaseUiSource, 'CI / BUILD MONITOR') && str_contains($releaseUiSource, '/api/admin/source-control/workflows?limit=10'), 'Release Studio includes a live, read-only GitHub Actions monitor with explicit production-verification caveats');
$assert(str_contains($releaseUiSource, 'HENDY APP STORE · RELEASE STUDIO') && str_contains($releaseUiSource, 'data-admin-tab="store-releases"') && str_contains($releaseUiSource, 'function adminStoreReleases()') && str_contains($releaseUiSource, 'store-release-upload-form') && str_contains($releaseUiSource, 'data-store-release-publish') && str_contains($releaseUiSource, '/release-upload') && str_contains($releaseUiSource, "'Authorization':'tma ' + initData"), 'Admin has a dedicated authenticated App Store Release Studio with upload, publish and hide controls');
$assert(str_contains($releaseWebsiteSource, 'href="/app-store/"') && str_contains($releaseWebsiteSource, 'HENDY App Store') && !str_contains($releaseWebsiteSource, 'id="downloads"'), 'Homepage links to the dedicated App Store and avoids a duplicate downloads catalogue');
$assert(!str_contains($releaseWebsiteSource, 'loadPublicReleases') && !str_contains($releaseWebsiteSource, 'data-release-platform'), 'Homepage avoids a duplicate release API request; the dedicated App Store owns download rendering');
$appStorePage = file_get_contents(dirname(__DIR__, 2) . '/website/public/app-store/index.html');
$appStoreScript = file_get_contents(dirname(__DIR__, 2) . '/website/public/app-store/app.js');
$appStoreCss = file_get_contents(dirname(__DIR__, 2) . '/website/public/app-store/style.css');
$appStoreConfig = file_get_contents(dirname(__DIR__, 2) . '/website/public/app-store/config.js');
$assert(str_contains($releaseWebsiteSource, 'href="/app-store/"') && str_contains($releaseWebsiteSource, 'HENDY App Store'), 'HENDY homepage links to the standalone App Store storefront');
$assert(str_contains($appStorePage, 'HENDY APP STORE') && str_contains($appStorePage, 'id="app-grid"') && str_contains($appStorePage, 'id="release-modal"') && str_contains($appStorePage, 'Khu vực thành viên'), 'Dedicated HENDY App Store page has a product catalogue, app detail modal and member navigation');
$assert(!str_contains($appStorePage, 'DEVELOPER INTEGRATION') && !str_contains($appStorePage, 'Auto-update API') && !str_contains($appStorePage, 'id="update-api"'), 'Public storefront hides developer API documentation and internal source-facing UI while retaining server APIs');
$assert(!str_contains($appStorePage, 'Developer Portal') && !str_contains($appStorePage, 'onrender.com/admin') && !str_contains($appStoreScript, 'sau khi Admin hoàn tất'), 'Public App Store hides private Developer/Admin entry points and internal workflow details');
$assert(str_contains($appStoreScript, '/api/public/releases') && str_contains($appStoreScript, 'safeHttps') && str_contains($appStoreScript, 'data-category') && str_contains($appStoreScript, 'data-platform') && str_contains($appStoreScript, 'SHA-256'), 'App Store storefront consumes the live HENDY release API with HTTPS validation, platform filters and checksums');
$assert(str_contains($appStoreScript, 'detectDevice') && str_contains($appStoreScript, 'screenshots') && str_contains($appStoreScript, 'otaAvailable') && str_contains($appStoreScript, 'releaseNotes'), 'App Store UI uses device detection, application screenshots, release notes and guarded iOS OTA links');
$assert(str_contains($appStorePage, 'data-category="otp"') && str_contains($appStorePage, 'data-category="proxy"') && str_contains($appStorePage, 'id="sort-select"') && str_contains($appStoreScript, "sort === 'version'") && str_contains($appStoreScript, "sort === 'platform'"), 'App Store supports the full published-service category filter set and real catalogue sorting controls');
$assert(str_contains($appStoreCss, '.app-grid') && str_contains($appStoreCss, '@media(max-width:700px)') && str_contains($appStoreCss, '.modal-panel'), 'App Store styles provide responsive desktop/mobile cards and application details');
$assert(str_contains($appStoreConfig, 'hendydichvumang-telegram-bot.onrender.com') && str_contains($appStoreConfig, 'hendydichvumang-szad.onrender.com'), 'App Store API config supports Static Site and same-origin website service');
$assert(str_contains($releaseControllerSource, 'productScreenshotsInput') && str_contains($releaseControllerSource, "'screenshots' => array_values(") && str_contains($releaseControllerSource, 'array_slice(is_array(' . '$metadata' . "['screenshots'] ?? null) ? " . '$metadata' . "['screenshots'] : [], 0, 8)"), 'product metadata accepts up to eight HTTPS screenshots and public release responses sanitize the gallery');
$assert(str_contains($releaseUiSource, 'name="screenshots"') && str_contains($releaseUiSource, 'data-store-release-product-select') && str_contains($releaseUiSource, 'data-store-release-save-manifest'), 'Admin metadata form and dedicated release panel support screenshots and iOS manifest management');
$assert(str_contains($neonConfigSource, 'realtime: true') && str_contains($neonConfigSource, 'hendystorage'), 'Neon config declares Function, Object Storage and Realtime');
$webhookSecret = 'sample-webhook-secret-for-tests-000001';
$assert(WebhookSecurity::verifySecret($webhookSecret, $webhookSecret), 'webhook secret đúng được chấp nhận');
$assert(!WebhookSecurity::verifySecret($webhookSecret, 'wrong-webhook-secret-for-tests-000001'), 'webhook secret sai bị từ chối');
$hmacBody = '{"update_id":123,"message":{"text":"hello"}}';
$hmac = 'sha256=' . hash_hmac('sha256', $hmacBody, $webhookSecret);
$assert(WebhookSecurity::verifyHmac($hmacBody, $hmac, $webhookSecret), 'HMAC callback hợp lệ được chấp nhận');
$assert(!WebhookSecurity::verifyHmac($hmacBody, 'sha256=' . str_repeat('0', 64), $webhookSecret), 'HMAC callback sai bị từ chối');
$assert(WebhookSecurity::decodeJson($hmacBody, 1024)['update_id'] === 123, 'JSON webhook được giải mã');

$previousAdminId = getenv('ADMIN_ID');
$previousAdminIds = getenv('ADMIN_IDS');
putenv('ADMIN_ID=9500000001');
putenv('ADMIN_IDS=9500000002,9500000001,invalid');
$assert(TelegramAuth::adminIds() === ['9500000001', '9500000002'], 'admin allowlist merges and deduplicates ADMIN_ID and ADMIN_IDS');
$assert(TelegramAuth::isAdmin('9500000002'), 'secondary admin is authorized by shared admin allowlist');
if ($previousAdminId === false) putenv('ADMIN_ID'); else putenv('ADMIN_ID=' . $previousAdminId);
if ($previousAdminIds === false) putenv('ADMIN_IDS'); else putenv('ADMIN_IDS=' . $previousAdminIds);


$assert(WebServiceKernel::handles('/api/services/system/health'), 'Web Service kernel nhận route đã đăng ký');
$assert(!WebServiceKernel::handles('/api/not-services/system/health'), 'Web Service kernel không nhận route ngoài namespace');
$assert(in_array('system', WebServiceKernel::registeredServices(), true), 'System Web Service được đăng ký trong allow-list');
$invalidServicePathRejected = false;
try {
    WebServiceKernel::dispatch('GET', '/api/services/unknown/health', [], []);
} catch (RuntimeException) {
    $invalidServicePathRejected = true;
}
$assert($invalidServicePathRejected, 'Web Service không được đăng ký sẽ bị từ chối');

$assert(VideoJobService::isAllowedMediaUrl('https://youtu.be/dQw4w9WgXcQ'), 'chấp nhận URL YouTube HTTPS');
$assert(VideoJobService::isAllowedMediaUrl('https://www.tiktok.com/@creator/video/123'), 'chấp nhận URL TikTok HTTPS');
$assert(!VideoJobService::isAllowedMediaUrl('http://youtube.com/watch?v=abc'), 'từ chối URL video HTTP');
$assert(!VideoJobService::isAllowedMediaUrl('https://youtube.com.evil.example/watch?v=abc'), 'từ chối domain giả mạo');
$assert(!VideoJobService::isAllowedMediaUrl('https://127.0.0.1/admin'), 'từ chối IP nội bộ thay vì domain video');

$token = sprintf('123456789:%s', str_repeat('A', 40));
$authDate = time();
$user = json_encode(['id' => 12345678, 'first_name' => 'Hendy'], JSON_UNESCAPED_UNICODE);
$params = [
    'auth_date' => (string) $authDate,
    'query_id' => 'AAEAAAE',
    'user' => (string) $user,
];
ksort($params, SORT_STRING);
$checkString = implode("\n", array_map(
    static fn ($key, $value): string => $key . '=' . $value,
    array_keys($params),
    array_values($params)
));
$secret = hash_hmac('sha256', $token, 'WebAppData', true);
$hash = hash_hmac('sha256', $checkString, $secret);
$initData = http_build_query([...$params, 'hash' => $hash]);
$validated = TelegramAuth::validateInitData($initData, $token);
$assert((string) $validated['id'] === '12345678', 'xác thực Telegram initData hợp lệ');

$invalid = str_replace($hash, str_repeat('0', 64), $initData);
$thrown = false;
try {
    TelegramAuth::validateInitData($invalid, $token);
} catch (Throwable) {
    $thrown = true;
}
$assert($thrown, 'initData bị sửa phải bị từ chối');

$expiredParams = [
    'auth_date' => (string) ($authDate - 90000),
    'user' => (string) $user,
];
ksort($expiredParams, SORT_STRING);
$expiredString = implode("\n", array_map(
    static fn ($key, $value): string => $key . '=' . $value,
    array_keys($expiredParams),
    array_values($expiredParams)
));
$expiredHash = hash_hmac('sha256', $expiredString, $secret);
$expiredData = http_build_query([...$expiredParams, 'hash' => $expiredHash]);
$thrown = false;
try {
    TelegramAuth::validateInitData($expiredData, $token);
} catch (Throwable) {
    $thrown = true;
}
$assert($thrown, 'initData quá hạn phải bị từ chối');

echo "Đã chạy {$checks} kiểm tra PHP.\n";

$assert(str_contains($sourceControlServiceSource, "'PATCH', '/git/refs/heads/'") && str_contains($sourceControlServiceSource, "'force' => false"), 'Admin source editor updates main using a fast-forward-only Git ref write');
