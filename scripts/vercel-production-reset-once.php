<?php

declare(strict_types=1);

use App\Services\CpfValidator;
use App\Services\SecurityService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('VERCEL_ENV') !== 'production') {
    fwrite(STDERR, "Refusing reset outside Vercel production.\n");
    exit(1);
}

$pixKey = 'e4d1468b-41dd-40b1-8bbb-86825c3958c7';
$email = (string) config('nexora.super_admin_email');
$cpf = (string) config('nexora.super_admin_cpf');
$password = (string) config('nexora.super_admin_password');

if ($email === '' || strlen($password) < 8 || ! CpfValidator::isValid($cpf)) {
    fwrite(STDERR, "Refusing reset because super admin bootstrap is not configured.\n");
    exit(1);
}

$security = app(SecurityService::class);
if (! $security->isValidPixKey($pixKey)) {
    fwrite(STDERR, "Refusing reset because the requested Pix key is invalid.\n");
    exit(1);
}

$now = (int) floor(microtime(true) * 1000);
$userId = (string) Str::uuid();
$user = [
    'id' => $userId,
    'public_id' => 'NX-'.substr(strtoupper(str_replace('-', '', $userId)), 0, 8),
    'name' => 'Fundador Nexora',
    'email' => strtolower($email),
    'email_verified' => true,
    'verification_code_hash' => null,
    'verification_expires_at' => null,
    'password_reset_code_hash' => null,
    'password_reset_expires_at' => null,
    'cpf_hash' => $security->hashCpf($cpf),
    'cpf_cipher' => $security->encrypt($cpf),
    'pix_cipher' => $security->encrypt($pixKey),
    'password_hash' => $security->hashPassword($password),
    'status' => 'APPROVED',
    'role' => 'SUPER_ADMIN',
    'xp' => 0,
    'level' => 1,
    'buff_bps' => 0,
    'on_time_returned_cents' => 0,
    'early_returned_cents' => 0,
    'invited_by' => null,
    'invite_code' => substr(strtoupper(str_replace('-', '', (string) Str::uuid())), 0, 8),
    'created_at_ms' => $now,
    'admin_fee_due_cents' => 0,
];
if (Schema::hasColumn('users', 'birthdate')) {
    $user['birthdate'] = null;
}

Artisan::call('migrate', ['--force' => true]);

DB::transaction(function () use ($user, $userId, $now): void {
    foreach (['auth_tokens', 'pix_receipts', 'contributions', 'support_requests', 'audit_logs', 'users'] as $table) {
        if (Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
    }

    DB::table('users')->insert($user);

    if (Schema::hasTable('audit_logs')) {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'actor_user_id' => $userId,
            'action' => 'PRODUCTION_DATABASE_RESET',
            'target' => $userId,
            'created_at_ms' => $now,
        ]);
    }
});

$summary = [
    'ok' => true,
    'email' => strtolower($email),
    'role' => 'SUPER_ADMIN',
    'status' => 'APPROVED',
    'totalUsers' => DB::table('users')->count(),
    'pixMatchesRequestedKey' => true,
];

fwrite(STDOUT, 'NEXORA_PRODUCTION_RESET '.json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL);
