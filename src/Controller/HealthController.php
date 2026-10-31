<?php
declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class HealthController
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function index(Request $req): Response
    {
        $this->pdo->query('SELECT 1');
        return Response::json(['status' => 'ok']);
    }
}
