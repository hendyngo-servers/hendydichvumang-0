<?php
declare(strict_types=1);
namespace Hendy;
use RuntimeException;
use Throwable;

final class ProxyService
{
    private const PROVIDER_URL = 'https://app.homeproxy.vn/api/v3/users/rotatev2';

    public static function adminStatus(): array
    {
        $logs = Database::all('SELECT user_id,action,status,provider_message,created_at FROM homeproxy_logs ORDER BY created_at DESC LIMIT 30');
        return [
            'enabled'=>self::isEnabled(),
            'encryptionConfigured'=>self::encryptionConfigured(),
            'connectedMembers'=>(int)(Database::value('SELECT COUNT(*) FROM homeproxy_accounts') ?? 0),
            'requestsToday'=>(int)(Database::value("SELECT COUNT(*) FROM homeproxy_logs WHERE created_at>=CURRENT_DATE") ?? 0),
            'successToday'=>(int)(Database::value("SELECT COUNT(*) FROM homeproxy_logs WHERE created_at>=CURRENT_DATE AND status='success'") ?? 0),
            'failedToday'=>(int)(Database::value("SELECT COUNT(*) FROM homeproxy_logs WHERE created_at>=CURRENT_DATE AND status='failed'") ?? 0),
            'recentLogs'=>$logs
        ];
    }

    public static function setEnabled(bool $enabled): array
    {
        Database::run(
            'INSERT INTO app_settings (key,value,is_public,updated_at) VALUES (?, ?, FALSE, NOW()) ON CONFLICT (key) DO UPDATE SET value=EXCLUDED.value,is_public=FALSE,updated_at=NOW()',
            ['homeproxy_enabled',$enabled?'1':'0']
        );
        return self::adminStatus();
    }

    public static function userStatus(string $userId): array
    {
        $row=Database::one('SELECT last_action,last_status,last_action_at FROM homeproxy_accounts WHERE user_id=?',[$userId]);
        return [
            'enabled'=>self::isEnabled(),
            'encryptionConfigured'=>self::encryptionConfigured(),
            'connected'=>$row!==null,
            'lastAction'=>$row['last_action']??null,
            'lastStatus'=>$row['last_status']??null,
            'lastActionAt'=>$row['last_action_at']??null
        ];
    }

    public static function connect(string $userId,string $apiKey): array
    {
        self::assertReady();
        $apiKey=trim($apiKey);
        if(strlen($apiKey)<16||strlen($apiKey)>512||!preg_match('/\A[A-Za-z0-9._~-]+\z/D',$apiKey)) {
            throw new RuntimeException('API Key Xoay không đúng định dạng. Hãy sao chép Key Xoay từ tài khoản HomeProxy.');
        }
        $nonce=random_bytes(12);$tag='';
        $ciphertext=openssl_encrypt($apiKey,'aes-256-gcm',self::encryptionKey(),OPENSSL_RAW_DATA,$nonce,$tag,'hendy-homeproxy:'.$userId,16);
        if($ciphertext===false||$tag==='') throw new RuntimeException('Không thể mã hóa API Key trên máy chủ.');
        Database::run(
            'INSERT INTO homeproxy_accounts (user_id,token_ciphertext,token_nonce,token_tag,created_at,updated_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON CONFLICT (user_id) DO UPDATE SET token_ciphertext=EXCLUDED.token_ciphertext,token_nonce=EXCLUDED.token_nonce,token_tag=EXCLUDED.token_tag,updated_at=NOW()',
            [$userId,base64_encode($ciphertext),base64_encode($nonce),base64_encode($tag)]
        );
        return self::userStatus($userId);
    }

    public static function disconnect(string $userId): array
    {
        Database::run('DELETE FROM homeproxy_accounts WHERE user_id=?',[$userId]);
        return self::userStatus($userId);
    }
    public static function check(string $userId): array { return self::execute($userId,'check',true); }
    public static function rotate(string $userId): array { return self::execute($userId,'rotate',false); }

