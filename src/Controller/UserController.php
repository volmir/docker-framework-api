<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database\Connection;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Validator;
use App\Users\EmailTaken;
use App\Users\Users;

final class UserController
{
    private Users $users;

    public function __construct(Connection $connection)
    {
        $this->users = new Users($connection);
    }

    public function index(Request $request): Response
    {
        $query = Validator::validate($request->query, [
            'limit' => 'nullable|int|min:1|max:100', 'offset' => 'nullable|int|min:0',
        ]);
        $limit = $query['limit'] ?? 20;
        $offset = $query['offset'] ?? 0;
        return Response::json(['data' => $this->users->list($limit, $offset), 'meta' => ['limit' => $limit, 'offset' => $offset]]);
    }

    public function show(Request $request): Response
    {
        $query = Validator::validate($request->query, ['id' => 'required|int|min:1']);
        $user = $this->users->find($query['id']);
        if ($user === null) {
            throw new HttpException(404, 'not_found', 'User not found');
        }
        return Response::json($user);
    }

    public function create(Request $request): Response
    {
        $data = Validator::validate($request->body, [
            'email' => 'required|string|email|max:255', 'password' => 'required|string|min:8|max:256',
        ]);
        try {
            $user = $this->users->create($data['email'], $data['password']);
        } catch (EmailTaken) {
            throw new HttpException(409, 'email_taken', 'Email is already taken');
        }
        return Response::json(['data' => $user], 201)->withHeader('Location', '/users/show?id=' . $user['id']);
    }
}
