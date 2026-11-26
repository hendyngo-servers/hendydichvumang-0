import test from 'node:test';
import assert from 'node:assert/strict';
import { buildPresignedUrl } from '../neon-functions/hendystorage/index.mjs';
const env={AWS_ENDPOINT_URL_S3:'https://br-example.storage.c-3.ap-southeast-1.aws.neon.tech',AWS_ACCESS_KEY_ID:'nak_test_access',AWS_SECRET_ACCESS_KEY:'test-secret-for-unit-tests-only',AWS_REGION:'ap-southeast-1'};
const moduleKey='modules/proxy/0123456789abcdef0123456789abcdef.json';
const websiteKey='website/screenshots/0123456789abcdef0123456789abcdef.jpg';
const now=new Date('2026-11-12T08:00:00.000Z');
test('signs private Bot module PUT requests',()=>{
 const r=buildPresignedUrl({method:'PUT',key:moduleKey,contentType:'application/json',env,now});
 const u=new URL(r.url);assert.equal(u.protocol,'https:');assert.equal(u.pathname,'/uploads/'+moduleKey);
 assert.equal(u.searchParams.get('X-Amz-SignedHeaders'),'content-type;host');assert.equal(u.searchParams.get('X-Amz-Expires'),'300');
 assert.match(u.searchParams.get('X-Amz-Signature')||'',/^[a-f0-9]{64}$/);assert.deepEqual(r.headers,{'Content-Type':'application/json'});
});
test('signs Website screenshot PUT requests',()=>{
 const r=buildPresignedUrl({method:'PUT',key:websiteKey,contentType:'image/jpeg',env,now});
 assert.equal(new URL(r.url).pathname,'/uploads/'+websiteKey);assert.equal(r.method,'PUT');
});
test('signs GET and DELETE requests and caps URL lifetime',()=>{
 const get=buildPresignedUrl({method:'GET',key:websiteKey,env,now,expiresIn:1200});
 const del=buildPresignedUrl({method:'DELETE',key:websiteKey,env,now});
 assert.equal(new URL(get.url).searchParams.get('X-Amz-Expires'),'900');assert.equal(del.method,'DELETE');
});
const storeReleaseKey='store-releases/42/12345678-1234-4123-8123-123456789abc.apk';
test('signs private HENDY Store release PUT requests',()=>{
 const r=buildPresignedUrl({method:'PUT',key:storeReleaseKey,contentType:'application/vnd.android.package-archive',env,now});
 const u=new URL(r.url);
 assert.equal(u.protocol,'https:');
 assert.equal(u.pathname,'/uploads/'+storeReleaseKey);
 assert.equal(u.searchParams.get('X-Amz-SignedHeaders'),'content-type;host');
 assert.deepEqual(r.headers,{'Content-Type':'application/vnd.android.package-archive'});
});
test('restricts Store release object keys to product IDs and UUID filenames',()=>{
 assert.throws(()=>buildPresignedUrl({method:'GET',key:'store-releases/../42/payload.apk',env,now}),/Invalid object key/);
 assert.throws(()=>buildPresignedUrl({method:'GET',key:'store-releases/0/12345678-1234-4123-8123-123456789abc.apk',env,now}),/Invalid object key/);
 assert.throws(()=>buildPresignedUrl({method:'GET',key:'store-releases/42/plain.apk',env,now}),/Invalid object key/);
});
test('rejects path traversal, PHP objects, bad MIME and non-Neon endpoints',()=>{
 assert.throws(()=>buildPresignedUrl({method:'GET',key:'../private.txt',env,now}),/Invalid object key/);
 assert.throws(()=>buildPresignedUrl({method:'PUT',key:moduleKey,contentType:'application/x-php',env,now}),/Unsupported content type/);
 assert.throws(()=>buildPresignedUrl({method:'GET',key:websiteKey.replace('.jpg','.php'),env,now}),/Invalid object key/);
 assert.throws(()=>buildPresignedUrl({method:'GET',key:moduleKey,env:{...env,AWS_ENDPOINT_URL_S3:'https://evil.example'},now}),/Invalid Neon storage endpoint/);
});
