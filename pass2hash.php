<?php
declare(strict_types=1);
require __DIR__ . '/lib/blog.php';
require __DIR__ . '/lib/session.php';
locale(loadConfig()['language']);
header('Cache-Control: no-store');
$result = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = input($_POST, 'password');
    $confirmation = input($_POST, 'confirmation');

    try {
        validatePassword($password, $confirmation);
        $result = password_hash($password, PASSWORD_DEFAULT);
    } catch (BlogError $exception) {
        $error = t($exception->getMessage());
    }
}
?>
<!DOCTYPE html>
<html <?= languageAttributes() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e('hash_title') ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            display: grid;
            place-items: center;
            background: #101112;
            color: #e8e8e8;
            font: 16px/1.5 system-ui, sans-serif;
        }

        main {
            width: min(100%, 440px);
            padding: 32px;
            border: 1px solid #292b2e;
            border-radius: 14px;
            background: #171819;
        }

        h1 {
            margin: 0 0 24px;
            font-size: 1.4rem;
            font-weight: 600;
        }

        label {
            display: block;
            margin: 16px 0 7px;
            color: #b8b8b8;
            font-size: 0.9rem;
        }

        input,
        textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #34373a;
            border-radius: 8px;
            outline: none;
            background: #101112;
            color: #e8e8e8;
            font: inherit;
        }

        input:focus,
        textarea:focus {
            border-color: #858585;
        }

        textarea {
            min-height: 82px;
            resize: vertical;
            overflow-wrap: anywhere;
        }

        button {
            width: 100%;
            margin: 22px 0 4px;
            padding: 12px;
            border: 0;
            border-radius: 8px;
            background: #e8e8e8;
            color: #111;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover {
            background: #d0d0d0;
        }

        .error {
            margin: 16px 0 0;
            color: #ff7777;
        }
    </style>
</head>
<body>
<main>
    <h1><?= e('hash_title') ?></h1>

    <form method="post">
        <label for="password"><?= e('password') ?></label>
        <input
            id="password"
            name="password"
            type="password"
            required
            autocomplete="new-password"
        >

        <label for="confirmation"><?= e('confirm_password') ?></label>
        <input
            id="confirmation"
            name="confirmation"
            type="password"
            required
            autocomplete="new-password"
        >

        <label for="result"><?= e('result') ?></label>
        <textarea id="result" readonly><?= htmlspecialchars($result, ENT_QUOTES, 'UTF-8') ?></textarea>

        <button type="submit"><?= e('hash_generate') ?></button>

        <?php if ($error !== ''): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </form>
</main>
</body>
</html>
