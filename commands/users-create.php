<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Http\Validator;
use App\Users\Users;

/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'users:create',
    'description' => 'Create a user; read the password without echo or from STDIN, never from arguments',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        if (count($args) !== 1) {
            throw new RuntimeException('Usage: users:create <email> (password via terminal or STDIN)');
        }
        $data = Validator::validate(['email' => $args[0]], ['email' => 'required|string|email|max:255']);
        $tty = stream_isatty(STDIN);
        $state = '';
        if ($tty) {
            exec('stty -g 2>/dev/null', $settings, $status);
            if ($status !== 0 || ($settings[0] ?? '') === '') {
                throw new RuntimeException('Cannot read terminal settings; pipe the password through STDIN');
            }
            $state = $settings[0];
            exec('stty -echo 2>/dev/null', $output, $status);
            if ($status !== 0) {
                throw new RuntimeException('Cannot disable terminal echo; pipe the password through STDIN');
            }
            fwrite(STDERR, 'Password: ');
        }
        try {
            $password = fgets(STDIN);
        } finally {
            if ($tty) {
                exec('stty ' . escapeshellarg($state) . ' 2>/dev/null');
                fwrite(STDERR, PHP_EOL);
            }
        }
        $data = Validator::validate(
            $data + ['password' => $password === false ? null : rtrim($password, "\r\n")],
            ['email' => 'required|string|email|max:255', 'password' => 'required|string|min:8|max:256'],
        );
        echo json_encode((new Users($connection))->create($data['email'], $data['password']), JSON_THROW_ON_ERROR) . PHP_EOL;
        return 0;
    },
];

return $command;
