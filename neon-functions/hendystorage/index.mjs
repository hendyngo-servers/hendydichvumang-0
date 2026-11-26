import { createHash, createHmac, timingSafeEqual } from 'node:crypto';
const BUCKET = 'uploads';
const MAX_EXPIRES_SECONDS = 900;
const TYPES = new Set(['application/json','text/plain','text/css','text/html','image/svg+xml','image/png','image/jpeg','image/webp','image/gif','application/octet-stream','application/zip','application/vnd.android.package-archive','application/vnd.microsoft.portable-executable','application/x-msdownload','application/x-dosexec','application/x-executable','application/x-elf','application/x-msi','application/x-ole-storage','application/x-apple-diskimage','application/x-xar','application/x-apple-installer-package','application/x-iso9660-image','application/x-debian-package','application/vnd.debian.binary-package','application/x-rpm','application/gzip','application/x-gzip','audio/mpeg','audio/wav','audio/x-wav','audio/flac','audio/mp4','audio/aac','audio/ogg','audio/opus']);
const json=(body,status=200)=>new Response(JSON.stringify(body),{status,headers:{'content-type':'application/json; charset=utf-8','cache-control':'no-store','x-content-type-options':'nosniff'}});
const hmac=(key,value,encoding)=>createHmac('sha256',key).update(value).digest(encoding);
const sha256Hex=(value)=>createHash('sha256').update(value).digest('hex');
const enc=value=>encodeURIComponent(String(value)).replace(/[!'()*]/g,c=>'%'+c.charCodeAt(0).toString(16).toUpperCase());
function query(entries){return entries.map(([k,v])=>[enc(k),enc(v)]).sort(([a,x],[b,y])=>a.localeCompare(b)||x.localeCompare(y)).map(([k,v])=>k+'='+v).join('&');}
function allowedKey(key){
 if(typeof key!=='string'||key.length>1024)return false;
 const uuid='[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
 return /^modules\/[a-z][a-z0-9-]{1,62}\/[A-Za-z0-9_-]{16,64}\.(json|md|txt|css|html|svg|png|jpg|jpeg|webp)$/.test(key)
 || /^store-releases\/[1-9][0-9]{0,17}\/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(apk|exe|msi|msix|zip|dmg|pkg|ipa|appimage|deb|rpm|tar\.gz)$/i.test(key)
 || /^website\/screenshots\/[a-f0-9]{32}\.(jpg|png|webp|gif)$/.test(key)
 || new RegExp('^ai-cover/sources/'+uuid+'/source\\.(mp3|wav|flac|m4a|aac|ogg|opus)$','i').test(key)
 || new RegExp('^ai-cover/results/'+uuid+'/cover\\.mp3$','i').test(key)
 || /^ai-cover\/models\/[a-z0-9][a-z0-9_-]{1,47}\/voice(-[a-f0-9]{16})?\.(pth|index)$/.test(key);
}
export function buildPresignedUrl({method,key,contentType='',expiresIn=300,env=process.env,now=new Date()}){
 if(!['GET','PUT','DELETE'].includes(method))throw new Error('Unsupported operation');
 if(!allowedKey(key))throw new Error('Invalid object key');
 if(method==='PUT'&&!TYPES.has(contentType))throw new Error('Unsupported content type');
 const endpoint=env.AWS_ENDPOINT_URL_S3, accessKey=env.AWS_ACCESS_KEY_ID, secretKey=env.AWS_SECRET_ACCESS_KEY, region=env.AWS_REGION;
 if(![endpoint,accessKey,secretKey,region].every(v=>typeof v==='string'&&v.length>0))throw new Error('Object storage is not configured');
 const expires=Math.max(1,Math.min(MAX_EXPIRES_SECONDS,Number(expiresIn)||300));
 const url=new URL(endpoint);
 if(url.protocol!=='https:'||!url.hostname.includes('.storage.')||!url.hostname.endsWith('.neon.tech'))throw new Error('Invalid Neon storage endpoint');
 const objectPath=BUCKET+'/'+key.split('/').map(enc).join('/');
 url.pathname=url.pathname.replace(/\/$/,'')+'/'+objectPath;
 const amzDate=now.toISOString().replace(/[:-]|\.\d{3}/g,'');
 const date=amzDate.slice(0,8), scope=date+'/'+region+'/s3/aws4_request';
 const signedHeaders=method==='PUT'?'content-type;host':'host';
 const params=[['X-Amz-Algorithm','AWS4-HMAC-SHA256'],['X-Amz-Credential',accessKey+'/'+scope],['X-Amz-Date',amzDate],['X-Amz-Expires',String(expires)],['X-Amz-SignedHeaders',signedHeaders]];
 const canonicalQuery=query(params);
 const canonicalHeaders=method==='PUT'?'content-type:'+contentType.trim()+'\nhost:'+url.host+'\n':'host:'+url.host+'\n';
 const canonicalRequest=[method,url.pathname,canonicalQuery,canonicalHeaders,signedHeaders,'UNSIGNED-PAYLOAD'].join('\n');
 const toSign=['AWS4-HMAC-SHA256',amzDate,scope,sha256Hex(canonicalRequest)].join('\n');
 const signingKey=hmac(hmac(hmac(hmac('AWS4'+secretKey,date),region),'s3'),'aws4_request');
 const signature=hmac(signingKey,toSign,'hex');
 return {url:url.origin+url.pathname+'?'+canonicalQuery+'&X-Amz-Signature='+signature,method,headers:method==='PUT'?{'Content-Type':contentType}:{},expiresIn:expires};
}
function authorized(request,secret){
 const a=Buffer.from(request.headers.get('authorization')||''),b=Buffer.from('Bearer '+secret);
 return a.length===b.length&&timingSafeEqual(a,b);
}
export default {async fetch(request){
 const url=new URL(request.url);
 if(request.method==='GET'&&url.pathname.endsWith('/healthz')){
  try{buildPresignedUrl({method:'GET',key:'modules/health/0123456789abcdef0123456789abcdef.txt',expiresIn:60});return json({status:'ok',service:'hendy-neon-storage',storage:'ready'});}
  catch{return json({status:'degraded',service:'hendy-neon-storage',storage:'unavailable'},503);}
 }
 const website=url.pathname.endsWith('/website/presign');
 const aiCover=!website&&url.pathname.endsWith('/ai-cover/presign');
 const storeRelease=!website&&!aiCover&&url.pathname.endsWith('/store-release/presign');
 const bot=!website&&!aiCover&&!storeRelease&&url.pathname.endsWith('/presign');
 if(request.method!=='POST'||(!website&&!aiCover&&!storeRelease&&!bot))return json({error:'Not found'},404);
 const secret=website?(process.env.HENDY_WEBSITE_STORAGE_KEY||''):(process.env.HENDY_FUNCTION_API_KEY||'');
 if(!secret||!authorized(request,secret))return json({error:'Unauthorized'},401);
 if(Number(request.headers.get('content-length')||0)>4096)return json({error:'Payload too large'},413);
 try{
  const input=await request.json(),action=input&&input.action,method=action==='upload'?'PUT':action==='download'?'GET':action==='delete'?'DELETE':'';
  if(!method)return json({error:'Unsupported action'},400);
  const key=String(input.key||'');
  if(website&&!key.startsWith('website/screenshots/'))return json({error:'Invalid website object key'},400);
  if(bot&&!key.startsWith('modules/'))return json({error:'Invalid module object key'},400);
  if(storeRelease&&!key.startsWith('store-releases/'))return json({error:'Invalid store release object key'},400);
  if(aiCover&&!key.startsWith('ai-cover/'))return json({error:'Invalid AI Cover object key'},400);
  if(aiCover&&method==='PUT'&&!['application/octet-stream','audio/mpeg','audio/wav','audio/x-wav','audio/flac','audio/mp4','audio/aac','audio/ogg','audio/opus'].includes(String(input.contentType||'')))return json({error:'Unsupported AI Cover content type'},400);
  return json(buildPresignedUrl({method,key,contentType:method==='PUT'?String(input.contentType||''):'',expiresIn:input.expiresIn}));
 }catch(error){const message=error instanceof Error?error.message:'Invalid request';const bad=/invalid|unsupported/i.test(message);return json({error:bad?message:'Storage signing is unavailable'},bad?400:503);}
}};
