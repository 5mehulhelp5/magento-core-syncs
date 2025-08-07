<?php
use Illuminate\Database\Capsule\Manager as Capsule;

$env = include dirname(__DIR__, 2) . '/../box-v0.1/app/etc/env.php';

$capsule = new Capsule();
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $env['db']['connection']['default']['host'],
    'database'  => $env['db']['connection']['default']['dbname'],
    'username'  => $env['db']['connection']['default']['username'],
    'password'  => $env['db']['connection']['default']['password'],
    'charset'   => 'utf8',
    'collation' => 'utf8_unicode_ci',
    'prefix'    => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();