    private static function execute(string $userId,string $action,bool $checkOnly): array
    {
        self::assertReady();$token=self::decryptToken($userId);
        try {
            $data=self::requestProvider($token,$checkOnly);
            $providerStatus=strtolower((string)($data['status']??''));
            if(in_array($providerStatus,['error','failed','fail'],true)) throw new RuntimeException('HomeProxy từ chối yêu cầu. Hãy kiểm tra Key Xoay hoặc trạng thái gói Proxy.');
            Database::run('INSERT INTO homeproxy_logs (user_id,action,status,provider_message,created_at) VALUES (?, ?, ?, ?, NOW())',[$userId,$action,'success',$checkOnly?'Đã kiểm tra IP hiện tại.':'Đã gửi yêu cầu xoay IP.']);
            Database::run('UPDATE homeproxy_accounts SET last_action=?,last_status=?,last_action_at=NOW() WHERE user_id=?',[$action,'success',$userId]);
            return ['action'=>$action,'checkedAt'=>gmdate(DATE_ATOM),'message'=>$checkOnly?'Đã kiểm tra thông tin Proxy hiện tại.':'HomeProxy đã tiếp nhận yêu cầu xoay IP.','data'=>self::sanitize($data)];
        } catch(Throwable $error) {
            Database::run('INSERT INTO homeproxy_logs (user_id,action,status,provider_message,created_at) VALUES (?, ?, ?, ?, NOW())',[$userId,$action,'failed','Yêu cầu HomeProxy thất bại.']);
            Database::run('UPDATE homeproxy_accounts SET last_action=?,last_status=?,last_action_at=NOW() WHERE user_id=?',[$action,'failed',$userId]);
            if($error instanceof RuntimeException) throw $error;
            throw new RuntimeException('Không thể kết nối HomeProxy lúc này. Vui lòng thử lại sau.');
        }
    }

    private static function requestProvider(string $token,bool $checkOnly): array
    {
        $query=['token'=>$token];if($checkOnly)$query['checkOnly']='true';
        $url=self::PROVIDER_URL.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $handle=curl_init($url);
        if($handle===false) throw new RuntimeException('Không khởi tạo được kết nối HomeProxy.');
        curl_setopt_array($handle,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'HENDY-HomeProxy-Integration/1.0'
        ]);
        $body=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);$errno=curl_errno($handle);curl_close($handle);
        if($body===false) throw new RuntimeException('HomeProxy không phản hồi (cURL '.$errno.').');
        if($status<200||$status>=300) throw new RuntimeException('HomeProxy trả về HTTP '.$status.'. Hãy kiểm tra Key Xoay và gói Proxy.');
        $decoded=json_decode((string)$body,true);
        if(!is_array($decoded)) throw new RuntimeException('HomeProxy trả về dữ liệu không đúng định dạng JSON.');
        return $decoded;
    }

    private static function decryptToken(string $userId): string
    {
        $row=Database::one('SELECT token_ciphertext,token_nonce,token_tag FROM homeproxy_accounts WHERE user_id=?',[$userId]);
        if($row===null) throw new RuntimeException('Bạn chưa kết nối Key Xoay HomeProxy.');
        $ciphertext=base64_decode((string)$row['token_ciphertext'],true);
        $nonce=base64_decode((string)$row['token_nonce'],true);
        $tag=base64_decode((string)$row['token_tag'],true);
        if($ciphertext===false||$nonce===false||$tag===false) throw new RuntimeException('Thông tin kết nối HomeProxy bị lỗi. Hãy kết nối lại Key Xoay.');
        $token=openssl_decrypt($ciphertext,'aes-256-gcm',self::encryptionKey(),OPENSSL_RAW_DATA,$nonce,$tag,'hendy-homeproxy:'.$userId);
        if($token===false||$token==='') throw new RuntimeException('Không giải mã được Key Xoay. Admin cần kiểm tra khóa mã hóa máy chủ.');
        return $token;
    }

    private static function sanitize(mixed $value,int $depth=0): mixed
    {
        if($depth>6)return '[đã lược bớt]';
        if(is_array($value)){
            $result=[];
            foreach(array_slice($value,0,40,true) as $key=>$item){
                if(preg_match('/token|api[_-]?key|secret|authorization|cookie|access[_-]?key/i',(string)$key))continue;
                $result[$key]=self::sanitize($item,$depth+1);
            }
            return $result;
        }
        if(is_string($value))return mb_substr($value,0,1000,'UTF-8');
        if(is_int($value)||is_float($value)||is_bool($value)||$value===null)return $value;
        return null;
    }

    private static function isEnabled(): bool
    { return trim((string)(Database::value('SELECT value FROM app_settings WHERE key=?',['homeproxy_enabled'])??'0'))==='1'; }
    private static function encryptionConfigured(): bool
    { return strlen(trim((string)Env::get('HOMEPROXY_ENCRYPTION_KEY','')))>=32; }
    private static function assertReady(): void
    {
        if(!self::isEnabled())throw new RuntimeException('Tích hợp HomeProxy hiện đang tạm tắt. Vui lòng liên hệ Admin.');
        if(!self::encryptionConfigured())throw new RuntimeException('HomeProxy chưa được cấu hình khóa mã hóa trên máy chủ. Vui lòng liên hệ Admin.');
    }
    private static function encryptionKey(): string
    {
        $secret=trim((string)Env::get('HOMEPROXY_ENCRYPTION_KEY',''));
        if(strlen($secret)<32)throw new RuntimeException('HOMEPROXY_ENCRYPTION_KEY chưa được cấu hình hợp lệ trong Render Environment.');
        return hash('sha256',$secret,true);
    }
}
