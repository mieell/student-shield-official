<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication | StudentShield</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?= view('partials/school_brand', ['studentPalette' => true]) ?>
        <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 
                        sans: ['Inter', 'sans-serif'], 
                        heading: ['Nunito', 'sans-serif']
                    },
                    colors: {
                        white: '#FBFDFB', // Anti-glare soft white
                        black: '#131A15', // Softened black
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
                            900: '#212825', // Replaces harsh text-gray-900 with deep sage
                        },
                        red: {
                            50: '#FDF7F7',
                            100: '#FAEDED',
                            200: '#F4D6D6',
                            500: '#D95A5A', // Less neon, softer warning red
                            600: '#C24545',
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media (max-width: 1440px) { html { font-size: 15px; } }
        @media (max-width: 1280px) { html { font-size: 14px; } }
        @media (max-width: 1024px) { html { font-size: 13px; } }
        @media (max-width: 768px) { html { font-size: 16px; } }

        body { font-family: 'Inter', sans-serif; background-color: #0F8A3A; }
        h1, h2, h3, .font-heading { font-family: 'Nunito', sans-serif; }

        .bg-admin {
            background: var(--school-header-background);
        }

        .bg-grid {
            background-size: 40px 40px;
            background-image:
                linear-gradient(to right, rgba(255,255,255,0.035) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255,255,255,0.035) 1px, transparent 1px);
            mask-image: radial-gradient(circle at center, black 30%, transparent 80%);
            -webkit-mask-image: radial-gradient(circle at center, black 30%, transparent 80%);
            position: absolute; inset: 0; z-index: 0;
        }

        .admin-card {
            background: rgba(255, 255, 255, 0.10) !important;
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.22) !important;
            box-shadow:
                0 32px 64px -16px rgba(0, 0, 0, 0.45),
                0 0 0 1px rgba(255, 255, 255, 0.10) inset,
                0 1px 0 rgba(255, 255, 255, 0.25) inset !important;
        }

        .otp-input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 24px;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.06);
            border: 1.5px solid rgba(255, 255, 255, 0.1);
            color: #f1f5f9;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 640px) {
            .otp-input { width: 42px; height: 50px; font-size: 20px; }
        }

        .otp-input:focus {
            background: rgba(255, 255, 255, 0.1);
            box-shadow: 0 0 0 3px rgba(242, 195, 0, 0.25);
            border-color: #F2C300;
            outline: none;
        }

        .glow-blob {
            position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.12; z-index: 0;
            animation: pulseGlow 8s ease infinite alternate;
        }
        @keyframes pulseGlow {
            0% { opacity: 0.08; transform: scale(1); }
            100% { opacity: 0.18; transform: scale(1.1); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            50% { transform: translateX(5px); }
            75% { transform: translateX(-5px); }
        }
    </style>
<?= view('partials/ui_polish') ?>
</head>
<body class="bg-admin min-h-screen flex items-center justify-center p-4 relative overflow-hidden">

<div class="bg-grid"></div>
<div class="glow-blob bg-gold w-[500px] h-[500px] top-[-20%] right-[-10%]"></div>
<div class="glow-blob bg-white w-[300px] h-[300px] bottom-[-10%] left-[10%]" style="animation-delay: 3s;"></div>

<div class="w-full max-w-lg z-10 animate-[fadeInUp_0.7s_ease-out]">
    <div class="admin-card rounded-3xl p-8 sm:p-10 text-center">

        <!-- Header -->
        <div class="mb-6">
            <img src="<?= base_url('images/logo.png?v=6') ?>" alt="SVBSM Logo" class="w-40 h-40 object-contain mx-auto mb-5"
                 onerror="this.onerror=null; this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><circle cx=\'50\' cy=\'50\' r=\'40\' fill=\'none\' stroke=\'%23F2C300\' stroke-width=\'6\'/></svg>';">
            <h1 class="text-3xl font-heading font-bold tracking-tight text-white mb-2">Security <span class="text-gold">Verification</span></h1>
            <p class="text-white/70 text-sm font-medium">To protect student data, we've sent a 6-digit verification code to <span class="text-gold font-semibold"><?= esc(session()->get('otp_masked_email') ?? 'your registered counselor email') ?></span>.</p>
        </div>

        <!-- Flash Alerts -->
        <?php if (session()->getFlashdata('message') || session()->getFlashdata('success')): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/30 p-4 mb-6 rounded-xl">
                <div class="flex flex-col sm:flex-row items-center gap-2 justify-center">
                    <i class="fas fa-check-circle text-emerald-400"></i>
                    <p class="text-sm font-medium text-emerald-200 text-center"><?= esc(session()->getFlashdata('message') ?? session()->getFlashdata('success')) ?></p>
                </div>
            </div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="bg-red-500/10 border border-red-500/30 p-4 mb-6 rounded-xl animate-[shake_0.5s_ease-in-out]">
                <div class="flex flex-col sm:flex-row items-center gap-2 justify-center">
                    <i class="fas fa-exclamation-circle text-red-400"></i>
                    <p class="text-sm font-medium text-red-200 text-center"><?= esc(session()->getFlashdata('error')) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- OTP Form -->
        <form action="<?= base_url('admin/verify-otp') ?>" method="POST" id="otpForm" class="space-y-6">
            <div class="hidden"><?= csrf_field() ?></div>
            <input type="hidden" name="otp" id="fullOtpInput">
            
            <div class="flex justify-center gap-2 sm:gap-3">
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" autocomplete="off" required>
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" autocomplete="off" required>
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" autocomplete="off" required>
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" autocomplete="off" required>
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" autocomplete="off" required>
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" autocomplete="off" required>
            </div>

            <div class="mt-4 p-3 rounded-xl bg-white/5 border border-white/10 text-left">
                <label for="trust_device" class="flex items-start gap-2.5 cursor-pointer select-none">
                    <input type="checkbox" id="trust_device" name="trust_device" value="1"
                           class="mt-0.5 w-4 h-4 text-gold bg-white/10 border-white/20 rounded focus:ring-gold/50 focus:ring-2 cursor-pointer appearance-none checked:bg-gold checked:border-gold relative before:content-['✓'] before:absolute before:text-black before:text-xs before:font-bold before:opacity-0 checked:before:opacity-100 before:top-1/2 before:left-1/2 before:-translate-x-1/2 before:-translate-y-1/2 before:transition-opacity shrink-0">
                    <div class="text-xs">
                        <span class="font-semibold text-white/90">Trust this device for 30 days</span>
                        <span class="block text-white/60 text-[11px] mt-0.5 font-normal leading-relaxed">⚠️ For personal computers only. Never check this on shared school lab or library computers.</span>
                    </div>
                </label>
            </div>

            <button type="submit" id="submitBtn"
                class="w-full bg-gold hover:bg-gold-light text-primary font-bold py-4 px-4 rounded-xl shadow-lg shadow-gold/20 transition-all duration-300 transform hover:-translate-y-0.5 focus:outline-none focus:ring-4 focus:ring-gold/30 flex items-center justify-center gap-2 group mt-2">
                <i class="fas fa-shield-alt text-primary group-hover:scale-110 transition-transform" id="btnIcon"></i>
                <span id="btnText" class="font-heading text-lg tracking-wide">Verify & Secure Login</span>
                <svg id="btnSpinner" class="animate-spin h-5 w-5 text-primary hidden ml-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            </button>
        </form>
        
        <!-- Footer -->
        <div class="mt-8 pt-5 border-t border-white/10 text-center space-y-3">
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                <form action="<?= base_url('admin/resend-otp') ?>" method="POST" id="resendForm" class="inline">
                    <?= csrf_field() ?>
                    <button type="submit" id="resendBtn" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gold hover:text-gold-light transition-colors px-3 py-1.5 rounded-lg bg-gold/10 hover:bg-gold/20 border border-gold/30 cursor-pointer">
                        <i class="fas fa-redo-alt text-xs"></i>
                        <span>Resend Verification Code</span>
                    </button>
                </form>
                <span class="text-white/30 hidden sm:inline">•</span>
                <a href="<?= base_url('admin/login') ?>" class="inline-flex items-center gap-1.5 text-xs text-white/70 hover:text-white transition-colors">
                    <i class="fas fa-arrow-left text-xs"></i>
                    <span>Back to Login</span>
                </a>
            </div>
            <p class="text-[11px] text-white/50">Resends are limited and codes expire in 5 minutes for security.</p>
        </div>
    </div>
</div>

<script>
    // Auto-focus logic for the 6 boxes
    const inputs = document.querySelectorAll('.otp-input');
    const fullOtpInput = document.getElementById('fullOtpInput');

    inputs.forEach((input, index) => {
        // Move to next box on input
        input.addEventListener('input', (e) => {
            if (e.target.value.length === 1 && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
            updateHiddenInput();
        });

        // Handle backspace to move back
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !e.target.value && index > 0) {
                inputs[index - 1].focus();
            }
        });
        
        // Handle pasting a full 6-digit code
        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const pastedData = e.clipboardData.getData('text').slice(0, 6).replace(/\D/g, '');
            for (let i = 0; i < pastedData.length; i++) {
                if (i < inputs.length) {
                    inputs[i].value = pastedData[i];
                    if (i < inputs.length - 1) inputs[i + 1].focus();
                }
            }
            updateHiddenInput();
        });
    });

    function updateHiddenInput() {
        fullOtpInput.value = Array.from(inputs).map(input => input.value).join('');
    }

    document.getElementById('otpForm').addEventListener('submit', function() {
        const btn = document.getElementById('submitBtn');
        const text = document.getElementById('btnText');
        const icon = document.getElementById('btnIcon');
        const spinner = document.getElementById('btnSpinner');
        btn.disabled = true;
        btn.classList.add('opacity-80', 'cursor-wait');
        text.innerText = 'Verifying...';
        icon.classList.add('hidden');
        spinner.classList.remove('hidden');
    });
</script>

</body>
</html>
