<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Env.php';
require dirname(__DIR__).'/app/Database.php';
require dirname(__DIR__).'/app/MultiAgentService.php';
use Hendy\Env;
use Hendy\MultiAgentService;
Env::load(dirname(__DIR__).'/.env');
$poll=max(3,min(30,Env::int('MULTI_AGENT_WORKER_POLL_SECONDS',5)));
fwrite(STDOUT,"HENDY Multi-Agent Worker started; poll={$poll}s.\n");
while(true){try{$worked=MultiAgentService::runOnce();if(!$worked)sleep($poll);}catch(Throwable$e){error_log('[multi-agent-worker] '.$e::class.'; retrying.');sleep(max(5,$poll));}}
