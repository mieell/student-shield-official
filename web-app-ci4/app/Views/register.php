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
        @media (max-width: 1440px) { html { font-size: 15px; } }
        @media (max-width: 1280px) { html { font-size: 14px; } }
        @media (max-width: 768px) { html { font-size: 16px; } } /* Reset for mobile */
        
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
            from { opacity: 0; transform: translateY(30px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            50% { transform: translateX(5px); }
            75% { transform: translateX(-5px); }
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
</head>
<body class="student-registration bg-student min-h-[100dvh] w-full flex flex-col items-center justify-center p-3 sm:p-6 lg:p-8 relative overflow-x-hidden overflow-y-auto" onmousemove="updateSpotlight(event)">

    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div id="spotlight-overlay" class="spotlight absolute inset-0 z-10"></div>
        <div class="bg-grid absolute inset-0"></div>
        <div class="glow-blob bg-gold w-[300px] h-[300px] sm:w-[500px] sm:h-[500px] top-[-5%] right-[-10%] mix-blend-screen"></div>
        <div class="glow-blob bg-primary-light w-[400px] h-[400px] sm:w-[600px] sm:h-[600px] bottom-[-10%] left-[-15%] mix-blend-screen" style="animation-delay: -5s; animation-direction: alternate-reverse;"></div>
    </div>

    <!-- Increased max width to fit 2 column layout, my-auto ensures centered on large and fully scrollable on small -->
    <div class="w-full max-w-[700px] z-20 relative animate-[fadeInUp_0.8s_cubic-bezier(0.16,1,0.3,1)] my-auto py-4 sm:py-6">
        
        <!-- Responsive Card Density: p-4 on mobile, p-8 on tablet, p-10 on desktop -->
        <div class="student-card p-4 sm:p-8 md:p-10 rounded-2xl sm:rounded-[2.5rem] relative overflow-hidden group hover:shadow-[0_40px_80px_-20px_rgba(0,0,0,0.6)] transition-shadow duration-500">
            
            <div class="text-center mb-5 sm:mb-8">
                <div class="flex flex-col items-center justify-center mb-3 sm:mb-4">
                    <img src="<?= base_url('images/logo.png?v=6') ?>" alt="SVBSM Logo" class="w-16 h-16 sm:w-24 sm:h-24 object-contain mx-auto mb-2 drop-shadow-[0_10px_20px_rgba(0,0,0,0.5)]">
                    <p class="text-white/90 font-semibold text-sm sm:text-base tracking-wide">St. Vincent Blessed School of Manila, Inc.</p>
                </div>
                
                <h1 class="text-[1.65em] sm:text-[2rem] font-heading font-extrabold tracking-tight text-white mb-1 leading-tight">Student <span class="text-gold">Registration</span></h1>
                <p class="text-white/70 font-medium text-sm sm:text-base">Create your secure account</p>
            </div>

            <!-- Error/Success Flash -->
            <?php if (session()->getFlashdata('error')): ?>
                <div class="bg-red-500/10 backdrop-blur-md border border-red-500/30 p-3.5 sm:p-4 mb-4 sm:mb-6 rounded-2xl shadow-lg">
                    <p class="text-[16px] sm:text-[17px] font-bold text-red-200 leading-snug tracking-tight text-center"><?= session()->getFlashdata('error') ?></p>
                </div>
            <?php endif; ?>
            <?php if (session()->getFlashdata('success')): ?>
                <div class="bg-green-500/10 backdrop-blur-md border border-green-500/30 p-3.5 sm:p-4 mb-4 sm:mb-6 rounded-2xl shadow-lg">
                    <p class="text-[16px] sm:text-[17px] font-bold text-green-200 leading-snug tracking-tight text-center"><?= session()->getFlashdata('success') ?></p>
                </div>
            <?php endif; ?>
            <?php if (isset($validation)): ?>
                <div class="bg-red-500/10 backdrop-blur-md border border-red-500/30 p-3.5 sm:p-4 mb-4 sm:mb-6 rounded-2xl shadow-lg text-red-200 text-sm">
                    <?= $validation->listErrors() ?>
                </div>
            <?php endif; ?>

            <!-- Registration Form -->
            <form action="<?= base_url('register/process') ?>" method="post" class="space-y-3.5 sm:space-y-4 relative z-10 w-full">
                <?= csrf_field() ?>
                
                <div class="space-y-1.5">
                    <label for="name" class="block text-sm sm:text-base font-bold text-white/80 ml-1 uppercase tracking-wider">Full Name</label>
                    <div class="relative group/input">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-user text-white/40 group-focus-within/input:text-gold transition-colors text-[16px]"></i>
                        </div>
                        <input type="text" name="name" id="name" required value="<?= set_value('name') ?>"
                               class="w-full pl-11 pr-4 py-3 input-dark rounded-xl text-[16px] font-bold outline-none"
                               placeholder="Juan Dela Cruz">
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label for="lrn" class="block text-sm sm:text-base font-bold text-white/80 ml-1 uppercase tracking-wider">Learner Reference Number (LRN)</label>
                    <input type="text" name="lrn" id="lrn" required inputmode="numeric" pattern="[0-9]{12}" minlength="12" maxlength="12" value="<?= set_value('lrn') ?>" aria-describedby="lrnHelp" class="w-full px-4 py-3 input-dark rounded-xl text-[16px] font-bold outline-none" placeholder="Enter your 12-digit LRN">
                    <p id="lrnHelp" class="text-xs text-white/80 ml-1">Use the LRN on your school record or report card.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5 sm:gap-4">
                    <div class="space-y-1.5">
                        <label for="grade_level" class="block text-sm sm:text-base font-bold text-white/80 ml-1 uppercase tracking-wider">Grade Level</label>
                        <div class="relative group/input">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-layer-group text-white/40 group-focus-within/input:text-gold transition-colors text-[16px]"></i>
                            </div>
                            <input type="text" name="grade_level" id="grade_level" required value="<?= set_value('grade_level') ?>"
                                   class="w-full pl-11 pr-4 py-3 input-dark rounded-xl text-[16px] font-bold outline-none"
                                   placeholder="Grade 7, Grade 11, etc.">
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label for="section" class="block text-sm sm:text-base font-bold text-white/80 ml-1 uppercase tracking-wider">Section</label>
                        <div class="relative group/input">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-chalkboard-teacher text-white/40 group-focus-within/input:text-gold transition-colors text-[16px]"></i>
                            </div>
                            <input type="text" name="section" id="section" required value="<?= set_value('section') ?>"
                                   class="w-full pl-11 pr-4 py-3 input-dark rounded-xl text-[16px] font-bold outline-none"
                                   placeholder="e.g. Rizal">
                        </div>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label for="parent_contact" class="block text-sm sm:text-base font-bold text-white/80 ml-1 uppercase tracking-wider">Parent/Guardian Contact (Phone/Email)</label>
                    <div class="relative group/input">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-phone-alt text-white/40 group-focus-within/input:text-gold transition-colors text-[16px]"></i>
                        </div>
                        <input type="text" name="parent_contact" id="parent_contact" required value="<?= set_value('parent_contact') ?>"
                               class="w-full pl-11 pr-4 py-3 input-dark rounded-xl text-[16px] font-bold outline-none"
                               placeholder="09123456789 or parent@email.com">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5 sm:gap-4 pt-1 sm:pt-2">
                    <div class="space-y-1.5">
                        <label for="password" class="block text-sm sm:text-base font-bold text-white/80 ml-1 uppercase tracking-wider">Create Password</label>
                        <div class="relative group/input">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-white/40 group-focus-within/input:text-gold transition-colors text-[16px]"></i>
                            </div>
                            <input type="password" name="password" id="password" required
                                   class="w-full pl-11 pr-4 py-3 input-dark rounded-xl text-[16px] font-bold outline-none tracking-[0.1em]"
                                   placeholder="••••••••">
                        </div>
                        <!-- Anti-CLS Pre-allocated High-Contrast Password Checklist Container -->
                        <div class="grid grid-cols-2 gap-x-2 gap-y-1.5 mt-2 text-sm text-gray-200 min-h-[46px]" id="password-criteria">
                            <div id="req-length" class="flex items-center gap-1.5 transition-colors"><i class="far fa-circle text-[16px] text-white/50"></i> At least 8 chars</div>
                            <div id="req-upper" class="flex items-center gap-1.5 transition-colors"><i class="far fa-circle text-[16px] text-white/50"></i> 1 Uppercase</div>
                            <div id="req-lower" class="flex items-center gap-1.5 transition-colors"><i class="far fa-circle text-[16px] text-white/50"></i> 1 Lowercase</div>
                            <div id="req-number" class="flex items-center gap-1.5 transition-colors"><i class="far fa-circle text-[16px] text-white/50"></i> 1 Number</div>
                            <div id="req-special" class="flex items-center gap-1.5 transition-colors"><i class="far fa-circle text-[16px] text-white/50"></i> 1 Special char</div>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label for="password_confirm" class="block text-sm sm:text-base font-bold text-white/80 ml-1 uppercase tracking-wider">Confirm Password</label>
                        <div class="relative group/input">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-white/40 group-focus-within/input:text-gold transition-colors text-[16px]"></i>
                            </div>
                            <input type="password" name="password_confirm" id="password_confirm" required
                                   class="w-full pl-11 pr-4 py-3 input-dark rounded-xl text-[16px] font-bold outline-none tracking-[0.1em]"
                                   placeholder="••••••••">
                        </div>
                        <p id="password-match-msg" class="text-[14px] text-red-400 mt-1 hidden font-medium flex items-center gap-1"><i class="fas fa-exclamation-circle"></i> Passwords do not match</p>
                    </div>
                </div>

                <!-- Touch-Optimized Terms & Privacy Checkbox (Min 44px height) -->
                <div class="pt-1">
                    <label class="flex items-start gap-3 p-2 -ml-2 rounded-xl cursor-pointer min-h-[44px] select-none hover:bg-white/5 transition-colors group/checkbox">
                        <div class="relative flex items-center justify-center mt-0.5 shrink-0">
                            <input type="checkbox" name="terms" id="terms" required class="peer sr-only" <?= set_checkbox('terms', '1') ?>>
                            <div class="w-5 h-5 border-2 border-white/40 rounded-md bg-white/5 peer-checked:bg-gold peer-checked:border-gold transition-all duration-300"></div>
                            <i class="fas fa-check absolute text-[14px] text-[#0A1C11] opacity-0 peer-checked:opacity-100 transition-opacity duration-300 pointer-events-none"></i>
                        </div>
                        <span class="text-sm sm:text-base text-white/90 leading-relaxed">
                            I agree to the <button type="button" onclick="openTermsModal('terms')" class="text-gold hover:text-gold-light font-bold underline underline-offset-2 transition-colors py-1">Terms and Conditions</button> & <button type="button" onclick="openTermsModal('privacy')" class="text-gold hover:text-gold-light font-bold underline underline-offset-2 transition-colors py-1">Data Privacy Policy</button>.
                        </span>
                    </label>
                </div>

                <button type="submit" id="submitBtn" class="submit-btn w-full font-black py-3.5 px-6 rounded-xl focus:outline-none focus:ring-4 focus:ring-gold/30 flex items-center justify-center gap-3 h-[52px] sm:h-[56px] mt-6 sm:mt-8 group/btn">
                    <span id="btnText" class="font-heading text-[16px] tracking-wide pt-0.5 text-primary-light mix-blend-multiply">Submit Registration</span>
                    <i id="btnIcon" class="fas fa-paper-plane text-primary-light mix-blend-multiply text-sm group-hover/btn:translate-x-1.5 group-hover/btn:-translate-y-1.5 transition-all duration-300"></i>
                    <svg id="btnSpinner" class="animate-spin h-5 w-5 text-primary-light mix-blend-multiply hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-30" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                </button>
            </form>
            
            <!-- Instant Direct Navigation to Login (Isolated from Form Events with 44px Touch Target) -->
            <div class="text-center mt-5 sm:mt-6 relative z-20">
                <a href="<?= base_url('login') ?>" class="inline-flex items-center gap-1.5 text-[16px] sm:text-[17px] font-semibold text-white/80 hover:text-gold transition-colors py-2 px-3 rounded-lg hover:bg-white/5 min-h-[44px]">
                    <i class="fas fa-arrow-left text-[14px]"></i> Back to Login
                </a>
            </div>
            
        </div>
    </div>

    <!-- Terms and Conditions Modal (Sticky Header & Footer with Independent Internal Scroll) -->
    <div id="termsModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-3 sm:p-4">
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
                <p>Welcome to Student Shield! By registering an account, you agree to the following terms regarding your usage and how we handle your data.</p>
                
                <h3 id="terms-section-terms" class="text-gold font-semibold text-base sm:text-lg mt-4">1. Purpose of the Portal</h3>
                <p>Student Shield is a secure platform designed for St. Vincent Blessed School of Manila (SVBSM) students to report incidents (such as bullying or conflicts) and to express concerns ("Vents") to the Guidance Counselor. This platform is strictly for school-related well-being and disciplinary matters.</p>

                <h3 id="terms-section-privacy" class="text-gold font-semibold text-base sm:text-lg mt-4">2. Data Collection and Privacy</h3>
                <p>In compliance with the <strong>Data Privacy Act of 2012 (R.A. 10173)</strong>, we assure you that:</p>
                <ul class="list-disc pl-5 space-y-1">
                    <li>Any personal information (Name, Student ID, Contact Info) collected during registration will be kept strictly confidential.</li>
                    <li>Reports and Vents submitted through this system are end-to-end secured and only accessible by authorized school personnel (e.g., Guidance Counselors and Administrators).</li>
                    <li>Your identity will remain protected, and any evidence you upload will be handled with the utmost sensitivity.</li>
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
    
    <style>
        #termsContentBox h3 { color: #FFF4C7; }
        #termsContentBox :is(button,a):focus-visible { outline:3px solid #FFF4C7; outline-offset:3px; }
        #termsContentBox .custom-scrollbar { scrollbar-color:rgba(255,255,255,.5) #0B7533; }
        #termsContentBox .custom-scrollbar::-webkit-scrollbar-thumb { background:rgba(255,255,255,.5); }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.02); border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(217, 170, 46, 0.5); }
    </style>

<script>
    function openTermsModal(section) {
        const modal    = document.getElementById('termsModal');
        const backdrop = document.getElementById('termsBackdrop');
        const content  = document.getElementById('termsContentBox');
        const body     = content.querySelector('.overflow-y-auto');

        modal.classList.remove('hidden');

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
            // Reset scroll so next open feels fresh
            const body = content.querySelector('.overflow-y-auto');
            if (body) body.scrollTop = 0;
        }, 300);
    }

    function updateSpotlight(e) {
        const overlay = document.getElementById('spotlight-overlay');
        overlay.style.setProperty('--x', `${e.clientX}px`);
        overlay.style.setProperty('--y', `${e.clientY}px`);
    }

    document.querySelector('form').addEventListener('submit', function(e) {
        const pass = document.getElementById('password').value;
        const confirmPass = document.getElementById('password_confirm').value;
        
        const hasLength = pass.length >= 8;
        const hasUpper = /[A-Z]/.test(pass);
        const hasLower = /[a-z]/.test(pass);
        const hasNumber = /[0-9]/.test(pass);
        const hasSpecial = /[^A-Za-z0-9]/.test(pass);
        
        if (!hasLength || !hasUpper || !hasLower || !hasNumber || !hasSpecial || pass !== confirmPass) {
            e.preventDefault();
            alert('Please ensure your password meets all security requirements and that passwords match.');
            return;
        }

        const btn = document.getElementById('submitBtn');
        const text = document.getElementById('btnText');
        const icon = document.getElementById('btnIcon');
        const spinner = document.getElementById('btnSpinner');
        btn.classList.add('opacity-80', 'cursor-wait', 'scale-[0.98]');
        text.innerText = 'Processing...';
        icon.classList.add('hidden');
        spinner.classList.remove('hidden');
    });

    document.getElementById('password').addEventListener('input', function(e) {
        const val = e.target.value;
        
        const reqs = {
            'req-length': val.length >= 8,
            'req-upper': /[A-Z]/.test(val),
            'req-lower': /[a-z]/.test(val),
            'req-number': /[0-9]/.test(val),
            'req-special': /[^A-Za-z0-9]/.test(val)
        };

        for (const [id, isValid] of Object.entries(reqs)) {
            const el = document.getElementById(id);
            const icon = el.querySelector('i');
            
            if (isValid) {
                el.classList.add('text-emerald-300', 'font-semibold');
                el.classList.remove('text-gray-300');
                icon.className = 'fas fa-check-circle text-[14px] text-emerald-400';
            } else {
                el.classList.remove('text-emerald-300', 'font-semibold');
                el.classList.add('text-gray-300');
                icon.className = 'far fa-circle text-[16px] text-white/50';
            }
        }
        checkPasswordMatch();
    });

    document.getElementById('password_confirm').addEventListener('input', checkPasswordMatch);

    function checkPasswordMatch() {
        const pass = document.getElementById('password').value;
        const confirm = document.getElementById('password_confirm').value;
        const msg = document.getElementById('password-match-msg');
        
        if (confirm.length > 0) {
            if (pass !== confirm) {
                msg.classList.remove('hidden');
            } else {
                msg.classList.add('hidden');
            }
        } else {
            msg.classList.add('hidden');
        }
    }
</script>
</body>
</html>
