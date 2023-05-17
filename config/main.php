<?php

return [
    'db' => [
        'develop' => [
            'host' => 'db',
            'port' => 3306,
            'username' => 'user',
            'password' => 'password',
            'dbname' => 'no_framework_api',
        ],
        'production' => [
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root',
            'password' => '',
            'dbname' => 'production',
        ],
        'test' => [
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root',
            'password' => '',
            'dbname' => 'test',
        ],                
    ], 
];
