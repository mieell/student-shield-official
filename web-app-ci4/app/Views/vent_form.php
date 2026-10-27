<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>StudentShield - Safe Space Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?= view('partials/school_brand') ?>
        <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 
                        sans: ['Nunito', 'sans-serif'],
                        heading: ['Nunito', 'sans-serif']
                    },
                    colors: {
                        white: '#FFFFFF', // Anti-glare soft white
                        black: '#131A15', // Softened black
                        gray: {
                            50: '#F4F7F5',
                            100: '#E6EBE8',
                            200: '#CFD9D4',
                            300: '#AAB8B1',
                            400: '#82958B',
                            500: '#526259',
                            600: '#4D5D55',
                            700: '#3D4943',
                            800: '#2E3833',
                            900: '#24352B', // Replaces harsh text-gray-900 with deep sage
                        },
                        red: {
                            50: '#FDF7F7',
                            100: '#FAEDED',
                            200: '#F4D6D6',
                            400: '#B3261E',
                            500: '#B3261E', // Muted red for report and error accents
                            600: '#B3261E',
                            700: '#A33535',
                        },
                        primary: StudentShieldBrand.primary,
                        gold: { DEFAULT: '#D9AA2E', light: '#EAC255', bright: '#FCEEA1', dark: '#B5881D' },
                        calm: { 50: '#f0fdf4', 100: '#dcfce7', 200: '#bbf7d0', 600: '#16a34a' }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="<?= base_url('css/student-forms.css?v=7') ?>">
    <link rel="stylesheet" href="<?= base_url('css/student-vent.css?v=' . filemtime(FCPATH . 'css/student-vent.css')) ?>">
    <link rel="stylesheet" href="<?= base_url('css/safe-space.css?v=' . filemtime(FCPATH . 'css/safe-space.css')) ?>">
</head>
<body class="student-form-page student-form-page--vent student-workspace-page antialiased min-h-screen">

    <?= view('partials/student_vent_header', ['page' => 'vent']) ?>
    <main class="student-form-content vent-content">
        <div class="student-form-body">
                <!-- Page heading stays ahead of the form on every screen size. -->
                <header class="student-page-header">
                    <div class="vent-heading-copy">
                    <span class="student-section-label">
                        <svg aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M21 12a9 9 0 0 1-9 9H3l2-4a9 9 0 1 1 16-5Z" /></svg>
                        Safe Space
                        <svg class="vent-section-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6"/></svg>
                    </span>
                    <h2>A space to say how you feel.</h2>
                    <p>Write at your own pace. Your guidance counselor can help you take the next step.</p>
                    </div>
                    <img class="vent-heading-illustration" src="<?= base_url('images/safe-space-writing.webp') ?>" alt="" aria-hidden="true" width="2018" height="779" decoding="async">
                </header>

                <!-- Flash Messages -->
                <?php if (session()->getFlashdata('message')): ?>
                    <div class="bg-emerald-50 border-l-4 border-emerald-400 p-4 mb-5 rounded-r-xl flex items-center gap-3 animate-[shake_0.5s_ease-in-out]">
                        <svg class="h-5 w-5 text-emerald-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                        <p class="font-medium text-sm text-emerald-700"><?= session()->getFlashdata('message') ?></p>
                    </div>
                <?php endif; ?>
                <?php if (session()->getFlashdata('error')): ?>
                    <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-5 rounded-r-xl flex items-center gap-3 animate-[shake_0.5s_ease-in-out]">
                        <svg class="h-5 w-5 text-red-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
                        <p class="font-medium text-sm text-red-700"><?= session()->getFlashdata('error') ?></p>
                    </div>
                <?php endif; ?>

                <div class="vent-workspace">
                <div class="vent-composer">
                <!-- Vent Form -->
                <form action="<?= base_url('student/vent/submit') ?>" method="POST" id="ventForm" class="student-entry-form">
                    <div class="hidden"><?= csrf_field() ?></div>

                    <!-- Textarea — grows to fill space -->
                    <div class="vent-writing-field flex flex-col">
                        <div class="field-heading">
                            <label for="vent_text">What's on your mind?</label>
                            <span id="charCount" class="char-count">0 / 2000</span>
                        </div>
                        <textarea id="vent_text" name="vent_text" maxlength="2000" aria-describedby="ventStorageNote" placeholder="Start wherever you feel comfortable…"
                                  class="textarea-field" required></textarea>
                    </div>

                    <!-- Mood Quick-Select -->
                    <div class="mood-picker" id="moodPicker">
                        <p id="moodPrompt" class="mood-prompt">How do you feel right now?</p>
                        <p id="moodHelp" class="mood-help">Optional. Select any feelings that fit.</p>
                        <input type="hidden" name="emotions" id="emotionsInput" value="">
                        <details id="moodDisclosure" class="mood-disclosure">
                        <summary id="moodToggle" class="mood-toggle" aria-describedby="moodHelp">
                            <span>Choose feelings</span>
                            <span id="moodCount" class="mood-count" hidden></span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </summary>
                        <div id="moodPanel" class="mood-panel">
                        <div id="moodSelect" role="group" aria-labelledby="moodPrompt" aria-describedby="moodHelp">
                            <?php
                            $moodList = [
                                'Okay'        => '😐',
                                'Calm'        => '😌',
                                'Happy'       => '😊',
                                'Hopeful'     => '🙂',
                                'Sad'         => '😔',
                                'Angry'       => '😠',
                                'Anxious'     => '😰',
                                'Lonely'      => '🥺',
                                'Overwhelmed' => '😵',
                                'Hurt'        => '🤕',
                                'Numb'        => '😶',
                                'Scared'      => '😨',
                            ];
                            foreach ($moodList as $mood => $emoji): ?>
                                <button type="button" data-mood="<?= esc($mood, 'attr') ?>" aria-pressed="false" class="mood-btn">
                                    <span class="mood-emoji" aria-hidden="true"><?= $emoji ?></span>
                                    <span><?= esc($mood) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="moodDone" class="mood-done">Done</button>
                        </div>
                        </details>
                        <div id="moodSelections" class="mood-selections" aria-label="Selected feelings" hidden></div>
                        <span id="moodSelectionStatus" class="sr-only" role="status"></span>
                    </div>

                    <!-- Counselor notification preference -->
                    <label for="hide_from_parents" class="confidentiality-option">
                        <input type="checkbox" id="hide_from_parents" name="hide_from_parents" value="1" aria-labelledby="parentPreferenceLabel" aria-describedby="parentPreferenceHelp">
                        <span class="confidentiality-copy">
                            <span id="parentPreferenceLabel" class="confidentiality-title">Only alert my guidance counselor</span>
                            <span id="parentPreferenceHelp" class="confidentiality-help">Turn off automated parent or guardian alerts for this entry.</span>
                        </span>
                    </label>

                    <div class="vent-submit-row">
                    <p id="ventStorageNote" class="vent-storage-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Stored securely for counselor review.</p>
                    <!-- Submit -->
                    <button type="submit" id="submitBtn"
                        class="submit-btn w-full text-white font-bold py-4 px-6 rounded-2xl shadow-[0_8px_20px_-6px_rgba(11,94,46,0.25)] focus:outline-none focus:ring-4 focus:ring-calm-200 flex items-center justify-center gap-3 h-14 lg:h-16 group transition-transform mb-2">
                        <span id="btnText" class="font-heading text-[18px] lg:text-[20px] tracking-wide">Submit securely</span>
                        <svg id="btnIcon" class="h-5 w-5 text-gold/80 group-hover:-translate-y-0.5 transition-transform" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" /></svg>
                        <svg id="btnSpinner" class="animate-spin h-5 w-5 text-white hidden" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    </button>
                    </div>
                </form>

                </div>
                <aside class="vent-support" id="meet-section" aria-label="Student support">
                    <div class="vent-support-intro">
                        <h2>You can take your time.</h2>
                        <p>Share as much or as little as you feel comfortable with.</p>
                    </div>
                    <section class="vent-session-support" aria-labelledby="ventTalkHeading">
                        <h2 id="ventTalkHeading">Prefer to talk?</h2>
                        <?= view('partials/student_meet_card', ['ventStyle' => true]) ?>
                    <section class="vent-urgent-support" aria-labelledby="ventUrgentHeading">
                        <h2 id="ventUrgentHeading">Need urgent support?</h2>
                        <p>Find immediate support through the crisis helpline.</p>
                        <details class="vent-crisis-details">
                            <summary>View crisis support</summary>
                            <div>
                                <p>National Center for Mental Health</p>
                                <p class="vent-crisis-contacts"><span>Hotline:</span><a href="tel:1553">1553</a><a href="tel:09178998727">0917-899-8727</a></p>
                            </div>
                        </details>
                    </section>
                    </section>
                </aside>
                </div>
                <?= view('partials/student_form_footer') ?>
        </div>
    </main>

    <!-- Empathy Modal -->
    <div id="empathyModal" class="fixed inset-0 z-[100] hidden items-center justify-center">
        <div class="absolute inset-0 bg-black/30 backdrop-blur-sm transition-opacity duration-500 opacity-0" id="empathyBackdrop"></div>
        <div class="bg-white p-8 sm:p-10 max-w-md w-[90%] rounded-2xl text-center shadow-2xl relative z-10 scale-95 opacity-0 transition-all duration-500" id="empathyContent">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-2xl bg-gradient-to-br from-calm-50 to-calm-100 mb-5">
                <svg class="h-8 w-8 text-calm-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z" clip-rule="evenodd" /></svg>
            </div>
            <h2 class="text-2xl font-heading font-bold text-gray-900 mb-2">We hear you.</h2>
            <p class="text-gray-500 leading-relaxed mb-2 text-sm">
                You've taken a brave step by sharing what you feel.
            </p>
            <p class="text-gray-600 leading-relaxed mb-6 text-sm">
                Remember — you don't have to carry this alone.<br>
                <span class="text-primary font-semibold">Our counseling team has been notified and is ready to support you.</span>
            </p>
            <button id="closeModalBtn" class="w-full submit-btn text-white font-bold py-3.5 px-6 rounded-xl shadow-lg">I understand</button>
        </div>
    </div>

    <script>
        const textarea = document.getElementById('vent_text');
        const charCount = document.getElementById('charCount');
        textarea.addEventListener('input', function() {
            const len = this.value.length;
            charCount.textContent = len + ' / 2000';
            if (len > 1800) charCount.style.color = '#B3261E';
            else if (len > 1200) charCount.style.color = '#8A5700';
            else charCount.style.color = '#526259';
        });

        const moodDisclosure = document.getElementById('moodDisclosure');
        const moodToggle = document.getElementById('moodToggle');
        const moodSelections = document.getElementById('moodSelections');
        const moodButtons = [...document.querySelectorAll('.mood-btn')];
        const syncFeelings = () => { moodDisclosure.open = false; };
        syncFeelings();
        function renderMoodPicker(announce = false) {
            const selected = moodButtons.filter(button => button.classList.contains('active'));
            document.getElementById('emotionsInput').value = selected.map(button => button.dataset.mood).join(',');
            const count = document.getElementById('moodCount');
            count.hidden = selected.length === 0;
            count.textContent = selected.length + ' selected';
            moodSelections.hidden = selected.length === 0;
            moodSelections.replaceChildren();
            // Keep the collapsed summary compact even when every feeling is selected.
            selected.slice(0, 2).forEach(button => {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'mood-selection-chip';
                chip.dataset.mood = button.dataset.mood;
                chip.setAttribute('aria-label', 'Remove ' + button.dataset.mood);
                chip.textContent = button.textContent.trim().replace(/\s+/g, ' ') + ' ×';
                chip.addEventListener('click', () => {
                    button.classList.remove('active');
                    button.setAttribute('aria-pressed', 'false');
                    renderMoodPicker(true);
                    (moodSelections.querySelector('button') || moodToggle).focus();
                });
                moodSelections.appendChild(chip);
            });
            if (selected.length > 2) {
                const more = document.createElement('button');
                more.type = 'button';
                more.className = 'mood-selection-more';
                more.textContent = '+' + (selected.length - 2) + ' more';
                more.setAttribute('aria-label', 'Review all ' + selected.length + ' selected feelings');
                more.addEventListener('click', () => {
                    moodDisclosure.open = true;
                    document.getElementById('moodDone').focus();
                });
                moodSelections.appendChild(more);
            }
            if (announce) document.getElementById('moodSelectionStatus').textContent = selected.length
                ? 'Selected feelings: ' + selected.map(button => button.dataset.mood).join(', ')
                : 'No feelings selected';
        }
        function closeMoodPicker() {
            moodDisclosure.open = false;
            moodToggle.focus();
        }
        document.getElementById('moodDone').addEventListener('click', closeMoodPicker);
        moodDisclosure.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeMoodPicker();
            }
        });
        document.addEventListener('click', event => {
            if (moodDisclosure.open && !moodDisclosure.contains(event.target) && !moodSelections.contains(event.target)) {
                syncFeelings();
            }
        });
        moodButtons.forEach(button => button.addEventListener('click', () => {
            button.classList.toggle('active');
            button.setAttribute('aria-pressed', String(button.classList.contains('active')));
            renderMoodPicker(true);
        }));
        renderMoodPicker();

        document.getElementById('ventForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            const text = document.getElementById('btnText');
            const icon = document.getElementById('btnIcon');
            const spinner = document.getElementById('btnSpinner');
            const form = document.getElementById('ventForm');

            btn.classList.add('opacity-90', 'cursor-wait');
            text.innerText = 'Encrypting & Sending...';
            icon.classList.add('hidden');
            spinner.classList.remove('hidden');

            fetch(form.action, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/x-www-form-urlencoded', 
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: new URLSearchParams(new FormData(form)).toString()
            })
            .then(r => {
                // Session expired: server returned a non-JSON redirect or 401
                if (r.status === 401) {
                    btn.classList.remove('opacity-90', 'cursor-wait');
                    text.innerText = 'Submit Securely';
                    spinner.classList.add('hidden');
                    icon.classList.remove('hidden');
                    const errToast = document.createElement('div');
                    errToast.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 bg-amber-500 text-white px-6 py-3.5 rounded-xl shadow-xl text-sm font-semibold flex items-center gap-2.5 z-[110]';
                    errToast.innerHTML = '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg> Your session expired. <a href="/login" class="underline font-bold ml-1">Click here to log in again</a>.';
                    document.body.appendChild(errToast);
                    setTimeout(() => { errToast.style.opacity = '0'; errToast.style.transition = 'opacity 0.4s'; setTimeout(() => errToast.remove(), 400); }, 7000);
                    throw new Error('session_expired'); // stop the chain
                }
                if (!r.ok) {
                    return r.text().then(t => { throw new Error('Server error ' + r.status + ': ' + t.substring(0, 100)); });
                }
                return r.json();
            })
            .then(data => {
                // QA BUG FIX: Do not clear textarea or show 'Success' if backend returns error/rate-limit!
                if (data.status === 'error') {
                    btn.classList.remove('opacity-90', 'cursor-wait');
                    text.innerText = 'Submit Securely';
                    spinner.classList.add('hidden');
                    icon.classList.remove('hidden');
                    
                    const errToast = document.createElement('div');
                    errToast.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 bg-red-500 text-white px-6 py-3.5 rounded-xl shadow-xl shadow-red-500/20 text-sm font-semibold flex items-center gap-2.5 z-[110] animate-[slideUp_0.4s_ease-out]';
                    errToast.innerHTML = '<svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg> ' + data.message;
                    document.body.appendChild(errToast);
                    setTimeout(() => { errToast.style.opacity = '0'; errToast.style.transition = 'opacity 0.4s'; setTimeout(() => errToast.remove(), 400); }, 4000);
                    return;
                }

                textarea.value = '';
                charCount.textContent = '0 / 2000';
                btn.classList.remove('opacity-90', 'cursor-wait');
                btn.style.background = 'var(--form-primary)';
                text.innerText = 'Submitted Securely';
                spinner.classList.add('hidden');
                document.querySelectorAll('.mood-btn').forEach(b => {
                    b.classList.remove('active');
                    b.setAttribute('aria-pressed', 'false');
                });
                document.getElementById('emotionsInput').value = '';
                syncFeelings();
                renderMoodPicker();

                if (data.risk_level === 'HIGH' || data.risk_level === 'CRITICAL') {
                    const modal = document.getElementById('empathyModal');
                    const bd = document.getElementById('empathyBackdrop');
                    const ct = document.getElementById('empathyContent');
                    modal.classList.remove('hidden'); modal.classList.add('flex');
                    setTimeout(() => { bd.classList.replace('opacity-0','opacity-100'); ct.classList.remove('scale-95','opacity-0'); ct.classList.add('scale-100','opacity-100'); }, 50);
                    document.getElementById('closeModalBtn').onclick = () => {
                        bd.classList.replace('opacity-100','opacity-0'); ct.classList.remove('scale-100','opacity-100'); ct.classList.add('scale-95','opacity-0');
                        setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 500);
                    };
                } else {
                    const toast = document.createElement('div');
                    toast.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 bg-primary text-white px-6 py-3.5 rounded-xl shadow-xl shadow-primary/25 text-sm font-semibold flex items-center gap-2.5 z-50';
                    toast.innerHTML = '<svg class="w-5 h-5 text-gold" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg> Thank you for sharing. We hear you.';
                    document.body.appendChild(toast);
                    setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.5s'; setTimeout(() => toast.remove(), 500); }, 3500);
                }
                setTimeout(() => { btn.style.background = ''; text.innerText = 'Submit Securely'; icon.classList.remove('hidden'); }, 3000);
            })
            .catch(err => {
                if (err.message === 'session_expired') return; // already handled above
                console.error('Vent submission error:', err);
                text.innerText = 'Submit Securely'; spinner.classList.add('hidden'); icon.classList.remove('hidden');
                btn.classList.remove('opacity-90', 'cursor-wait');
                // Distinguish: SyntaxError = response wasn't JSON (unexpected server crash)
                // err.message starts with 'Server error' = we got an HTTP error back
                // Everything else = true network failure
                let userMsg = err instanceof SyntaxError
                    ? 'Server error. Your vent was not sent. Please try again.'
                    : (err.message && err.message.startsWith('Server error')
                        ? err.message.replace(/^Server error \d+:\s*/, '').trim() || 'Server error. Please try again.'
                        : 'Network error. Please check your connection and try again.');
                const errToast = document.createElement('div');
                errToast.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 bg-red-500 text-white px-6 py-3.5 rounded-xl shadow-xl text-sm font-semibold flex items-center gap-2.5 z-[110]';
                errToast.innerHTML = '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg> ' + userMsg;
                document.body.appendChild(errToast);
                setTimeout(() => { errToast.style.opacity = '0'; errToast.style.transition = 'opacity 0.4s'; setTimeout(() => errToast.remove(), 400); }, 5000);
            });
        });

    </script>
</body>
</html>
