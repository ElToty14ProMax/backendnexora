<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$baseUrl = rtrim((string) (getenv('NEXORA_PROD_URL') ?: 'https://backend-laravel-two.vercel.app'), '/');
$runId = gmdate('YmdHis');
$password = 'CodexTest!'.$runId;
$adminEmail = 'frankegr14+codex-admin-'.$runId.'@gmail.com';
$requesterEmail = 'frankegr14+codex-req-'.$runId.'@gmail.com';
$donorEmail = 'frankegr14+codex-donor-'.$runId.'@gmail.com';
$adminCpf = generateCpf(((int) substr($runId, -9)) + 123456);
$requesterCpf = generateCpf((int) substr($runId, -9));
$donorCpf = generateCpf((int) substr(strrev($runId), 0, 9));
$results = [];
$adminBearer = null;

function generateCpf(int $seed): string
{
    $base = str_pad((string) ($seed % 1000000000), 9, '0', STR_PAD_LEFT);
    if (preg_match('/^(\d)\1{8}$/', $base)) {
        $base = '123456789';
    }
    $digits = array_map('intval', str_split($base));
    for ($length = 9; $length <= 10; $length++) {
        $sum = 0;
        for ($i = 0; $i < $length; $i++) {
            $sum += $digits[$i] * (($length + 1) - $i);
        }
        $check = ($sum * 10) % 11;
        $digits[] = $check === 10 ? 0 : $check;
    }
    return implode('', $digits);
}

