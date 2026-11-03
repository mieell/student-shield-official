<section class="student-meet-card" aria-label="Counseling meeting"
    data-status-url="<?= esc(base_url('student/api/meeting/status'), 'attr') ?>"
    data-request-url="<?= esc(base_url('student/meeting/request'), 'attr') ?>"
    data-cancel-url="<?= esc(base_url('student/meeting/cancel-request'), 'attr') ?>">
    <div class="meet-card-header">
        <div class="meet-icon-wrap">
            <?php if ($ventStyle ?? false): ?>
            <svg aria-hidden="true" width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><rect x="2" y="5" width="14" height="14" rx="3"/><path d="m17 9 5-3v12l-5-3z"/></svg>
            <?php else: ?>
            <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="2" y="5" width="14" height="14" rx="4" fill="#FBBC04"/><path d="M17 9 22 6v12l-5-3V9Z" fill="#34A853"/><path d="M2 14h5v5H6a4 4 0 0 1-4-4v-1Z" fill="#4285F4"/></svg>
            <?php endif; ?>
        </div>
        <div>
            <h3 class="meet-card-title"><?= ($ventStyle ?? false) ? 'Counselor video session' : 'Counselor Video Session' ?></h3>
            <p class="meet-card-subtitle"><?= ($ventStyle ?? false) ? 'Private one-to-one consultation' : 'Private 1-on-1 consultation' ?></p>
        </div>
    </div>
    <div class="meet-card-body">
        <p data-meet-status role="status" class="meet-status-text">Checking session…</p>
        <a data-meet-join hidden target="_blank" rel="noopener noreferrer" class="meet-btn meet-btn--join" aria-label="Join Google Meet (opens in a new tab)">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            Join Google Meet
        </a>
        <button data-meet-request type="button" hidden class="meet-btn meet-btn--request">
            <?= ($ventStyle ?? false) ? 'Request a session' : 'Request Session' ?>
        </button>
        <button data-meet-cancel type="button" hidden class="meet-btn--cancel">
            Cancel session request
        </button>
        <p data-meet-error role="alert" hidden class="meet-error-text"></p>
    </div>
</section>
<style>
.student-meet-card {
    box-sizing: border-box;
    width: 100%;
    margin: 0;
    padding: 18px 20px;
    border: 1px solid var(--form-divider, #D5E1D9);
    border-radius: 16px;
    background: var(--form-surface, #FFFFFF);
    color: var(--form-text, #24352B);
    text-align: left;
    font: 400 14px/1.5 'Nunito', 'Inter', sans-serif;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.meet-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
}
.meet-icon-wrap {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #F4F7F5;
    border: 1px solid #E6EBE8;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.meet-card-title {
    margin: 0;
    font: 700 16px/1.3 'Nunito', 'Inter', sans-serif;
    color: var(--form-text, #24352B);
}
.meet-card-subtitle {
    margin: 2px 0 0;
    font-size: 12px;
    color: var(--form-muted, #526259);
}
.meet-card-body {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.meet-status-text {
    margin: 0;
    font-size: 12px;
    color: var(--form-muted, #526259);
    font-weight: 500;
}
.meet-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    min-height: 40px;
    padding: 8px 16px;
    border-radius: 10px;
    font: 600 14px/1.4 'Nunito', 'Inter', sans-serif;
    text-decoration: none;
    cursor: pointer;
    box-sizing: border-box;
    transition: all .15s ease;
}
.meet-btn--request {
    background: var(--form-mint, #EDF6F0);
    color: var(--school-green, #017F36);
    border: 1px solid var(--school-green, #017F36);
}
.meet-btn--request:hover {
    background: var(--school-green, #017F36);
    color: #FFFFFF;
}
.meet-btn--join {
    background: var(--school-green, #017F36);
    color: #FFFFFF;
    border: 1px solid var(--school-green, #017F36);
}
.meet-btn--join:hover {
    background: var(--school-green-hover, #006F30);
}
.meet-btn--cancel {
    background: #FDF2F2;
    border: 1px solid #F5C2C2;
    color: #B3261E;
    font: 600 13px/1.4 'Nunito', 'Inter', sans-serif;
    padding: 7px 14px;
    border-radius: 8px;
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    transition: all .15s ease;
}
.meet-btn--cancel:hover {
    background: #FCE8E8;
    color: #8C1D18;
    border-color: #E09898;
}
.meet-error-text {
    margin: 0;
    font-size: 12px;
    color: #B3261E;
}
.student-meet-card [hidden] {
    display: none !important;
}
.student-meet-card button:disabled {
    opacity: .6;
    cursor: wait;
}
</style>
<script src="<?= base_url('js/student-meet.js') ?>" defer></script>
