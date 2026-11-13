<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Guardian consent | StudentShield</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: clamp(1rem, 5vw, 4rem) 1rem; background: #f2f7f4; color: #173a2a; font: 1rem/1.6 system-ui, sans-serif; }
        main { max-width: 42rem; margin: auto; background: white; border: 1px solid #d5e3da; border-top: .35rem solid #0b5e2e; border-radius: 1rem; padding: clamp(1rem, 5vw, 2.5rem); }
        h1 { margin: .25rem 0 1rem; line-height: 1.15; }
        .brand { font-weight: 800; color: #0b5e2e; }
        .notice { white-space: pre-wrap; overflow-wrap: anywhere; padding: 1rem; background: #f2f7f4; border-radius: .5rem; }
        label, input { display: block; }
        input[type="text"], input[type="tel"] { width: 100%; min-height: 44px; padding: .65rem; border: 1px solid #638371; border-radius: .4rem; font: inherit; margin: .25rem 0 1rem; }
        input.code-input { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .08em; text-transform: uppercase; }
        .actions { display: flex; flex-wrap: wrap; gap: .65rem; }
        button { min-height: 44px; background: #0b5e2e; color: white; border: 1px solid #0b5e2e; border-radius: .45rem; padding: .65rem 1rem; font: inherit; cursor: pointer; }
        button.secondary { background: white; color: #0b5e2e; }
        .message { font-weight: 600; }
        .helper { color: #3d5d4d; font-size: .92rem; margin-top: -.35rem; margin-bottom: 1.25rem; }
        .nav-link { display: inline-block; margin-top: 1rem; color: #0b5e2e; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <div class="brand">StudentShield</div>
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
