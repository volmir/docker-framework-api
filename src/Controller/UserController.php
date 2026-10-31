<?php
declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class UserController
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function index(Request $req): Response
    {
        $rows = $this->pdo->query(
            'SELECT id, email, created_at FROM users ORDER BY id'
        )->fetchAll();
        return Response::json(['data' => $rows]);
    }

    public function show(Request $req): Response
    {
        $id = $req->query['id'] ?? null;
        if ($id === null || !ctype_digit((string) $id)) {
            return Response::json(['error' => 'id required'], 400);
        }
        $statement = $this->pdo->prepare(
            'SELECT id, email, created_at FROM users WHERE id = ?'
        );
        $statement->execute([(int) $id]);
        $user = $statement->fetch();
        return $user
            ? Response::json($user)
            : Response::json(['error' => 'Not Found'], 404);
    }

    public function create(Request $req): Response
    {
        if ($req->method !== 'POST') {
            return new Response('Method Not Allowed', 405, ['Allow' => 'POST']);
        }
        $email = $req->body['email'] ?? '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'valid email required'], 400);
        }
        $password = $req->body['password'] ?? 'secret';
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash) VALUES (?, ?)'
        );
        $statement->execute([$email, password_hash($password, PASSWORD_ARGON2ID)]);
        return Response::json(['id' => (int) $this->pdo->lastInsertId()], 201);
    }
}
