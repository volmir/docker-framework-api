<?php
declare(strict_types=1);

namespace App\Controller;

use App\Database\Connection;
use App\Http\Request;
use App\Http\Response;
use Throwable;

final class HealthController
{
    public function __construct(private Connection $connection)
    {
    }

    public function index(Request $request): Response
    {
        try {
            $this->connection->pdo()->query('SELECT 1');
        } catch (Throwable) {
            return Response::json(['status' => 'down'], 503);
        }

        return Response::json(['status' => 'ok']);
    }
}
