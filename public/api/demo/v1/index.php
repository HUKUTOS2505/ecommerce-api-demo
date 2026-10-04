<?php
declare(strict_types=1);
$root=dirname(__DIR__,4);
$publicRoot=dirname(__DIR__,3);
$appRoot=is_file($root.'/src/bootstrap.php')?$root:$publicRoot.'/private/ecommerce-demo';
require $appRoot.'/src/bootstrap.php';
