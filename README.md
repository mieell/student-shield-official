# StudentShield

**School reporting and counselor review platform · Capstone project**

StudentShield is a capstone web application for emotional-distress screening, bullying reports, and counseling follow-up at **Saint Vincent Blessed School of Manila (SVBSM)**. It gives students a private reporting channel and helps authorized school personnel organize concerns and prioritize review.

**Website:** [studentshield.app](https://studentshield.app)

Screening supports counselor assessment; it does not diagnose mental illness, predict clinical outcomes, or replace professional care. Automated notifications are delivery attempts, not proof that a recipient received, read, or acted on a message.

## Project overview

StudentShield connects a role-based PHP application with a Python text-screening service. It combines student reporting, counselor review, and school-level summaries in one workflow.

- **Web engineering:** CodeIgniter 4, JavaScript, Tailwind CSS, and MySQL/MariaDB.
- **Service integration:** authenticated FastAPI requests, multilingual text screening, and a PHP fallback when the ML service is unavailable.
- **Access and privacy:** role-based authorization, encrypted vent text, restricted clinical records, and audit logging.
- **Delivery:** separate GitHub Actions deployments for shared PHP hosting and a Docker-based Hugging Face Space.
- **Verification:** PHP and Python test suites, including authorization, screening rules, and workflow checks. See the testing instructions below.

### Reporting workflow

Students submit fictional or real concerns through their authorized accounts. Counselors review individual reports and record notes or arrange sessions. Students can check their own report references and progress in **My reports**: Submitted → Under review → Closed by counselor. Verification and parent-alert attempts do not automatically close a report.

The principal views aggregate summaries, while the super administrator manages accounts and access. The repository presents the implementation; access to staff tools and private records requires authorization. No shared production credentials are published here.

## Features and roles

| Role | Access |
|---|---|
| Student | Registration with a twelve-digit Learner Reference Number (LRN), Safe Space vents and optional feelings, bullying reports with optional evidence and private status tracking, counseling requests, and assigned Google Meet access. |
| Counselor | Student registration approval, vent and incident review, incident verification and report closure, counselor notes, student risk history, and counseling sessions. |
| Super Admin | Account creation and management, roles and access restrictions, and audit metadata. Individual clinical case records and counseling sessions are restricted. |
| Principal | Aggregate analytics, report filters, and summary CSV exports. Individual clinical records are restricted. |
| Parent / Guardian | Eligible notifications and receipts; no portal account. |

Students sign in using their account name and password after counselor approval. Staff use the staff sign-in portal with six-digit email OTP verification when enabled; a valid remembered-device token can bypass a new challenge. Counselors publish Google Meet links created outside StudentShield, and the application checks assigned access before redirecting participants to the external meeting.

## Architecture

The application runs across two hosting environments:

| Component | Technology | Production hosting |
|---|---|---|
| Web application | PHP, CodeIgniter 4, HTML, Tailwind CSS, JavaScript | InfinityFree, with Cloudflare in front of the web domain |
| Application records | MySQL / MariaDB | InfinityFree |
| Text screening | Python, FastAPI, PyTorch, Transformers | Hugging Face Spaces (Docker) |
| Notifications | Configured SMS gateway and SMTP | External services configured through environment variables |

The web application sends vent text, an internal student identifier, and recent risk history to the authenticated `POST /analyze-text` endpoint. Analysis requests require an `X-API-KEY` header matching `ML_API_KEY` in the service environment.

The screening pipeline combines a fine-tuned multilingual mDeBERTa model, the `SamLowe/roberta-base-go_emotions` emotion classifier, and language rules for English, Filipino/Taglish, and regional expressions. The distress model can load from local model files or `ashlogg/student-shield-mdeberta` on Hugging Face Hub. Recent risk labels and timestamps come from the application database; the current history calculation uses a fourteen-day window rather than a per-student FAISS memory store.

PHP retains local keyword screening when the ML service is unavailable and applies safety guards before saving results and attempting alerts. The risk percentage is a software screening score, not a clinically validated probability of harm.

### Alert rules

| Vent classification | Score band | Notification behavior |
|---|---|---|
| Low | 1–30% | Available for authorized review. |
| Medium | 31–60% | Available for authorized review. |
| High | 61–85% | Counselor alert attempts; no automatic parent vent alert. |
| Critical | 86–100% | Counselor alerts and eligible parent/guardian alert attempts, subject to available contacts, parent-alert cooldowns, and the student's parent-hiding selection. |

Parent hiding suppresses the Critical-vent parent alert while preserving counselor escalation. Bullying reports follow a separate process: counselor notification at intake, followed by eligible parent incident alerts only after counselor verification. Receipt and false-report notices follow their own rules.

The application sends SMS requests through iProg SMS. Configure IPROG_SMS_API_TOKEN in the hosting environment and verify account credits, network coverage, and sender-name requirements. A queued message is not a delivery receipt; confirm delivery separately in iProg. Email is configured separately through SMTP.

## Repository layout

```text
web-app-ci4/          CodeIgniter application, views, configuration, and PHP tests
ml_service/           FastAPI service, screening rules, training scripts, and tests
schema.sql            Database schema
.github/workflows/    Web and ML deployment workflows
AGENTS.md             Repository constraints and contribution guardrails
AI_CONTEXT.md         Additional project notes; check current code for implementation details
```

## Local development

### Requirements

- **PHP 8.2+** and Composer, matching the current `web-app-ci4/composer.json` requirement. Enable the PHP extensions required by Composer and the application, including `intl`, `mbstring`, `mysqli`, `openssl`, and `curl`.
- MySQL or MariaDB. Laragon is the project's Windows development environment.
- **Python 3.10+**, `pip`, and a virtual environment for the ML service.
- Internet access and sufficient disk space/memory for the first model and NLP-resource downloads. Local development hardware is not a benchmarked production-capacity specification.

### 1. Create the database

Create a local database named `student_shield` with `utf8mb4` encoding, then import [schema.sql](schema.sql) using phpMyAdmin, HeidiSQL, or your database client. Use fictional records for development and testing.

### 2. Configure and start the web application

From the repository root:

```powershell
cd web-app-ci4
composer install
```

If `.env` does not exist, copy the supplied `env` template to `.env`. Do not overwrite an existing configuration. Set your local database credentials and application URL:

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'

database.default.hostname = localhost
database.default.database = student_shield
database.default.username = YOUR_LOCAL_DATABASE_USER
database.default.password = YOUR_LOCAL_DATABASE_PASSWORD
database.default.DBDriver = MySQLi

ML_API_URL = http://127.0.0.1:8000
ML_API_KEY = YOUR_GENERATED_SHARED_API_KEY
```

Configure a strong encryption key before accepting any submissions. For a new local installation, generate it using CodeIgniter's command:

```powershell
php spark key:generate
php spark serve
```

Preserve the encryption key when working with existing encrypted records; replacing it can make those records unreadable. Configure counselor contacts, SMTP, and the SMS gateway separately when testing notifications, using controlled recipient numbers and email addresses. Create staff accounts through a controlled setup process; do not deploy the legacy demonstration seeder with its built-in credentials.

- Student sign-in: [localhost:8080/login](http://localhost:8080/login)
- Registration: [localhost:8080/register](http://localhost:8080/register)
- Staff sign-in: [localhost:8080/admin/login](http://localhost:8080/admin/login)

If using Laragon's virtual host instead of `spark serve`, point the document root at `web-app-ci4/public/` and match `app.baseURL` to that host.

### 3. Configure and start the ML service

Open another PowerShell terminal at the repository root:

```powershell
cd ml_service
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
$env:ML_API_KEY = 'YOUR_GENERATED_SHARED_API_KEY'
python main.py
```

Use the same generated API key as the PHP configuration. The service reads `ML_API_KEY` from its process environment; the web application's `.env` does not configure Python automatically. On macOS/Linux, activate the environment with `source .venv/bin/activate` and set the key with `export ML_API_KEY='YOUR_GENERATED_SHARED_API_KEY'`.

The local API runs on port **8000**, with interactive documentation at [localhost:8000/docs](http://localhost:8000/docs). The Docker deployment uses port **7860**. Full inference can download models and language resources; the local fallback has reduced capabilities and is not equivalent to a successful model response.

## Tests

PHP tests are under `web-app-ci4/tests/`. After installing Composer dependencies, run the unit tests from `web-app-ci4`:

```powershell
php vendor/bin/phpunit tests/unit
```

Python tests are under `ml_service/tests/`. With the ML environment active, run from the repository root:

```powershell
python -m pip install pytest
python -m pytest ml_service/tests
```

The screening tests use mocked model and translation behavior, and the risk-history tests exercise deterministic functions. Unit tests should not require live student records, a production database, notification delivery, or an active internet connection after dependencies are installed. Passing mocked tests does not establish clinical accuracy or live notification delivery.

## Production deployment

- [Web deployment workflow](.github/workflows/ftp-deploy.yml): pushes to `main` or `master` trigger FTP synchronization of `web-app-ci4/` to InfinityFree. FTP credentials are GitHub Actions secrets. Environment files, tests, and selected runtime directories are excluded.
- [ML deployment workflow](.github/workflows/deploy_hf.yml): changes to `ml_service/` or the workflow on `main`, or a manual workflow run, upload the service to the Docker Space `ashlogg/student_shield_official` using the `HF_TOKEN` Actions secret.
- The Hugging Face model repository and Space are separate: the model is `ashlogg/student-shield-mdeberta`; the Space upload target is the underscore-separated identifier specified in the workflow.
- InfinityFree production has **no SSH or runtime CLI access**. Prepare dependencies before upload and apply database changes using the hosting database tools. Local `spark` commands are not production-server instructions.
- Keep production environment values in the hosting configuration, and the service API key in the Space's secret environment. Azure is a legacy deployment, not the current ML host.

## Privacy and project limits

Vents are linked to authenticated student accounts and can be reviewed by authorized counselors; the platform is confidential by design rather than anonymous to school staff. Vent and incident text normally use CodeIgniter's AES-256-CTR encryption. The current vent-controller error path falls back to Base64 encoding, which is **not encryption** and remains a confidentiality limitation.

Keep real disclosures, student identities, LRN/contact records, credentials, model-training exports derived from private records, and production logs out of public commits and issue reports. The repository ignores `.env`, PDFs, selected local documents, logs, and model artifacts; ignore rules do not remove previously committed material from Git history.

Automated enrollment-based retention/deletion and verified guardian-consent recording are not established features. Deployment, access controls, and a privacy notice alone do not establish legal compliance. Model accuracy, service availability, and intervention outcomes require separate evaluation; no such results are claimed by this README.

See [AGENTS.md](AGENTS.md) before contributing. Preserve API authentication, backward-compatible encryption, local screening safeguards, language coverage, and the role and notification boundaries described above.

