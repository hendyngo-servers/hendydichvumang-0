<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

final class TelegramApi
{
    public function __construct(private readonly string $token)
    {
        if (!preg_match('/\A[0-9]{5,12}:[A-Za-z0-9_-]{30,}\z/D', $token)) {
            throw new RuntimeException('Token Telegram chưa được cấu hình hoặc không đúng định dạng.');
        }
    }

    public function call(
        string $method,
        array $parameters = [],
        int $timeoutSeconds = 35,
        int $connectTimeoutSeconds = 8
    ): array {
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]{1,63}\z/D', $method)) {
            throw new RuntimeException('Tên phương thức Telegram API không hợp lệ.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP extension curl chưa được cài đặt.');
        }

        $body = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $handle = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        if ($handle === false) {
            throw new RuntimeException('Không thể khởi tạo kết nối Telegram.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, min(15, $connectTimeoutSeconds)),
            CURLOPT_TIMEOUT => max(2, min(60, $timeoutSeconds)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_USERAGENT => 'HendyBot-Cybertech/2.0',
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException('Kết nối Telegram thất bại: ' . ($curlError !== '' ? $curlError : 'network error'));
        }
        if (strlen((string) $response) > 5_242_880) {
            throw new RuntimeException('Phản hồi Telegram vượt quá giới hạn cho phép.');
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || !($decoded['ok'] ?? false) || $status < 200 || $status >= 300) {
            $description = is_array($decoded)
                ? (string) ($decoded['description'] ?? 'Telegram API trả về lỗi.')
                : 'Phản hồi Telegram không hợp lệ.';
            $errorCode = is_array($decoded) ? (int) ($decoded['error_code'] ?? $status) : $status;
            throw new RuntimeException('Telegram API lỗi ' . $errorCode . ': ' . mb_substr($description, 0, 300));
        }

        return is_array($decoded['result'] ?? null)
            ? $decoded['result']
            : ['value' => $decoded['result'] ?? null];
    }

    public function sendMessage(string $chatId, string $text, array $options = []): array
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            ...$options,
        ]);
    }

    public function sendPhoto(string $chatId, string $photo, string $caption, array $options = []): array
    {
        return $this->call('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            ...$options,
        ]);
    }

    /**
     * Download a trusted banner URL on the server, validate it as a real image,
     * then upload the bytes to Telegram. Telegram's own URL fetcher often
     * rejects image hosts that require redirects or anti-bot handling.
     */
    public function sendPhotoFromUrl(string $chatId, string $url, string $caption, array $options = []): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('BOT_HOME_PHOTO must be a valid HTTPS URL.');
        }

        $allowed = array_values(array_filter(array_map(
            static fn(string $host): string => strtolower(trim($host)),
            explode(',', (string)Env::get('BOT_HOME_PHOTO_ALLOWED_HOSTS', 'i.postimg.cc'))
        )));
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if (!in_array($host, $allowed, true)) {
            throw new RuntimeException('Banner host is not in BOT_HOME_PHOTO_ALLOWED_HOSTS.');
        }

        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('Could not initialize the banner download.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => 10_485_760,
            CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/png,image/webp,image/gif'],
            CURLOPT_USERAGENT => 'HendyBot-Cybertech/2.1',
        ]);
        $bytes = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $mime = strtolower(trim(explode(';', (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0]));
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($bytes === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Banner download failed (HTTP ' . $status . ')' . ($curlError !== '' ? ': ' . $curlError : '.'));
        }
        if (!is_string($bytes) || strlen($bytes) < 16 || strlen($bytes) > 10_485_760) {
            throw new RuntimeException('Banner image is empty or exceeds the 10 MB limit.');
        }
        $imageInfo = @getimagesizefromstring($bytes);
        $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        if (!isset($extensions[$mime]) || $imageInfo === false || ($imageInfo['mime'] ?? '') !== $mime) {
            throw new RuntimeException('Banner URL did not return a supported image (JPEG, PNG, WEBP or GIF).');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'hendy-banner-');
        if ($temporary === false) throw new RuntimeException('Could not allocate a temporary image file.');
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('Could not write the temporary banner image.');
            }
            return $this->sendPhotoFile($chatId, $temporary, $caption, $options, $mime);
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    public function sendPhotoFile(
        string $chatId,
        string $filePath,
        string $caption,
        array $options = [],
        ?string $mimeType = null
    ): array {
        if (!is_file($filePath) || !is_readable($filePath)) throw new RuntimeException('Photo upload file is not readable.');
        $mimeType ??= (new \finfo(FILEINFO_MIME_TYPE))->file($filePath) ?: 'application/octet-stream';
        if (!in_array($mimeType, ['image/jpeg','image/png','image/webp','image/gif'], true)) {
            throw new RuntimeException('Photo upload MIME type is not supported.');
        }
        $parameters = [
            'chat_id' => $chatId,
            'photo' => new \CURLFile($filePath, $mimeType, basename($filePath)),
            'caption' => $caption,
            'parse_mode' => 'HTML',
            ...$options,
        ];
        return $this->callMultipart('sendPhoto', $parameters, 45, 10);
    }

    public function sendAudioFile(string $chatId, string $filePath, string $caption = '', array $options = []): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) throw new RuntimeException('Audio upload file is not readable.');
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($filePath) ?: 'audio/mpeg';
        $parameters = [
            'chat_id' => $chatId,
            'audio' => new \CURLFile($filePath, $mimeType, basename($filePath)),
            'caption' => $caption,
            'parse_mode' => 'HTML',
            ...$options,
        ];
        return $this->callMultipart('sendAudio', $parameters, 90, 10);
    }

    private function callMultipart(string $method, array $parameters, int $timeoutSeconds, int $connectTimeoutSeconds): array
    {
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]{1,63}\z/D', $method)) {
            throw new RuntimeException('Tên phương thức Telegram API không hợp lệ.');
        }
        $fields = [];
        foreach ($parameters as $key => $value) {
            $fields[$key] = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : $value;
        }
        $handle = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        if ($handle === false) throw new RuntimeException('Không thể khởi tạo kết nối Telegram upload.');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, min(15, $connectTimeoutSeconds)),
            CURLOPT_TIMEOUT => max(2, min(120, $timeoutSeconds)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_USERAGENT => 'HendyBot-Cybertech/2.1',
        ]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);
        if ($response === false) {
            throw new RuntimeException('Telegram upload failed: ' . ($curlError !== '' ? $curlError : 'network error'));
        }
        if (strlen((string)$response) > 5_242_880) throw new RuntimeException('Phản hồi Telegram vượt quá giới hạn cho phép.');
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded) || !($decoded['ok'] ?? false) || $status < 200 || $status >= 300) {
            $description = is_array($decoded) ? (string)($decoded['description'] ?? 'Telegram API trả về lỗi.') : 'Phản hồi Telegram không hợp lệ.';
            $errorCode = is_array($decoded) ? (int)($decoded['error_code'] ?? $status) : $status;
            throw new RuntimeException('Telegram API lỗi ' . $errorCode . ': ' . mb_substr($description, 0, 300));
        }
        return is_array($decoded['result'] ?? null) ? $decoded['result'] : ['value'=>$decoded['result'] ?? null];
    }

    /**
     * Resolve a Telegram file_id into a validated temporary image.
     * The returned file is owned by the caller and must be unlinked.
     *
     * @return array{path:string,mime:string}
     */
    /**
     * Download a Telegram audio/voice/document as a validated temporary file.
     * Caller owns the returned file and must unlink it.
     *
     * @return array{path:string,mime:string,size:int}
     */
    public function downloadAudioToTemp(string $fileId, int $maxBytes = 20_971_520): array
    {
        if ($fileId === '' || strlen($fileId) > 512) throw new RuntimeException('Telegram audio file_id không hợp lệ.');
        $maxBytes = max(1024, min(20_971_520, $maxBytes));
        $file = $this->call('getFile', ['file_id' => $fileId], 15, 5);
        $remotePath = (string) ($file['file_path'] ?? '');
        $remoteSize = (int) ($file['file_size'] ?? 0);
        if ($remotePath === '' || strlen($remotePath) > 512
            || !preg_match('#\A[A-Za-z0-9_./-]+\z#D', $remotePath)
            || str_contains($remotePath, '..')) {
            throw new RuntimeException('Telegram trả về đường dẫn audio không hợp lệ.');
        }
        if ($remoteSize > $maxBytes) throw new RuntimeException('Audio vượt giới hạn tải về của HENDY AI Cover.');

        $curl = curl_init('https://api.telegram.org/file/bot' . $this->token . '/' . $remotePath);
        if ($curl === false) throw new RuntimeException('Không thể khởi tạo tải audio từ Telegram.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_HTTPHEADER => ['Accept: audio/mpeg,audio/wav,audio/flac,audio/mp4,audio/aac,audio/ogg,application/octet-stream'],
            CURLOPT_USERAGENT => 'HendyAICover/1.0',
        ]);
        $bytes = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($bytes === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Không tải được audio từ Telegram (HTTP ' . $status . ')' . ($error !== '' ? ': ' . $error : '.'));
        }
        if (!is_string($bytes) || strlen($bytes) < 128 || strlen($bytes) > $maxBytes) {
            throw new RuntimeException('Audio rỗng hoặc vượt giới hạn dung lượng.');
        }

        $mime = strtolower((string) ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: ''));
        $mimeMap = [
            'audio/mpeg' => 'audio/mpeg',
            'audio/mp3' => 'audio/mpeg',
            'audio/wav' => 'audio/wav',
            'audio/x-wav' => 'audio/wav',
            'audio/vnd.wave' => 'audio/wav',
            'audio/flac' => 'audio/flac',
            'audio/x-flac' => 'audio/flac',
            'audio/mp4' => 'audio/mp4',
            'audio/aac' => 'audio/aac',
            'audio/ogg' => 'audio/ogg',
            'application/ogg' => 'audio/ogg',
            'application/octet-stream' => 'application/octet-stream',
        ];
        if (!isset($mimeMap[$mime])) throw new RuntimeException('Tệp Telegram không phải audio được hỗ trợ.');
        $mime = $mimeMap[$mime];
        $temporary = tempnam(sys_get_temp_dir(), 'hendy-ai-cover-input-');
        if ($temporary === false) throw new RuntimeException('Không tạo được tệp audio tạm.');
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($temporary);
            throw new RuntimeException('Không lưu được audio tạm.');
        }
        return ['path' => $temporary, 'mime' => $mime, 'size' => strlen($bytes)];
    }

    public function downloadImageToTemp(string $fileId, int $maxBytes = 20_971_520): array
    {
        if ($fileId === '' || strlen($fileId) > 512) throw new RuntimeException('Telegram file_id không hợp lệ.');
        $file = $this->call('getFile', ['file_id' => $fileId], 15, 5);
        $remotePath = (string)($file['file_path'] ?? '');
        if ($remotePath === '' || strlen($remotePath) > 512
            || !preg_match('#\A[A-Za-z0-9_./-]+\z#D', $remotePath)
            || str_contains($remotePath, '..')) {
            throw new RuntimeException('Telegram trả về đường dẫn ảnh không hợp lệ.');
        }

        $curl = curl_init('https://api.telegram.org/file/bot' . $this->token . '/' . $remotePath);
        if ($curl === false) throw new RuntimeException('Không thể khởi tạo tải ảnh từ Telegram.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_USERAGENT => 'HendyBot-Cybertech/2.1',
        ]);
        $bytes = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($bytes === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Không tải được ảnh từ Telegram (HTTP ' . $status . ')' . ($error !== '' ? ': ' . $error : '.'));
        }
        if (!is_string($bytes) || strlen($bytes) < 16 || strlen($bytes) > $maxBytes) {
            throw new RuntimeException('Ảnh vượt giới hạn dung lượng cho phép.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $imageInfo = @getimagesizefromstring($bytes);
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)
            || $imageInfo === false || ($imageInfo['mime'] ?? '') !== $mime
            || (int)($imageInfo[0] ?? 0) < 1 || (int)($imageInfo[1] ?? 0) < 1
            || (int)$imageInfo[0] * (int)$imageInfo[1] > 40_000_000) {
            throw new RuntimeException('Chỉ nhận ảnh JPG, PNG hoặc WEBP hợp lệ (tối đa 40 MP).');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'hendy-ai-input-');
        if ($temporary === false) throw new RuntimeException('Không tạo được tệp tạm cho ảnh.');
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($temporary);
            throw new RuntimeException('Không lưu được ảnh tạm.');
        }
        return ['path' => $temporary, 'mime' => $mime];
    }

    /**
     * Download a channel video through the Bot API using its file_id.
     * Official hosted Bot API downloads are limited to 20 MB; callers must
     * enforce the configured size ceiling before invoking this method.
     *
     * @return array{path:string,mime:string,size:int}
     */
    public function downloadVideoToTemp(string $fileId, int $maxBytes = 20_971_520): array
    {
        if ($fileId === '' || strlen($fileId) > 512) {
            throw new RuntimeException('Telegram video file_id không hợp lệ.');
        }
        $maxBytes = max(1_048_576, min(20_971_520, $maxBytes));
        $file = $this->call('getFile', ['file_id' => $fileId], 15, 5);
        $remotePath = (string) ($file['file_path'] ?? '');
        $remoteSize = (int) ($file['file_size'] ?? 0);
        if ($remotePath === '' || strlen($remotePath) > 512
            || !preg_match('#\A[A-Za-z0-9_./-]+\z#D', $remotePath)
            || str_contains($remotePath, '..')) {
            throw new RuntimeException('Telegram trả về đường dẫn video không hợp lệ.');
        }
        if ($remoteSize > $maxBytes) {
            throw new RuntimeException('Video vượt giới hạn tải về của Code Telegram.');
        }

        $curl = curl_init('https://api.telegram.org/file/bot' . $this->token . '/' . $remotePath);
        if ($curl === false) throw new RuntimeException('Không thể khởi tạo tải video từ Telegram.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_HTTPHEADER => ['Accept: video/mp4,video/quicktime,video/webm,application/octet-stream'],
            CURLOPT_USERAGENT => 'HendyCodeTelegram/1.0',
        ]);
        $bytes = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($bytes === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Không tải được video từ Telegram (HTTP ' . $status . ')' . ($error !== '' ? ': ' . $error : '.'));
        }
        if (!is_string($bytes) || strlen($bytes) < 128 || strlen($bytes) > $maxBytes) {
            throw new RuntimeException('Video rỗng hoặc vượt giới hạn dung lượng.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if (!in_array($mime, ['video/mp4', 'video/quicktime', 'video/webm', 'application/octet-stream'], true)) {
            throw new RuntimeException('Tệp Telegram không được nhận diện là video hợp lệ.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'hendy-code-video-');
        if ($temporary === false) throw new RuntimeException('Không tạo được tệp video tạm.');
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($temporary);
            throw new RuntimeException('Không lưu được video tạm.');
        }
        @chmod($temporary, 0600);
        return ['path' => $temporary, 'mime' => $mime, 'size' => strlen($bytes)];
    }

    public function answerCallback(string $callbackId, string $text = ''): void
    {
        $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => false,
        ], 4, 2);
    }

    public function sendChatAction(string $chatId, string $action = 'typing'): void
    {
        $allowed = ['typing', 'upload_photo', 'upload_document', 'record_video', 'upload_video'];
        if (!in_array($action, $allowed, true)) {
            $action = 'typing';
        }
        $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action], 2, 1);
    }

    public function setWebhook(string $url, string $secret): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || !preg_match('/\A[A-Za-z0-9_-]{32,256}\z/D', $secret)) {
            throw new RuntimeException('HTTPS webhook URL hoặc webhook secret không hợp lệ.');
        }

        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query', 'channel_post', 'edited_channel_post'],
            'drop_pending_updates' => false,
        ]);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    public function getUpdates(int $offset = 0, int $timeout = 20): array
    {
        $result = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query', 'channel_post', 'edited_channel_post'],
        ]);
        return array_is_list($result) ? $result : [];
    }

}
