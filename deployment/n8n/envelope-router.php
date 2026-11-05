<?php
// Separate PHP listener prevents a send worker from calling back into itself.
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if(!in_array($path,['/up','/api/sales/gmail-envelope'],true)){http_response_code(404);exit;}
require dirname(__DIR__,2).'/public/index.php';
