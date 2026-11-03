<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('images/logo.png?v=3') ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudentShield - Sign in</title>
    <link rel="preload" as="image" href="<?= base_url('images/logo.png?v=6') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
        <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 
                        sans: ['Nunito', 'sans-serif'],
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
                        primary: { DEFAULT: '#235D3A', light: '#2F754A', dark: '#19452B', darker: '#12331F', darkest: '#0A1C11' },
                        gold: { DEFAULT: '#D9AA2E', light: '#EAC255', bright: '#FCEEA1', dark: '#B5881D' },
                        calm: { 50: '#f0fdf4', 100: '#dcfce7', 200: '#bbf7d0', 600: '#16a34a' }
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        /* Responsive scaling */
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

        /* Visible cross-hatch grid */
        .bg-grid {
            position: absolute; inset: 0; z-index: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        /* Large decorative ring arcs at corners */
        .bg-rings {
            position: absolute; inset: 0; z-index: 0; pointer-events: none; overflow: hidden;
        }
        .bg-rings::before {
            content: '';
            position: absolute;
            width: 520px; height: 520px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.10);
            top: -200px; left: -200px;
            box-shadow: inset 0 0 0 40px rgba(255,255,255,0.03);
        }
        .bg-rings::after {
            content: '';
            position: absolute;
            width: 420px; height: 420px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.08);
            bottom: -160px; right: -160px;
            box-shadow: inset 0 0 0 30px rgba(255,255,255,0.03);
        }
        /* Extra inner ring top-left */
        .bg-ring-sm {
            position: absolute;
            width: 280px; height: 280px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.07);
            top: -80px; left: -80px;
            z-index: 0; pointer-events: none;
        }

        .student-card {
            background: rgba(255, 255, 255, 0.10);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.22);
            box-shadow:
                0 32px 64px -16px rgba(0,0,0,0.45),
                0 0 0 1px rgba(255,255,255,0.10) inset,
                0 1px 0 rgba(255,255,255,0.25) inset;
        }

        /* WCAG AA fix: raised contrast to ≥4.5:1 for text on input bg */
        .input-dark {
            background: rgba(0, 0, 0, 0.35);
            border: 1.5px solid rgba(255, 255, 255, 0.22);
            color: #ffffff;
            transition: all 0.3s ease;
        }
        .input-dark:focus {
            background: rgba(0, 0, 0, 0.45);
            box-shadow: 0 0 0 4px rgba(242, 195, 0, 0.25);
            border-color: #F2C300;
            outline: none;
        }
        /* WCAG AA fix: placeholder raised to 0.85 opacity */
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
        
        
        /* Gold gradient button */
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
        
        /* Dynamic Spotlight Effect */
        .spotlight {
            background: radial-gradient(
                600px circle at var(--x) var(--y),
                rgba(217, 170, 46, 0.05),
                transparent 80%
            );
        }

        
        /* Fit shorter screens through spacing, while keeping controls readable. */
        body.student-login { padding: clamp(12px, 2vh, 24px) 16px; }
        .student-login .login-shell { padding-block: 0; flex-shrink: 0; }
        .student-login .student-card { padding: clamp(20px, 3vh, 32px) clamp(20px, 4vw, 40px); }
        .student-login .login-heading { margin-bottom: clamp(16px, 2.5vh, 24px); }
        .student-login .login-identity { margin-bottom: 12px; }
        .student-login .login-identity img { width: clamp(64px, 10vh, 88px); height: clamp(64px, 10vh, 88px); }
        .student-login .login-security { margin-top: 12px; }
        .student-login .login-form { display: flex; flex-direction: column; gap: 16px; }
        .student-login .login-form > * { margin-block: 0; }
        .student-login .input-dark { min-height: 48px; padding-block: 12px; }
        .student-login .submit-btn { height: 52px; min-height: 48px; padding-block: 12px; }
        @media (max-height: 700px) {
            .student-login .login-heading { margin-bottom: 14px; }
            .student-login .login-form { gap: 12px; }
            .student-login .login-identity { margin-bottom: 8px; }
        }

        /* Accessibility: Reduced Motion */
        @media (prefers-reduced-motion: reduce) {
            /* Remove entrance animations; pausing their first frame hides the form at opacity: 0. */
            *, ::before, ::after { animation: none !important; transition: none !important; }
        }
    </style>
