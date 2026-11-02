# StudentShield — AI Context & Infrastructure Guide

> **Purpose:** This document is written for AI assistants picking up this project mid-development. Read this before touching any code or configs. It covers where everything is deployed, how it's connected, and how to manage it.

---

## 🏗️ Project Overview

**StudentShield** is a web-based mental health monitoring system for students at SVBSM (Saint Vincent Blessed School of Manila). It allows students to anonymously "vent" feelings, which are analyzed for psychological risk (LOW / MEDIUM / HIGH / CRITICAL) using a Python ML service. Counselors and admins receive alerts for high-risk submissions.

**Stack:**
- **Backend:** CodeIgniter 4 (PHP) — served via InfinityFree hosting
- **ML Service:** Python FastAPI — deployed on Hugging Face Spaces (Docker)
- **Local Dev:** Laragon on Windows (`D:\laragon\www\student shield\`)
- **Database:** MySQL (local: Laragon root/no-password; production: InfinityFree MySQL)
- **SMS Alerts:** Local Android SMS Gateway app (LAN IP: `192.168.1.3:8080`)
- **Email Alerts:** Gmail SMTP (configured via environment in .env)
- **Repo:** `https://github.com/mieell/student-shield-official` (branch: `main`)

---

## 📁 Repository Structure

```
student-shield/
├── web-app-ci4/          # CodeIgniter 4 PHP web application
│   ├── app/
│   │   ├── Controllers/  # VentController.php (core logic), AuthController, etc.
│   │   ├── Views/        # vent_form.php, dashboards, login, etc.
│   │   ├── Models/       # User models
│   │   └── Config/       # Routes.php, Security.php, etc.
│   ├── public/           # Web root (point server here)
│   └── .env              # Environment config (NOT committed — see below)
├── ml_service/           # Python FastAPI ML service
│   ├── main.py           # FastAPI app (risk analysis, authenticated inference)
│   ├── Dockerfile        # HF Spaces Docker config (port 7860)
│   ├── requirements.txt  # Python deps
│   ├── models/           # Optional local Transformer checkpoints
│   ├── risk_history.py   # Trajectory from database-backed risk history
│   └── data/             # Training data
├── .github/workflows/
│   └── deploy_hf.yml     # Auto-deploys ml_service/ to Hugging Face on push
├── schema.sql            # Full MySQL schema (run this to recreate DB)
├── AI_CONTEXT.md         # THIS FILE
├── MANAGEMENT_GUIDE.md   # Additional management notes
└── docs/
    └── Deployment_Manuals/
        └── azure_vm_management_and_contingency.md  # Legacy Azure doc (Azure is NO LONGER used)
```

---

## ☁️ Deployment Map

### 1. Web App — Cloudflare (DNS + SSL) + InfinityFree (Free PHP Hosting)

| Item | Value |
|---|---|
| **Primary Domain** | `https://studentshield.app/` (Registered via Name.com) |
| **DNS / SSL Shield** | **Cloudflare** (Universal Edge SSL, TLS v1.3, DDoS protection, Proxying) |
| **Host** | InfinityFree (Account: `if0_41428179`, Origin IP: `185.27.134.230`) |
| **Legacy Subdomain** | `https://studentshield.gt.tc/` (Aliased) |
| **Web root** | Points to `web-app-ci4/public/` |
| **PHP version** | 8.1+ (CI4 requirement) |
| **Deployment method** | **✅ Automated CI/CD via GitHub Actions** ([`.github/workflows/ftp-deploy.yml`](./.github/workflows/ftp-deploy.yml)) — Auto-syncs `web-app-ci4/` to InfinityFree FTP (`/studentshield.gt.tc/htdocs/`) on push to `main` (Excludes `.env` for security) |
| **Database** | InfinityFree MySQL (managed via phpMyAdmin in their control panel) |
| **DB name** | `if0_41428179_studentshield` (Account: `if0_41428179`) |

> ⚠️ **InfinityFree limitations**: No SSH or runtime CLI commands. Database alterations must be run via phpMyAdmin. Production `.env` remains directly on server to prevent secret leaks.

### 2. ML Service & Model Repositories — Hugging Face

| Item | Value |
|---|---|
| **FastAPI Space (Runtime)** | `https://huggingface.co/spaces/ashlogg/student_shield_official` |
| **Direct API Endpoint** | `https://ashlogg-student-shield-official.hf.space` |
| **Fine-Tuned Model Repo** | `https://huggingface.co/ashlogg/student-shield-mdeberta` |
| **Emotion Model (Public)** | `SamLowe/roberta-base-go_emotions` (28 emotions) |
| **Trained Architecture** | `microsoft/mdeberta-v3-base` (Fine-tuned for Taglish/Filipino crisis detection) |
| **Training Dataset** | 3,251 Train / 814 Val samples (Balanced with 65 real decrypted vents + synthetic Taglish/Bisaya/Ilocano data) |
| **HF Username** | `ashlogg` |
| **Internal Port** | `7860` (standard HF Docker port) |
| **Auto-deploy** | ✅ Yes — GitHub Actions (`deploy_hf.yml`) triggers on push to `main` when `ml_service/**` changes |

**How the Current ML Pipeline Works:**
```
1. Student submits vent → PHP VentController sends HTTP POST to HF Space API
2. Pre-processing: Elongation collapse (e.g., 'pagooooood' → 'pagod') + Taglish slang/typo normalization (dko, tlga, wla, nlng, etc.)
3. Neural Layer: Fine-tuned mDeBERTa from ashlogg/student-shield-mdeberta (distress) + SamLowe/roberta-base-go_emotions (emotions)
4. Sarcasm & Irony Inversion: Sarcastic failure/anger/truancy mapped to MEDIUM (55%) rather than false-positive LOW
5. Idiom Disambiguation: Colloquial phrases (e.g. 'mamatay sa pagod') mapped to Severe Burnout (HIGH 79%) rather than suicide CRITICAL
6. Clinical Triage:
   - CRITICAL (86%–100%): Explicit intent/methods/notes → Alerts Counselor + Parents
   - HIGH (61%–85%): Passive ideation / C-SSRS Level 2 / Severe Distress → Alerts Counselor ONLY (No parent alarm fatigue)
   - MEDIUM (31%–60%): Academic pressure / bullying / moderate distress → Dashboard queue
   - LOW (0%–30%): General venting / positive reflection
7. Parent Fallback: Parent Email → Parent SMS → Counselor manual follow-up notice if no parent contact on file
```

**Autonomous Coding Agents (Google Jules & Copilot):**
- System constraints & guardrails: [`AGENTS.md`](./AGENTS.md) and [`.github/copilot-instructions.md`](./.github/copilot-instructions.md)
- Dataset extraction CLI: `php spark decrypt:vents` (decrypts real vents for ML fine-tuning)

**To deploy ML changes manually:**
```bash
git subtree push --prefix ml_service https://ashlogg:<HF_TOKEN>@huggingface.co/spaces/ashlogg/student-shield-official main
```

### 3. LOCAL DEVELOPMENT — Laragon (Windows)

| Item | Value |
|---|---|
| **Path** | `D:\laragon\www\student shield\` |
| **MySQL binary** | `D:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` |
| **Local URL** | `http://student-shield.test` (or whatever Laragon assigns) |
| **DB user/pass** | `root` / *(no password)* |
| **DB name** | `student_shield` |
| **PHP** | Via Laragon's bundled PHP |

---

## 🔑 Environment Variables (`.env`)

The `.env` file lives at `web-app-ci4/.env` and is **NOT committed to git**. Never copy live values into tracked Markdown, PHP defaults, tests, screenshots, or issue reports. The following is a safe template:

```ini
CI_ENVIRONMENT = development          # Change to "production" on server

app.baseURL = 'https://studentshield.app/'
app.appTimezone = 'Asia/Manila'

# DATABASE (local Laragon values — override on InfinityFree server)
database.default.hostname = localhost
database.default.database = student_shield
database.default.username = root
database.default.password =
database.default.DBDriver = MySQLi
database.default.port = 3306

# ENCRYPTION KEY (required for vent text encryption)
encryption.key = hex2bin('<64-hex-character-secret>')

# ML SERVICE (Hugging Face)
ML_API_URL = https://ashlogg-student-shield-official.hf.space
ML_API_KEY = <random-secret-shared-with-the-ML-service>

# EMAIL ALERTS & 2FA OTP (Gmail SMTP)
email.protocol = smtp
email.SMTPHost = smtp.gmail.com
email.SMTPUser = <school-alert-email>
email.SMTPPass = <smtp-app-password>
email.SMTPPort = 587
email.SMTPCrypto = tls

# COUNSELOR CONTACTS
COUNSELOR_PHONE = <counselor-phone>
COUNSELOR_EMAIL = <counselor-email>

# LOCAL SMS GATEWAY (Android phone on LAN — only works locally)
sms.GatewayUrl = <sms-gateway-url>

```

> ⚠️ **On production (InfinityFree)**, the server has its own `.env` with the InfinityFree DB hostname, username, and password. You cannot SSH in to see it — it was set manually via their file manager.

---

## 🗄️ Database Schema

Run `schema.sql` from the repo root to recreate the database. Key tables:

| Table | Purpose |
|---|---|
| `users` | All users (students, counselors, admins). `role`: 0=Student, 1=Counselor, 2=Admin |
| `students` | Student profile linked to `users.id` — stores parent phone/email, section, grade |
| `counselors` | Counselor profile |
| `admins` | Admin profile |
| `vent_logs` | Core table — stores encrypted vent text + ML analysis results |
| `bully_reports` | Bullying incident reports |
| `counselor_notes` | Notes written by counselors about students |
| `case_status_history` | Audit trail of case status changes |
| `sms_logs` | Log of sent SMS alerts |
| `email_logs` | Log of sent email alerts |
| `virtual_meetings` | Video session records (Daily.co links) |
| `audit_logs` | System-wide action audit trail |

### `vent_logs` — Full Column List (Production server must have ALL of these)

```sql
id, user_id, raw_text (encrypted), emotions, risk_percent,
risk_level, trajectory, escalation_probability,
status (Pending/Reviewed/Flagged), created_at, deleted_at
```

> ⚠️ **Schema compatibility note**: Confirm that production contains `trajectory` and `escalation_probability`. Older database exports may be missing them. If needed, add them through phpMyAdmin because production has no CLI access:
> ```sql
> ALTER TABLE vent_logs
>   ADD COLUMN trajectory VARCHAR(50) DEFAULT 'INSUFFICIENT_DATA' AFTER risk_level,
>   ADD COLUMN escalation_probability DECIMAL(5,4) DEFAULT 0.0 AFTER trajectory;
> ```

---

## 🤖 ML Service Architecture

**File:** `ml_service/main.py` (FastAPI)

- Exposes `/analyze-text` (POST) — main endpoint used by `VentController.php`
- Accepts: `{ "raw_text": "...", "student_id": "...", "risk_history": [] }`
- Returns: `{ "risk_level": "LOW|MEDIUM|HIGH|CRITICAL", "risk_score": 1-100, "trajectory": "...", "escalation_probability": 0.0-0.99 }`. Despite its name, `risk_score` is a banded percentage, not a 0-1 probability.
- Uses: trained mDeBERTa + RoBERTa models; `risk_history.py` evaluates risk labels/timestamps supplied from the web database. No FAISS memory is used by the active pipeline.
- API key auth: the `X-API-KEY` header must match `ML_API_KEY` in both deployment environments. Do not document the value.

**Fallback:** If the HF Space is cold-starting or unreachable, `VentController.php` automatically falls back to `localKeywordFallback()` — a PHP keyword heuristic that still catches high-risk Taglish phrases. No user-facing error is shown.

---

## 🔄 Key Workflows

### Student Registration and Approval
1. A student registers at `/register` with name, grade, section, parent/guardian contact, and a strong password.
2. The student must accept the displayed Terms and Data Privacy Policy.
3. Registration creates a role-0 account with `is_active = 0`.
4. A counselor or super admin approves or rejects the registration from `/admin/pending-registrations`.
5. Only an approved student can log in and reach `/student/dashboard`.

> The current checkbox records acceptance only as a submission requirement; it does not persist a separate guardian-consent record, signer identity, or consent timestamp.

### Submitting a Vent (Student Flow)
1. Student fills `vent_form.php` → AJAX POST to `/student/vent/submit`
2. `VentController::submit()` validates (auth, rate-limit 30s, length <5000 chars)
3. Calls HF ML service (`/analyze-text`) via cURL (10s connect timeout, 60s total)
4. Encrypts vent text with `hex2bin` key (`AES-256-CTR`) → stores to `vent_logs`
5. Dispatches alerts based on clinical triage:
   - **CRITICAL:** Sends immediate alert email to Counselor + sends support email/SMS to Parent (unless hidden).
   - **HIGH:** Sends high-priority email to Counselor only (to prevent parent alert fatigue).
6. Returns JSON → JS shows empathetic feedback or calming resources

### Bullying Incident Report
1. An authenticated student submits a self or bystander report, optionally with image/video evidence.
2. The controller rate-limits submissions, encrypts the report details, and stores the report as `pending_review`.
3. The reporter receives a confirmation and the counseling team receives the intake alert. Parents are not contacted at intake.
4. Staff manually chooses one of the guarded outcomes:
   - **Verify & alert:** marks the report verified and sends parent/guardian notifications for uniquely resolved student records.
   - **Reject as fake:** sends no incident alert to parents and applies the progressive false-report strike policy.
   - **Resolve/archive:** closes or soft-deletes the operational record while retaining audit history.

### Counselor and Super Admin Flow
- Counselors and super admins share `/admin/login`, email OTP verification, and `/admin/dashboard`.
- Counselors can triage vents and incidents, approve registrations, open student case summaries, add notes, update case status, and conduct one-to-one Jitsi meetings.
- Super admins additionally manage users and view system audit logs.
- `/counselor/*` URLs redirect to the corresponding unified `/admin/*` route.

### Virtual Meeting Flow
1. A student requests a session from the student dashboard or Safe Space page.
2. The dashboard polls for pending requests and alerts staff.
3. Staff starts a meeting from the student's case summary; the server generates a unique room name.
4. Student and counselor join the same `meet.jit.si` room. Ending the session updates the meeting record.

### Deploying Web App Changes (Automated)
Web application deployment is **100% automated** via GitHub Actions:
1. Edit files locally in `D:\laragon\www\student shield\web-app-ci4\`
2. Commit + push to GitHub (`git push origin main`)
3. GitHub Actions workflow (`.github/workflows/ftp-deploy.yml`) triggers automatically and uses `SamKirkland/FTP-Deploy-Action` to sync changed files into InfinityFree's `/studentshield.gt.tc/htdocs/` within ~15 seconds.
4. Production `.env` is intentionally excluded from the sync to protect live database passwords and API keys.

### Deploying ML Service Changes
1. Edit files in `ml_service/`
2. Commit + push to GitHub (`git push origin main`)
3. GitHub Actions (`deploy_hf.yml`) automatically pushes `ml_service/` to HF Spaces
4. HF rebuilds Docker container (takes ~2-5 min)
5. Monitor at: `https://huggingface.co/spaces/ashlogg/student-shield-official`

---

## 🆘 Contingency Plans

### If HF Space is down / cold
- PHP fallback (`localKeywordFallback`) activates automatically — no action needed
- Check HF Space status at: `https://huggingface.co/spaces/ashlogg/student-shield-official`
- Force rebuild: go to HF Space → Settings → Factory reboot

### If InfinityFree hosting is down
- Option A: Run locally + use Ngrok: `ngrok http 80` → update `app.baseURL` in `.env`
- Option B: Move to another free host (000webhost, Netlify Functions, etc.)

### If DB schema is out of sync on production
- Log into InfinityFree control panel → MySQL Databases → phpMyAdmin
- Run the missing ALTER TABLE statements manually (no SSH/CLI available)

### Azure VM (LEGACY — NO LONGER IN USE)
- The `docs/Deployment_Manuals/azure_vm_management_and_contingency.md` references an old Azure VM at `20.187.146.70` — **this is decommissioned**. Azure credits ran out. ML is now on Hugging Face permanently.

---

## 🔐 Security Notes

- Vent text is **AES-256 encrypted** before storing (`encryption.key` in `.env`)
- If `encryption.key` is missing/wrong, the inner try-catch in `VentController` falls back to `base64_encode` and logs an error — submissions are never silently lost
- Forms render CSRF fields, but the global CSRF filter is currently disabled in `app/Config/Filters.php`. Treat CSRF protection as **not enforced** until the filter is enabled and all AJAX flows are verified.
- Sessions use CI4 file-based session handler
- Rate limiting: 1 vent per 30 seconds per session (enforced in `VentController::submit`)

### Current Security/Operations Follow-ups
- Remove the public `/debug-meeting` diagnostic route before production use; it exposes schema and record details and performs a temporary database write.
- Rotate any credentials that were previously committed or hard-coded, then remove them from Git history and application defaults.
- Stop logging OTP plaintext values.
- Restore TLS peer and host verification for calls to the ML service.
- Fix the undefined `$riskPercent` reference in the HIGH-risk counselor alert.
- Enable and test the global CSRF filter.
- Consolidate the two Hugging Face deployment workflows to prevent duplicate deployments.

---

## 🛠️ Common Tasks Reference

| Task | How |
|---|---|
| Add a DB column locally | `D:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -u root student_shield -e "ALTER TABLE ..."` |
| Add a DB column on production | InfinityFree phpMyAdmin → SQL tab |
| Check CI4 error logs (local) | `web-app-ci4/writable/logs/log-YYYY-MM-DD.log` |
| Run CI4 CLI (local only) | `cd web-app-ci4; php spark <command>` |
| Trigger HF deploy manually | Push any change to `ml_service/` on `main` branch |
| Test ML endpoint | `curl -X POST https://ashlogg-student-shield-official.hf.space/analyze-text -H "X-API-KEY: <YOUR_ML_API_KEY>" -H "Content-Type: application/json" -d '{"raw_text":"test","student_id":"1"}'` |
| Run PowerShell git commands | Use `;` not `&&` as separator (PowerShell limitation) |
