<?php
return [
    'driver'    => 'mysql',
    'host'      => '127.0.0.1',
    'port'      => 3306,
    'database'  => 'box_connector',
    'username'  => 'root',
    'password'  => '0300',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
    'strict'    => false,

    // NEW: no more DB lookup for admin path
    'app' => [
        'admin_path' => 'secret_admin', // change this to rotate the entry path
    ],
];
