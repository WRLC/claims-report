<?php
require __DIR__.'/../src/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$sql=file_get_contents(__DIR__.'/../schema.sql');
foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql))) as $statement) db()->exec($statement);
echo "Schema installed.\n";
