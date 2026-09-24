<?php

declare(strict_types=1);

session_start();

if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function flash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $current = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $current;
}

function redirect(string $to = 'index.php'): void
{
    header('Location: ' . $to);
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

$errors = [];
$editingId = 0;
$values = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'contact_number' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash('The form expired. Submit it again.', 'error');
        redirect();
    }

    $action = (string) ($_POST['action'] ?? '');
    $postedId = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($postedId > 0 && find_contact($postedId) !== null) {
            delete_contact($postedId);
            flash('Contact deleted.');
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
            try {
                if ($postedId > 0) {
                    if (find_contact($postedId) === null) {
                        flash('That contact no longer exists.', 'error');
                        redirect();
                    }
                    update_contact($postedId, $values);
                    flash('Contact updated.');
                } else {
                    create_contact($values);
                    flash('Contact added.');
                }
                redirect();
            } catch (PDOException $exception) {
                if (!is_unique_violation($exception)) {
                    throw $exception;
                }
                $errors['email'] = 'A contact with this email already exists.';
            }
        }
    }
} elseif (isset($_GET['edit'])) {
    $contact = find_contact((int) $_GET['edit']);
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
$notice = flash();
$isEditing = $editingId > 0;
$formTitle = $isEditing ? 'Edit contact' : 'Add contact';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact List</title>
    <style>
        :root {
            --bg: #f3efe8;
            --ink: #1d1915;
            --muted: #6f675e;
            --line: #e4dcd0;
            --card: #fffdf9;
            --accent: #0e6b63;
            --accent-dark: #0a4e49;
            --danger: #8f2d2d;
            --ok-bg: #e7f4ec;
            --ok-ink: #1c5c34;
            --error-bg: #fbecec;
            --error-ink: #8f2d2d;
            --shadow: 0 12px 30px rgba(70, 52, 28, 0.06);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            background:
                radial-gradient(900px 420px at 0% -10%, #efe4d2 0%, transparent 60%),
                var(--bg);
            font-family: "Segoe UI", "Helvetica Neue", sans-serif;
            line-height: 1.45;
        }

        .page {
            width: min(1080px, calc(100% - 32px));
            margin: 0 auto;
            padding: 42px 0 72px;
        }

        .mast h1 {
            margin: 0;
            font-family: Palatino, "Palatino Linotype", Georgia, serif;
            font-size: clamp(2.2rem, 4vw, 3.1rem);
            font-weight: 600;
            letter-spacing: -0.03em;
            line-height: 1.05;
        }

        .eyebrow {
            margin: 0 0 8px;
            color: var(--accent);
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .lede {
            max-width: 38rem;
            margin: 10px 0 0;
            color: var(--muted);
        }

        .banner {
            margin: 22px 0 0;
            padding: 12px 14px;
            border-radius: 12px;
            font-weight: 600;
        }

        .banner-ok { background: var(--ok-bg); color: var(--ok-ink); }
        .banner-error { background: var(--error-bg); color: var(--error-ink); }

        .layout {
            display: grid;
            grid-template-columns: 320px minmax(0, 1fr);
            gap: 18px;
            align-items: start;
            margin-top: 24px;
        }

        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow);
            padding: 20px;
        }

        .panel-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }

        .panel-head h2 { margin: 0; font-size: 1.15rem; }

        .count, .hint, .empty {
            margin: 0;
            color: var(--muted);
            font-size: 0.92rem;
        }

        .field { margin-bottom: 14px; }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.92rem;
            font-weight: 650;
        }

        input[type="text"],
        input[type="email"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d9d0c4;
            border-radius: 10px;
            background: #fff;
            color: var(--ink);
            font: inherit;
        }

        input:focus {
            outline: 2px solid rgba(14, 107, 99, 0.25);
            border-color: var(--accent);
        }

        input[aria-invalid="true"] { border-color: var(--danger); }
        .hint { margin-top: 6px; }

        .error {
            margin: 6px 0 0;
            color: var(--danger);
            font-size: 0.88rem;
            font-weight: 600;
        }

        .button, .actions button {
            font: inherit;
            cursor: pointer;
        }

        .button {
            width: 100%;
            margin-top: 6px;
            padding: 11px 14px;
            border: 0;
            border-radius: 10px;
            background: var(--accent);
            color: #fff;
            font-weight: 700;
        }

        .button:hover { background: var(--accent-dark); }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }

        th, td {
            padding: 12px 10px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid var(--line);
        }

        th {
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        tr:last-child td { border-bottom: 0; }

        .actions {
            display: flex;
            gap: 10px;
            align-items: center;
            white-space: nowrap;
        }

        .actions a, .text-link, .actions button {
            color: var(--accent-dark);
            background: none;
            border: 0;
            padding: 0;
            font-weight: 700;
            text-decoration: none;
        }

        .actions button { color: var(--danger); }
        .actions a:hover, .text-link:hover, .actions button:hover { text-decoration: underline; }
        .actions form { margin: 0; }
        .empty { padding: 8px 0 4px; }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        @media (max-width: 840px) {
            .layout { grid-template-columns: 1fr; }
            thead { display: none; }

            tr {
                display: block;
                padding: 8px 0 12px;
                border-bottom: 1px solid var(--line);
            }

            tr:last-child { border-bottom: 0; }
            td { display: block; padding: 4px 0; border-bottom: 0; }

            td[data-label]::before {
                content: attr(data-label);
                display: block;
                color: var(--muted);
                font-size: 0.75rem;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
            }

            .actions { margin-top: 8px; }
        }
    </style>
</head>
<body>
    <main class="page">
        <header class="mast">
            <p class="eyebrow">Directory</p>
            <h1>Contact List</h1>
            <p class="lede">Every field is required. Contacts are listed by last name.</p>
        </header>

        <?php if ($notice !== null): ?>
            <p class="banner banner-<?= e((string) $notice['type']) ?>" role="status">
                <?= e((string) $notice['message']) ?>
            </p>
        <?php endif; ?>

        <div class="layout">
            <section class="panel" aria-labelledby="form-title">
                <div class="panel-head">
                    <h2 id="form-title"><?= e($formTitle) ?></h2>
                    <?php if ($isEditing): ?>
                        <a class="text-link" href="index.php">Cancel</a>
                    <?php endif; ?>
                </div>

                <form method="post" action="index.php" novalidate>
                    <input type="hidden" name="csrf" value="<?= e((string) $_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="save">
                    <?php if ($isEditing): ?>
                        <input type="hidden" name="id" value="<?= $editingId ?>">
                    <?php endif; ?>

                    <div class="field">
                        <label for="first_name">First name</label>
                        <input
                            id="first_name"
                            name="first_name"
                            type="text"
                            maxlength="50"
                            value="<?= e($values['first_name']) ?>"
                            <?= isset($errors['first_name']) ? 'aria-invalid="true" aria-describedby="first_name-error"' : '' ?>
                        >
                        <?php if (isset($errors['first_name'])): ?>
                            <p class="error" id="first_name-error"><?= e($errors['first_name']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="last_name">Last name</label>
                        <input
                            id="last_name"
                            name="last_name"
                            type="text"
                            maxlength="50"
                            value="<?= e($values['last_name']) ?>"
                            <?= isset($errors['last_name']) ? 'aria-invalid="true" aria-describedby="last_name-error"' : '' ?>
                        >
                        <?php if (isset($errors['last_name'])): ?>
                            <p class="error" id="last_name-error"><?= e($errors['last_name']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="email">Email</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            maxlength="50"
                            autocomplete="email"
                            spellcheck="false"
                            value="<?= e($values['email']) ?>"
                            <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>
                        >
                        <?php if (isset($errors['email'])): ?>
                            <p class="error" id="email-error"><?= e($errors['email']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="contact_number">Contact number</label>
                        <input
                            id="contact_number"
                            name="contact_number"
                            type="text"
                            inputmode="numeric"
                            maxlength="15"
                            autocomplete="tel"
                            value="<?= e($values['contact_number']) ?>"
                            <?= isset($errors['contact_number']) ? 'aria-invalid="true" aria-describedby="contact_number-error"' : '' ?>
                        >
                        <p class="hint">Digits only, up to 15.</p>
                        <?php if (isset($errors['contact_number'])): ?>
                            <p class="error" id="contact_number-error"><?= e($errors['contact_number']) ?></p>
                        <?php endif; ?>
                    </div>

                    <button class="button" type="submit"><?= $isEditing ? 'Save changes' : 'Add contact' ?></button>
                </form>
            </section>

            <section class="panel" aria-labelledby="list-title">
                <div class="panel-head">
                    <h2 id="list-title">Contacts</h2>
                    <p class="count"><?= count($contacts) === 1 ? '1 person' : count($contacts) . ' people' ?></p>
                </div>

                <?php if ($contacts === []): ?>
                    <p class="empty">No contacts yet. Add the first one with the form.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Last name</th>
                                    <th scope="col">First name</th>
                                    <th scope="col">Email</th>
                                    <th scope="col">Contact number</th>
                                    <th scope="col"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contacts as $contact): ?>
                                    <tr>
                                        <td data-label="Last name"><?= e((string) $contact['last_name']) ?></td>
                                        <td data-label="First name"><?= e((string) $contact['first_name']) ?></td>
                                        <td data-label="Email"><?= e((string) $contact['email']) ?></td>
                                        <td data-label="Contact number"><?= e((string) $contact['contact_number']) ?></td>
                                        <td class="actions">
                                            <a href="index.php?edit=<?= (int) $contact['id'] ?>">Edit</a>
                                            <form method="post" action="index.php" onsubmit="return confirm('Delete this contact?');">
                                                <input type="hidden" name="csrf" value="<?= e((string) $_SESSION['csrf']) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $contact['id'] ?>">
                                                <button type="submit">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
