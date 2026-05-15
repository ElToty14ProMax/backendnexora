# Nexora Laravel Backend

Laravel/PostgreSQL port of the Nexora API. The Kotlin backend remains in `../backend`; this service is the new API target for the Android app.

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
NEXORA_ADMIN_PIX_KEY=your-platform-pix-key
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
- `/admin-web/index.html`

Pix instructions return a platform Pix copy/paste code and keep receiver Pix keys private. Receipt dates are assigned by the server when evidence is submitted.

## Verification

```powershell
php artisan test
```

A manual smoke test was also run with a temporary SQLite database covering registration, verification, login, admin approval, support request approval, Pix instruction privacy, both receipt uploads, and contribution confirmation.