function httpRequest(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $curl = curl_init($url);
    $headerList = array_merge(['Accept: application/json'], $headers);
    if ($body !== null) {
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $headerList[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headerList,
        CURLOPT_TIMEOUT => 45,
    ]);
    $started = microtime(true);
    $raw = curl_exec($curl);
    $durationMs = (int) round((microtime(true) - $started) * 1000);
    if ($raw === false) {
        $error = curl_error($curl);
        curl_close($curl);
        return ['status' => 0, 'json' => null, 'text' => $error, 'durationMs' => $durationMs];
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $text = substr($raw, $headerSize);
    curl_close($curl);
    $json = json_decode($text, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'text' => $text, 'durationMs' => $durationMs];
}

function record(array &$results, string $name, array $response, array $expectedStatuses, ?callable $validator = null, string $note = ''): array
{
    $passed = in_array($response['status'], $expectedStatuses, true);
    if ($passed && $validator !== null) {
        $passed = (bool) $validator($response['json'], $response);
    }
    $results[] = [
        'name' => $name,
        'status' => $response['status'],
        'expected' => implode('/', $expectedStatuses),
        'passed' => $passed,
        'durationMs' => $response['durationMs'],
        'note' => $note,
        'bodySample' => substr($response['text'] ?? '', 0, 240),
    ];
    return $response;
}

function api(string $method, string $path, ?array $body = null, array $headers = []): array
{
    global $baseUrl;
    return httpRequest($method, $baseUrl.$path, $body, $headers);
}

function bearer(string $token): array
{
    return ['Authorization: Bearer '.$token];
}

function adminHeaders(): array
{
    global $adminBearer;
    if (! is_string($adminBearer) || $adminBearer === '') {
        throw new RuntimeException('Admin bearer token is not available yet.');
    }
    return bearer($adminBearer);
}

function requireJsonValue(array $response, string $key): string
{
    $value = $response['json'][$key] ?? null;
    if (! is_string($value) || $value === '') {
        throw new RuntimeException("Missing JSON key {$key}");
    }
    return $value;
}

function dbUserId(string $email): string
{
    $id = DB::table('users')->where('email', $email)->value('id');
    if (! is_string($id) || $id === '') {
        throw new RuntimeException("User not found for {$email}");
    }
    return $id;
}

if ((string) config('database.default') !== 'pgsql') {
    fwrite(STDERR, "This smoke test must be pointed at the production PostgreSQL database.\n");
    exit(2);
}

$configuredHost = (string) config('database.connections.pgsql.host');
$configuredUrl = (string) config('database.connections.pgsql.url');
if (! str_contains($configuredHost.$configuredUrl, 'neon.tech')) {
    fwrite(STDERR, "Refusing direct DB setup because the configured pgsql host is not Neon.\n");
    exit(2);
}

try {
    record($results, 'GET /health', api('GET', '/health'), [200], fn ($json) => ($json['ok'] ?? false) === true);

    record($results, 'GET /me without token', api('GET', '/me'), [401], fn ($json) => isset($json['error']));
    record($results, 'POST /auth/login invalid credentials', api('POST', '/auth/login', [
        'identifier' => 'naoexiste-'.$runId.'@example.com',
        'password' => 'wrong-password',
    ]), [401], fn ($json) => isset($json['error']));

    record($results, 'POST /auth/register admin test user', api('POST', '/auth/register', [
        'name' => 'Codex Admin '.$runId,
        'email' => $adminEmail,
        'cpf' => $adminCpf,
        'pixKey' => $adminEmail,
        'password' => $password,
    ]), [201], fn ($json) => isset($json['message']));

    $registerRequester = record($results, 'POST /auth/register requester', api('POST', '/auth/register', [
        'name' => 'Codex Requester '.$runId,
        'email' => $requesterEmail,
        'cpf' => $requesterCpf,
        'pixKey' => $requesterEmail,
        'password' => $password,
    ]), [201], fn ($json) => isset($json['message']));

    $registerDonor = record($results, 'POST /auth/register donor', api('POST', '/auth/register', [
        'name' => 'Codex Donor '.$runId,
        'email' => $donorEmail,
        'cpf' => $donorCpf,
        'pixKey' => $donorEmail,
        'password' => $password,
    ]), [201], fn ($json) => isset($json['message']));

    record($results, 'POST /auth/resend-verification', api('POST', '/auth/resend-verification', [
        'email' => $requesterEmail,
    ]), [200], fn ($json) => ($json['ok'] ?? false) === true, 'Email endpoint must not return 500 even when SMTP rejects credentials.');

    record($results, 'POST /auth/recover-password', api('POST', '/auth/recover-password', [
        'email' => $requesterEmail,
    ]), [200], fn ($json) => ($json['ok'] ?? false) === true, 'Email endpoint must not return 500 even when SMTP rejects credentials.');

    record($results, 'POST /auth/verify-email invalid code', api('POST', '/auth/verify-email', [
        'email' => $requesterEmail,
        'code' => '000000',
    ]), [400], fn ($json) => isset($json['error']));

    DB::table('users')->where('email', $adminEmail)->update([
        'email_verified' => true,
        'verification_code_hash' => null,
        'verification_expires_at' => null,
        'status' => 'APPROVED',
        'role' => 'SUPER_ADMIN',
    ]);
    DB::table('users')->where('email', $requesterEmail)->update([
        'email_verified' => true,
        'verification_code_hash' => null,
        'verification_expires_at' => null,
        'status' => 'APPROVED',
        'xp' => 100,
        'level' => 2,
        'admin_fee_due_cents' => 0,
    ]);
    DB::table('users')->where('email', $donorEmail)->update([
        'email_verified' => true,
        'verification_code_hash' => null,
        'verification_expires_at' => null,
        'status' => 'APPROVED',
    ]);

    $adminLogin = record($results, 'POST /auth/login admin test user', api('POST', '/auth/login', [
        'identifier' => $adminEmail,
        'password' => $password,
    ]), [200], fn ($json) => isset($json['token'], $json['profile']) && ($json['profile']['role'] ?? '') === 'SUPER_ADMIN');
    $adminBearer = requireJsonValue($adminLogin, 'token');

    record($results, 'GET /admin/overview with admin bearer', api('GET', '/admin/overview', null, adminHeaders()), [200], fn ($json) => isset($json['totalUsers']));

    $requesterLogin = record($results, 'POST /auth/login requester', api('POST', '/auth/login', [
        'identifier' => $requesterEmail,
        'password' => $password,
    ]), [200], fn ($json) => isset($json['token'], $json['profile']));
    $requesterToken = requireJsonValue($requesterLogin, 'token');

    $donorLogin = record($results, 'POST /auth/login donor', api('POST', '/auth/login', [
        'identifier' => $donorEmail,
        'password' => $password,
    ]), [200], fn ($json) => isset($json['token'], $json['profile']));
    $donorToken = requireJsonValue($donorLogin, 'token');

    record($results, 'GET /me requester', api('GET', '/me', null, bearer($requesterToken)), [200], fn ($json) => ($json['email'] ?? '') !== '');
    record($results, 'GET /dashboard requester', api('GET', '/dashboard', null, bearer($requesterToken)), [200], fn ($json) => isset($json['activeRequests']));

    $support = record($results, 'POST /support-requests', api('POST', '/support-requests', [
        'amountCents' => 2000,
        'dueDays' => 7,
        'description' => 'Smoke test Vercel '.$runId,
    ], bearer($requesterToken)), [201], fn ($json) => isset($json['id']) && ($json['status'] ?? '') === 'PENDING_ADMIN');
    $supportId = requireJsonValue($support, 'id');

    record($results, 'GET /support-requests/mine requester', api('GET', '/support-requests/mine', null, bearer($requesterToken)), [200], fn ($json) => is_array($json));
    record($results, 'POST /admin/support-requests/{id}/approve', api('POST', '/admin/support-requests/'.$supportId.'/approve', null, adminHeaders()), [200], fn ($json) => ($json['ok'] ?? false) === true);
    record($results, 'GET /community donor', api('GET', '/community', null, bearer($donorToken)), [200], fn ($json) => is_array($json));

    $contribution = record($results, 'POST /support-requests/{id}/contributions', api('POST', '/support-requests/'.$supportId.'/contributions', [
        'amountCents' => 2000,
    ], bearer($donorToken)), [201], function ($json) use ($requesterEmail) {
        $code = is_array($json) ? (string) ($json['pixCopyCode'] ?? '') : '';
        $adminPixKey = (string) config('nexora.admin_pix_key');
        return isset($json['contributionId'])
            && $code !== ''
            && str_contains($code, $requesterEmail)
            && ($adminPixKey === '' || ! str_contains($code, $adminPixKey))
            && ($json['receiverPixKey'] ?? 'not-empty') === '';
    });
    $contributionId = requireJsonValue($contribution, 'contributionId');

    $txId = 'CODX-'.$runId.'-A';
    $senderBytes = 'sender receipt '.$runId;
    $receiverBytes = 'receiver receipt '.$runId;
    record($results, 'POST /support-requests/contributions/{id}/receipt sender', api('POST', '/support-requests/contributions/'.$contributionId.'/receipt', [
        'side' => 'SENDER',
        'amountCents' => 2000,
        'transactionId' => $txId,
        'receiptMimeType' => 'image/png',
        'receiptImageBase64' => base64_encode($senderBytes),
        'receiptHash' => hash('sha256', $senderBytes),
    ], bearer($donorToken)), [201], fn ($json) => ($json['hasSenderReceipt'] ?? false) === true);

    record($results, 'POST /support-requests/contributions/{id}/receipt receiver', api('POST', '/support-requests/contributions/'.$contributionId.'/receipt', [
        'side' => 'RECEIVER',
        'amountCents' => 2000,
        'transactionId' => $txId,
        'receiptMimeType' => 'image/png',
        'receiptImageBase64' => base64_encode($receiverBytes),
        'receiptHash' => hash('sha256', $receiverBytes),
    ], bearer($requesterToken)), [201], fn ($json) => ($json['evidenceComplete'] ?? false) === true);

    record($results, 'POST /admin/contributions/{id}/confirm', api('POST', '/admin/contributions/'.$contributionId.'/confirm', null, adminHeaders()), [200], fn ($json) => ($json['ok'] ?? false) === true);
    record($results, 'POST /admin/support-requests/{id}/confirm-return', api('POST', '/admin/support-requests/'.$supportId.'/confirm-return', null, adminHeaders()), [200], fn ($json) => ($json['ok'] ?? false) === true);

    $secondSupport = record($results, 'POST /support-requests second request', api('POST', '/support-requests', [
        'amountCents' => 1000,
        'dueDays' => 7,
        'description' => 'Smoke auto split '.$runId,
    ], bearer($requesterToken)), [201], fn ($json) => isset($json['id']));
    $secondSupportId = requireJsonValue($secondSupport, 'id');

    record($results, 'POST /admin/support-requests/{id}/approve second', api('POST', '/admin/support-requests/'.$secondSupportId.'/approve', null, adminHeaders()), [200], fn ($json) => ($json['ok'] ?? false) === true);
    record($results, 'POST /support-requests/contributions/auto-split', api('POST', '/support-requests/contributions/auto-split', [
        'amountCents' => 1000,
    ], bearer($donorToken)), [201], fn ($json) => isset($json['instructions']) && count($json['instructions']) >= 1);

    $rejectedSupport = record($results, 'POST /support-requests reject candidate', api('POST', '/support-requests', [
        'amountCents' => 500,
        'dueDays' => 5,
        'description' => 'Smoke reject '.$runId,
    ], bearer($requesterToken)), [201], fn ($json) => isset($json['id']));
    $rejectedSupportId = requireJsonValue($rejectedSupport, 'id');
    record($results, 'POST /admin/support-requests/{id}/reject', api('POST', '/admin/support-requests/'.$rejectedSupportId.'/reject', [
        'reason' => 'Smoke test rejection path',
    ], adminHeaders()), [200], fn ($json) => ($json['ok'] ?? false) === true);

    $requesterId = dbUserId($requesterEmail);
    $donorId = dbUserId($donorEmail);
    record($results, 'POST /admin/users/{id}/reputation', api('POST', '/admin/users/'.$requesterId.'/reputation', [
        'xp' => 120,
        'level' => 2,
        'buffBps' => 0,
        'adminFeeDueCents' => 0,
    ], adminHeaders()), [200], fn ($json) => ($json['ok'] ?? false) === true);
    record($results, 'POST /admin/users/{id}/role', api('POST', '/admin/users/'.$donorId.'/role', [
        'role' => 'USER',
    ], adminHeaders()), [200], fn ($json) => ($json['ok'] ?? false) === true);
    record($results, 'POST /admin/users/{id}/block', api('POST', '/admin/users/'.$donorId.'/block', null, adminHeaders()), [200], fn ($json) => ($json['ok'] ?? false) === true);
    record($results, 'POST /admin/users/{id}/approve', api('POST', '/admin/users/'.$donorId.'/approve', null, adminHeaders()), [200], fn ($json) => ($json['ok'] ?? false) === true);
    record($results, 'POST /admin/users/{id}/confirm-admin-fee', api('POST', '/admin/users/'.$requesterId.'/confirm-admin-fee', null, adminHeaders()), [200], fn ($json) => ($json['ok'] ?? false) === true);

    record($results, 'GET /support-requests/contributions/mine donor', api('GET', '/support-requests/contributions/mine', null, bearer($donorToken)), [200], fn ($json) => is_array($json));
    record($results, 'GET /support-requests/contributions/mine requester', api('GET', '/support-requests/contributions/mine', null, bearer($requesterToken)), [200], fn ($json) => is_array($json));
    record($results, 'GET /admin/users', api('GET', '/admin/users', null, adminHeaders()), [200], fn ($json) => is_array($json));
    record($results, 'GET /admin/support-requests', api('GET', '/admin/support-requests', null, adminHeaders()), [200], fn ($json) => is_array($json));
    record($results, 'GET /admin/contributions', api('GET', '/admin/contributions', null, adminHeaders()), [200], fn ($json) => is_array($json));
    record($results, 'GET /admin/audit-logs', api('GET', '/admin/audit-logs?limit=20', null, adminHeaders()), [200], fn ($json) => is_array($json));
} catch (Throwable $error) {
    $results[] = [
        'name' => 'test-run-exception',
        'status' => 0,
        'expected' => 'none',
        'passed' => false,
        'durationMs' => 0,
        'note' => $error->getMessage(),
        'bodySample' => '',
    ];
}

$failed = array_values(array_filter($results, fn ($item) => $item['passed'] !== true));
$status = $failed === [] ? 'passed' : 'failed';
$summary = [
    'status' => $status,
    'target' => $baseUrl,
    'runId' => $runId,
    'testedAtUtc' => gmdate('c'),
    'adminEmail' => $adminEmail,
    'requesterEmail' => $requesterEmail,
    'donorEmail' => $donorEmail,
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed === [] ? 0 : 1);
