<?php if (!defined('CONTACT_LIST')) {
    exit;
} ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact List</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="page">
        <header class="mast">
            <p class="eyebrow">Directory</p>
            <h1>Contact List</h1>
            <p class="lede">Every field is required. Contacts are listed by last name.</p>
        </header>

        <?php if ($notice !== null): ?>
            <p class="banner banner-<?= e($noticeType) ?>" role="<?= e($noticeRole) ?>">
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

                <form id="contact-form" method="post" action="index.php" novalidate>
                    <input type="hidden" name="csrf" value="<?= e((string) $_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="save">
                    <?php if ($isEditing): ?>
                        <input type="hidden" name="id" value="<?= (int) $editingId ?>">
                    <?php endif; ?>

                    <?php foreach ($fields as $name => $field): ?>
                        <div class="field">
                            <label for="<?= e($name) ?>"><?= e($field['label']) ?></label>
                            <input
                                id="<?= e($name) ?>"
                                name="<?= e($name) ?>"
                                type="<?= e($field['type']) ?>"
                                maxlength="<?= (int) $field['maxlength'] ?>"
                                <?php if (isset($field['autocomplete'])): ?>
                                    autocomplete="<?= e($field['autocomplete']) ?>"
                                <?php endif; ?>
                                <?php if (array_key_exists('spellcheck', $field)): ?>
                                    spellcheck="<?= $field['spellcheck'] ? 'true' : 'false' ?>"
                                <?php endif; ?>
                                <?php if (isset($field['inputmode'])): ?>
                                    inputmode="<?= e($field['inputmode']) ?>"
                                <?php endif; ?>
                                value="<?= e((string) ($values[$name] ?? '')) ?>"
                                required
                                <?= field_attrs($name, $errors, isset($field['hint'])) ?>
                            >
                            <?php if (isset($field['hint'])): ?>
                                <p class="hint" id="<?= e($name) ?>-hint"><?= e($field['hint']) ?></p>
                            <?php endif; ?>
                            <?php if (isset($errors[$name])): ?>
                                <p class="error" id="<?= e($name) ?>-error"><?= e($errors[$name]) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <button class="button" type="submit"><?= $isEditing ? 'Save changes' : 'Add contact' ?></button>
                </form>
            </section>

            <section class="panel" aria-labelledby="list-title">
                <div class="panel-head">
                    <h2 id="list-title">Contacts</h2>
                    <p class="count"><?= e($countLabel) ?></p>
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
                                    <tr data-id="<?= (int) $contact['id'] ?>" data-email="<?= e((string) $contact['email']) ?>">
                                        <td data-label="Last name"><?= e((string) $contact['last_name']) ?></td>
                                        <td data-label="First name"><?= e((string) $contact['first_name']) ?></td>
                                        <td data-label="Email"><?= e((string) $contact['email']) ?></td>
                                        <td data-label="Contact number"><?= e((string) $contact['contact_number']) ?></td>
                                        <td class="actions">
                                            <a href="index.php?edit=<?= (int) $contact['id'] ?>">Edit</a>
                                            <form method="post" action="index.php" data-confirm="Delete this contact?">
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
    <script src="script.js"></script>
</body>
</html>
