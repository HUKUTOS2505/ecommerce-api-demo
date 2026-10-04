<?php
declare(strict_types=1);
use Demo\{Api,ApiError,SandboxStore};
require_once __DIR__.'/ApiError.php';
require_once __DIR__.'/Catalog.php';
require_once __DIR__.'/SandboxStore.php';
require_once __DIR__.'/Api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
header('Vary: Cookie');
$started=hrtime(true);
try {
    $host=$_SERVER['HTTP_HOST']??'localhost';
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    $expected=(str_starts_with($host,'nikitazalesskiy.ru')?'https://':((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https://':'http://')).$host;
    if($origin!==''&&$origin!==$expected)throw new ApiError(403,'origin_not_allowed','Cross-origin requests are not allowed.');
    if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)throw new ApiError(413,'payload_too_large','The demo accepts at most 8 KB per request.');
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    $prefix='/api/demo/v1';
    if(!str_starts_with($path,$prefix.'/'))throw new ApiError(404,'endpoint_not_found','API endpoint not found.');
    $route=substr($path,strlen($prefix));
    $store=new SandboxStore(getenv('DEMO_DATA_DIR')?:dirname(__DIR__).'/var');
    header('X-CSRF-Token: '.$store->csrf);
    [$status,$payload]=(new Api($store))->dispatch($_SERVER['REQUEST_METHOD'],$route);
} catch(ApiError $e) {
    $status=$e->status;$payload=['error'=>['code'=>$e->errorCode,'message'=>$e->getMessage(),'details'=>(object)$e->details]];
    if($status===429)header('Retry-After: 60');
    if($status===405)header('Allow: '.implode(', ',$e->details['allowed_methods']));
} catch(Throwable $e) {
    error_log('Ecommerce demo failed: '.get_class($e));
    $status=503;$payload=['error'=>['code'=>'temporarily_unavailable','message'=>'The sandbox is temporarily unavailable. Please try again later.','details'=>(object)[]]];
}
header('Server-Timing: api;dur='.round((hrtime(true)-$started)/1e6,2));
http_response_code($status);
echo json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
