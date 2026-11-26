<?php
declare(strict_types=1);
namespace Hendy;
use RuntimeException;
use Throwable;

/** PostgreSQL-backed sequential Multi-Agent orchestration. Final results require Admin approval. */
final class MultiAgentService
{
    private const WORKFLOWS=['research'=>['research'],'content'=>['research','content'],'sales'=>['research','sales'],'video'=>['research','content','video'],'support'=>['support']];
    private const TASK_TYPES=['research','content','sales','video','support','custom'];
    private const REQUIRED_TABLES=['multi_agent_agents','multi_agent_tasks','multi_agent_runs','multi_agent_events','multi_agent_workers'];

    public static function isValidAgentKey(string $key): bool { return preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D',$key)===1; }
    public static function isValidTaskType(string $type): bool { return in_array($type,self::TASK_TYPES,true); }
    public static function workflowForTaskType(string $type,string $customAgentKey=''): array
    {
        if($type==='custom'){if(!self::isValidAgentKey($customAgentKey))throw new RuntimeException('Hãy chọn một Agent hợp lệ cho tác vụ tùy chỉnh.');return[$customAgentKey];}
        if(!isset(self::WORKFLOWS[$type]))throw new RuntimeException('Loại công việc không được hỗ trợ.');
        return self::WORKFLOWS[$type];
    }
    public static function validModelName(string $model): bool { return $model===''||preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{1,79}\z/D',$model)===1; }

    public static function schemaStatus(): array
    {
        try{
            $rows=Database::all("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_name=ANY(CAST(? AS text[]))",['{'.implode(',',self::REQUIRED_TABLES).'}']);
            $present=array_map(static fn(array $r):string=>(string)$r['table_name'],$rows);
            $missing=array_values(array_diff(self::REQUIRED_TABLES,$present));
            return['ready'=>$missing===[],'requiredTables'=>self::REQUIRED_TABLES,'presentTables'=>$present,'missingTables'=>$missing];
        }catch(Throwable){return['ready'=>false,'requiredTables'=>self::REQUIRED_TABLES,'presentTables'=>[],'missingTables'=>self::REQUIRED_TABLES];}
    }
    public static function readiness(): array
    {
        $schema=self::schemaStatus();$enabled=Env::bool('MULTI_AGENT_ENABLED',false);$key=trim((string)Env::get('OPENAI_API_KEY',''))!=='';
        $model=trim((string)Env::get('MULTI_AGENT_OPENAI_MODEL','gpt-6.2-astra'));if(!self::validModelName($model))$model='gpt-6.2-astra';
        $online=false;
        if($schema['ready']){try{$online=(int)Database::value("SELECT COUNT(*) FROM multi_agent_workers WHERE last_seen_at>=NOW()-INTERVAL '90 seconds'")>0;}catch(Throwable){$online=false;}}
        return['schemaReady'=>(bool)$schema['ready'],'missingTables'=>$schema['missingTables'],'workerConfigured'=>$enabled,'apiKeyConfigured'=>$key,'model'=>$model,'workerOnline'=>$online,'ready'=>(bool)$schema['ready']&&$enabled&&$key];
    }
    public static function adminSnapshot(): array
    {
        $ready=self::readiness();$stats=['total'=>0,'queued'=>0,'running'=>0,'awaiting_approval'=>0,'approved'=>0,'rejected'=>0,'failed'=>0];
        if(!$ready['schemaReady'])return['readiness'=>$ready,'stats'=>$stats,'agents'=>[],'tasks'=>[],'runs'=>[],'workers'=>[]];
        foreach(Database::all('SELECT status,COUNT(*) AS total FROM multi_agent_tasks GROUP BY status')as$r){$s=(string)$r['status'];$n=(int)$r['total'];$stats['total']+=$n;if(array_key_exists($s,$stats))$stats[$s]=$n;}
        $agents=Database::all('SELECT agent_key,name,role,description,system_prompt,enabled,model_override,sort_order,updated_at FROM multi_agent_agents ORDER BY sort_order,agent_key');
        foreach($agents as &$agent){$agent['enabled']=in_array($agent['enabled'],[true,1,'1','t','true'],true);}unset($agent);
        $tasks=Database::all('SELECT t.id,t.title,t.task_type,t.prompt,t.status,t.agent_key,t.workflow_steps,t.current_step,t.result_text,t.error_message,t.attempts,t.priority,t.created_by,t.created_at,t.started_at,t.completed_at,t.approved_by,t.approval_note,a.name AS agent_name FROM multi_agent_tasks t LEFT JOIN multi_agent_agents a ON a.agent_key=t.agent_key ORDER BY t.created_at DESC LIMIT 40');
        foreach($tasks as&$t){$steps=$t['workflow_steps']??[];if(is_string($steps)){$v=json_decode($steps,true);$steps=is_array($v)?$v:[];}$t['workflow_steps']=is_array($steps)?$steps:[];$t['result_text']=(string)($t['result_text']??'');$t['error_message']=(string)($t['error_message']??'');}unset($t);
        $runs=Database::all('SELECT r.id,r.task_id,r.agent_key,a.name AS agent_name,r.status,r.model,r.input_tokens,r.output_tokens,r.error_message,r.started_at,r.finished_at FROM multi_agent_runs r LEFT JOIN multi_agent_agents a ON a.agent_key=r.agent_key ORDER BY r.created_at DESC LIMIT 40');
        $workers=Database::all('SELECT worker_id,started_at,last_seen_at,metadata FROM multi_agent_workers ORDER BY last_seen_at DESC LIMIT 10');
        return['readiness'=>$ready,'stats'=>$stats,'agents'=>$agents,'tasks'=>$tasks,'runs'=>$runs,'workers'=>$workers];
    }
    public static function createAgent(array $in,string $actor): array
    {
        self::requireSchema();$key=strtolower(trim((string)($in['agentKey']??'')));$name=trim((string)($in['name']??''));$role=trim((string)($in['role']??''));$desc=trim((string)($in['description']??''));$prompt=trim((string)($in['systemPrompt']??''));$model=trim((string)($in['modelOverride']??''));
        if(!self::isValidAgentKey($key))throw new RuntimeException('Agent Key chỉ được dùng chữ thường, số và dấu gạch dưới.');
        if($name===''||mb_strlen($name)>100||$role===''||mb_strlen($role)>100)throw new RuntimeException('Tên và vai trò Agent phải có từ 1 đến 100 ký tự.');
        if($desc===''||mb_strlen($desc)>300||mb_strlen($prompt)<20||mb_strlen($prompt)>6000)throw new RuntimeException('Mô tả hoặc hướng dẫn Agent không hợp lệ.');
        if(!self::validModelName($model))throw new RuntimeException('Model override không đúng định dạng.');
        $enabled=filter_var($in['enabled']??true,FILTER_VALIDATE_BOOLEAN);
        $row=Database::one('INSERT INTO multi_agent_agents(agent_key,name,role,description,system_prompt,enabled,model_override,sort_order,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?) RETURNING agent_key,name,role,description,system_prompt,enabled,model_override,sort_order,updated_at',[$key,$name,$role,$desc,$prompt,$enabled,$model,100,$actor,$actor]);
        if($row===null)throw new RuntimeException('Không thể tạo Agent.');
        self::event(null,$key,'agent.created',['name'=>$name],$actor);return$row;
    }
    public static function saveAgent(string $key,array $in,string $actor): array
    {
        self::requireSchema();if(!self::isValidAgentKey($key))throw new RuntimeException('Agent Key không hợp lệ.');
        $prompt=trim((string)($in['systemPrompt']??''));$model=trim((string)($in['modelOverride']??''));
        if(mb_strlen($prompt)<20||mb_strlen($prompt)>6000)throw new RuntimeException('Hướng dẫn Agent phải có từ 20 đến 6.000 ký tự.');
        if(!self::validModelName($model))throw new RuntimeException('Model override không đúng định dạng.');
        $enabled=filter_var($in['enabled']??false,FILTER_VALIDATE_BOOLEAN);
        $row=Database::one('UPDATE multi_agent_agents SET system_prompt=?,enabled=?,model_override=?,updated_by=?,updated_at=NOW() WHERE agent_key=? RETURNING agent_key,name,role,description,system_prompt,enabled,model_override,sort_order,updated_at',[$prompt,$enabled,$model,$actor,$key]);
        if($row===null)throw new RuntimeException('Không tìm thấy Agent cần cập nhật.');
        self::event(null,$key,'agent.updated',['enabled'=>$enabled],$actor);return$row;
    }
    public static function createTask(array $in,string $actor): array
    {
        if(!self::readiness()['ready'])throw new RuntimeException('Multi-Agent chưa sẵn sàng. Cần migration, MULTI_AGENT_ENABLED=true và OPENAI_API_KEY.');
        $type=strtolower(trim((string)($in['taskType']??'')));if(!self::isValidTaskType($type))throw new RuntimeException('Loại công việc không hợp lệ.');
        $workflow=self::workflowForTaskType($type,strtolower(trim((string)($in['agentKey']??''))));
        $title=trim((string)($in['title']??''));$prompt=trim((string)($in['prompt']??''));$context=trim((string)($in['context']??''));
        if(mb_strlen($title)<5||mb_strlen($title)>120)throw new RuntimeException('Tiêu đề công việc phải có từ 5 đến 120 ký tự.');
        if(mb_strlen($prompt)<10||mb_strlen($prompt)>8000)throw new RuntimeException('Yêu cầu công việc phải có từ 10 đến 8.000 ký tự.');
        if(mb_strlen($context)>10000)throw new RuntimeException('Tài liệu ngữ cảnh không được vượt quá 10.000 ký tự.');
        $first=(string)$workflow[0];
        foreach($workflow as$a){$v=Database::value('SELECT enabled FROM multi_agent_agents WHERE agent_key=?',[$a]);if(!in_array($v,[true,1,'1','t','true'],true))throw new RuntimeException('Agent '.$a.' đang tắt hoặc không tồn tại.');}
        $id=self::uuid();$priority=max(0,min(100,(int)($in['priority']??50)));
        return Database::transaction(function()use($id,$title,$type,$prompt,$context,$workflow,$first,$actor,$priority):array{
            $row=Database::one("INSERT INTO multi_agent_tasks(id,title,task_type,prompt,context_text,workflow_steps,current_step,agent_key,status,priority,created_by) VALUES(?,?,?,?,?,CAST(? AS JSONB),0,?,'queued',?,?) RETURNING id,title,task_type,status,agent_key,workflow_steps,current_step,created_at",[$id,$title,$type,$prompt,$context,json_encode($workflow,JSON_THROW_ON_ERROR),$first,$priority,$actor]);
            if($row===null)throw new RuntimeException('Không thể tạo công việc Multi-Agent.');
            self::event($id,$first,'task.created',['taskType'=>$type,'workflow'=>$workflow],$actor);return$row;
        });
    }
    public static function approveTask(string $id,string $actor,string $note=''): array
    {
        self::requireSchema();self::assertUuid($id);$note=trim($note);if(mb_strlen($note)>1000)throw new RuntimeException('Ghi chú duyệt không được vượt quá 1.000 ký tự.');
        $r=Database::one("UPDATE multi_agent_tasks SET status='approved',approved_by=?,approval_note=?,updated_at=NOW() WHERE id=? AND status='awaiting_approval' RETURNING id,status,approved_by,approval_note",[$actor,$note,$id]);
        if($r===null)throw new RuntimeException('Chỉ có thể duyệt công việc đang chờ duyệt.');self::event($id,null,'task.approved',['note'=>$note],$actor);return$r;
    }
    public static function rejectTask(string $id,string $actor,string $note=''): array
    {
        self::requireSchema();self::assertUuid($id);$note=trim($note);if(mb_strlen($note)>1000)throw new RuntimeException('Ghi chú từ chối không được vượt quá 1.000 ký tự.');
        $r=Database::one("UPDATE multi_agent_tasks SET status='rejected',approved_by=?,approval_note=?,updated_at=NOW() WHERE id=? AND status='awaiting_approval' RETURNING id,status,approved_by,approval_note",[$actor,$note,$id]);
        if($r===null)throw new RuntimeException('Chỉ có thể từ chối công việc đang chờ duyệt.');self::event($id,null,'task.rejected',['note'=>$note],$actor);return$r;
    }
    public static function retryTask(string $id,string $actor): array
    {
        self::requireSchema();self::assertUuid($id);
        $r=Database::one("UPDATE multi_agent_tasks SET status='queued',attempts=0,claimed_at=NULL,completed_at=NULL,error_message='',updated_at=NOW() WHERE id=? AND status='failed' RETURNING id,status,agent_key,current_step",[$id]);
        if($r===null)throw new RuntimeException('Chỉ có thể chạy lại công việc đang thất bại; nội dung bị từ chối không tự chạy lại.');
        self::event($id,(string)$r['agent_key'],'task.requeued',[],$actor);return$r;
    }
    public static function runOnce(): bool
    {
        if(!self::readiness()['ready'])return false;self::heartbeat();self::recoverStaleTasks();$task=self::claimTask();if($task===null)return false;
        try{
            $previous=Database::value("SELECT output_text FROM multi_agent_runs WHERE task_id=? AND status='completed' ORDER BY finished_at DESC NULLS LAST,created_at DESC LIMIT 1",[$task['id']]);
            $message="CÔNG VIỆC: {$task['title']}\n\nYÊU CẦU: {$task['prompt']}\n\nNGỮ CẢNH DO ADMIN CUNG CẤP:\n".((string)$task['context_text']!==''?(string)$task['context_text']:'(Không có tài liệu bổ sung)')."\n\nĐẦU RA TỪ AGENT TRƯỚC:\n".((string)($previous??'')!==''?(string)$previous:'(Đây là bước đầu tiên)')."\n\nHãy tạo đầu ra rõ ràng, có cấu trúc và nêu rõ điểm chưa được xác minh.";
            $result=self::callModel((string)$task['model'],(string)$task['system_prompt'],$message);
            $steps=$task['workflow_steps'];if(is_string($steps)){$x=json_decode($steps,true);$steps=is_array($x)?$x:[];}if(!is_array($steps))$steps=[];
            $nextIndex=(int)$task['current_step']+1;$next=isset($steps[$nextIndex])?(string)$steps[$nextIndex]:'';
            if($next!==''){$v=Database::value('SELECT enabled FROM multi_agent_agents WHERE agent_key=?',[$next]);if(!in_array($v,[true,1,'1','t','true'],true))throw new RuntimeException('Agent kế tiếp đã bị tắt trong khi quy trình đang chạy.');}
            Database::transaction(function()use($task,$result,$nextIndex,$next,$steps):void{
                Database::run("UPDATE multi_agent_runs SET status='completed',input_tokens=?,output_tokens=?,output_text=?,finished_at=NOW() WHERE id=? AND status='running'",[$result['inputTokens'],$result['outputTokens'],$result['text'],$task['run_id']]);
                if($next!==''){
                    Database::run("UPDATE multi_agent_tasks SET status='queued',agent_key=?,current_step=?,result_text=?,attempts=0,claimed_at=NULL,error_message='',updated_at=NOW() WHERE id=? AND status='running'",[$next,$nextIndex,$result['text'],$task['id']]);
                    self::event((string)$task['id'],$next,'task.step_queued',['completedAgent'=>$task['agent_key'],'nextAgent'=>$next,'step'=>$nextIndex+1,'stepCount'=>count($steps)],null);
                }else{
                    Database::run("UPDATE multi_agent_tasks SET status='awaiting_approval',result_text=?,claimed_at=NULL,completed_at=NOW(),error_message='',updated_at=NOW() WHERE id=? AND status='running'",[$result['text'],$task['id']]);
                    self::event((string)$task['id'],(string)$task['agent_key'],'task.awaiting_approval',['model'=>$result['model'],'inputTokens'=>$result['inputTokens'],'outputTokens'=>$result['outputTokens']],null);
                }
            });
        }catch(Throwable$e){
            $safe='Agent gặp lỗi khi xử lý. Hãy kiểm tra cấu hình model/quyền API và thử lại.';
            try{Database::transaction(function()use($task,$safe):void{
                Database::run("UPDATE multi_agent_runs SET status='failed',error_message=?,finished_at=NOW() WHERE id=? AND status='running'",[$safe,$task['run_id']]);
                Database::run("UPDATE multi_agent_tasks SET status='failed',claimed_at=NULL,completed_at=NOW(),error_message=?,updated_at=NOW() WHERE id=? AND status='running'",[$safe,$task['id']]);
                self::event((string)$task['id'],(string)$task['agent_key'],'task.failed',[],null);
            });}catch(Throwable$record){error_log('[multi-agent] Could not record failed task '.substr((string)$task['id'],0,8).': '.$record::class);}
            error_log('[multi-agent] Task '.substr((string)$task['id'],0,8).' failed: '.$e::class);
        }
        return true;
    }
    private static function claimTask(): ?array
    {
        return Database::transaction(function():?array{
            $t=Database::one("SELECT t.id,t.title,t.task_type,t.prompt,t.context_text,t.workflow_steps,t.current_step,t.agent_key,t.attempts,a.system_prompt,COALESCE(NULLIF(a.model_override,''),?) AS model FROM multi_agent_tasks t JOIN multi_agent_agents a ON a.agent_key=t.agent_key WHERE t.status='queued' AND a.enabled=TRUE ORDER BY t.priority DESC,t.created_at ASC LIMIT 1 FOR UPDATE OF t SKIP LOCKED",[trim((string)Env::get('MULTI_AGENT_OPENAI_MODEL','gpt-6.2-astra'))]);
            if($t===null)return null;$run=self::uuid();
            Database::run("UPDATE multi_agent_tasks SET status='running',attempts=attempts+1,claimed_at=NOW(),started_at=COALESCE(started_at,NOW()),updated_at=NOW() WHERE id=?",[$t['id']]);
            Database::run("INSERT INTO multi_agent_runs(id,task_id,agent_key,status,model,started_at) VALUES(?,?,?,'running',?,NOW())",[$run,$t['id'],$t['agent_key'],$t['model']]);
            self::event((string)$t['id'],(string)$t['agent_key'],'task.agent_started',['attempt'=>(int)$t['attempts']+1,'model'=>(string)$t['model']],null);$t['run_id']=$run;return$t;
        });
    }
    private static function callModel(string $model,string $prompt,string $message): array
    {
        $key=trim((string)Env::get('OPENAI_API_KEY',''));if($key==='')throw new RuntimeException('Missing OpenAI API configuration.');
        if(!self::validModelName($model))throw new RuntimeException('Invalid model configuration.');
        $developer=trim($prompt)."\n\nQUY TẮC HENDY: Chỉ tạo bản nháp từ dữ liệu được cung cấp. Không tuyên bố đã truy cập Internet, gọi API, gửi Telegram, đăng bài, xử lý video thật, thanh toán, hoàn tiền hoặc thay đổi tài khoản. Không bịa nguồn, số liệu, giá, trạng thái hệ thống hoặc kết quả kiểm chứng. Nếu thiếu dữ liệu, ghi rõ giả định và câu hỏi cần bổ sung. Mọi đầu ra cần Admin phê duyệt. Không yêu cầu mật khẩu, token hoặc khóa bí mật.";
        $body=['model'=>$model,'messages'=>[['role'=>'developer','content'=>$developer],['role'=>'user','content'=>$message]],'max_completion_tokens'=>1800,'tool_choice'=>'none','store'=>false];
        $ch=curl_init('https://api.openai.com/v1/chat/completions');if($ch===false)throw new RuntimeException('Cannot initialize model request.');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>100,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$failed=$raw===false;curl_close($ch);
        if($failed||$status<200||$status>=300||!is_string($raw))throw new RuntimeException('Model provider request failed (HTTP '.$status.').');
        $data=json_decode($raw,true);$content=$data['choices'][0]['message']['content']??null;
        if(is_array($content))$content=implode("\n",array_map(static fn(mixed$p):string=>is_array($p)?(string)($p['text']??''):'',$content));
        $text=is_string($content)?trim($content):'';if($text===''||mb_strlen($text)>30000)throw new RuntimeException('Model provider returned an invalid answer.');
        return['text'=>$text,'model'=>(string)($data['model']??$model),'inputTokens'=>max(0,(int)($data['usage']['prompt_tokens']??0)),'outputTokens'=>max(0,(int)($data['usage']['completion_tokens']??0))];
    }
    private static function recoverStaleTasks(): void
    {
        $mins=max(10,min(120,Env::int('MULTI_AGENT_STALE_MINUTES',15)));$max=max(1,min(5,Env::int('MULTI_AGENT_MAX_ATTEMPTS',3)));
        $rows=Database::all("UPDATE multi_agent_tasks SET status=CASE WHEN attempts>=? THEN 'failed' ELSE 'queued' END,claimed_at=NULL,completed_at=CASE WHEN attempts>=? THEN NOW() ELSE NULL END,error_message=CASE WHEN attempts>=? THEN 'Worker bị dừng nhiều lần; Admin có thể kiểm tra và chạy lại.' ELSE 'Worker được khởi động lại; công việc đã trở về hàng chờ.' END,updated_at=NOW() WHERE status='running' AND COALESCE(claimed_at,updated_at)<NOW()-make_interval(mins => ?) RETURNING id,agent_key,status",[$max,$max,$max,$mins]);
        foreach($rows as$r)self::event((string)$r['id'],(string)$r['agent_key'],'task.worker_recovered',['status'=>(string)$r['status']],null);
    }
    private static function heartbeat(): void
    {
        $id=substr(preg_replace('/[^A-Za-z0-9_.:-]/','-',(string)gethostname()).'-'.getmypid(),0,120);
        Database::run("INSERT INTO multi_agent_workers(worker_id,started_at,last_seen_at,metadata) VALUES(?,NOW(),NOW(),CAST(? AS JSONB)) ON CONFLICT(worker_id) DO UPDATE SET last_seen_at=NOW(),metadata=EXCLUDED.metadata",[$id,json_encode(['pid'=>getmypid()],JSON_THROW_ON_ERROR)]);
    }
    private static function event(?string$task,?string$agent,string$type,array$details,?string$actor): void
    {
        Database::run('INSERT INTO multi_agent_events(id,task_id,agent_key,event_type,details,actor_id) VALUES(?,?,?,?,CAST(? AS JSONB),?)',[self::uuid(),$task,$agent,$type,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$actor]);
    }
    private static function requireSchema(): void {if(!self::schemaStatus()['ready'])throw new RuntimeException('Thiếu schema Multi-Agent. Hãy áp migration trước khi sử dụng.');}
    private static function assertUuid(string$id): void {if(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',$id)!==1)throw new RuntimeException('Mã công việc không hợp lệ.');}
    private static function uuid(): string {$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);}
}
