<?php
declare(strict_types=1);
namespace Hendy;
use RuntimeException;
final class ModuleRegistry
{
    private const KEY='/\A[a-z][a-z0-9-]{1,62}\z/';
    private const ROOT=__DIR__.'/../storage/modules';
    public static function stage(array $file,string $module,string $actor): array
    {
        if(!preg_match(self::KEY,$module)) throw new RuntimeException('Mã module không hợp lệ.');
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Tải tệp lên thất bại.');
        if(($file['size']??0)<1||$file['size']>2097152) throw new RuntimeException('Tệp tối đa 2 MB.');
        $tmpName=(string)($file['tmp_name']??'');
        if($tmpName===''||!is_uploaded_file($tmpName)) throw new RuntimeException('Nguồn tệp tải lên không hợp lệ.');
        $name=basename((string)($file['name']??''));$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if(!in_array($ext,['json','md','txt','css','html','svg','png','jpg','jpeg','webp'],true))
            throw new RuntimeException('Loại tệp không được phép; PHP/JS thực thi bị từ chối.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if(!in_array($mime,['application/json','text/plain','text/css','text/html','image/svg+xml','image/png','image/jpeg','image/webp'],true))
            throw new RuntimeException('MIME type không được hỗ trợ.');
        $stored=bin2hex(random_bytes(16)).'.'.$ext;
        $hash=hash_file('sha256',$tmpName);
        if(!is_string($hash)) throw new RuntimeException('Không thể kiểm tra SHA-256 của tệp.');
        if(NeonStorage::configured()){
            $stored='modules/'.$module.'/'.$stored;
            NeonStorage::uploadFile($tmpName,$stored,$mime);
        }else{
            $root=self::ROOT.'/'.$module;
            if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root)) throw new RuntimeException('Không tạo được vùng chờ module.');
            $target=$root.'/'.$stored;
            if(!move_uploaded_file($tmpName,$target)) throw new RuntimeException('Không lưu được tệp vào vùng chờ.');
            chmod($target,0600);
        }
        $row=Database::one(
            "INSERT INTO module_artifacts (module_key,original_name,stored_name,mime_type,sha256,uploaded_by,status)
             VALUES (?,?,?,?,?,?,'pending_review')
             RETURNING id,module_key,original_name,mime_type,sha256,status,created_at",
            [$module,$name,$stored,$mime,$hash,$actor]
        );
        Realtime::publish('module.uploaded',['module'=>$module,'artifactId'=>$row['id']??null,'status'=>'pending_review',
            'storage'=>str_starts_with($stored,'modules/')?'neon':'local']);
        return $row??[];
    }
}
