<?php
declare(strict_types=1);
namespace Hendy;
use RuntimeException;
/** Private Neon Object Storage bridge; S3 keys stay inside Neon Functions. */
final class NeonStorage
{
    public static function configured(): bool
    {
        $url=trim((string)Env::get('NEON_STORAGE_FUNCTION_URL',''));
        $key=trim((string)Env::get('NEON_STORAGE_FUNCTION_KEY',''));
        if($url===''&&$key==='') return false;
        if($url===''||$key==='') throw new RuntimeException('Neon Object Storage chưa được cấu hình đầy đủ.');
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'
            ||!str_contains(strtolower((string)($parts['host']??'')),'.compute.')
            ||!str_ends_with(strtolower((string)($parts['host']??'')),'.neon.tech'))
            throw new RuntimeException('NEON_STORAGE_FUNCTION_URL không phải URL Neon Function hợp lệ.');
        if(strlen($key)<32||strlen($key)>256) throw new RuntimeException('NEON_STORAGE_FUNCTION_KEY phải dài từ 32 đến 256 ký tự.');
        return true;
    }
    public static function uploadFile(string $localPath,string $objectKey,string $contentType): void
    {
        if(!is_file($localPath)||!is_readable($localPath)) throw new RuntimeException('Không đọc được tệp cần tải lên.');
        if(!self::validObjectKey($objectKey)) throw new RuntimeException('Đường dẫn tệp lưu trữ không hợp lệ.');
        $isAiCover = str_starts_with($objectKey, 'ai-cover/');
        $isStoreRelease = str_starts_with($objectKey, 'store-releases/');
        $allowedTypes = $isAiCover
            ? ['application/octet-stream','audio/mpeg','audio/wav','audio/x-wav','audio/flac','audio/mp4','audio/aac','audio/ogg','audio/opus']
            : ($isStoreRelease
                ? ['application/octet-stream','application/zip','application/vnd.android.package-archive','application/vnd.microsoft.portable-executable','application/x-msdownload','application/x-dosexec','application/x-executable','application/x-elf','application/x-msi','application/x-ole-storage','application/x-apple-diskimage','application/x-apple-installer-package','application/x-iso9660-image','application/x-debian-package','application/vnd.debian.binary-package','application/x-rpm','application/gzip','application/x-gzip']
                : ['application/json','text/plain','text/css','text/html','image/svg+xml','image/png','image/jpeg','image/webp']);
        if(!in_array($contentType,$allowedTypes,true))
            throw new RuntimeException('Loại tệp không được hỗ trợ cho Neon Storage.');
        $signed=self::requestPresignedUrl('upload',$objectKey,$contentType);
        $parts=parse_url($signed['url']);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'
            ||!str_contains(strtolower((string)($parts['host']??'')),'.storage.')
            ||!str_ends_with(strtolower((string)($parts['host']??'')),'.neon.tech'))
            throw new RuntimeException('Neon Storage trả về URL tải lên không hợp lệ.');
        $stream=fopen($localPath,'rb');
        if($stream===false) throw new RuntimeException('Không mở được tệp tải lên.');
        $curl=curl_init($signed['url']);
        if($curl===false){fclose($stream);throw new RuntimeException('Không khởi tạo được kết nối Neon Storage.');}
        try{
            curl_setopt_array($curl,[
                CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$stream,CURLOPT_INFILESIZE=>filesize($localPath),
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: '.$contentType,'Expect:'],
                CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,
            ]);
            $response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if($response===false||$status<200||$status>=300) throw new RuntimeException('Không thể lưu tệp vào Neon Object Storage (HTTP '.$status.').');
        }finally{curl_close($curl);fclose($stream);}
    }
    /**
     * Create a short-lived private upload capability for the external AI Cover
     * worker. The S3 secret never leaves the Neon Function.
     *
     * @return array{url:string,method:string,headers:array}
     */
    public static function presignedUploadRequest(string $objectKey, string $contentType = 'audio/mpeg', int $expiresIn = 300): array
    {
        if (!self::validObjectKey($objectKey) || !str_starts_with($objectKey, 'ai-cover/results/')) {
            throw new RuntimeException('Đường dẫn kết quả AI Cover không hợp lệ.');
        }
        if ($contentType !== 'audio/mpeg') {
            throw new RuntimeException('AI Cover chỉ chấp nhận kết quả MP3.');
        }
        $signed = self::requestPresignedUrl('upload', $objectKey, $contentType, $expiresIn);
        $parts = parse_url($signed['url']);
        $method = strtoupper((string) ($signed['method'] ?? ''));
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !str_contains(strtolower((string) ($parts['host'] ?? '')), '.storage.')
            || !str_ends_with(strtolower((string) ($parts['host'] ?? '')), '.neon.tech')
            || !in_array($method, ['PUT', 'POST'], true)
            || !is_array($signed['headers'] ?? null)) {
            throw new RuntimeException('Neon Storage không cấp được quyền tải MP3 lên an toàn.');
        }
        $headers = [];
        foreach ($signed['headers'] as $name => $value) {
            if (!is_string($name) || !is_scalar($value)
                || preg_match('/[\\r\\n]/', $name . (string) $value)) {
                throw new RuntimeException('Header tải lên Neon Storage không hợp lệ.');
            }
            $headers[$name] = (string) $value;
        }
        return ['url' => (string) $signed['url'], 'method' => $method, 'headers' => $headers];
    }

    public static function presignedDownloadUrl(string $objectKey,int $expiresIn=300): string
    {
        if(!self::validObjectKey($objectKey)) throw new RuntimeException('Đường dẫn tệp lưu trữ không hợp lệ.');
        $signed=self::requestPresignedUrl('download',$objectKey,'',$expiresIn);
        $parts=parse_url($signed['url']);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'
            ||!str_contains(strtolower((string)($parts['host']??'')),'.storage.')
            ||!str_ends_with(strtolower((string)($parts['host']??'')),'.neon.tech'))
            throw new RuntimeException('Neon Storage trả về URL tải xuống không hợp lệ.');
        return $signed['url'];
    }
    private static function validObjectKey(string $key): bool
    {
        if (strlen($key) > 1024) return false;
        if (preg_match('#\Amodules/[a-z][a-z0-9-]{1,62}/[A-Za-z0-9_-]{16,64}\.(?:json|md|txt|css|html|svg|png|jpg|jpeg|webp)\z#',$key) === 1) return true;
        if (preg_match('#\\Astore-releases/[1-9][0-9]{0,17}/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\.(?:apk|exe|msi|msix|zip|dmg|pkg|ipa|appimage|deb|rpm|tar\\.gz)\\z#i', $key) === 1) return true;
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
        if (preg_match('#\Aai-cover/sources/'.$uuid.'/source\.(?:mp3|wav|flac|m4a|aac|ogg|opus)\z#i',$key) === 1) return true;
        if (preg_match('#\Aai-cover/results/'.$uuid.'/cover\.mp3\z#i',$key) === 1) return true;
        if (preg_match('#\Aai-cover/models/[a-z0-9][a-z0-9_-]{1,47}/voice(?:-[a-f0-9]{16})?\.(?:pth|index)\z#',$key) === 1) return true;
        return false;
    }
    /**
     * Download a private object to a temporary file using a short-lived Neon URL.
     * The caller owns and must unlink the returned file.
     *
     * @return array{path:string,size:int}
     */
    public static function downloadToTemp(string $objectKey, int $maxBytes = 50_000_000): array
    {
        if (!self::validObjectKey($objectKey)) throw new RuntimeException('Đường dẫn tệp lưu trữ không hợp lệ.');
        $maxBytes = max(1024, min(500_000_000, $maxBytes));
        $url = self::presignedDownloadUrl($objectKey, 300);
        $temporary = tempnam(sys_get_temp_dir(), 'hendy-ai-cover-');
        if ($temporary === false) throw new RuntimeException('Không tạo được tệp tạm cho AI Cover.');
        $stream = fopen($temporary, 'wb');
        if ($stream === false) { @unlink($temporary); throw new RuntimeException('Không mở được tệp tạm AI Cover.'); }
        $curl = curl_init($url);
        if ($curl === false) { fclose($stream); @unlink($temporary); throw new RuntimeException('Không khởi tạo được tải xuống Neon Storage.'); }
        try {
            curl_setopt_array($curl, [
                CURLOPT_FILE => $stream, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 90,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_MAXFILESIZE => $maxBytes, CURLOPT_USERAGENT => 'Hendy-AI-Cover/1.0',
            ]);
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            fflush($stream);
            $size = (int) (filesize($temporary) ?: 0);
            if ($ok === false || $status < 200 || $status >= 300 || $size < 16 || $size > $maxBytes) {
                throw new RuntimeException('Không tải được tệp AI Cover từ Neon Storage (HTTP ' . $status . ')' . ($error !== '' ? ': ' . $error : '.'));
            }
            return ['path' => $temporary, 'size' => $size];
        } catch (\Throwable $error) {
            if (is_file($temporary)) @unlink($temporary);
            throw $error;
        } finally {
            curl_close($curl);
            fclose($stream);
        }
    }

    public static function deleteObject(string $objectKey): void
    {
        if (!self::validObjectKey($objectKey)) throw new RuntimeException('Đường dẫn tệp lưu trữ không hợp lệ.');
        $signed = self::requestPresignedUrl('delete', $objectKey, '');
        $curl = curl_init($signed['url']);
        if ($curl === false) throw new RuntimeException('Không khởi tạo được xóa Neon Storage.');
        try {
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($response === false || ($status < 200 || $status >= 300) && $status !== 404) {
                throw new RuntimeException('Không xóa được object Neon Storage (HTTP ' . $status . ').');
            }
        } finally { curl_close($curl); }
    }

    private static function requestPresignedUrl(string $action,string $objectKey,string $contentType,int $expiresIn=300): array
    {
        if(!self::configured()) throw new RuntimeException('Neon Object Storage chưa được cấu hình.');
        $baseUrl=rtrim((string)Env::get('NEON_STORAGE_FUNCTION_URL'),'/');
        $apiKey=(string)Env::get('NEON_STORAGE_FUNCTION_KEY');
        $payload=json_encode(['action'=>$action,'key'=>$objectKey,'contentType'=>$contentType,'expiresIn'=>max(1,min(900,$expiresIn))],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $presignPath = str_starts_with($objectKey, 'ai-cover/') ? '/ai-cover/presign' : (str_starts_with($objectKey, 'store-releases/') ? '/store-release/presign' : '/presign');
        $curl=curl_init($baseUrl.$presignPath);
        if($curl===false) throw new RuntimeException('Không khởi tạo được yêu cầu tới Neon Function.');
        try{
            curl_setopt_array($curl,[
                CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Content-Type: application/json','Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,
            ]);
            $response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if($response===false||$status<200||$status>=300) throw new RuntimeException('Neon Function không cấp được URL lưu trữ (HTTP '.$status.').');
            $result=json_decode((string)$response,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($result)||!is_string($result['url']??null)||!is_array($result['headers']??null)||!is_string($result['method']??null))
                throw new RuntimeException('Phản hồi Neon Function không đúng định dạng.');
            return $result;
        }catch(\JsonException $error){throw new RuntimeException('Neon Function trả về dữ liệu không hợp lệ.',0,$error);}
        finally{curl_close($curl);}
    }
}
