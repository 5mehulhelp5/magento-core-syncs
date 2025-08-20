<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Database\DatabaseManager;

// Create and bind the container
$container = new Container();
Container::setInstance($container);
Facade::setFacadeApplication($container);

// Create Capsule with the same container
$capsule = new Capsule($container);

// Add default connection
$env = include dirname(__DIR__, 2) . '/../box-v0.1/app/etc/env.php';
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $env['db']['connection']['default']['host'],
    'database'  => $env['db']['connection']['default']['dbname'],
    'username'  => $env['db']['connection']['default']['username'],
    'password'  => $env['db']['connection']['default']['password'],
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
]);

// Add legacy connection
$legacy = require __DIR__ . '/legacy_database.php';
$capsule->addConnection($legacy, 'legacy');
// NEW: expose admin path as a global constant
if (!defined('ADMIN_PATH')) {
    $adminFromConfig = isset($legacy['app']['admin_path']) ? trim($legacy['app']['admin_path'], "/ \t") : 'admin';
    define('ADMIN_PATH', $adminFromConfig !== '' ? $adminFromConfig : 'admin');
}
// Boot global Eloquent
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Bind "db" so Facade can resolve it
$container->instance('db', $capsule->getDatabaseManager());
