<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/api/demo/v1/')){require __DIR__.'/public/api/demo/v1/index.php';return true;}
$public=realpath(__DIR__.'/public');$target=realpath($public.rawurldecode($path));
if($target!==false && ($target===$public || str_starts_with($target,$public.DIRECTORY_SEPARATOR))) {
    if(is_file($target))return false;
    if(is_dir($target)&&is_file($target.'/index.html')){header('Content-Type: text/html; charset=utf-8');readfile($target.'/index.html');return true;}
}
http_response_code(404);header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html lang="en"><title>Page not found</title><h1>Page not found</h1><a href="/en/lab/ecommerce-api/">Open the playground</a></html>';return true;
