<?php

declare(strict_types=1);

define('CONTACT_LIST', true);

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

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$editingId = 0;
$values = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'contact_number' => '',
];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valid()) {
            flash('The form expired. Submit it again.', 'error');
            redirect();
        }

        $action = (string) ($_POST['action'] ?? '');
        $postedId = 0;

        if (isset($_POST['id']) && $_POST['id'] !== '') {
            $parsedId = is_string($_POST['id']) ? parse_id($_POST['id']) : null;
            if ($parsedId === null) {
                flash('That contact no longer exists.', 'error');
                redirect();
            }
            $postedId = $parsedId;
        }

        if ($action === 'delete') {
            if ($postedId > 0 && find_contact($postedId) !== null) {
                delete_contact($postedId);
                flash('Contact deleted.');
            } else {
                flash('That contact no longer exists.', 'error');
            }
            redirect();
        }

        if ($action === 'save') {
            $ignoreId = $postedId > 0 ? $postedId : null;
            $result = validate_contact($_POST, $ignoreId);
            $values = $result['data'];
            $errors = $result['errors'];
            $editingId = $postedId;

            if ($errors === []) {
                if ($postedId > 0) {
                    if (find_contact($postedId) === null) {
                        flash('That contact no longer exists.', 'error');
                        redirect();
                    }
                    try {
                        update_contact($postedId, $values);
                    } catch (PDOException $exception) {
                        if (!is_unique_violation($exception)) {
                            throw $exception;
                        }
                        $errors['email'] = 'A contact with this email already exists.';
                    }
                    if ($errors === []) {
                        flash('Contact updated.');
                        redirect();
                    }
                } else {
                    try {
                        create_contact($values);
                        flash('Contact added.');
                        redirect();
                    } catch (PDOException $exception) {
                        if (!is_unique_violation($exception)) {
                            throw $exception;
                        }
                        $errors['email'] = 'A contact with this email already exists.';
                    }
                }
            }
        } else {
            flash('That action is not available.', 'error');
            redirect();
        }
    } elseif (isset($_GET['edit'])) {
        $editId = is_string($_GET['edit']) ? parse_id($_GET['edit']) : null;
        if ($editId === null) {
            flash('That contact was not found.', 'error');
            redirect();
        }

        $contact = find_contact($editId);
        if ($contact === null) {
            flash('That contact was not found.', 'error');
            redirect();
        }

        $editingId = (int) $contact['id'];
        $values = [
            'first_name' => (string) $contact['first_name'],
            'last_name' => (string) $contact['last_name'],
            'email' => (string) $contact['email'],
            'contact_number' => (string) $contact['contact_number'],
        ];
    }

    $contacts = all_contacts();
} catch (Throwable $exception) {
    unavailable($exception);
}

if ($errors !== []) {
    http_response_code(422);
}

$notice = flash();
$noticeType = is_array($notice) && (($notice['type'] ?? '') === 'error') ? 'error' : 'ok';
$noticeRole = $noticeType === 'error' ? 'alert' : 'status';
$isEditing = $editingId > 0;
$formTitle = $isEditing ? 'Edit contact' : 'Add contact';
$contactCount = count($contacts);
$countLabel = $contactCount === 1 ? '1 person' : $contactCount . ' people';
$fields = [
    'first_name' => [
        'label' => 'First name',
        'type' => 'text',
        'autocomplete' => 'given-name',
        'maxlength' => 50,
    ],
    'last_name' => [
        'label' => 'Last name',
        'type' => 'text',
        'autocomplete' => 'family-name',
        'maxlength' => 50,
    ],
    'email' => [
        'label' => 'Email',
        'type' => 'email',
        'autocomplete' => 'email',
        'maxlength' => 50,
        'spellcheck' => false,
    ],
    'contact_number' => [
        'label' => 'Contact number',
        'type' => 'text',
        'autocomplete' => 'tel',
        'maxlength' => 15,
        'inputmode' => 'numeric',
        'hint' => 'Digits only, up to 15.',
    ],
];

$view = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'page.php';
if (!is_file($view)) {
    unavailable(new RuntimeException('The HTML view is missing.'));
}

require $view;
