<?php
declare(strict_types=1);

namespace App\Controller;

use App\Database\Connection;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Validator;
use PDO;
use PDOException;

final class UserController
{
    public function __construct(private Connection $connection)
    {
    }

    public function index(Request $request): Response
    {
        $query = Validator::validate($request->query, [
            'limit' => 'nullable|int|min:1|max:100', 'offset' => 'nullable|int|min:0',
        ]);
        $limit = $query['limit'] ?? 20;
        $offset = $query['offset'] ?? 0;
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, email, created_at FROM users ORDER BY id LIMIT ? OFFSET ?'
        );
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->bindValue(2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return Response::json(['data' => $statement->fetchAll(), 'meta' => ['limit' => $limit, 'offset' => $offset]]);
    }

    public function show(Request $request): Response
    {
        $query = Validator::validate($request->query, ['id' => 'required|int|min:1']);
        $statement = $this->connection->pdo()->prepare('SELECT id, email, created_at FROM users WHERE id = ?');
        $statement->execute([$query['id']]);
        $user = $statement->fetch();
        if ($user === false) {
            throw new HttpException(404, 'not_found', 'User not found');
        }
        return Response::json($user);
    }

    public function create(Request $request): Response
    {
        $data = Validator::validate($request->body, [
            'email' => 'required|string|email|max:255', 'password' => 'required|string|min:8|max:256',
        ]);
        $pdo = $this->connection->pdo();
        $statement = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        try {
            $statement->execute([$data['email'], password_hash($data['password'], PASSWORD_ARGON2ID)]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new HttpException(409, 'email_taken', 'Email is already taken');
            }
            throw $error;
        }
        $id = (int) $pdo->lastInsertId();
        $statement = $pdo->prepare('SELECT id, email, created_at FROM users WHERE id = ?');
        $statement->execute([$id]);
        return Response::json(['data' => $statement->fetch()], 201)->withHeader('Location', '/users/show?id=' . $id);
    }
}
