<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/contacts.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_name('contact_list');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/**
 * @param array<string, mixed> $payload
 */
function json_response(int $status, array $payload): void
{
    $payload['csrf'] = (string) $_SESSION['csrf'];
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @param array<string, mixed> $row
 * @return array{id: int, first_name: string, last_name: string, email: string, contact_number: string}
 */
function contact_json(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'first_name' => (string) $row['first_name'],
        'last_name' => (string) $row['last_name'],
        'email' => (string) $row['email'],
        'contact_number' => (string) $row['contact_number'],
    ];
}

/**
 * @return list<array{id: int, first_name: string, last_name: string, email: string, contact_number: string}>
 */
function contact_list_json(): array
{
    return array_map('contact_json', all_contacts());
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(200, ['contacts' => contact_list_json()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(405, ['ok' => false, 'message' => 'That action is not available.']);
    }

    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode(file_get_contents('php://input') ?: '', true);
        $_POST = is_array($decoded) ? $decoded : [];
    }

    if (!csrf_valid()) {
        json_response(403, ['ok' => false, 'message' => 'The form expired. Submit it again.']);
    }

    $action = (string) ($_POST['action'] ?? '');
    $postedId = 0;
    if (isset($_POST['id']) && $_POST['id'] !== '' && $_POST['id'] !== null) {
        $rawId = is_scalar($_POST['id']) ? (string) $_POST['id'] : '';
        $parsedId = parse_id($rawId);
        if ($parsedId === null) {
            json_response(404, ['ok' => false, 'message' => 'That contact no longer exists.']);
        }
        $postedId = $parsedId;
    }

    if ($action === 'delete') {
        if ($postedId > 0 && find_contact($postedId) !== null) {
            delete_contact($postedId);
            json_response(200, [
                'ok' => true,
                'message' => 'Contact deleted.',
                'contacts' => contact_list_json(),
            ]);
        }
        json_response(404, ['ok' => false, 'message' => 'That contact no longer exists.']);
    }

    if ($action !== 'save') {
        json_response(400, ['ok' => false, 'message' => 'That action is not available.']);
    }

    $ignoreId = $postedId > 0 ? $postedId : null;
    $result = validate_contact($_POST, $ignoreId);
    if ($result['errors'] !== []) {
        json_response(422, ['ok' => false, 'errors' => $result['errors']]);
    }

    if ($postedId > 0) {
        if (find_contact($postedId) === null) {
            json_response(404, ['ok' => false, 'message' => 'That contact no longer exists.']);
        }
        try {
            update_contact($postedId, $result['data']);
        } catch (PDOException $exception) {
            if (!is_unique_violation($exception)) {
                throw $exception;
            }
            json_response(422, [
                'ok' => false,
                'errors' => ['email' => 'A contact with this email already exists.'],
            ]);
        }
        json_response(200, [
            'ok' => true,
            'message' => 'Contact updated.',
            'contacts' => contact_list_json(),
        ]);
    }

    try {
        create_contact($result['data']);
    } catch (PDOException $exception) {
        if (!is_unique_violation($exception)) {
            throw $exception;
        }
        json_response(422, [
            'ok' => false,
            'errors' => ['email' => 'A contact with this email already exists.'],
        ]);
    }

    json_response(200, [
        'ok' => true,
        'message' => 'Contact added.',
        'contacts' => contact_list_json(),
    ]);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    json_response(500, ['ok' => false, 'message' => 'The contact list is unavailable right now.']);
}
