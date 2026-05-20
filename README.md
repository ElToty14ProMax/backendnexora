# Nexora Laravel Backend

Laravel/PostgreSQL port of the Nexora API. The Kotlin backend remains in `../backend`; this service is now API-only. The React web app lives in `../nexora-web`.

## Local PostgreSQL Setup

Create a PostgreSQL database named `nexora`, then configure `.env`:

```env
APP_URL=http://localhost:8000
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=nexora
DB_USERNAME=postgres
DB_PASSWORD=your-password

NEXORA_ENV=dev
NEXORA_ADMIN_TOKEN=replace-with-32-plus-random-chars
NEXORA_DATA_KEY_B64=base64-encoded-32-byte-key
NEXORA_CPF_PEPPER=replace-with-long-random-pepper
NEXORA_ADMIN_PIX_KEY=bank-generated-random-pix-key-uuid-v4
NEXORA_CONTRIBUTION_EXPIRATION_MINUTES=5
NEXORA_SUPER_ADMIN_EMAIL=admin@example.com
NEXORA_SUPER_ADMIN_CPF=valid-founder-cpf
NEXORA_SUPER_ADMIN_PASSWORD=local-bootstrap-password
```

Generate a 32-byte data key:

```powershell
php -r "$bytes=random_bytes(32); echo base64_encode($bytes), PHP_EOL;"
```

Run:

```powershell
composer install
php artisan migrate
php artisan serve --host=0.0.0.0 --port=8000
```

Android emulator default API URL is now:

```text
http://10.0.2.2:8000
```

## API Compatibility

The Laravel routes intentionally match the previous Ktor backend:

- `/auth/register`, `/auth/verify-email`, `/auth/login`, recovery endpoints
- `/me`, `/dashboard`, `/community`
- `/support-requests/...`
- `/admin/...`

Pix instructions generate a bank-ready copy/paste code for the requester's registered Pix key. The API does not return that key as a visible field, but the BR Code itself must contain a resolvable Pix key so bank apps can pay the correct receiver. Receipt dates are assigned by the server when evidence is submitted.

## Verification

```powershell
php artisan test
```

A manual smoke test was also run with a temporary SQLite database covering registration, verification, login, admin approval, support request approval, Pix instruction privacy, both receipt uploads, and contribution confirmation.

## Vercel Deployment

This repository includes `vercel.json` and `api/index.php` so Vercel runs Laravel through the community PHP runtime instead of trying to deploy it as a Vite/Node app.

Vercel does not provide your local PostgreSQL. Use a managed PostgreSQL database such as Neon, Supabase, Railway, Render, or another hosted Postgres, then add these environment variables in Vercel:

```env
APP_NAME=Nexora
APP_ENV=production
APP_KEY=base64:your-generated-laravel-key
APP_DEBUG=false
APP_URL=https://your-vercel-project.vercel.app

DB_CONNECTION=pgsql
DB_HOST=your-managed-postgres-host
DB_PORT=5432
DB_DATABASE=nexora
DB_USERNAME=your-db-user
DB_PASSWORD=your-db-password
DB_SSLMODE=require

LOG_CHANNEL=stderr
SESSION_DRIVER=array
CACHE_STORE=array
QUEUE_CONNECTION=sync

NEXORA_ENV=prod
NEXORA_ADMIN_TOKEN=replace-with-32-plus-random-chars
NEXORA_DATA_KEY_B64=base64-encoded-32-byte-key
NEXORA_CPF_PEPPER=replace-with-long-random-pepper
NEXORA_ADMIN_PIX_KEY=bank-generated-random-pix-key-uuid-v4
NEXORA_SUPER_ADMIN_EMAIL=admin@example.com
NEXORA_SUPER_ADMIN_CPF=valid-founder-cpf
NEXORA_SUPER_ADMIN_PASSWORD=bootstrap-password
NEXORA_FOUNDER_EMAILS=admin@example.com

OCR_PROVIDER=ocrspace
OCR_SPACE_API_KEY=your-ocr-space-api-key
OCR_SPACE_LANGUAGE=por
OCR_SPACE_ENGINE=2
```

If the Vercel Neon integration creates `DATABASE_URL`, `POSTGRES_URL`, `PGHOST`, `PGUSER`, and `PGPASSWORD`, Laravel will use those automatically. You only need to add `DB_CONNECTION=pgsql` and the Nexora application secrets. For Neon pooled connections, keep `DB_DISABLE_PREPARES=true` and `DB_EMULATE_PREPARES=false` so PostgreSQL transactions work correctly through PgBouncer.

Pix keys accepted by Nexora must be the bank-generated random key (EVP UUID v4), for example `550e8400-e29b-41d4-a716-446655440000`. CPF, email, and phone Pix keys are rejected at registration and when generating Pix copy/paste codes.

Generate `APP_KEY` locally:

```powershell
php artisan key:generate --show
```

After the first successful deploy, run migrations against the production database from your machine by temporarily setting `.env` to the hosted database values and running:

```powershell
php artisan migrate --force
```

If the deploy is already live on Vercel and you only have the Vercel environment configured, call the protected migration endpoint once after deploy:

```powershell
Invoke-RestMethod -Method Post `
  -Uri "https://your-vercel-project.vercel.app/system/migrate" `
  -Headers @{ "X-Admin-Token" = "your-NEXORA_ADMIN_TOKEN" }
```

This endpoint exists to repair production schema drift such as missing `contributions.verification_status` after an automatic GitHub deploy. Keep `NEXORA_ADMIN_TOKEN` long and secret.

For free OCR on Vercel, use `OCR_PROVIDER=ocrspace` and create a free API key at OCR.space. Add the key in Vercel Project Settings -> Environment Variables as `OCR_SPACE_API_KEY`. `tesseract` is still useful locally, but Vercel serverless functions do not normally include the native Tesseract binary.

The default `AWS_*` variables are Laravel placeholders for S3/cloud storage. Nexora is not using them right now while receipts are stored in PostgreSQL, so they can stay empty unless the app is later changed to store receipt images in S3/R2.
