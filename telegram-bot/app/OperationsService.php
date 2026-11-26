<?php
declare(strict_types=1);
namespace Hendy;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class OperationsService
{
    private const BOT_KEYS = ['primary'];

    public static function forBot(string $botKey): array
    {
        $key = in_array($botKey, self::BOT_KEYS, true) ? $botKey : 'primary';
        $mode = strtolower(trim((string) (Database::value('SELECT value FROM app_settings WHERE key = ?', ['ops_' . $key . '_mode']) ?? 'off')));
        if (!in_array($mode, ['off', 'maintenance', 'upgrade'], true)) $mode = 'off';
        $message = trim((string) (Database::value('SELECT value FROM app_settings WHERE key = ?', ['ops_' . $key . '_message']) ?? ''));
        $until = trim((string) (Database::value('SELECT value FROM app_settings WHERE key = ?', ['ops_' . $key . '_until']) ?? ''));
        $active = $mode !== 'off';
        if ($active && $until !== '') {
            try { $active = (new DateTimeImmutable($until))->getTimestamp() > time(); }
            catch (Throwable) { $active = true; }
        }
        if ($message === '') $message = $mode === 'upgrade'
            ? 'HENDY BOT đang nâng cấp hệ thống để cải thiện tốc độ và độ ổn định.'
            : 'HENDY BOT đang bảo trì. Vui lòng quay lại sau.';
        $effectiveMode = $active ? $mode : 'off';
        $effectiveUntil = $active && $until !== '' ? $until : null;
        return ['botKey'=>$key,'mode'=>$effectiveMode,'active'=>$active,'message'=>$message,'until'=>$effectiveUntil];
    }

    public static function all(): array
    {
        return ['bots'=>['primary'=>self::forBot('primary')],'timestamp'=>gmdate(DATE_ATOM)];
    }

    public static function update(array $payload): array
    {
        $bots = $payload['bots'] ?? null;
        if (!is_array($bots) || $bots === []) throw new RuntimeException('Thiếu cấu hình bot cần cập nhật.');
        foreach ($bots as $botKey => $settings) {
            if (!in_array((string)$botKey,self::BOT_KEYS,true) || !is_array($settings)) throw new RuntimeException('Bot mục tiêu không hợp lệ.');
            $mode = strtolower(trim((string)($settings['mode'] ?? 'off')));
            if (!in_array($mode,['off','maintenance','upgrade'],true)) throw new RuntimeException('Chế độ chỉ được là off, maintenance hoặc upgrade.');
            $message = trim((string)($settings['message'] ?? ''));
            if (mb_strlen($message,'UTF-8') > 1000) throw new RuntimeException('Thông báo bảo trì tối đa 1.000 ký tự.');
            $until = trim((string)($settings['until'] ?? ''));
            if ($until !== '') {
                try { $date = new DateTimeImmutable($until,new DateTimeZone('Asia/Ho_Chi_Minh')); }
                catch (Throwable) { throw new RuntimeException('Thời điểm kết thúc bảo trì không hợp lệ.'); }
                if ($date->getTimestamp() <= time()) throw new RuntimeException('Thời điểm kết thúc phải nằm trong tương lai.');
                if ($date->getTimestamp() > time()+30*86400) throw new RuntimeException('Thời điểm kết thúc không được cách hiện tại quá 30 ngày.');
                $until = $date->format(DATE_ATOM);
            }
            foreach ([
                'ops_'.$botKey.'_mode'=>$mode,
                'ops_'.$botKey.'_message'=>$message,
                'ops_'.$botKey.'_until'=>$until
            ] as $key=>$value) {
                Database::run(
                    'INSERT INTO app_settings (key,value,is_public,updated_at) VALUES (?, ?, FALSE, NOW()) ON CONFLICT (key) DO UPDATE SET value=EXCLUDED.value,is_public=FALSE,updated_at=NOW()',
                    [$key,$value]
                );
            }
        }
        return self::all();
    }
}
