<?php
declare(strict_types=1);

namespace Pims;

use Pims\Http\HttpException;
use Pims\Http\Request;
use Pims\Repositories\UserRepository;

/** Session handling, login state, and CSRF protection. */
final class Auth
{
    private const IDLE_TIMEOUT = 7200; // 2 hours

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name('pims_session');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Expire idle sessions.
        $now = time();
        if (isset($_SESSION['last_activity']) && $now - $_SESSION['last_activity'] > self::IDLE_TIMEOUT) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = $now;

        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function userId(): ?int
    {
        return self::check() ? (int) $_SESSION['user_id'] : null;
    }

    /** Current user as a safe public array, or null. */
    public static function user(): ?array
    {
        $id = self::userId();
        if ($id === null) {
            return null;
        }
        $user = UserRepository::findById($id);
        if ($user === null) { // user was deleted while logged in
            self::logout();
            return null;
        }
        return ['id' => (int) $user['id'], 'email' => $user['email']];
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);            // prevent session fixation
        $_SESSION['user_id'] = $userId;
        $_SESSION['csrf'] = bin2hex(random_bytes(32)); // rotate token on privilege change
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    /** Require a valid CSRF header on state-changing requests. */
    public static function verifyCsrf(Request $request): void
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $sent = $request->header('X-CSRF-Token') ?? '';
        if ($sent === '' || !hash_equals(self::csrfToken(), $sent)) {
            throw new HttpException(403, 'csrf_invalid', 'Missing or invalid CSRF token.');
        }
    }
}
