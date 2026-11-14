<?php helper('url'); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Guardian consent | StudentShield</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Nunito:wght@700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100dvh;
            padding: clamp(1.25rem, 5vw, 3.5rem) 1rem;
            background: linear-gradient(180deg, #f2f7f4 0%, #eaf2ed 100%);
            color: #173a2a;
            font: 400 1rem/1.6 'Inter', system-ui, -apple-system, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        main {
            width: 100%;
            max-width: 42rem;
            background: #ffffff;
            border: 1px solid #d5e3da;
            border-top: 5px solid #0f8a3a;
            border-radius: 18px;
            box-shadow: 0 18px 40px -24px rgba(10, 62, 31, 0.25);
            padding: clamp(1.35rem, 4vw, 2.5rem);
        }
        .consent-header {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 1.15rem;
            margin-bottom: 1.35rem;
            border-bottom: 1px solid #e3ede6;
        }
        .consent-logo {
            width: 48px;
            height: 48px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .consent-school {
            margin: 0 0 2px;
            font-size: 0.78rem;
            font-weight: 600;
            color: #526b5b;
        }
        .brand {
            font-family: 'Nunito', 'Inter', system-ui, sans-serif;
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #0f8a3a;
        }
        .brand span { color: #b98916; }
        h1 {
            margin: 0 0 0.75rem;
            font-family: 'Nunito', 'Inter', system-ui, sans-serif;
            font-size: clamp(1.5rem, 3vw, 1.85rem);
            font-weight: 800;
            line-height: 1.2;
            color: #123c22;
            letter-spacing: -0.02em;
        }
        p { margin: 0 0 1rem; color: #364c3f; font-size: 0.96rem; }
        .message {
            padding: 0.9rem 1.05rem;
            border-radius: 10px;
            border: 1px solid #cfe2d4;
            background: #edf6ef;
            color: #123c22;
            font-weight: 600;
        }
        .notice {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            margin: 1rem 0 1.35rem;
            padding: 1rem 1.1rem;
            background: #fffdf6;
            border: 1px solid #eadca8;
            border-left: 4px solid #d9aa2e;
            border-radius: 10px;
            color: #243b2e;
            font-size: 0.94rem;
            line-height: 1.6;
        }
        label {
            display: block;
            font-size: 0.875rem;
            font-weight: 700;
            color: #1f3c2a;
            margin-bottom: 0.4rem;
        }
        input[type="text"], input[type="tel"] {
            display: block;
            width: 100%;
            min-height: 46px;
            padding: 0.65rem 0.85rem;
            border: 1.5px solid #9ab3a3;
            border-radius: 10px;
            font: inherit;
            color: #173a2a;
            background: #ffffff;
            margin: 0 0 1.25rem;
            transition: border-color 150ms ease, box-shadow 150ms ease;
        }
        input[type="text"]:focus, input[type="tel"]:focus {
            outline: none;
            border-color: #0f8a3a;
            box-shadow: 0 0 0 3px rgba(15, 138, 58, 0.16);
        }
        input.code-input { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .08em; text-transform: uppercase; }
        .helper { color: #3d5d4d; font-size: .92rem; margin-top: -.25rem; margin-bottom: 1.25rem; }
        .nav-link { display: inline-block; margin-top: 1rem; color: #0f8a3a; font-weight: 600; text-decoration: none; }
        .nav-link:hover { text-decoration: underline; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 0.25rem; }
        button {
            min-height: 46px;
            padding: 0.7rem 1.35rem;
            background: #0f8a3a;
            color: #ffffff;
            border: 1px solid #0f8a3a;
            border-radius: 10px;
            font: 700 0.95rem/1.2 'Inter', system-ui, sans-serif;
            cursor: pointer;
            transition: background-color 150ms ease, transform 120ms ease;
        }
        button:hover { background: #0c7230; }
        button.secondary { background: #ffffff; color: #174d30; border-color: #bfd2c5; }
        button.secondary:hover { background: #f2f8f4; border-color: #0f8a3a; }
        button:focus-visible { outline: 2px solid #d9aa2e; outline-offset: 2px; }
        @media (max-width: 520px) {
            .actions { flex-direction: column; }
            button { width: 100%; }
        }
    </style>
</head>
<body>
<main>
    <div class="consent-header">
        <img src="<?= base_url('images/logo.png?v=6') ?>" alt="SVBSM Logo" class="consent-logo">
        <div>
            <p class="consent-school">St. Vincent Blessed School of Manila, Inc.</p>
            <div class="brand">Student<span>Shield</span></div>
        </div>
    </div>
    <h1>Guardian consent</h1>
    <?php $flashError = session()->getFlashdata('error'); ?>
    <?php $displayMessage = $flashError ?: $message; ?>
    <?php if ($displayMessage !== ''): ?><p class="message" role="status"><?= esc($displayMessage) ?></p><?php endif; ?>
    <?php if (!empty($showLookup)): ?>
        <p class="helper">Enter the verified parent or guardian mobile number and the 8-character code from your StudentShield SMS.</p>
        <form method="post" action="<?= esc(site_url('guardian-consent'), 'attr') ?>">
            <?= csrf_field() ?>
            <label for="guardian-phone">Guardian mobile number</label>
            <input type="tel" id="guardian-phone" name="guardian_phone" value="<?= esc($lookupPhone ?? '', 'attr') ?>" placeholder="09123456789" autocomplete="tel" maxlength="32" required>
            <label for="consent-code">Consent code</label>
            <input type="text" id="consent-code" name="consent_code" class="code-input" value="<?= esc($lookupCode ?? '', 'attr') ?>" placeholder="ABCD-EFGH" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="32" required>
            <div class="actions">
                <button type="submit">View consent form</button>
            </div>
        </form>
    <?php elseif ($notice !== null): ?>
        <p>Complete this form only if you are the parent or guardian the school contacted. Contact the guidance office if you received this notice by mistake or have questions.</p>
        <p class="notice"><?= esc($notice) ?></p>
        <?php $action = $token !== null ? site_url('guardian-consent/' . $token) : site_url('guardian-consent/respond'); ?>
        <form method="post" action="<?= esc($action, 'attr') ?>">
            <?= csrf_field() ?>
            <?php if ($token === null): ?>
                <input type="hidden" name="guardian_phone" value="<?= esc($lookupPhone ?? '', 'attr') ?>">
                <input type="hidden" name="consent_code" value="<?= esc($lookupCode ?? '', 'attr') ?>">
            <?php endif; ?>
            <label for="guardian-name">Parent or guardian full name</label>
            <input type="text" id="guardian-name" name="guardian_name" autocomplete="name" maxlength="120" required>
            <div class="actions">
                <button name="choice" value="accepted">I give consent</button>
                <button name="choice" value="declined" class="secondary">I do not give consent</button>
            </div>
        </form>
    <?php else: ?>
        <a class="nav-link" href="<?= esc(site_url('login'), 'attr') ?>">Return to StudentShield sign-in</a>
    <?php endif; ?>
</main>
</body>
</html>
