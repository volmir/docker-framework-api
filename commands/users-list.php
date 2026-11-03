<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Http\Validator;
use App\Users\Users;

/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'users:list',
    'description' => 'List users as JSON: users:list [--limit=20] (1-100)',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        if (count($args) > 1 || (isset($args[0]) && !str_starts_with($args[0], '--limit='))) {
            throw new RuntimeException('Usage: users:list [--limit=20]');
        }
        $data = Validator::validate(['limit' => isset($args[0]) ? substr($args[0], 8) : 20], ['limit' => 'required|int|min:1|max:100']);
        echo json_encode((new Users($connection))->list($data['limit'], 0), JSON_THROW_ON_ERROR) . PHP_EOL;
        return 0;
    },
];

return $command;