<link rel="stylesheet" href="<?= base_url('css/unified-login.css?v=' . filemtime(FCPATH . 'css/unified-login.css')) ?>">
<?= view('partials/ui_polish') ?>
</head>
<body class="student-login bg-student min-h-[100dvh] w-full flex flex-col items-center justify-center p-3 sm:p-6 lg:p-8 relative overflow-x-hidden overflow-y-auto" onmousemove="updateSpotlight(event)">

            <!-- Premium Fast-Load Splash Screen -->
    <div id="splashScreen" class="fixed inset-0 z-[9999] flex flex-col items-center justify-center bg-student backdrop-blur-xl transition-all duration-700 ease-in-out">
        <div class="relative flex flex-col items-center z-10 w-full max-w-sm px-6">
            <!-- Logo -->
            <div class="relative mb-6 flex items-center justify-center w-24 h-24 sm:w-32 sm:h-32">
                <img src="<?= base_url('images/logo.png?v=6') ?>" alt="SVBSM Logo" 
                     class="relative w-full h-full object-contain z-10 drop-shadow-[0_0_20px_rgba(217,170,46,0.3)] animate-pulse"
                     id="splashLogoImg">
            </div>
            
            <!-- Branding -->
            <h1 class="text-3xl sm:text-4xl font-heading font-black tracking-[0.05em] text-white uppercase text-center drop-shadow-lg mb-2">
                Student<span class="text-gold">Shield</span>
            </h1>
            
            <p class="text-white/70 text-[10px] sm:text-[11px] font-semibold tracking-[0.15em] uppercase font-mono mb-8" id="splashStatusText">Getting things ready...</p>

            <!-- Sleek Loading Bar -->
            <div class="w-48 sm:w-64 h-1.5 bg-black/40 rounded-full overflow-hidden border border-white/5 relative">
                <div class="absolute top-0 bottom-0 left-0 bg-gradient-to-r from-[#D9AA2E] to-[#FCEEA1] shadow-[0_0_10px_#D9AA2E] rounded-full transition-all duration-200 ease-out" id="splashProgressBar" style="width: 0%;"></div>
            </div>
        </div>
    </div>


    <!-- Background Effects Container (Fixed background prevents mobile scroll interference) -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <!-- Interactive Spotlight Overlay -->
        <div id="spotlight-overlay" class="spotlight absolute inset-0 z-10"></div>

        <!-- Visible cross-hatch grid -->
        <div class="bg-grid absolute inset-0"></div>
        <!-- Large corner ring arcs -->
        <div class="bg-rings absolute inset-0"></div>
        <!-- Small inner ring top-left -->
        <div class="bg-ring-sm"></div>
        <!-- Ambient gold glow top-right -->
        <div class="glow-blob bg-gold w-[300px] h-[300px] sm:w-[450px] sm:h-[450px] top-[-5%] right-[-10%] mix-blend-screen" style="opacity:0.12;"></div>
        <!-- Ambient light-green glow bottom-left -->
        <div class="glow-blob bg-primary-light w-[350px] h-[350px] sm:w-[500px] sm:h-[500px] bottom-[-10%] left-[-15%] mix-blend-screen" style="animation-delay: -5s; animation-direction: alternate-reverse; opacity:0.1;"></div>
    </div>

    <div class="login-shell w-full max-w-[480px] z-20 relative my-auto">
        
        <!-- Responsive Card Density: p-5 on mobile, p-8 on tablet, p-12 on desktop -->
        <div class="student-card p-5 sm:p-8 md:p-12 rounded-2xl sm:rounded-[2.5rem] relative overflow-hidden group hover:shadow-[0_40px_80px_-20px_rgba(0,0,0,0.6)] transition-shadow duration-500">
            
            <div class="login-heading text-center">
                <!-- Crest & Clean School Identity -->
                <div class="login-identity flex flex-col items-center justify-center">
                    <img src="<?= base_url('images/logo.png?v=6') ?>" alt="SVBSM Logo" class="w-20 h-20 sm:w-28 sm:h-28 object-contain mx-auto mb-2.5 drop-shadow-[0_10px_20px_rgba(0,0,0,0.5)]"
                         onerror="this.onerror=null; this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><circle cx=\'50\' cy=\'50\' r=\'40\' fill=\'none\' stroke=\'%23D9AA2E\' stroke-width=\'5\'/><path d=\'M35 55l10 10 25-25\' fill=\'none\' stroke=\'%23D9AA2E\' stroke-width=\'5\' stroke-linecap=\'round\' stroke-linejoin=\'round\'/></svg>';">
                    <p class="text-white/90 font-semibold text-[12px] sm:text-[14px] tracking-wide">St. Vincent Blessed School of Manila, Inc.</p>
                </div>
                
                <h1 class="text-[1.65em] sm:text-[2rem] font-heading font-extrabold tracking-tight text-white mb-1 leading-tight">Student<span class="text-gold">Shield</span></h1>
                <p class="text-white/70 font-medium text-[13px] sm:text-[15px]">One sign-in for your school workspace</p>
                
                <!-- WCAG AA fix: solid dark bg + white text for ≥4.5:1 contrast -->
                <div class="login-security inline-flex items-center gap-2 px-3.5 sm:px-4 py-1.5 rounded-full bg-black/60 border border-white/20 shadow-sm">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-[10.5px] sm:text-[12px] font-bold text-white uppercase tracking-widest whitespace-nowrap">🔒 Secure &amp; Confidential</span>
                </div>
            </div>

            <div class="login-fields">
            <!-- Error Flash -->
            <?php if (session()->getFlashdata('error')): ?>
                <div role="alert" class="auth-error bg-red-500/10 backdrop-blur-md border border-red-500/30 p-3.5 sm:p-4 mb-5 sm:mb-8 rounded-2xl shadow-lg">
                    <div class="flex items-start gap-3 sm:gap-4">
                        <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-full bg-red-500/20 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i class="fas fa-exclamation text-red-400 text-sm"></i>
                        </div>
                        <p class="text-[13px] sm:text-[14px] font-bold text-red-200 leading-snug tracking-tight pt-1 pr-1"><?= session()->getFlashdata('error') ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form action="<?= base_url('login/authenticate') ?>" method="post" class="login-form relative z-10 w-full">
                <?= csrf_field() ?>
                
                <div class="space-y-1.5 sm:space-y-2">
                    <label for="name" class="block text-[12px] sm:text-[13px] font-bold text-white/80 ml-1 uppercase tracking-wider">Username</label>
                    <div class="relative group/input">
                        <div class="absolute inset-y-0 left-0 pl-4 sm:pl-5 flex items-center pointer-events-none transition-colors group-focus-within/input:text-gold">
                            <i class="fas fa-user text-white/40 group-focus-within/input:text-gold transition-colors text-[16px] sm:text-[18px]"></i>
                        </div>
                        <input type="text" name="identifier" id="name" required autocomplete="username"
                               class="w-full pl-11 sm:pl-14 pr-4 sm:pr-12 py-3.5 sm:py-4 input-dark rounded-xl sm:rounded-[1.25rem] text-[16px] font-bold outline-none"
                               placeholder="Username">
                    </div>
                </div>

                <div class="space-y-1.5 sm:space-y-2">
                    <label for="password" class="block text-[12px] sm:text-[13px] font-bold text-white/80 ml-1 uppercase tracking-wider">Password</label>
                    <div class="relative group/input">
                        <div class="absolute inset-y-0 left-0 pl-4 sm:pl-5 flex items-center pointer-events-none transition-colors group-focus-within/input:text-gold">
                            <i class="fas fa-lock text-white/40 group-focus-within/input:text-gold transition-colors text-[16px] sm:text-[18px]"></i>
                        </div>
                        <input type="password" name="password" id="password" required autocomplete="current-password"
                               class="w-full pl-11 sm:pl-14 pr-4 sm:pr-12 py-3.5 sm:py-4 input-dark rounded-xl sm:rounded-[1.25rem] text-[16px] font-bold outline-none tracking-[0.1em]"
                               placeholder="••••••••">
                        <button type="button" id="togglePassword" class="password-toggle" aria-controls="password" aria-pressed="false" aria-label="Show password">Show</button>
                    </div>
                </div>

                <!-- Accessible Touch-Optimized Remember Me -->
                <div class="pt-1">
                    <label for="remember" class="flex items-start gap-3 p-2 -ml-2 rounded-xl cursor-pointer select-none hover:bg-white/5 transition-colors group">
                        <input id="remember" name="remember" type="checkbox"
                               class="mt-1 w-5 h-5 text-gold bg-white/10 border-2 border-white/40 rounded focus:ring-gold/60 focus:ring-2 focus:ring-offset-2 focus:ring-offset-[#06401E] focus-visible:outline-none cursor-pointer appearance-none checked:bg-gold checked:border-gold relative before:content-['✓'] before:absolute before:text-black before:text-xs before:font-bold before:opacity-0 checked:before:opacity-100 before:top-1/2 before:left-1/2 before:-translate-x-1/2 before:-translate-y-1/2 before:transition-opacity shrink-0">
                        <div class="text-[12px] sm:text-[13px] leading-snug">
                            <span class="font-semibold text-white/90 group-hover:text-gold transition-colors block">
                                Remember me on this device
                            </span>
                            <span class="text-white/60 text-[11px] block mt-0.5 font-normal">
                                ⚠️ Personal device only. Do not check on shared school lab or library computers.
                            </span>
                        </div>
                    </label>
                </div>

                <button type="submit" id="submitBtn" class="submit-btn w-full font-black py-3.5 sm:py-4 px-4 sm:px-6 rounded-xl sm:rounded-[1.25rem] focus:outline-none focus:ring-4 focus:ring-gold/30 flex items-center justify-center gap-2 sm:gap-3 h-[52px] sm:h-[60px] mt-6 sm:mt-8 group/btn">
                    <span id="btnText" class="font-heading text-[16px] sm:text-[18px] tracking-wide pt-0.5 text-primary-light mix-blend-multiply">Sign in</span>
                    <i id="btnIcon" class="fas fa-arrow-right text-primary-light mix-blend-multiply text-[13px] sm:text-sm group-hover/btn:translate-x-1.5 group-hover/btn:scale-110 transition-all duration-300"></i>
                    <svg id="btnSpinner" class="animate-spin h-5 w-5 text-primary-light mix-blend-multiply hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-30" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                </button>

                <div class="text-center mt-4 sm:mt-5">
                    <p class="text-white/70 text-[13px]">
                        New student?
                        <a href="<?= base_url('register') ?>" class="inline-block py-2 px-1 text-gold-light hover:text-gold font-bold underline underline-offset-2 transition-colors duration-200 min-h-[44px]">Register here</a>
                    </p>
                </div>
            </form>
            </div>
            
        </div>
        
    </div>

