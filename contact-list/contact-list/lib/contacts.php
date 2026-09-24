<?php

declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param array<string, string> $errors
 */
function field_attrs(string $name, array $errors, bool $hasHint = false): string
{
    if (preg_match('/^[a-z_]+$/', $name) !== 1) {
        return 'aria-required="true"';
    }

    $describedBy = [];
    if ($hasHint) {
        $describedBy[] = $name . '-hint';
    }
    if (isset($errors[$name])) {
        $describedBy[] = $name . '-error';
    }

    $parts = ['aria-required="true"'];
    if (isset($errors[$name])) {
        $parts[] = 'aria-invalid="true"';
    }
    if ($describedBy !== []) {
        $parts[] = 'aria-describedby="' . implode(' ', $describedBy) . '"';
    }

    return implode(' ', $parts);
}

function flash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = [
            'message' => $message,
            'type' => $type === 'error' ? 'error' : 'ok',
        ];
        return null;
    }

    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $current = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $current;
}

function redirect(): void
{
    header('Location: index.php', true, 303);
    exit;
}

function unavailable(Throwable $exception): void
{
    error_log($exception->getMessage());
    http_response_code(500);
    echo 'The contact list is unavailable right now.';
    exit;
}

function csrf_valid(): bool
{
    $token = $_POST['csrf'] ?? '';

    return is_string($token)
        && is_string($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('The pdo_sqlite PHP extension is required.');
    }

    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the data directory.');
    }

    $pdo = new PDO('sqlite:' . $dir . DIRECTORY_SEPARATOR . 'contacts.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            email TEXT NOT NULL COLLATE NOCASE UNIQUE,
            contact_number TEXT NOT NULL
        )'
    );

    return $pdo;
}

function text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    return strlen($value);
}

function parse_id(string $raw): ?int
{
    if (preg_match('/^[1-9]\d*$/', $raw) !== 1) {
        return null;
    }

    $id = (int) $raw;
    if ($id < 1 || (string) $id !== $raw) {
        return null;
    }

    return $id;
}

/**
 * @param array<string, mixed> $input
 * @return array{data: array{first_name: string, last_name: string, email: string, contact_number: string}, errors: array<string, string>}
 */
function validate_contact(array $input, ?int $ignoreId = null): array
{
    $data = [
        'first_name' => trim((string) ($input['first_name'] ?? '')),
        'last_name' => trim((string) ($input['last_name'] ?? '')),
        'email' => trim((string) ($input['email'] ?? '')),
        'contact_number' => trim((string) ($input['contact_number'] ?? '')),
    ];
    $errors = [];

    if ($data['first_name'] === '') {
        $errors['first_name'] = 'First name is required.';
    } elseif (text_length($data['first_name']) > 50) {
        $errors['first_name'] = 'First name must be 50 characters or fewer.';
    }

    if ($data['last_name'] === '') {
        $errors['last_name'] = 'Last name is required.';
    } elseif (text_length($data['last_name']) > 50) {
        $errors['last_name'] = 'Last name must be 50 characters or fewer.';
    }

    if ($data['email'] === '') {
        $errors['email'] = 'Email is required.';
    } elseif (text_length($data['email']) > 50) {
        $errors['email'] = 'Email must be 50 characters or fewer.';
    } elseif (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (email_exists($data['email'], $ignoreId)) {
        $errors['email'] = 'A contact with this email already exists.';
    }

    if ($data['contact_number'] === '') {
        $errors['contact_number'] = 'Contact number is required.';
    } elseif (preg_match('/^\d{1,15}$/', $data['contact_number']) !== 1) {
        $errors['contact_number'] = 'Contact number must contain only digits and be at most 15 digits.';
    }

    return ['data' => $data, 'errors' => $errors];
}

function email_exists(string $email, ?int $ignoreId = null): bool
{
    $sql = 'SELECT 1 FROM contacts WHERE email = :email';
    $params = ['email' => $email];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :id';
        $params['id'] = $ignoreId;
    }

    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);

    return $stmt->fetchColumn() !== false;
}

function is_unique_violation(PDOException $exception): bool
{
    $state = $exception->errorInfo[0] ?? '';

    return $state === '23000' || str_contains($exception->getMessage(), 'UNIQUE');
}

/**
 * @return list<array{id: int|string, first_name: string, last_name: string, email: string, contact_number: string}>
 */
function all_contacts(): array
{
    $rows = db()->query(
        'SELECT id, first_name, last_name, email, contact_number
         FROM contacts
         ORDER BY last_name COLLATE NOCASE ASC, first_name COLLATE NOCASE ASC, id ASC'
    )->fetchAll();

    return array_values($rows);
}

/**
 * @return array{id: int|string, first_name: string, last_name: string, email: string, contact_number: string}|null
 */
function find_contact(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, first_name, last_name, email, contact_number
         FROM contacts
         WHERE id = :id'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * @param array{first_name: string, last_name: string, email: string, contact_number: string} $data
 */
function create_contact(array $data): void
{
    $stmt = db()->prepare(
        'INSERT INTO contacts (first_name, last_name, email, contact_number)
         VALUES (:first_name, :last_name, :email, :contact_number)'
    );
    $stmt->execute($data);
}

/**
 * @param array{first_name: string, last_name: string, email: string, contact_number: string} $data
 */
function update_contact(int $id, array $data): void
{
    $data['id'] = $id;
    $stmt = db()->prepare(
        'UPDATE contacts
         SET first_name = :first_name,
             last_name = :last_name,
             email = :email,
             contact_number = :contact_number
         WHERE id = :id'
    );
    $stmt->execute($data);
}

function delete_contact(int $id): void
{
    $stmt = db()->prepare('DELETE FROM contacts WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

