<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/TelegramLink.php';
require dirname(__DIR__) . '/app/BotMenuDefinition.php';
require dirname(__DIR__) . '/app/ProductReadinessService.php';
require dirname(__DIR__) . '/app/PaymentService.php';
require dirname(__DIR__) . '/app/VideoJobService.php';
require dirname(__DIR__) . '/app/VipService.php';
require dirname(__DIR__) . '/app/VoucherService.php';
require dirname(__DIR__) . '/app/Realtime.php';
require dirname(__DIR__) . '/app/OperationsService.php';
require dirname(__DIR__) . '/app/MatchmakingService.php';
require dirname(__DIR__) . '/app/ProxyService.php';
require dirname(__DIR__) . '/app/AiMediaService.php';
require dirname(__DIR__) . '/app/BotService.php';

Hendy\Env::load(dirname(__DIR__) . '/.env');

$botKeys = ['primary', 'secondary'];
$offsets = ['primary' => 0, 'secondary' => 0];
$apis = [];

foreach ($botKeys as $key) {
    $token = Hendy\TelegramAuth::token($key);
    if ($token === '') {
        continue;
    }
    $apis[$key] = new Hendy\TelegramApi($token);
    $apis[$key]->deleteWebhook();
    echo "Đã bật polling cho {$key}.\n";
}

if ($apis === []) {
    throw new RuntimeException('Chưa cấu hình BOT1_TOKEN hoặc BOT2_TOKEN.');
}

while (true) {
    foreach ($apis as $key => $api) {
        try {
            foreach ($api->getUpdates($offsets[$key], 20) as $update) {
                $offsets[$key] = (int) ($update['update_id'] ?? 0) + 1;
                (new Hendy\BotService($key))->handle($update);
            }
        } catch (Throwable $error) {
            error_log('[polling:' . $key . '] ' . $error->getMessage());
            sleep(2);
        }
    }
}