<script>
            document.addEventListener("DOMContentLoaded", function() {
        const splash = document.getElementById('splashScreen');
        if (!sessionStorage.getItem('studentIntroPlayed')) {
            let hasLoaded = false;
            let minTimePassed = false;
            let progress = 0;
            const bar = document.getElementById('splashProgressBar');
            const status = document.getElementById('splashStatusText');
            
            const hideSplash = () => {
                splash.classList.add('opacity-0', 'backdrop-blur-none');
                splash.style.pointerEvents = 'none';
                setTimeout(() => { splash.style.display = 'none'; }, 700);
                sessionStorage.setItem('studentIntroPlayed', 'true');
            };

            const progressInterval = setInterval(() => {
                if(!hasLoaded) {
                    progress += Math.random() * 12;
                    if(progress > 85) progress = 85 + Math.random() * 5; // Hang around 85-90%
                    if(bar) bar.style.width = progress + '%';
                }
            }, 100);

            setTimeout(() => {
                minTimePassed = true;
                if(hasLoaded) finishLoading();
            }, 1000); // Wait at least 1s
            
            const finishLoading = () => {
                clearInterval(progressInterval);
                if(bar) bar.style.width = '100%';
                if(status) status.innerText = 'Ready';
                setTimeout(hideSplash, 300); // Fade out slightly after hitting 100%
            };

            window.addEventListener('load', () => {
                hasLoaded = true;
                if(minTimePassed) finishLoading();
            });

            setTimeout(finishLoading, 4000); // 4s absolute max
        } else {
            splash.style.display = 'none';
        }
    });

    function updateSpotlight(e) {
        const overlay = document.getElementById('spotlight-overlay');
        overlay.style.setProperty('--x', `${e.clientX}px`);
        overlay.style.setProperty('--y', `${e.clientY}px`);
    }

    (function () {
        const pw = document.getElementById('password');
        const toggle = document.getElementById('togglePassword');
        if (!pw || !toggle) return;
        toggle.addEventListener('click', function () {
            const show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            toggle.textContent = show ? 'Hide' : 'Show';
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    })();

    document.querySelector('form').addEventListener('submit', function() {
        const btn = document.getElementById('submitBtn');
        const text = document.getElementById('btnText');
        const icon = document.getElementById('btnIcon');
        const spinner = document.getElementById('btnSpinner');
        btn.classList.add('opacity-80', 'cursor-wait', 'scale-[0.98]');
        text.innerText = 'Authenticating...';
        icon.classList.add('hidden');
        spinner.classList.remove('hidden');
    });
</script>
</body>
</html>


