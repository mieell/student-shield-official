<?php
$contactAccount = $contactAccount ?? [];
$editingContacts = isset($contactAccount['id']);
$contactFields = [
    ['parent_phone', 'student', 'Parent/guardian phone (SMS)', 'tel'],
    ['parent_email', 'student', 'Parent/guardian email', 'email'],
    ['phone', 'staff', 'Staff phone (SMS)', 'tel'],
    ['email', 'staff', 'Staff email', 'email'],
];
?>
<?php foreach ($contactFields as [$field, $contactRole, $contactLabel, $type]):
    $value = (string) ($contactAccount[$field] ?? '');
?>
<div data-account-contact="<?= $contactRole ?>">
    <label>
    <span><span data-contact-label><?= esc($contactLabel) ?></span> <small data-contact-status <?= $value === '' ? 'hidden' : '' ?>>Unverified</small></span>
    <input data-contact-value name="<?= $field ?>" type="<?= $type ?>" maxlength="<?= $type === 'tel' ? 20 : 255 ?>" autocomplete="off"
           value="<?= esc($value, 'attr') ?>" placeholder="<?= $type === 'tel' ? '09123456789 or +639123456789' : 'name@example.com' ?>">
    </label>
    <?php if ($editingContacts): ?>
    <input data-contact-removal type="hidden" name="remove_<?= $field ?>" value="0">
    <button data-remove-contact type="button" class="secondary contact-remove" <?= $value === '' ? 'hidden' : '' ?>>Remove saved <?= $type === 'tel' ? 'phone' : 'email' ?></button>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<p data-contact-help>Students require at least one guardian contact. Staff require an email for OTP. Contact ownership is unverified.</p>
<?php if ($editingContacts): ?>
<p>Blank fields keep their saved values. Use Remove to delete a saved contact.</p>
<?php endif; ?>
