<?php
$inputId = $kind === 'victim' ? 'victim_name' : 'aggressor_name';
$inputName = $kind === 'victim' ? 'victim_name' : 'bully_name';
?>
<div class="student-picker" data-person="<?= esc($kind, 'attr') ?>">
    <div class="grid grid-cols-2 gap-2 mb-2">
        <div>
            <label for="<?= $kind ?>-grade" class="text-xs block mb-1">Grade level</label>
            <select id="<?= $kind ?>-grade" name="<?= $kind ?>_grade" class="directory-grade w-full border rounded-xl px-3 py-3 bg-white">
                <option value="">Choose grade</option>
                <?php foreach (range(7, 10) as $grade): ?><option value="<?= $grade ?>">Grade <?= $grade ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="<?= $kind ?>-section" class="text-xs block mb-1">Section</label>
            <select id="<?= $kind ?>-section" name="<?= $kind ?>_section" class="directory-section w-full border rounded-xl px-3 py-3 bg-white" disabled><option value="">Choose section</option></select>
        </div>
    </div>
    <label for="<?= $kind ?>-search" class="text-xs block mb-1">Search student name</label>
    <input id="<?= $kind ?>-search" type="search" maxlength="80" autocomplete="off" class="directory-search w-full border rounded-xl px-3 py-3 mb-2" placeholder="Type at least 2 letters" disabled>
    <label for="<?= $kind ?>-student" class="sr-only">Select student or choose not listed</label>
    <select id="<?= $kind ?>-student" name="<?= $kind ?>_student_id" class="directory-choice w-full border rounded-xl px-3 py-3 bg-white" <?= $kind === 'bully' ? 'required' : '' ?>>
        <option value="">Select a student</option>
        <option value="unknown">I don't know / not listed</option>
    </select>
    <p class="directory-status text-xs text-gray-600 mt-1" aria-live="polite">Choose a grade and section to search, or select “not listed”.</p>
    <label class="directory-description-label text-xs block mt-2" for="<?= $inputId ?>" hidden>Name or description of the person</label>
    <input id="<?= $inputId ?>" name="<?= $inputName ?>" type="text" maxlength="160" class="directory-description w-full border rounded-xl px-3 py-3 mt-1" hidden disabled placeholder="What do you know about this person?">
</div>
