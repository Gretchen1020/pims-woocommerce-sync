<?php
declare(strict_types=1);

namespace Pims\Controllers;

use Pims\Auth;
use Pims\Http\HttpException;
use Pims\Http\Request;
use Pims\Http\Response;
use Pims\Repositories\UserRepository;

final class AuthController
{
    private static ?string $dummyHash = null;

    /** GET /api/auth/me. Always 200; user is null when logged out. Also hands the client its CSRF token. */
    public static function me(Request $request, array $params): void
    {
        Response::json(['user' => Auth::user(), 'csrf_token' => Auth::csrfToken()]);
    }

    /** POST /api/auth/login */
    public static function login(Request $request, array $params): void
    {
        $body = $request->json();
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        $fields = [];
        if ($email === '') {
            $fields['email'] = 'Email is required.';
        }
        if ($password === '') {
            $fields['password'] = 'Password is required.';
        }
        if ($fields) {
            throw new HttpException(422, 'validation_failed', 'Email and password are required.', $fields);
        }

        $user = UserRepository::findByEmail($email);

        // Always run a hash check so response time does not reveal whether the email exists.
        self::$dummyHash ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
        $valid = password_verify($password, $user['password_hash'] ?? self::$dummyHash);

        if ($user === null || !$valid) {
            throw new HttpException(401, 'invalid_credentials', 'Invalid email or password.');
        }

        Auth::login((int) $user['id']);
        Response::json([
            'user'       => ['id' => (int) $user['id'], 'email' => $user['email']],
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    /** POST /api/auth/logout */
    public static function logout(Request $request, array $params): void
    {
        Auth::logout();
        Response::json(['ok' => true, 'csrf_token' => Auth::csrfToken()]);
    }
}
