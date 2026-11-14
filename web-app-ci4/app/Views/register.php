<?php
helper(['form', 'url']);
$sectionsByGrade = $sectionsByGrade ?? ['7' => [], '8' => [], '9' => [], '10' => []];
$selectedGrade = (string) set_value('grade_level');
$selectedSection = (string) set_value('section');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - Registration</title>
    <link rel="preload" as="image" href="<?= base_url('images/logo.png?v=6') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <?= view('partials/school_brand', ['studentPalette' => true]) ?>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 
                        sans: ['Nunito', 'sans-serif'],
                        heading: ['Nunito', 'sans-serif']
                    },
                    colors: {
                        white: '#FBFDFB', 
                        black: '#131A15', 
                        gray: {
                            50: '#F4F7F5',
                            100: '#E6EBE8',
                            200: '#CFD9D4',
                            300: '#AAB8B1',
                            400: '#82958B',
                            500: '#61756B',
                            600: '#4D5D55',
                            700: '#3D4943',
                            800: '#2E3833',
                            900: '#212825',
                        },
                        red: {
                            50: '#FDF7F7',
                            100: '#FAEDED',
                            200: '#F4D6D6',
                            500: '#D95A5A',
                            600: '#C24545',
                            700: '#A33535',
                        },
                        primary: StudentShieldBrand.primary,
                        gold: { DEFAULT: '#D9AA2E', light: '#EAC255', bright: '#FCEEA1', dark: '#B5881D' },
                    }
                }
            }
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Nunito', sans-serif; background-color: #0F8A3A; overflow-x: hidden; }
        h1, h2, h3, .font-heading { font-family: 'Nunito', sans-serif; }

        .bg-student {
            background:
                radial-gradient(ellipse at 85% 85%, rgba(0,0,0,0.15) 0%, transparent 60%),
                radial-gradient(ellipse at 70% 10%, rgba(217,170,46,0.08) 0%, transparent 45%),
                linear-gradient(160deg, #128F42 0%, #0E7C38 55%, #09682D 100%);
        }

        .bg-grid {
            position: absolute; inset: 0; z-index: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        .student-card {
            background: rgba(255, 255, 255, 0.10);
            backdrop-filter: blur(30px);
            -webkit-backdrop-filter: blur(30px);
            border: 1px solid rgba(255, 255, 255, 0.22);
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255,255,255,0.05) inset;
        }

        .input-dark {
            background: rgba(0, 0, 0, 0.35);
            border: 1.5px solid rgba(255, 255, 255, 0.22); 
            color: #ffffff;
            transition: all 0.3s ease;
        }
        .input-dark:focus {
            background: rgba(0, 0, 0, 0.45);
            box-shadow: 0 0 0 4px rgba(242, 195, 0, 0.2);
            border-color: #F2C300;
        }
        .input-dark::placeholder { color: rgba(255,255,255,0.75); }

        .glow-blob {
            position: absolute; border-radius: 50%; filter: blur(90px); opacity: 0.15; z-index: 0;
            animation: pulseGlow 10s ease-in-out infinite alternate; pointer-events: none;
            will-change: transform, opacity;
            transform: translateZ(0);
        }
        @keyframes pulseGlow {
            0% { opacity: 0.1; transform: scale(0.9) translate(0,0) translateZ(0); }
            100% { opacity: 0.2; transform: scale(1.1) translate(-20px, 20px) translateZ(0); }
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #cda434, #e6c55e);
            color: #0A1C11;
            position: relative; overflow: hidden;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 15px -3px rgba(217, 170, 46, 0.2);
        }
        .submit-btn::after {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(to right, transparent, rgba(255,255,255,0.3), transparent);
            transform: skewX(-20deg) translateX(-150%);
            transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .submit-btn:hover {
            box-shadow: 0 8px 25px -5px rgba(217, 170, 46, 0.4);
            transform: translateY(-1px) scale(1.01);
            background: linear-gradient(135deg, #dfb745, #f5d675);
        }
        .submit-btn:hover::after { transform: skewX(-20deg) translateX(150%); }
        
        .spotlight {
            background: radial-gradient(
                600px circle at var(--x) var(--y),
                rgba(217, 170, 46, 0.05),
                transparent 80%
            );
        }

        @keyframes scaleIn {
            0% { transform: translateY(15px) scale(0.95); opacity: 0; }
            100% { transform: translateY(0) scale(1); opacity: 1; }
        }
        
        @media (prefers-reduced-motion: reduce) {
            /* Remove entrance animations; pausing their first frame hides the form at opacity: 0. */
            *, ::before, ::after { animation: none !important; transition: none !important; }
        }
    </style>
<?= view('partials/ui_polish') ?>
<link rel="stylesheet" href="<?= base_url('css/registration.css?v=' . filemtime(FCPATH . 'css/registration.css')) ?>">
</head>
<body class="student-registration bg-student min-h-[100dvh] w-full flex flex-col items-center justify-center p-3 sm:p-6 lg:p-8 relative overflow-x-hidden overflow-y-auto" onmousemove="updateSpotlight(event)">

    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div id="spotlight-overlay" class="spotlight absolute inset-0 z-10"></div>
        <div class="bg-grid absolute inset-0"></div>
        <div class="glow-blob bg-gold w-[300px] h-[300px] sm:w-[500px] sm:h-[500px] top-[-5%] right-[-10%] mix-blend-screen"></div>
        <div class="glow-blob bg-primary-light w-[400px] h-[400px] sm:w-[600px] sm:h-[600px] bottom-[-10%] left-[-15%] mix-blend-screen" style="animation-delay: -5s; animation-direction: alternate-reverse;"></div>
    </div>

    <div class="registration-shell w-full z-20 relative animate-[fadeInUp_0.32s_ease-out] my-auto">
        
        <!-- Responsive Card Density: structured 8px rhythm across breakpoints -->
        <div class="student-card p-5 sm:p-8 md:p-9 rounded-2xl sm:rounded-3xl relative overflow-hidden group transition-shadow duration-500">
            
            <div class="registration-heading">
                <img src="<?= base_url('images/logo.png?v=6') ?>" alt="SVBSM Logo" class="registration-logo object-contain drop-shadow-[0_10px_20px_rgba(0,0,0,0.45)]">
                <div class="registration-heading-copy">
                    <p class="registration-school text-white/90 font-semibold tracking-wide">St. Vincent Blessed School of Manila, Inc.</p>
                    <h1 class="font-heading font-extrabold tracking-tight text-white leading-tight">Student <span class="text-gold">Registration</span></h1>
                    <p class="registration-subtitle text-white/85 font-medium">Use your school details. Guardian consent and Guidance Office approval are needed before you can sign in.</p>
                </div>
            </div>

            <!-- Error/Success Flash -->
            <?php if (session()->getFlashdata('error')): ?>
                <div class="bg-red-500/10 backdrop-blur-md border border-red-500/30 p-3.5 sm:p-4 mb-4 sm:mb-6 rounded-2xl shadow-lg">
                    <p class="text-[16px] sm:text-[17px] font-bold text-red-200 leading-snug tracking-tight text-center"><?= esc(session()->getFlashdata('error')) ?></p>
                </div>
            <?php endif; ?>
            <?php if (session()->getFlashdata('success')): ?>
                <div class="bg-green-500/10 backdrop-blur-md border border-green-500/30 p-3.5 sm:p-4 mb-4 sm:mb-6 rounded-2xl shadow-lg">
                    <p class="text-[16px] sm:text-[17px] font-bold text-green-200 leading-snug tracking-tight text-center"><?= esc(session()->getFlashdata('success')) ?></p>
                </div>
            <?php endif; ?>
            <?php if (isset($validation)): ?>
                <div class="bg-red-500/10 backdrop-blur-md border border-red-500/30 p-3.5 sm:p-4 mb-4 sm:mb-6 rounded-2xl shadow-lg text-red-200 text-sm">
                    <?= $validation->listErrors() ?>
                </div>
            <?php endif; ?>

            <!-- Registration Form -->
            <form action="<?= base_url('register/process') ?>" method="post" class="registration-form relative z-10 w-full">
                <?= csrf_field() ?>
                
                <div class="registration-field">
                    <label for="name" class="registration-label">Full Name</label>
                    <div class="relative group/input">
                        <div class="registration-input-icon pointer-events-none">
                            <i class="fas fa-user text-white/50 group-focus-within/input:text-gold transition-colors text-[15px]"></i>
                        </div>
                        <input type="text" name="name" id="name" required maxlength="255" autocomplete="name" value="<?= set_value('name') ?>"
                               class="w-full pl-11 pr-4 input-dark registration-control rounded-xl text-[15px] font-semibold outline-none"
                               placeholder="Juan Dela Cruz">
                    </div>
                </div>

                <div class="registration-field">
                    <label for="lrn" class="registration-label">Learner Reference Number (LRN)</label>
                    <div class="relative group/input">
                        <div class="registration-input-icon pointer-events-none">
                            <i class="fas fa-id-card text-white/50 group-focus-within/input:text-gold transition-colors text-[15px]"></i>
                        </div>
                        <input type="text" name="lrn" id="lrn" required inputmode="numeric" pattern="[0-9]{12}" minlength="12" maxlength="12" value="<?= set_value('lrn') ?>" aria-describedby="lrnHelp"
                               class="w-full pl-11 pr-16 input-dark registration-control rounded-xl text-[15px] font-semibold outline-none tabular-nums"
                               placeholder="12-digit numeric LRN">
                        <span id="lrnCount" class="registration-lrn-badge" aria-hidden="true">0/12</span>
                    </div>
                    <p id="lrnHelp" class="registration-help">Use the 12-digit numeric LRN on your school record or report card.</p>
                </div>

                <div class="registration-school-fields">
                    <div class="registration-field">
                        <label for="grade_level" class="registration-label">Grade Level</label>
                        <div class="relative group/input">
                            <div class="registration-input-icon pointer-events-none">
                                <i class="fas fa-layer-group text-white/50 group-focus-within/input:text-gold transition-colors text-[15px]"></i>
                            </div>
                            <select name="grade_level" id="grade_level" required class="w-full pl-10 pr-8 input-dark registration-control registration-select rounded-xl text-[14px] font-semibold outline-none" aria-controls="section">
                                <option value="">Choose grade</option>
                                <?php foreach (['7','8','9','10'] as $grade): ?><option value="<?= $grade ?>" <?= $selectedGrade === $grade ? 'selected' : '' ?>>Grade <?= $grade ?></option><?php endforeach; ?>
                            </select>
                            <span class="registration-select-chevron pointer-events-none" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M5 7.5l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </div>
                    </div>

                    <div class="registration-field">
                        <label for="section" class="registration-label">Section</label>
                        <div class="relative group/input">
                            <div class="registration-input-icon pointer-events-none">
                                <i class="fas fa-chalkboard-teacher text-white/50 group-focus-within/input:text-gold transition-colors text-[15px]"></i>
                            </div>
                            <select name="section" id="section" required aria-describedby="sectionHelp" class="w-full pl-10 pr-8 input-dark registration-control registration-select rounded-xl text-[14px] font-semibold outline-none">
                                <option value="">Choose section</option>
                                <?php foreach ($sectionsByGrade[$selectedGrade] ?? [] as $section): ?><option value="<?= esc($section, 'attr') ?>" <?= $selectedSection === $section ? 'selected' : '' ?>><?= esc($section) ?></option><?php endforeach; ?>
                            </select>
                            <span class="registration-select-chevron pointer-events-none" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M5 7.5l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </div>
                    </div>
                    <p id="sectionHelp" class="registration-help registration-school-help" role="status">Choose your grade first.</p>
                </div>

                <div class="registration-guardian registration-field">
                    <label for="parent_contact" class="registration-label">Parent or guardian mobile (required)</label>
                    <div class="relative group/input">
                        <div class="registration-input-icon pointer-events-none">
                            <i class="fas fa-phone-alt text-white/50 group-focus-within/input:text-gold transition-colors text-[15px]"></i>
                        </div>
                        <input type="tel" name="parent_contact" id="parent_contact" required maxlength="32" aria-describedby="guardian-contact-help" value="<?= set_value('parent_contact') ?>"
                               class="w-full pl-11 pr-4 input-dark registration-control rounded-xl text-[15px] font-semibold outline-none tabular-nums"
                               placeholder="09123456789">
                    </div>
                    <p id="guardian-contact-help" class="registration-help">School staff verify this number before sending a consent request. If you cannot safely provide a number, contact the Guidance Office for help.</p>
                </div>

                <div class="registration-password-fields grid grid-cols-1 md:grid-cols-2">
                    <div class="registration-field">
                        <label for="password" class="registration-label">Create Password</label>
                        <div class="relative group/input">
                            <div class="registration-input-icon pointer-events-none">
                                <i class="fas fa-lock text-white/50 group-focus-within/input:text-gold transition-colors text-[15px]"></i>
                            </div>
                            <input type="password" data-password-toggle maxlength="72" autocomplete="new-password" name="password" id="password" required aria-describedby="password-criteria"
                                   class="w-full pl-11 pr-4 input-dark registration-control rounded-xl text-[15px] font-semibold outline-none"
                                   placeholder="At least 8 characters">
                        </div>
                    </div>

                    <div class="registration-field">
                        <label for="password_confirm" class="registration-label">Confirm Password</label>
                        <div class="relative group/input">
                            <div class="registration-input-icon pointer-events-none">
                                <i class="fas fa-lock text-white/50 group-focus-within/input:text-gold transition-colors text-[15px]"></i>
                            </div>
                            <input type="password" data-password-toggle maxlength="72" autocomplete="new-password" name="password_confirm" id="password_confirm" required
                                   class="w-full pl-11 pr-4 input-dark registration-control rounded-xl text-[15px] font-semibold outline-none"
                                   placeholder="Re-enter password">
                        </div>
                        <p aria-live="polite" id="password-match-msg" class="text-[13px] text-red-300 mt-1 hidden font-semibold flex items-center gap-1.5"><i class="fas fa-exclamation-circle"></i> Passwords do not match</p>
                    </div>

                    <!-- Anti-CLS Password Requirement Chips spanning cleanly under both password fields -->
                    <div class="registration-criteria" id="password-criteria">
                        <div id="req-length" class="registration-chip"><i class="far fa-circle text-[13px] text-white/55"></i><span>8+ characters</span></div>
                        <div id="req-upper" class="registration-chip"><i class="far fa-circle text-[13px] text-white/55"></i><span>1 uppercase</span></div>
                        <div id="req-lower" class="registration-chip"><i class="far fa-circle text-[13px] text-white/55"></i><span>1 lowercase</span></div>
                        <div id="req-number" class="registration-chip"><i class="far fa-circle text-[13px] text-white/55"></i><span>1 number</span></div>
                        <div id="req-special" class="registration-chip"><i class="far fa-circle text-[13px] text-white/55"></i><span>1 special char</span></div>
                    </div>
                </div>

                <!-- Touch-Optimized Terms & Privacy Checkbox (Min 44px height) -->
                <div class="registration-consent">
                    <label class="flex items-center gap-3 px-3 py-2 rounded-xl cursor-pointer min-h-[44px] select-none hover:bg-white/5 transition-colors group/checkbox">
                        <div class="relative flex items-center justify-center shrink-0">
                            <input type="checkbox" name="terms" id="terms" value="1" required class="peer sr-only" <?= set_checkbox('terms', '1') ?>>
                            <div class="w-5 h-5 border-2 border-white/45 rounded-md bg-white/5 peer-checked:bg-gold peer-checked:border-gold transition-all duration-300"></div>
                            <i class="fas fa-check absolute text-[13px] text-[#0A1C11] opacity-0 peer-checked:opacity-100 transition-opacity duration-300 pointer-events-none"></i>
                        </div>
                        <span class="text-sm sm:text-[15px] text-white/95 leading-snug">
                            I agree to the <button type="button" onclick="openTermsModal('terms')" class="text-gold hover:text-gold-light font-bold underline underline-offset-2 transition-colors py-1">Terms and Conditions</button> &amp; <button type="button" onclick="openTermsModal('privacy')" class="text-gold hover:text-gold-light font-bold underline underline-offset-2 transition-colors py-1">Data Privacy Policy</button>.
                        </span>
                    </label>
                </div>

                <p id="registrationFeedback" role="alert" class="registration-feedback" hidden></p>
                <button type="submit" id="submitBtn" class="submit-btn w-full font-black py-3.5 px-6 rounded-xl focus:outline-none focus:ring-4 focus:ring-gold/30 flex items-center justify-center gap-2.5 h-[52px] group/btn">
                    <span id="btnText" class="font-heading text-[16px] font-extrabold tracking-wide text-[#0B2415]">Submit Registration</span>
                    <i id="btnIcon" class="fas fa-paper-plane text-[#0B2415] text-sm group-hover/btn:translate-x-1 group-hover/btn:-translate-y-1 transition-all duration-300"></i>
                    <svg id="btnSpinner" class="animate-spin h-5 w-5 text-[#0B2415] hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-30" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                </button>
            </form>
            
            <!-- Instant Direct Navigation to Login (Isolated from Form Events with 44px Touch Target) -->
            <div class="registration-footer text-center relative z-20">
                <a href="<?= base_url('login') ?>" class="inline-flex items-center gap-2 text-[15px] font-semibold text-white/85 hover:text-gold transition-colors py-2 px-4 rounded-xl hover:bg-white/5 min-h-[44px]">
                    <i class="fas fa-arrow-left text-[13px]"></i> Back to Login
                </a>
            </div>
            
        </div>
    </div>

    <!-- Terms and Conditions Modal (Sticky Header & Footer with Independent Internal Scroll) -->
    <div id="termsModal" role="dialog" aria-modal="true" aria-label="Terms and data privacy" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-3 sm:p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm transition-opacity opacity-0" id="termsBackdrop" onclick="closeTermsModal()"></div>
        
        <!-- Modal Content Box -->
        <div class="bg-[#0B7533] border border-white/30 rounded-2xl sm:rounded-3xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col relative z-10 transform scale-95 opacity-0 transition-all duration-300 overflow-hidden" id="termsContentBox">
            <!-- Sticky Header -->
            <div class="p-4 sm:p-6 border-b border-white/20 flex items-center justify-between sticky top-0 z-20 bg-[#0F8A3A] shrink-0">
                <h2 class="text-lg sm:text-2xl font-heading font-bold text-white flex items-center gap-2">
                    <i class="fas fa-shield-alt text-[#FFF4C7]"></i> Terms & Data Privacy
                </h2>
                <button type="button" onclick="closeTermsModal()" aria-label="Close terms and data privacy" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors min-h-[36px] min-w-[36px]">
                    <i class="fas fa-times text-base"></i>
                </button>
            </div>
            
            <!-- Scrollable Body -->
            <div class="p-4 sm:p-6 overflow-y-auto flex-1 custom-scrollbar text-white space-y-4 text-sm sm:text-base leading-relaxed">
                <p>StudentShield is not monitored 24/7. Approach the Guidance Office if you need help while waiting for a response.</p><p>Welcome to Student Shield! By registering an account, you agree to the following terms regarding your usage and how we handle your data.</p>
                
                <h3 id="terms-section-terms" class="text-gold font-semibold text-base sm:text-lg mt-4">1. Purpose of the Portal</h3>
                <p>Student Shield is a secure platform designed for St. Vincent Blessed School of Manila (SVBSM) students to report incidents (such as bullying or conflicts) and to express concerns ("Vents") to the Guidance Counselor. This platform is strictly for school-related well-being and disciplinary matters.</p>

                <h3 id="terms-section-privacy" class="text-gold font-semibold text-base sm:text-lg mt-4">2. Data Collection and Privacy</h3>
                <p>The school handles your information under its privacy policy and the <strong>Data Privacy Act of 2012 (R.A. 10173)</strong>.</p>
                <ul class="list-disc pl-5 space-y-1">
                    <li>Registration details are used to verify your account, request guardian consent, and provide guidance support.</li>
                    <li>Safe Space messages are screened to help prioritize counselor review. Message text may be processed by configured external services. Stored vent text is encrypted, and access follows assigned staff roles.</li>
                    <li>School staff may contact a verified guardian or a school-approved emergency contact when a safety concern requires protective action.</li>
                </ul>

                <h3 class="text-gold font-semibold text-base sm:text-lg mt-4">3. Honest Reporting</h3>
                <p>You agree to provide true, accurate, and complete information when reporting incidents. Submitting false reports or fabricating evidence with the intent to harm another student's reputation is a violation of school policy and may result in disciplinary action.</p>

                <h3 class="text-gold font-semibold text-base sm:text-lg mt-4">4. Account Security</h3>
                <p>You are responsible for maintaining the confidentiality of your password. Do not share your account credentials with anyone. If you suspect your account has been compromised, notify the guidance office immediately.</p>
            </div>

            <!-- Sticky Footer (Always Visible Confirmation Button) -->
            <div class="p-3.5 sm:p-5 border-t border-white/20 bg-[#09662C] sticky bottom-0 z-20 flex justify-end shrink-0">
                <button type="button" onclick="closeTermsModal()" class="bg-gold hover:bg-gold-light text-[#173E25] font-bold py-2.5 sm:py-3 px-6 sm:px-8 rounded-xl transition-colors min-h-[44px] shadow-lg">
                    I Understand
                </button>
            </div>
        </div>
    </div>

    <!-- Review Registration Details Confirmation Modal -->
    <div id="confirmRegistrationModal" role="dialog" aria-modal="true" aria-labelledby="confirmRegistrationTitle" class="fixed inset-0 z-[105] hidden flex items-center justify-center p-3 sm:p-4">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm transition-opacity opacity-0" id="confirmRegistrationBackdrop"></div>

        <div class="bg-[#0B7533] border border-white/30 rounded-2xl sm:rounded-3xl shadow-2xl w-full max-w-lg max-h-[92dvh] flex flex-col relative z-10 transform scale-95 opacity-0 transition-all duration-200 overflow-hidden" id="confirmRegistrationBox">
            <div class="p-3.5 sm:p-5 border-b border-white/20 flex items-center justify-between gap-3 sticky top-0 z-20 bg-[#0F8A3A] shrink-0">
                <div class="min-w-0">
                    <h2 id="confirmRegistrationTitle" class="text-[17px] sm:text-xl font-heading font-extrabold text-white flex items-center gap-2">
                        <i class="fas fa-clipboard-check text-[#FFF4C7]" aria-hidden="true"></i>
                        <span>Confirm Your Details</span>
                    </h2>
                    <p class="text-xs sm:text-[13px] text-white/85 mt-0.5 leading-snug">Double-check your school record and guardian mobile before submitting.</p>
                </div>
                <button type="button" id="confirmCloseBtn" aria-label="Close registration confirmation" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors min-h-[36px] min-w-[36px] shrink-0">
                    <i class="fas fa-times text-base" aria-hidden="true"></i>
                </button>
            </div>

            <div class="p-3.5 sm:p-6 overflow-y-auto flex-1 custom-scrollbar text-white space-y-2.5 sm:space-y-4">
                <dl class="confirm-summary-list" aria-label="Registration summary">
                    <div class="confirm-summary-row">
                        <dt>Full Name</dt>
                        <dd id="confirmName">-</dd>
                    </div>
                    <div class="confirm-summary-row">
                        <dt>12-Digit LRN</dt>
                        <dd id="confirmLrn" class="tabular-nums font-bold tracking-wide text-[#FFF4C7]">-</dd>
                    </div>
                    <div class="confirm-summary-row">
                        <dt>Grade &amp; Section</dt>
                        <dd id="confirmPlacement">-</dd>
                    </div>
                    <div class="confirm-summary-row">
                        <dt>Parent / Guardian Mobile</dt>
                        <dd id="confirmParentContact" class="tabular-nums font-bold text-[#FFF4C7]">-</dd>
                    </div>
                </dl>

                <p class="confirm-modal-note">
                    <i class="fas fa-info-circle text-[#FFF4C7] shrink-0 mt-0.5" aria-hidden="true"></i>
                    <span>Guardian consent SMS and Guidance Office approval will use these details. You can still edit them if anything looks wrong.</span>
                </p>
            </div>

            <div class="confirm-modal-footer p-3 sm:p-5 border-t border-white/20 bg-[#09662C] sticky bottom-0 z-20 flex flex-col-reverse sm:flex-row sm:justify-end gap-2 sm:gap-3 shrink-0">
                <button type="button" id="confirmCancelBtn" class="confirm-btn-secondary font-bold py-2.5 px-5 rounded-xl transition-colors min-h-[44px] w-full sm:w-auto">
                    Edit Details
                </button>
                <button type="button" id="confirmSubmitBtn" class="submit-btn font-extrabold py-2.5 px-6 rounded-xl flex items-center justify-center gap-2 min-h-[44px] w-full sm:w-auto text-[#0B2415]">
                    <span>Confirm &amp; Submit</span>
                    <i class="fas fa-check text-xs" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
    
    <style>
        #termsContentBox h3 { color: #FFF4C7; }
        #termsContentBox :is(button,a):focus-visible,
        #confirmRegistrationBox :is(button,a):focus-visible { outline:3px solid #FFF4C7; outline-offset:3px; }
        #termsContentBox .custom-scrollbar,
        #confirmRegistrationBox .custom-scrollbar { scrollbar-color:rgba(255,255,255,.5) #0B7533; }
        #termsContentBox .custom-scrollbar::-webkit-scrollbar-thumb,
        #confirmRegistrationBox .custom-scrollbar::-webkit-scrollbar-thumb { background:rgba(255,255,255,.5); }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.02); border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(217, 170, 46, 0.5); }
    </style>

<script>
    let termsTrigger = null;
    function openTermsModal(section) {
        termsTrigger = document.activeElement;
        const modal    = document.getElementById('termsModal');
        const backdrop = document.getElementById('termsBackdrop');
        const content  = document.getElementById('termsContentBox');
        const body     = content.querySelector('.overflow-y-auto');

        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        modal.querySelector('button').focus();

        // Animate in, then teleport scroll to the requested section
        setTimeout(() => {
            backdrop.classList.remove('opacity-0');
            content.classList.remove('opacity-0', 'scale-95');
            content.classList.add('opacity-100', 'scale-100');

            if (section) {
                // Wait one more frame so the element is fully laid-out before scrolling
                requestAnimationFrame(() => {
                    const target = document.getElementById('terms-section-' + section);
                    if (target && body) {
                        // scrollIntoView inside the overflow container
                        const offset = target.offsetTop - body.offsetTop - 12; // 12px breathing room
                        body.scrollTo({ top: offset, behavior: 'smooth' });
                    }
                });
            } else {
                // Default: always start from the top
                if (body) body.scrollTop = 0;
            }
        }, 10);
    }

    function closeTermsModal() {
        const modal    = document.getElementById('termsModal');
        const backdrop = document.getElementById('termsBackdrop');
        const content  = document.getElementById('termsContentBox');

        backdrop.classList.add('opacity-0');
        content.classList.remove('opacity-100', 'scale-100');
        content.classList.add('opacity-0', 'scale-95');

        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
            termsTrigger?.focus();
            // Reset scroll so next open feels fresh
            const body = content.querySelector('.overflow-y-auto');
            if (body) body.scrollTop = 0;
        }, 300);
    }

    const registrationForm = document.querySelector('form.registration-form');
    let registrationConfirmed = false;
    let confirmModalTrigger = null;

    function openConfirmRegistrationModal() {
        const modal = document.getElementById('confirmRegistrationModal');
        const backdrop = document.getElementById('confirmRegistrationBackdrop');
        const box = document.getElementById('confirmRegistrationBox');
        const gradeSelect = document.getElementById('grade_level');
        const sectionSelect = document.getElementById('section');
        const gradeLabel = gradeSelect.value ? ('Grade ' + gradeSelect.value) : '';
        const sectionLabel = sectionSelect.value ? sectionSelect.options[sectionSelect.selectedIndex]?.text || sectionSelect.value : '';

        document.getElementById('confirmName').textContent = document.getElementById('name').value.trim();
        document.getElementById('confirmLrn').textContent = document.getElementById('lrn').value.trim();
        document.getElementById('confirmPlacement').textContent = [gradeLabel, sectionLabel].filter(Boolean).join(' · ');
        document.getElementById('confirmParentContact').textContent = document.getElementById('parent_contact').value.trim();

        const cancelBtn = document.getElementById('confirmCancelBtn');
        const submitModalBtn = document.getElementById('confirmSubmitBtn');
        const closeBtn = document.getElementById('confirmCloseBtn');
        cancelBtn.disabled = false;
        submitModalBtn.disabled = false;
        closeBtn.disabled = false;

        confirmModalTrigger = document.activeElement;
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        setTimeout(() => {
            backdrop.classList.remove('opacity-0');
            box.classList.remove('opacity-0', 'scale-95');
            box.classList.add('opacity-100', 'scale-100');
            cancelBtn.focus();
        }, 10);
    }

    function closeConfirmRegistrationModal(restoreFocus = true) {
        const modal = document.getElementById('confirmRegistrationModal');
        if (modal.classList.contains('hidden')) return;
        const backdrop = document.getElementById('confirmRegistrationBackdrop');
        const box = document.getElementById('confirmRegistrationBox');

        backdrop.classList.add('opacity-0');
        box.classList.remove('opacity-100', 'scale-100');
        box.classList.add('opacity-0', 'scale-95');

        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
            if (restoreFocus) {
                (confirmModalTrigger && typeof confirmModalTrigger.focus === 'function'
                    ? confirmModalTrigger
                    : document.getElementById('submitBtn')).focus();
            }
        }, 200);
    }

    window.addEventListener('pageshow', () => {
        registrationConfirmed = false;
        const button = document.getElementById('submitBtn');
        button.disabled = false;
        button.classList.remove('opacity-80', 'cursor-wait', 'scale-[0.98]');
        document.getElementById('btnText').textContent = 'Submit Registration';
        document.getElementById('btnIcon').classList.remove('hidden');
        document.getElementById('btnSpinner').classList.add('hidden');
        const confirmModal = document.getElementById('confirmRegistrationModal');
        if (confirmModal && !confirmModal.classList.contains('hidden')) {
            confirmModal.classList.add('hidden');
            document.body.style.overflow = '';
        }
        document.getElementById('confirmCancelBtn').disabled = false;
        document.getElementById('confirmSubmitBtn').disabled = false;
        document.getElementById('confirmCloseBtn').disabled = false;
    });

    function updateSpotlight(e) {
        const overlay = document.getElementById('spotlight-overlay');
        overlay.style.setProperty('--x', `${e.clientX}px`);
        overlay.style.setProperty('--y', `${e.clientY}px`);
    }

    document.getElementById('termsModal').addEventListener('keydown', event => {
        if (event.key === 'Escape') { closeTermsModal(); return; }
        if (event.key === 'Tab') {
            const buttons = event.currentTarget.querySelectorAll('button');
            const first = buttons[0], last = buttons[buttons.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });

    document.getElementById('confirmRegistrationBackdrop').addEventListener('click', () => closeConfirmRegistrationModal(true));
    document.getElementById('confirmCloseBtn').addEventListener('click', () => closeConfirmRegistrationModal(true));
    document.getElementById('confirmCancelBtn').addEventListener('click', () => closeConfirmRegistrationModal(true));
    document.getElementById('confirmSubmitBtn').addEventListener('click', () => {
        const cancelBtn = document.getElementById('confirmCancelBtn');
        const submitModalBtn = document.getElementById('confirmSubmitBtn');
        const closeBtn = document.getElementById('confirmCloseBtn');
        cancelBtn.disabled = true;
        submitModalBtn.disabled = true;
        closeBtn.disabled = true;
        registrationConfirmed = true;

        const modal = document.getElementById('confirmRegistrationModal');
        modal.classList.add('hidden');
        document.body.style.overflow = '';

        if (typeof registrationForm.requestSubmit === 'function') {
            registrationForm.requestSubmit();
        } else {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.classList.add('opacity-80', 'cursor-wait', 'scale-[0.98]');
            document.getElementById('btnText').innerText = 'Processing...';
            document.getElementById('btnIcon').classList.add('hidden');
            document.getElementById('btnSpinner').classList.remove('hidden');
            registrationForm.submit();
        }
    });
    document.getElementById('confirmRegistrationModal').addEventListener('keydown', event => {
        if (event.key === 'Escape') { closeConfirmRegistrationModal(true); return; }
        if (event.key === 'Tab') {
            const buttons = Array.from(event.currentTarget.querySelectorAll('button:not(:disabled)'));
            if (buttons.length === 0) return;
            const first = buttons[0], last = buttons[buttons.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });

    registrationForm.addEventListener('input', () => { registrationConfirmed = false; });
    registrationForm.addEventListener('change', () => { registrationConfirmed = false; });

    function passwordRequirements(value) {
        return {
            'req-length': value.length >= 8,
            'req-upper': /[A-Z]/.test(value),
            'req-lower': /[a-z]/.test(value),
            'req-number': /[0-9]/.test(value),
            'req-special': /[^A-Za-z0-9]/.test(value)
        };
    }

    registrationForm.addEventListener('submit', function(e) {
        const pass = document.getElementById('password').value;
        const confirmPass = document.getElementById('password_confirm').value;
        const feedback = document.getElementById('registrationFeedback');

        if (!Object.values(passwordRequirements(pass)).every(Boolean) || pass !== confirmPass) {
            e.preventDefault();
            registrationConfirmed = false;
            feedback.textContent = 'Check the password requirements and make sure both passwords match.';
            feedback.hidden = false;
            document.getElementById('password').focus();
            return;
        }

        feedback.hidden = true;

        if (!registrationConfirmed) {
            e.preventDefault();
            openConfirmRegistrationModal();
            return;
        }

        const btn = document.getElementById('submitBtn');
        const text = document.getElementById('btnText');
        const icon = document.getElementById('btnIcon');
        const spinner = document.getElementById('btnSpinner');
        btn.disabled = true;
        btn.classList.add('opacity-80', 'cursor-wait', 'scale-[0.98]');
        text.innerText = 'Processing...';
        icon.classList.add('hidden');
        spinner.classList.remove('hidden');
    });

    document.getElementById('password').addEventListener('input', function(e) {
        for (const [id, isValid] of Object.entries(passwordRequirements(e.target.value))) {
            const el = document.getElementById(id);
            const icon = el.querySelector('i');
            
            if (isValid) {
                el.classList.add('is-met');
                icon.className = 'fas fa-check-circle text-[13px] text-emerald-300';
            } else {
                el.classList.remove('is-met');
                icon.className = 'far fa-circle text-[13px] text-white/55';
            }
        }
        checkPasswordMatch();
    });

    document.getElementById('password_confirm').addEventListener('input', checkPasswordMatch);

    function checkPasswordMatch() {
        const pass = document.getElementById('password').value;
        const confirm = document.getElementById('password_confirm').value;
        const msg = document.getElementById('password-match-msg');
        const mismatch = confirm.length > 0 && pass !== confirm;
        document.getElementById('password_confirm').setCustomValidity(mismatch ? 'Passwords do not match.' : '');
        document.getElementById('password_confirm').setAttribute('aria-invalid', String(mismatch));
        msg.classList.toggle('hidden', !mismatch);
    }
</script>
<?= view('partials/password_visibility') ?>
<script>
const sectionsByGrade = <?= json_encode($sectionsByGrade, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const gradeField = document.getElementById('grade_level');
const sectionField = document.getElementById('section');
function updateSections() {
    const previous = sectionField.value;
    sectionField.replaceChildren(new Option(gradeField.value ? 'Choose section' : 'Choose grade first', ''));
    const sections = sectionsByGrade[gradeField.value] || [];
    sections.forEach(section => sectionField.add(new Option(section, section, false, section === previous)));
    document.getElementById('sectionHelp').textContent = !gradeField.value ? 'Choose your grade first.'
        : sections.length ? 'Choose the section on your school record.'
        : 'No sections are listed yet. Please contact the Guidance Office.';
}
gradeField.addEventListener('change', updateSections);
updateSections();
const lrnField = document.getElementById('lrn');
const lrnCount = document.getElementById('lrnCount');
function syncLrnCounter() {
    const len = lrnField.value.length;
    if (!lrnCount) return;
    lrnCount.textContent = len + '/12';
    lrnCount.classList.toggle('is-complete', len === 12);
}
lrnField.addEventListener('input', () => {
    lrnField.value = lrnField.value.replace(/[^0-9]/g, '').slice(0, 12);
    syncLrnCounter();
});
lrnField.addEventListener('paste', event => {
    event.preventDefault();
    const digits = event.clipboardData.getData('text').replace(/[^0-9]/g, '');
    lrnField.value = (lrnField.value.slice(0, lrnField.selectionStart) + digits + lrnField.value.slice(lrnField.selectionEnd)).slice(0, 12);
    syncLrnCounter();
});
syncLrnCounter();
</script>
</body>
</html>
