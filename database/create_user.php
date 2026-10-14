<?php
declare(strict_types=1);

// Usage: php database/create_user.php you@example.com 'a-strong-password'

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Pims\Database;

[$script, $email, $password] = array_pad($argv, 3, null);

if (!$email || !$password) {
    fwrite(STDERR, "Usage: php database/create_user.php <email> <password>\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

try {
    $stmt = Database::connection()->prepare(
        'INSERT INTO users (email, password_hash) VALUES (:email, :hash)'
    );
    $stmt->execute([
        ':email' => $email,
        ':hash'  => password_hash($password, PASSWORD_DEFAULT),
    ]);
    echo "User created: {$email}\n";
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fwrite(STDERR, "A user with that email already exists.\n");
        exit(1);
    }
    throw $e;
}
