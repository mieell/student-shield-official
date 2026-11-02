# Gemini backup for vent screening

Your Hugging Face model remains the first choice. Only a failed request, non-2xx status or invalid risk response invokes the optional Gemini adapter. Bullying reports and other features do not call Gemini.

## Enable manually

Add these **server-side** values to `web-app-ci4/.env` locally, and separately to the hosted PHP application's private `.env` when deploying:

```dotenv
GEMINI_VENT_FALLBACK_ENABLED = true
GEMINI_API_KEY = "YOUR_PRIVATE_KEY"
GEMINI_VENT_MODEL = "YOUR_AVAILABLE_GEMINI_MODEL_ID"
```

Select a model available to your account that supports GenerateContent structured JSON-schema output. No model is hardcoded because availability, quotas and account access change. A missing key/model, unavailable model, timeout or refusal retains the local fallback. The integration stays off by default; no credentials are placed in browser scripts or URLs.

The PHP server sends the current entry text to Google's Gemini API, not the student's account ID, class, guardian contacts or prior entries. Identifying details written inside the entry are still part of that text; this is not automatic anonymization. Review the provider's current processing/retention terms and your privacy disclosure before using real entries. Use synthetic entries and controlled recipients for testing.

## Failure and safety behavior

- Valid primary result: no Gemini request and no full local-fallback recomputation; explicit crisis/threat guards still run.
- Model timeout/authentication error/rate limit/invalid result: full local assessment plus one Gemini request when configured.
- Valid Gemini result: use it only if it is at least as severe as the full local assessment. Explicit crisis/threat guards run before persistence.
- Gemini refusal, blocked/truncated response, malformed JSON, mismatched score/band, bad key, provider failure or timeout: retain the local result.
- No retry loop. Primary analysis uses a 15-second overall/3-second connection timeout when the backup switch is enabled; otherwise the previous 60/15 limits remain. Gemini has a 12-second overall/3-second connection timeout, a 128 KiB response cap, TLS certificate verification and no redirects.
- The current implementation has no cross-request circuit breaker or global spending cap. Configure provider quotas/budget controls and inspect outages; each eligible failed-model submission can make one API request.
- A valid but inaccurate primary prediction does not trigger Gemini. Silent fallback inside the ML service that still returns a valid result also does not trigger this PHP backup. This feature is availability fallback, not automatic accuracy evaluation.

Existing HIGH/CRITICAL alert conditions, guardian suppression and encryption/persistence paths are retained. A successful fallback uses the existing student response. If saving fails, the app must still report failure rather than claim success. Existing unrelated storage/security gaps are not repaired by this feature.

## Provenance and evaluation

Each saved entry gets its existing CREATE_VENT audit event plus VENT_ANALYSIS_MODEL, VENT_ANALYSIS_GEMINI or VENT_ANALYSIS_LOCAL with the vent ID. GEMINI means the API contributed an assessment subject to the local safety floor and guards; it does not mean the final score came unchanged from Google. Risk-history metrics are retained only for valid primary-model results; Gemini/local paths use INSUFFICIENT_DATA and zero rather than inventing a forecast.

Use audit provenance to separate API-assisted results from custom-model evaluation. Do not describe combined outputs as standalone custom-model accuracy. Neither a schema-valid response nor these offline tests establish clinical or multilingual accuracy.

## Verify and disable

Run `php vendor/bin/phpunit --filter VentApiFallbackTest --no-progress --do-not-cache-result --no-logging --no-coverage` from `web-app-ci4/`. Tests use synthetic data and stubbed transport, with no live database/API calls.

For a controlled local end-to-end check, use a separate local environment/test account, configure the provider and temporarily make the local ML URL unavailable. Submit a synthetic entry and verify the saved risk plus the VENT_ANALYSIS audit event. Confirm real notification destinations are not configured for the test. Restore the ML URL and verify a successful primary request does not use Gemini. Do not break the production ML configuration to test failover.

Set `GEMINI_VENT_FALLBACK_ENABLED = false` to disable the API. Removing the API key also prevents calls. The ordinary model/local fallback remains usable. FTP deployment excludes `.env*`, so a code push does not install these runtime secrets/settings.

API references: [GenerateContent](https://ai.google.dev/api/generate-content), [structured output](https://ai.google.dev/gemini-api/docs/generate-content/structured-output). Structured format compliance is not proof of semantic correctness.
