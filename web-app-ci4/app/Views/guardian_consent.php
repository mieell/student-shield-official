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
        input[type="text"] { width: 100%; padding: .65rem; border: 1px solid #638371; border-radius: .4rem; font: inherit; margin: .25rem 0 1rem; }
        .actions { display: flex; flex-wrap: wrap; gap: .65rem; }
        button { background: #0b5e2e; color: white; border: 1px solid #0b5e2e; border-radius: .45rem; padding: .65rem 1rem; font: inherit; cursor: pointer; }
        button.secondary { background: white; color: #0b5e2e; }
        .message { font-weight: 600; }
    </style>
</head>
<body>
<main>
    <div class="brand">StudentShield</div>
    <h1>Guardian consent</h1>
    <?php if ($message !== ''): ?><p class="message" role="status"><?= esc($message) ?></p><?php endif; ?>
    <?php if ($notice !== null): ?>
        <p>Complete this form only if you are the parent or guardian the school contacted. Contact the guidance office if you received the link by mistake or have questions.</p>
        <p class="notice"><?= esc($notice) ?></p>
        <form method="post" action="<?= esc(site_url('guardian-consent/' . $token), 'attr') ?>">
            <?= csrf_field() ?>
            <label for="guardian-name">Parent or guardian full name</label>
            <input type="text" id="guardian-name" name="guardian_name" autocomplete="name" maxlength="120" required>
            <div class="actions">
                <button name="choice" value="accepted">I give consent</button>
                <button name="choice" value="declined" class="secondary">I do not give consent</button>
            </div>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
