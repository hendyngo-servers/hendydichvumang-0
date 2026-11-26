<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Env.php';
require dirname(__DIR__).'/app/Database.php';
require dirname(__DIR__).'/app/MultiAgentService.php';
use Hendy\Env;
use Hendy\MultiAgentService;
Env::load(dirname(__DIR__).'/.env');
if(!Env::bool('MULTI_AGENT_ENABLED',false)){fwrite(STDOUT,"Multi-Agent disabled.\n");exit(0);}
$s=MultiAgentService::readiness();
if(!$s['schemaReady']){fwrite(STDERR,"Apply migration 20261120_multi_agent_control_center.sql before enabling Multi-Agent.\n");exit(1);}
if(!$s['apiKeyConfigured']){fwrite(STDERR,"MULTI_AGENT_ENABLED=true requires OPENAI_API_KEY in the server-side environment file.\n");exit(1);}
fwrite(STDOUT,"Multi-Agent preflight passed.\n");
