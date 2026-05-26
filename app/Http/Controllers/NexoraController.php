<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\CpfValidator;
use App\Services\OcrService;
use App\Services\PixCopyCode;
use App\Services\ReceiptAnalyzer;
use App\Services\ReceitaFederalService;
use App\Services\ReputationRules;
use App\Services\RoadmapRules;
use App\Services\SecurityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NexoraController extends Controller
{
    public function __construct(
        private readonly SecurityService $security,
        private readonly ReceitaFederalService $receita
    ) {
        try {
            $this->ensureBootstrapSuperAdmin();
        } catch (\Throwable) {
            // Migrations may not have run yet.
        }
    }

    public function health(): JsonResponse
    {
        return $this->ok('nexora-backend-laravel');
    }

    public function register(Request $request): JsonResponse
    {
        $email = $this->security->normalizeEmail((string) $request->input('email', ''));
        $name = trim((string) $request->input('name', ''));
        $cpf = CpfValidator::digits((string) $request->input('cpf', ''));
        $birthdate = trim((string) $request->input('birthdate', ''));
        $pixKey = trim((string) $request->input('pixKey', ''));
        $password = (string) $request->input('password', '');

        if (strlen($name) < 2 || strlen($name) > 80) {
            throw new ApiException(400, 'Informe um nome valido.');
        }
        if (! $this->security->isValidEmail($email)) {
            throw new ApiException(400, 'Informe um e-mail valido.');
        }
        if ($birthdate === '') {
            throw new ApiException(400, 'Informe a data de nascimento.');
        }
        $birthdate = $this->normalizeBirthdate($birthdate);
        $minAgeDate = (new \DateTime)->modify('-13 years')->format('Y-m-d');
        if ($birthdate > $minAgeDate) {
            throw new ApiException(400, 'Precisa ter pelo menos 13 anos para se cadastrar.');
        }
        $maxAgeDate = (new \DateTime)->modify('-120 years')->format('Y-m-d');
        if ($birthdate < $maxAgeDate) {
            throw new ApiException(400, 'Data de nascimento invalida.');
        }

        if ($this->receita->isConfigured()) {
            $rfData = $this->receita->validateCpf($cpf, $birthdate);
            if ($rfData === null) {
                throw new ApiException(400, 'Não foi possível validar o CPF com a Receita Federal. Tente novamente mais tarde.');
            }
            if (isset($rfData['situacao'])) {
                $situacao = strtolower($rfData['situacao']);
                if (! in_array($situacao, ['regular', 'ativa', 'ativa irregular', 'ativo'], true)) {
                    if (str_contains($situacao, 'falecido') || str_contains($situacao, 'óbito')) {
                        throw new ApiException(400, 'CPF pertence a pessoa falecida.');
                    }
                    if (str_contains($situacao, 'suspensa') || str_contains($situacao, 'suspenso')) {
                        throw new ApiException(400, 'CPF está com situação suspensa na Receita Federal.');
                    }
                    if (str_contains($situacao, 'cancelada') || str_contains($situacao, 'nula')) {
                        throw new ApiException(400, 'CPF está cancelado ou nulo na Receita Federal.');
                    }
                    throw new ApiException(400, "CPF com situação irregular na Receita Federal: {$rfData['situacao']}");
                }
            }
            if (isset($rfData['nascimento'])) {
                $rfBirth = $this->parseDateFromRf($rfData['nascimento']);
                if ($rfBirth !== null && $rfBirth !== $birthdate) {
                    throw new ApiException(400, 'Data de nascimento nãoconfere com a Receita Federal.');
                }
            }
        }

        if (! CpfValidator::isValid($cpf)) {
            throw new ApiException(400, 'CPF invalido.');
        }
        if (! $this->security->isValidPixKey($pixKey)) {
            throw new ApiException(400, 'Informe a chave Pix aleatoria gerada pelo banco. CPF, e-mail e telefone nao sao aceitos.');
        }
        if (strlen($password) < 8) {
            throw new ApiException(400, 'A senha precisa ter pelo menos 8 caracteres.');
        }

        $roadmap = RoadmapRules::currentStep($this->levelCounts());
        if (DB::table('users')->where('status', 'APPROVED')->count() >= $roadmap['capacity']) {
            throw new ApiException(409, 'A comunidade esta no limite atual de participantes.');
        }
        if ($this->userByEmail($email) !== null) {
            throw new ApiException(409, 'E-mail ja cadastrado.');
        }
        if (DB::table('users')->where('cpf_hash', $this->security->hashCpf($cpf))->exists()) {
            throw new ApiException(409, 'CPF ja cadastrado.');
        }

        $inviter = null;
        $inviteCode = trim((string) $request->input('inviteCode', ''));
        if ($inviteCode !== '') {
            $inviter = DB::table('users')->where('invite_code', strtoupper($inviteCode))->first();
            if ($inviter === null) {
                throw new ApiException(400, 'Código de convite inválido.');
            }
        }

        if ($email === config('nexora.super_admin_email') && $cpf !== config('nexora.super_admin_cpf')) {
            throw new ApiException(403, 'Dados do fundador não conferem.');
        }

        $role = match (true) {
            $email === config('nexora.super_admin_email') && $cpf === config('nexora.super_admin_cpf') => 'SUPER_ADMIN',
            in_array($email, config('nexora.founder_emails', []), true) => 'ADMIN',
            default => 'USER',
        };
        $status = in_array($role, ['ADMIN', 'SUPER_ADMIN'], true) ? 'APPROVED' : 'PENDING_REVIEW';
        $code = $this->security->newVerificationCode();
        $now = $this->nowMs();
        $id = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $id,
            'public_id' => $this->uniquePublicId(),
            'name' => $name,
            'email' => $email,
            'email_verified' => false,
            'verification_code_hash' => $this->security->hashVerificationCode($email, $code),
            'verification_expires_at' => $now + 30 * 60 * 1000,
            'cpf_hash' => $this->security->hashCpf($cpf),
            'cpf_cipher' => $this->security->encrypt($cpf),
            'birthdate' => $birthdate,
            'pix_cipher' => $this->security->encrypt($pixKey),
            'password_hash' => $this->security->hashPassword($password),
            'status' => $status,
            'role' => $role,
            'xp' => 0,
            'level' => 1,
            'buff_bps' => 0,
            'on_time_returned_cents' => 0,
            'early_returned_cents' => 0,
            'invited_by' => $inviter?->id,
            'invite_code' => $this->uniqueInviteCode(),
            'created_at_ms' => $now,
            'admin_fee_due_cents' => 0,
        ]);
        $this->audit(null, 'USER_REGISTERED', $id);
        $this->sendVerificationCode($email, $name, $code);

        return response()->json([
            'message' => 'Cadastro criado. Verifique o e-mail para continuar.',
            'devVerificationCode' => (! $this->mailConfigured() && ! $this->isProduction()) ? $code : null,
        ], 201);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $email = $this->security->normalizeEmail((string) $request->input('email', ''));
        $user = $this->userByEmail($email);
        if ($user !== null && ! (bool) $user->email_verified) {
            $code = $this->security->newVerificationCode();
            DB::table('users')->where('email', $email)->where('email_verified', false)->update([
                'verification_code_hash' => $this->security->hashVerificationCode($email, $code),
                'verification_expires_at' => $this->nowMs() + 30 * 60 * 1000,
            ]);
            $this->sendVerificationCode($email, $user->name, $code);
        }

        return $this->ok('Se o cadastro existir, um novo código será enviado.');
    }

    public function recoverPassword(Request $request): JsonResponse
    {
        $email = $this->security->normalizeEmail((string) $request->input('email', ''));
        $user = $this->userByEmail($email);
        if ($user !== null) {
            $code = $this->security->newVerificationCode();
            DB::table('users')->where('email', $email)->update([
                'password_reset_code_hash' => $this->security->hashRecoveryCode($email, $code),
                'password_reset_expires_at' => $this->nowMs() + 30 * 60 * 1000,
            ]);
            $this->sendRecoveryCode($email, $code);
        }

        return $this->ok('Se o e-mail existir, enviaremos instruções de recuperação.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $email = $this->security->normalizeEmail((string) $request->input('email', ''));
        $newPassword = (string) $request->input('newPassword', '');
        if (strlen($newPassword) < 8) {
            throw new ApiException(400, 'A nova senha precisa ter pelo menos 8 caracteres.');
        }
        $updated = DB::table('users')
            ->where('email', $email)
            ->where('password_reset_code_hash', $this->security->hashRecoveryCode($email, trim((string) $request->input('code', ''))))
            ->where('password_reset_expires_at', '>=', $this->nowMs())
            ->update([
                'password_hash' => $this->security->hashPassword($newPassword),
                'password_reset_code_hash' => null,
                'password_reset_expires_at' => null,
            ]);
        if ($updated !== 1) {
            throw new ApiException(400, 'Código inválido ou expirado.');
        }
        $this->audit(null, 'PASSWORD_RESET', 'email:'.crc32($email));

        return $this->ok('Senha atualizada.');
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $email = $this->security->normalizeEmail((string) $request->input('email', ''));
        $updated = DB::table('users')
            ->where('email', $email)
            ->where('verification_code_hash', $this->security->hashVerificationCode($email, trim((string) $request->input('code', ''))))
            ->where('verification_expires_at', '>=', $this->nowMs())
            ->update([
                'email_verified' => true,
                'verification_code_hash' => null,
                'verification_expires_at' => null,
            ]);
        if ($updated !== 1) {
            throw new ApiException(400, 'Código inválido ou expirado.');
        }

        return $this->ok('E-mail verificado. Aguarde a validação manual se necessário.');
    }

    public function login(Request $request): JsonResponse
    {
        $identifier = trim((string) $request->input('identifier', ''));
        $user = str_contains($identifier, '@')
            ? $this->userByEmail($this->security->normalizeEmail($identifier))
            : (strlen(CpfValidator::digits($identifier)) === 11
                ? DB::table('users')->where('cpf_hash', $this->security->hashCpf(CpfValidator::digits($identifier)))->first()
                : null);

        if ($user === null || ! $this->security->verifyPassword((string) $request->input('password', ''), $user->password_hash)) {
            throw new ApiException(401, 'CPF/e-mail ou senha incorretos.');
        }
        if (! (bool) $user->email_verified) {
            throw new ApiException(403, 'Verifique seu e-mail antes de entrar.');
        }
        if ($user->status === 'BLOCKED') {
            throw new ApiException(403, 'Conta bloqueada para novas ações.');
        }

        $token = $this->security->newToken();
        DB::table('auth_tokens')->insert([
            'token_hash' => $this->security->hashToken($token),
            'user_id' => $user->id,
            'expires_at' => $this->nowMs() + 7 * 24 * 60 * 60 * 1000,
            'created_at_ms' => $this->nowMs(),
        ]);

        return response()->json(['token' => $token, 'profile' => $this->profileResponse($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profileResponse($this->requireUser($request)));
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $stats = $this->dashboardStats();
        $roadmap = RoadmapRules::currentStep($this->levelCounts());

        return response()->json([
            'communityLiquidityCents' => $stats['liquidityCents'],
            'inCirculationCents' => $stats['inCirculationCents'],
            'completionPercent' => $stats['completionPercent'],
            'activeRequests' => $stats['activeRequests'],
            'completedOperations' => $stats['completedOperations'],
            'activeUsers' => $stats['activeUsers'],
            'userLimitCents' => ReputationRules::supportLimitCents((int) $user->level),
            'roadmapStep' => $roadmap['step'],
            'roadmapCapacity' => $roadmap['capacity'],
        ]);
    }

    public function community(Request $request): JsonResponse
    {
        $currentUser = $this->requireUser($request);
        $rows = DB::table('support_requests')
            ->whereIn('status', ['OPEN', 'FUNDED'])
            ->where('requester_id', '<>', $currentUser->id)
            ->orderByRaw('COALESCE(approved_at, created_at_ms) ASC')
            ->orderBy('created_at_ms')
            ->get();

        return response()->json($rows->map(fn ($support) => $this->supportResponse($support, $this->userById($support->requester_id), false))->values());
    }

    public function myRequests(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        return response()->json(DB::table('support_requests')
            ->where('requester_id', $user->id)
            ->orderBy('created_at_ms')
            ->get()
            ->map(fn ($support) => $this->supportResponse($support, $user, true))
            ->values());
    }

    public function myContributions(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $contributions = $this->contributionHistoryQuery()
            ->where(function ($query) use ($user) {
                $query->where('contributions.donor_id', $user->id)
                    ->orWhere('support_requests.requester_id', $user->id);
            })
            ->orderBy('contributions.created_at_ms', 'desc')
            ->orderBy('contributions.id')
            ->get()
            ->map(function ($row) use ($user) {
                if ($this->checkAndExpireContribution($row)) {
                    $row = $this->contributionWithJoinsById($row->id);
                    if ($row === null) {
                        return null;
                    }
                }

                return $this->historyResponse($row, $user->id);
            })
            ->filter()
            ->values();

        return response()->json($contributions);
    }

    public function createSupportRequest(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        if ($user->status !== 'APPROVED') {
            throw new ApiException(403, 'Conta aguardando validação manual.');
        }
        if (! ReputationRules::canRequestHelp($user)) {
            throw new ApiException(403, 'Para solicitar ajuda, e necessario estar no Nivel 2, com pelo menos 100 XP.');
        }
        $amountCents = (int) $request->input('amountCents', 0);
        $dueDays = (int) $request->input('dueDays', 0);
        if ($amountCents <= 0) {
            throw new ApiException(400, 'Informe um valor valido.');
        }
        if ($dueDays < 1 || $dueDays > 30) {
            throw new ApiException(400, 'Prazo precisa ficar entre 1 e 30 dias.');
        }
        if ($amountCents > ReputationRules::supportLimitCents((int) $user->level)) {
            throw new ApiException(400, 'Valor acima do seu limite atual.');
        }
        $nextFee = ReputationRules::adminFeeFor($amountCents);
        $feeLimit = ReputationRules::adminFeeLimitCents((int) $user->level);
        if ((int) $user->admin_fee_due_cents >= $feeLimit || (int) $user->admin_fee_due_cents + $nextFee > $feeLimit) {
            throw new ApiException(409, 'Taxa administrativa pendente atingiu o limite do seu nivel.');
        }

        $id = (string) Str::uuid();
        $support = [
            'id' => $id,
            'requester_id' => $user->id,
            'public_code' => $this->uniqueSupportCode(),
            'amount_cents' => $amountCents,
            'funded_cents' => 0,
            'due_days' => $dueDays,
            'due_at' => null,
            'description' => Str::limit(trim((string) $request->input('description', '')), 500, ''),
            'status' => 'PENDING_ADMIN',
            'created_at_ms' => $this->nowMs(),
            'approved_at' => null,
            'returned_at' => null,
            'rejected_reason' => null,
        ];
        if ($support['description'] === '') {
            $support['description'] = null;
        }
        DB::table('support_requests')->insert($support);
        $this->audit($user->id, 'SUPPORT_REQUEST_CREATED', $id);

        return response()->json($this->supportResponse((object) $support, $user, true), 201);
    }

    public function createContribution(Request $request, string $id): JsonResponse
    {
        $donor = $this->requireUser($request);
        if ($donor->status !== 'APPROVED') {
            throw new ApiException(403, 'Conta aguardando validação manual.');
        }
        $support = $this->supportById($id);
        if ($support === null) {
            throw new ApiException(404, 'Solicitação não encontrada.');
        }
        if ($support->status !== 'OPEN') {
            throw new ApiException(409, 'Solicitação não está aberta para novos apoios.');
        }
        if ($support->requester_id === $donor->id) {
            throw new ApiException(400, 'Não é possível apoiar a própria solicitação.');
        }
        $amountCents = (int) $request->input('amountCents', 0);
        $available = $this->availableContributionCents($support);
        if ($amountCents <= 0 || $amountCents > $available) {
            if ($available <= 0) {
                throw new ApiException(400, 'Esta solicitação já está totalmente reservada ou concluída por outros usuários.');
            }
            throw new ApiException(400, 'Valor de apoio inválido. O máximo disponível para esta solicitação no momento é R$ '.number_format($available / 100, 2, ',', '.'));
        }

        $contribution = DB::transaction(fn () => $this->insertContribution($support->id, $donor->id, $amountCents));
        $this->audit($donor->id, 'CONTRIBUTION_CREATED', $contribution->id);

        return response()->json($this->instructionResponse($contribution, $support), 201);
    }

    public function createContributionBatch(Request $request): JsonResponse
    {
        $donor = $this->requireUser($request);
        if ($donor->status !== 'APPROVED') {
            throw new ApiException(403, 'Conta aguardando validação manual.');
        }
        $total = (int) $request->input('amountCents', 0);
        if ($total <= 0) {
            throw new ApiException(400, 'Informe um valor valido.');
        }

        $created = DB::transaction(function () use ($donor, $total) {
            $remaining = $total;
            $created = [];
            $supports = DB::table('support_requests')
                ->where('status', 'OPEN')
                ->where('requester_id', '<>', $donor->id)
                ->orderByRaw('COALESCE(approved_at, created_at_ms) ASC')
                ->orderBy('created_at_ms')
                ->lockForUpdate()
                ->get();
            foreach ($supports as $support) {
                if ($remaining <= 0) {
                    break;
                }
                $available = $this->availableContributionCents($support);
                if ($available <= 0) {
                    continue;
                }
                $amount = min($remaining, $available);
                $contribution = $this->insertContribution($support->id, $donor->id, $amount);
                $this->audit($donor->id, 'CONTRIBUTION_CREATED', $contribution->id);
                $created[] = [$contribution, $support];
                $remaining -= $amount;
            }

            return $created;
        });

        if ($created === []) {
            throw new ApiException(409, 'Não há solicitações abertas de outras pessoas para distribuir esse valor. A sua própria solicitação não pode receber seu próprio Pix.');
        }
        $instructions = array_map(fn ($item) => $this->instructionResponse($item[0], $item[1]), $created);
        $allocated = array_sum(array_column($instructions, 'amountCents'));

        return response()->json([
            'requestedAmountCents' => $total,
            'allocatedAmountCents' => $allocated,
            'unallocatedAmountCents' => max($total - $allocated, 0),
            'instructions' => $instructions,
            'message' => 'Pix fracionado por ordem cronológica. Use cada código Pix do destinatário e envie os comprovantes depois da transferência.',
        ], 201);
    }

    public function submitReceipt(Request $request, string $id): JsonResponse
    {
        $user = $this->requireUser($request);
        $contribution = $this->contributionById($id);
        if ($contribution === null) {
            throw new ApiException(404, 'Apoio não encontrado.');
        }
        $support = $this->supportById($contribution->request_id);
        $side = strtoupper(trim((string) $request->input('side', 'SENDER')));
        if (! in_array($side, ['SENDER', 'RECEIVER'], true)) {
            throw new ApiException(400, 'Tipo de comprovante invalido.');
        }
        if ($side === 'SENDER' && $contribution->donor_id !== $user->id) {
            throw new ApiException(409, 'Apenas quem enviou o Pix pode anexar a foto de envio.');
        }
        if ($side === 'RECEIVER' && $support->requester_id !== $user->id) {
            throw new ApiException(409, 'Apenas quem recebeu o Pix pode anexar a foto de recebimento.');
        }
        if ((int) $contribution->amount_cents !== (int) $request->input('amountCents', 0)) {
            throw new ApiException(409, 'Valor do comprovante não confere.');
        }
        $hash = strtolower(trim((string) $request->input('receiptHash', '')));
        if (! $this->security->isValidSha256($hash)) {
            throw new ApiException(400, 'Hash do comprovante invalido.');
        }
        $imageBase64 = $this->validateReceiptImage((string) $request->input('receiptImageBase64', ''), (string) $request->input('receiptMimeType', 'image/jpeg'), $hash);

        $mime = strtolower(trim((string) $request->input('receiptMimeType', 'image/jpeg')));
        $receiptDate = now('America/Sao_Paulo')->format('Y-m-d');
        $submittedAt = $this->nowMs();
        $prefix = $side === 'SENDER' ? 'sender' : 'receiver';

        $transactionId = null;
        $ocrResult = null;
        $ocrTransactionId = null;
        $ocrAmountCents = null;
        $ocrConfidence = null;
        $ocrProvider = null;
        $ocrRawText = null;

        $ocrService = new OcrService;
        $analyzer = new ReceiptAnalyzer($ocrService);
        $cleanBase64 = str_contains($imageBase64, 'base64,') ? substr($imageBase64, strpos($imageBase64, 'base64,') + 7) : $imageBase64;
        $ocrResult = $analyzer->analyze($cleanBase64, $mime);

        if (! ($ocrResult['isPixReceipt'] ?? false)) {
            throw new ApiException(400, implode(' ', $ocrResult['validationErrors'] ?? [
                'A imagem enviada não parece ser um comprovante Pix válido.'
            ]));
        }

        if ((int) ($ocrResult['amountCents'] ?? 0) !== (int) $contribution->amount_cents) {
            throw new ApiException(409, 'O valor identificado no comprovante não confere com o valor deste apoio.');
        }

        $transactionId = $ocrResult['transactionId']
            ? $this->normalizeTransactionId($ocrResult['transactionId'])
            : null;

        $ocrTransactionId = $ocrResult['transactionId'];
        $ocrAmountCents = $ocrResult['amountCents'];
        $ocrConfidence = $ocrResult['confidence'];
        $ocrProvider = $ocrService->getProvider();
        $ocrRawText = $ocrResult['rawText'] ?? '';

        if (empty($transactionId)) {
            throw new ApiException(400, 'ID da transação não detectado na imagem.');
        }
        if (strlen($transactionId) < 6 || strlen($transactionId) > 80) {
            throw new ApiException(400, 'ID da transação inválido.');
        }
        if ($contribution->transaction_id !== null && $contribution->transaction_id !== $transactionId) {
            throw new ApiException(409, 'Este apoio já possui outro ID de transação.');
        }
        $duplicated = DB::table('contributions')->where('transaction_id', $transactionId)->where('id', '<>', $contribution->id)->first();
        if ($duplicated !== null) {
            throw new ApiException(409, 'ID de transação já cadastrado. Ele aparece apenas uma vez no histórico.');
        }

        $updateData = [
            'transaction_id' => $transactionId,
            "{$prefix}_receipt_hash" => $hash,
            "{$prefix}_receipt_image_base64" => $imageBase64,
            "{$prefix}_receipt_mime_type" => $mime,
            "{$prefix}_receipt_date" => $receiptDate,
            "{$prefix}_receipt_submitted_at" => $submittedAt,
            "{$prefix}_ocr_transaction_id" => $ocrTransactionId,
            "{$prefix}_ocr_amount_cents" => $ocrAmountCents,
            "{$prefix}_ocr_confidence" => $ocrConfidence,
            "{$prefix}_ocr_provider" => $ocrProvider,
            "{$prefix}_ocr_raw_text" => $ocrRawText,
        ];

        $existingHasSender = (bool) ($contribution->has_sender_receipt ?? false);
        $existingHasReceiver = (bool) ($contribution->has_receiver_receipt ?? false);

        $updateData['has_sender_receipt'] = $side === 'SENDER' ? true : $existingHasSender;
        $updateData['has_receiver_receipt'] = $side === 'RECEIVER' ? true : $existingHasReceiver;

        DB::table('contributions')->where('id', $contribution->id)->update($updateData);

        $updated = $this->contributionById($contribution->id);
        $this->updateOcrComparison($updated);
        $updated = $this->contributionById($contribution->id);

        $this->audit($user->id, "PIX_{$side}_RECEIPT_SUBMITTED", $contribution->id);

        $verificationStatus = $this->computeVerificationStatus($updated);
        DB::table('contributions')->where('id', $contribution->id)->update([
            'verification_status' => $verificationStatus,
            'admin_review_required' => in_array($verificationStatus, ['insufficient_data', 'review_needed', 'no_match'], true),
        ]);

        $final = $this->contributionById($contribution->id);

        if ((bool) ($final->admin_review_required ?? false)) {
            $this->sendVerificationReviewEmail($final);
        } elseif (in_array($final->verification_status ?? '', ['insufficient_data', 'review_needed'], true)) {
            $this->sendIncompleteVerificationEmail($final);
        }

        return response()->json([
            'contributionId' => $contribution->id,
            'transactionId' => $transactionId,
            'side' => $side,
            'receiptHash' => $hash,
            'receiptImageBase64' => $imageBase64,
            'receiptMimeType' => $mime,
            'amountCents' => (int) $contribution->amount_cents,
            'receiptDate' => $receiptDate,
            'submittedAt' => $submittedAt,
            'status' => 'PENDING_ADMIN',
            'verificationStatus' => $verificationStatus,
            'hasSenderReceipt' => $this->hasSenderReceipt($final),
            'hasReceiverReceipt' => $this->hasReceiverReceipt($final),
            'evidenceComplete' => $this->evidenceComplete($final),
            'ocrResult' => $ocrResult ? [
                'transactionId' => $ocrTransactionId,
                'amountCents' => $ocrAmountCents,
                'amountFormatted' => $ocrResult['amountFormatted'] ?? null,
                'confidence' => $ocrConfidence,
                'provider' => $ocrProvider,
                'date' => $ocrResult['date'] ?? null,
                'time' => $ocrResult['time'] ?? null,
            ] : null,
            'ocrComparison' => $this->getOcrComparison($final),
        ], 201);
    }

    private function updateOcrComparison(object $contribution): void
    {
        $senderOcrId = $contribution->sender_ocr_transaction_id ?? null;
        $receiverOcrId = $contribution->receiver_ocr_transaction_id ?? null;

        if (empty($senderOcrId) || empty($receiverOcrId)) {
            return;
        }

        $normalizedSender = $this->normalizeTransactionId((string) $senderOcrId);
        $normalizedReceiver = $this->normalizeTransactionId((string) $receiverOcrId);

        $result = $normalizedSender === $normalizedReceiver ? 'MATCH' : 'NO_MATCH';
        $notes = $result === 'NO_MATCH'
            ? "Transaction ID do remetente ({$senderOcrId}) diferente do destinatário ({$receiverOcrId})"
            : null;

        $verificationStatus = match ($result) {
            'MATCH' => 'match',
            'NO_MATCH' => 'no_match',
            default => 'review_needed',
        };

        DB::table('contributions')->where('id', $contribution->id)->update([
            'ocr_comparison_result' => $result,
            'ocr_comparison_notes' => $notes,
            'verification_status' => $verificationStatus,
            'admin_review_required' => $result === 'NO_MATCH',
        ]);
    }

    private function getOcrComparison(object $contribution): ?array
    {
        $senderTransactionId = $contribution->sender_ocr_transaction_id ?? null;
        $receiverTransactionId = $contribution->receiver_ocr_transaction_id ?? null;

        if (empty($senderTransactionId) && empty($receiverTransactionId)) {
            return null;
        }

        return [
            'senderTransactionId' => $senderTransactionId,
            'receiverTransactionId' => $receiverTransactionId,
            'result' => $contribution->ocr_comparison_result ?? null,
            'notes' => $contribution->ocr_comparison_notes ?? null,
            'senderConfidence' => $contribution->sender_ocr_confidence ?? null,
            'receiverConfidence' => $contribution->receiver_ocr_confidence ?? null,
            'senderProvider' => $contribution->sender_ocr_provider ?? null,
            'receiverProvider' => $contribution->receiver_ocr_provider ?? null,
        ];
    }

    private function computeVerificationStatus(object $contribution): string
    {
        $senderId = $this->normalizeTransactionId((string) ($contribution->sender_ocr_transaction_id ?? ''));
        $receiverId = $this->normalizeTransactionId((string) ($contribution->receiver_ocr_transaction_id ?? ''));
        $hasSenderReceipt = ! empty($contribution->sender_receipt_hash);
        $hasReceiverReceipt = ! empty($contribution->receiver_receipt_hash);
        $hasTransaction = ! empty($contribution->transaction_id);

        if ($hasSenderReceipt && $hasReceiverReceipt && $hasTransaction) {
            if ($senderId !== '' && $receiverId !== '' && $senderId === $receiverId) {
                return 'match';
            }
            if ($senderId !== '' && $receiverId !== '' && $senderId !== $receiverId) {
                return 'no_match';
            }

            return 'insufficient_data';
        }

        if (! $hasSenderReceipt || ! $hasReceiverReceipt) {
            return 'awaiting_photos';
        }

        if (! $hasTransaction) {
            return 'insufficient_data';
        }

        return 'review_needed';
    }

    public function analyzeReceipt(Request $request): JsonResponse
    {
        $imageBase64 = trim((string) $request->input('imageBase64', ''));
        $cleanBase64 = trim(str_contains($imageBase64, 'base64,') ? substr($imageBase64, strpos($imageBase64, 'base64,') + 7) : $imageBase64);
        $mimeType = trim((string) $request->input('mimeType', 'image/jpeg'));

        if ($cleanBase64 === '') {
            throw new ApiException(400, 'Imagem ausente.');
        }
        $bytes = base64_decode($cleanBase64, true);
        if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > 2500000) {
            throw new ApiException(400, 'Imagem inválida ou muito grande (máx. 2,5 MB).');
        }

        if (! in_array(strtolower($mimeType), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ApiException(400, 'Formato deve ser JPG, PNG ou WebP.');
        }

        $ocrService = new OcrService;
        $analyzer = new ReceiptAnalyzer($ocrService);
        $result = $analyzer->analyze($cleanBase64, $mimeType);

        return response()->json([
            'ok' => (bool) $result['isPixReceipt'],
            'transactionId' => $result['transactionId'],
            'amountCents' => $result['amountCents'],
            'amountFormatted' => $result['amountFormatted'],
            'date' => $result['date'],
            'time' => $result['time'],
            'sender' => $result['sender'],
            'receiver' => $result['receiver'],
            'confidence' => $result['confidence'],
            'isPixReceipt' => $result['isPixReceipt'],
            'validationErrors' => $result['validationErrors'],
            'rawText' => $result['rawText'],
            'provider' => $ocrService->getProvider(),
        ]);
    }

    public function adminOverview(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $stats = $this->dashboardStats();
        $roadmap = RoadmapRules::currentStep($this->levelCounts());

        return response()->json([
            'communityLiquidityCents' => $stats['liquidityCents'],
            'inCirculationCents' => $stats['inCirculationCents'],
            'completionPercent' => $stats['completionPercent'],
            'activeRequests' => $stats['activeRequests'],
            'completedOperations' => $stats['completedOperations'],
            'activeUsers' => $stats['activeUsers'],
            'totalUsers' => DB::table('users')->count(),
            'pendingUsers' => DB::table('users')->where('status', 'PENDING_REVIEW')->count(),
            'blockedUsers' => DB::table('users')->where('status', 'BLOCKED')->count(),
            'pendingRequests' => DB::table('support_requests')->where('status', 'PENDING_ADMIN')->count(),
            'openRequests' => DB::table('support_requests')->where('status', 'OPEN')->count(),
            'fundedRequests' => DB::table('support_requests')->where('status', 'FUNDED')->count(),
            'pendingContributions' => DB::table('contributions')->where('status', 'PENDING_ADMIN')->count(),
            'pendingReceipts' => DB::table('contributions')->where('status', 'PENDING_ADMIN')
                ->where(function ($query) {
                    $query->whereNull('sender_receipt_hash')->orWhereNull('sender_receipt_image_base64')
                        ->orWhereNull('receiver_receipt_hash')->orWhereNull('receiver_receipt_image_base64');
                })->count(),
            'adminFeeDueCents' => (int) DB::table('users')->sum('admin_fee_due_cents'),
            'roadmapStep' => $roadmap['step'],
            'roadmapCapacity' => $roadmap['capacity'],
            'generatedAt' => $this->nowMs(),
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $limit = min(max((int) $request->query('limit', 80), 1), 250);

        return response()->json(DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')
            ->select('audit_logs.id', 'users.public_id as actorPublicId', 'audit_logs.action', 'audit_logs.target', 'audit_logs.created_at_ms as createdAt')
            ->orderByDesc('audit_logs.created_at_ms')
            ->limit($limit)
            ->get());
    }

    public function adminUsers(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $query = DB::table('users');
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $digits = CpfValidator::digits($search);
            $query->where(function ($builder) use ($search, $digits) {
                $like = '%'.strtolower($search).'%';
                $builder->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(public_id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(invite_code) LIKE ?', [$like]);
                if (strlen($digits) === 11) {
                    $builder->orWhere('cpf_hash', $this->security->hashCpf($digits));
                }
            });
        }
        $status = strtoupper(trim((string) $request->query('status', '')));
        if ($status !== '' && $status !== 'ALL') {
            $query->where('status', $status);
        }
        $role = strtoupper(trim((string) $request->query('role', '')));
        if ($role !== '' && $role !== 'ALL') {
            $query->where('role', $role);
        }
        $this->applyDateFilter($query, 'created_at_ms', $request);

        return response()->json($query->orderByDesc('created_at_ms')->get()->map(fn ($user) => $this->adminUserResponse($user))->values());
    }

    public function adminApproveUser(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $user = $this->userById($id);
        if ($user === null) {
            throw new ApiException(404, 'Usuario nao encontrado.');
        }
        DB::table('users')->where('id', $id)->update(['status' => 'APPROVED']);
        $this->audit($actor?->id, 'USER_STATUS_APPROVED', $id);
        $this->sendUserFeedbackEmail($user, 'Conta aprovada - Nexora', 'Sua conta Nexora foi aprovada. Voce ja pode entrar no app e participar da comunidade.');

        return $this->ok('Usuário aprovado.');
    }

    public function adminBlockUser(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $user = $this->userById($id);
        if ($user === null) {
            throw new ApiException(404, 'Usuario nao encontrado.');
        }
        DB::table('users')->where('id', $id)->update(['status' => 'BLOCKED']);
        $this->audit($actor?->id, 'USER_STATUS_BLOCKED', $id);
        $this->sendUserFeedbackEmail($user, 'Conta bloqueada - Nexora', 'Sua conta foi bloqueada pela administracao. Se voce acredita que isso foi um engano, entre em contato com o suporte Nexora.');

        return $this->ok('Usuário bloqueado.');
    }

    public function adminUnblockUser(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $user = $this->userById($id);
        if ($user === null) {
            throw new ApiException(404, 'Usuário não encontrado.');
        }
        DB::table('users')->where('id', $id)->update(['status' => 'APPROVED']);
        $this->audit($actor?->id, 'USER_STATUS_UNBLOCKED', $id);
        $this->sendUserFeedbackEmail($user, 'Conta reativada - Nexora', 'Sua conta foi reativada pela administracao. Voce ja pode usar a Nexora novamente.');

        return $this->ok('Usuário desbloqueado.');
    }

    public function adminConfirmFee(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $user = $this->userById($id);
        if ($user === null) {
            throw new ApiException(404, 'Usuario nao encontrado.');
        }
        DB::table('users')->where('id', $id)->update(['admin_fee_due_cents' => 0, 'status' => 'APPROVED']);
        $this->audit($actor?->id, 'ADMIN_FEE_RESET', $id);
        $this->sendUserFeedbackEmail($user, 'Taxa administrativa confirmada - Nexora', 'A administracao confirmou o pagamento da sua taxa administrativa. Seu saldo pendente foi zerado.');

        return $this->ok('Taxa administrativa baixada.');
    }

    public function adminUpdateRole(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireSuperAdmin($request);
        $role = strtoupper(trim((string) $request->input('role', '')));
        if (! in_array($role, ['USER', 'ADMIN', 'SUPER_ADMIN'], true)) {
            throw new ApiException(400, 'Role invalido.');
        }
        DB::table('users')->where('id', $id)->update(['role' => $role]);
        $this->audit($actor?->id, "USER_ROLE_{$role}", $id);
        $updated = $this->userById($id);
        if ($updated !== null) {
            $this->sendUserFeedbackEmail($updated, 'Perfil administrativo atualizado - Nexora', "Seu perfil Nexora foi atualizado para {$role}.");
        }

        return $this->ok('Role atualizado.');
    }

    public function adminUpdateReputation(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $user = $this->userById($id);
        if ($user === null) {
            throw new ApiException(404, 'Usuário não encontrado.');
        }
        $xp = max((int) $request->input('xp', $user->xp), 0);
        $level = min(max((int) $request->input('level', ReputationRules::levelForXp($xp)), 1), 1000);
        $buff = min(max((int) $request->input('buffBps', $user->buff_bps), 0), 10000);
        $fee = max((int) $request->input('adminFeeDueCents', $user->admin_fee_due_cents), 0);
        DB::table('users')->where('id', $id)->update(['xp' => $xp, 'level' => $level, 'buff_bps' => $buff, 'admin_fee_due_cents' => $fee]);
        $this->audit($actor?->id, 'USER_REPUTATION_UPDATED', $id);
        $this->sendUserFeedbackEmail($user, 'Reputacao atualizada - Nexora', "Sua reputacao foi atualizada pela administracao. Nivel: {$level}. XP: {$xp}.");

        return $this->ok('Reputacao atualizada.');
    }

    public function adminResetDatabase(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);
        if (! $this->superAdminBootstrapReady()) {
            throw new ApiException(409, 'Bootstrap do Super Admin nao esta configurado.');
        }
        $adminPixKey = trim((string) $request->input('adminPixKey', ''));
        if ($adminPixKey !== '' && ! $this->security->isValidPixKey($adminPixKey)) {
            throw new ApiException(400, 'Chave Pix aleatoria invalida.');
        }
        DB::transaction(function () {
            DB::table('auth_tokens')->delete();
            DB::table('pix_receipts')->delete();
            DB::table('contributions')->delete();
            DB::table('support_requests')->delete();
            DB::table('audit_logs')->delete();
            DB::table('users')->delete();
        });
        $this->ensureBootstrapSuperAdmin($adminPixKey !== '' ? $adminPixKey : null);

        return $this->ok('Base de dados limpa. O Super Admin foi recriado.');
    }

    public function adminSupportRequests(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $query = DB::table('support_requests')
            ->join('users as requester', 'requester.id', '=', 'support_requests.requester_id')
            ->select('support_requests.*');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.strtolower($search).'%';
            $query->where(function ($builder) use ($like) {
                $builder->whereRaw('LOWER(support_requests.public_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(requester.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(requester.email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(requester.public_id) LIKE ?', [$like]);
            });
        }
        $status = strtoupper(trim((string) $request->query('status', '')));
        if ($status !== '' && $status !== 'ALL') {
            $query->where('support_requests.status', $status);
        }
        $this->applyDateFilter($query, 'support_requests.created_at_ms', $request);

        return response()->json($query->orderByDesc('support_requests.created_at_ms')->get()
            ->map(fn ($support) => $this->adminSupportResponse($support, $this->userById($support->requester_id)))
            ->values());
    }

    public function adminApproveRequest(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        DB::transaction(function () use ($id, $actor) {
            $support = $this->supportById($id);
            if ($support === null) {
                throw new ApiException(404, 'Solicitação não encontrada.');
            }
            $requester = $this->userById($support->requester_id);
            if ($support->status !== 'PENDING_ADMIN') {
                throw new ApiException(409, 'Solicitação não está aguardando aprovação.');
            }
            $fee = ReputationRules::adminFeeFor((int) $support->amount_cents);
            $feeLimit = ReputationRules::adminFeeLimitCents((int) $requester->level);
            $nextFeeDue = (int) $requester->admin_fee_due_cents + $fee;
            if ((int) $requester->admin_fee_due_cents >= $feeLimit) {
                DB::table('users')->where('id', $requester->id)->update(['status' => 'BLOCKED']);
                throw new ApiException(409, 'Taxa administrativa pendente atingiu o limite do usuário.');
            }
            if ($nextFeeDue > $feeLimit) {
                throw new ApiException(409, 'Taxa administrativa pendente excede o limite do usuário.');
            }
            $approvalTime = $this->nowMs();
            DB::table('support_requests')->where('id', $id)->update([
                'status' => 'OPEN',
                'approved_at' => $approvalTime,
                'due_at' => $approvalTime + (int) $support->due_days * 24 * 60 * 60 * 1000,
            ]);
            DB::table('users')->where('id', $requester->id)->update([
                'admin_fee_due_cents' => $nextFeeDue,
                'status' => $nextFeeDue >= $feeLimit ? 'BLOCKED' : $requester->status,
            ]);
            $this->audit($actor?->id, 'SUPPORT_REQUEST_APPROVED', $id);
            if ($nextFeeDue >= $feeLimit) {
                $this->audit($actor?->id, 'ADMIN_FEE_LIMIT_BLOCKED', $requester->id);
            }
        });
        $approved = $this->supportById($id);
        if ($approved !== null) {
            $requester = $this->userById($approved->requester_id);
            if ($requester !== null) {
                $this->sendSupportRequestFeedbackEmail($requester, $approved, 'Solicitacao aprovada - Nexora', "Sua solicitacao {$approved->public_code} foi aprovada pela administracao e ja esta aberta para receber apoios Pix.");
            }
        }

        return $this->ok('Solicitacao aprovada.');
    }

    public function adminRejectRequest(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $support = $this->supportById($id);
        if ($support === null) {
            throw new ApiException(404, 'Solicitacao nao encontrada.');
        }
        $reason = Str::limit((string) $request->input('reason', ''), 280, '');
        $updated = DB::table('support_requests')->where('id', $id)->where('status', 'PENDING_ADMIN')->update([
            'status' => 'REJECTED',
            'rejected_reason' => $reason,
        ]);
        if ($updated !== 1) {
            throw new ApiException(409, 'Solicitacao nao esta aguardando aprovacao.');
        }
        $this->audit($actor?->id, 'SUPPORT_REQUEST_REJECTED', $id);
        $requester = $this->userById($support->requester_id);
        if ($requester !== null) {
            $message = "Sua solicitacao {$support->public_code} foi recusada pela administracao.";
            if ($reason !== '') {
                $message .= "\n\nMotivo: {$reason}";
            }
            $this->sendSupportRequestFeedbackEmail($requester, $support, 'Solicitacao recusada - Nexora', $message);
        }

        return $this->ok('Solicitacao recusada.');
    }

    public function adminConfirmReturn(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        DB::transaction(function () use ($id, $actor) {
            $support = $this->supportById($id);
            if ($support === null || $support->status !== 'FUNDED') {
                throw new ApiException(409, 'Solicitacao precisa estar completa antes do retorno.');
            }
            $requester = $this->userById($support->requester_id);
            $returnedAt = $this->nowMs();
            $timingColumn = $support->due_at !== null && $returnedAt < (int) $support->due_at ? 'early_returned_cents' : 'on_time_returned_cents';
            $gainedXp = ReputationRules::xpForCompletedReturn((int) $support->amount_cents, (int) $requester->buff_bps);
            $newXp = (int) $requester->xp + $gainedXp;
            DB::table('support_requests')->where('id', $id)->update(['status' => 'RETURNED', 'returned_at' => $returnedAt]);
            DB::table('users')->where('id', $requester->id)->update([
                'xp' => $newXp,
                'level' => ReputationRules::levelForXp($newXp),
                $timingColumn => (int) $requester->{$timingColumn} + (int) $support->amount_cents,
            ]);
            $this->recalculateBuff($requester->id);
            if ($requester->invited_by !== null) {
                $this->recalculateBuff($requester->invited_by);
            }
            $this->audit($actor?->id, 'SUPPORT_RETURN_CONFIRMED', $id);
        });
        $returned = $this->supportById($id);
        if ($returned !== null) {
            $requester = $this->userById($returned->requester_id);
            if ($requester !== null) {
                $this->sendSupportRequestFeedbackEmail($requester, $returned, 'Retorno validado - Nexora', "O retorno da solicitacao {$returned->public_code} foi validado pela administracao. Seu XP e reputacao foram atualizados.");
            }
        }

        return $this->ok('Retorno validado e XP atualizado.');
    }

    public function adminContributions(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $query = $this->contributionHistoryQuery();
        Log::info('NEXORA adminContributions: query built, fetching...');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.strtolower($search).'%';
            $query->where(function ($builder) use ($like) {
                $builder->whereRaw('LOWER(contributions.id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(contributions.transaction_id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(support_requests.public_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(donor.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(donor.email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(donor.public_id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(requester.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(requester.email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(requester.public_id) LIKE ?', [$like]);
            });
        }
        $status = strtoupper(trim((string) $request->query('status', '')));
        if ($status !== '' && $status !== 'ALL') {
            $query->where('contributions.status', $status);
        }
        $receipt = strtolower(trim((string) $request->query('receipt', '')));
        if ($receipt === 'complete') {
            $query->where('contributions.transaction_id', '<>', null)
                ->where('contributions.has_sender_receipt', true)
                ->where('contributions.has_receiver_receipt', true);
        } elseif ($receipt === 'missing') {
            $query->where(function ($builder) {
                $builder->whereNull('contributions.transaction_id')
                    ->orWhere('contributions.has_sender_receipt', false)
                    ->orWhere('contributions.has_receiver_receipt', false);
            });
        }
        $verificationStatus = strtolower(trim((string) $request->query('verificationStatus', '')));
        if ($verificationStatus !== '' && $verificationStatus !== 'all') {
            $query->where('contributions.verification_status', $verificationStatus);
        }
        $adminReview = strtolower(trim((string) $request->query('adminReview', '')));
        if ($adminReview === 'true') {
            $query->where('contributions.admin_review_required', true);
        } elseif ($adminReview === 'false') {
            $query->where('contributions.admin_review_required', false);
        }
        $this->applyDateFilter($query, 'contributions.created_at_ms', $request);
        Log::info('NEXORA adminContributions: executing query...');

        $rows = $query
            ->orderByDesc('contributions.created_at_ms')
            ->get();
        Log::info('NEXORA adminContributions: got '.count($rows).' rows, mapping...');

        return response()->json($rows
            ->map(fn ($row) => $this->adminContributionResponse($row))
            ->values());
    }

    public function adminConfirmContribution(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        DB::transaction(function () use ($id, $actor) {
            $contribution = $this->contributionById($id);
            if ($contribution === null || $contribution->status !== 'PENDING_ADMIN') {
                throw new ApiException(409, 'Apoio não está aguardando validação.');
            }
            if (! $this->evidenceComplete($contribution)) {
                throw new ApiException(409, 'Validacao exige as duas fotos do Pix: envio e recebimento.');
            }
            $support = $this->supportById($contribution->request_id);
            if (! in_array($support->status, ['OPEN', 'FUNDED'], true)) {
                throw new ApiException(409, 'Solicitação não está ativa.');
            }
            $remaining = max((int) $support->amount_cents - (int) $support->funded_cents, 0);
            if ((int) $contribution->amount_cents > $remaining) {
                throw new ApiException(409, 'Valor excede o saldo restante da solicitacao.');
            }
            $confirmedAt = $this->nowMs();
            $newFunded = (int) $support->funded_cents + (int) $contribution->amount_cents;
            DB::table('contributions')->where('id', $id)->update([
                'status' => 'CONFIRMED',
                'confirmed_at' => $confirmedAt,
                'verification_status' => 'match',
                'admin_review_required' => false,
            ]);
            DB::table('support_requests')->where('id', $support->id)->update([
                'funded_cents' => $newFunded,
                'status' => $newFunded >= (int) $support->amount_cents ? 'FUNDED' : 'OPEN',
            ]);
            $this->audit($actor?->id, 'CONTRIBUTION_CONFIRMED', $id);
        });

        $confirmed = $this->contributionWithJoinsById($id);
        if ($confirmed !== null) {
            $this->sendContributionAcceptedEmail($confirmed);
        }

        return $this->ok('Apoio validado.');
    }

    public function adminDeactivateContribution(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $contribution = $this->contributionById($id);
        if ($contribution === null) {
            throw new ApiException(404, 'Apoio não encontrado.');
        }
        if (in_array($contribution->status, ['CONFIRMED', 'RETURNED'], true)) {
            throw new ApiException(409, 'Não é possível desativar um apoio já confirmado ou retornado.');
        }
        DB::table('contributions')->where('id', $id)->update([
            'status' => 'CANCELLED',
            'verification_status' => 'cancelled',
            'admin_review_required' => false,
        ]);
        $this->audit($actor?->id, 'CONTRIBUTION_DEACTIVATED', $id);

        return $this->ok('Apoio desativado.');
    }

    public function adminActivateContribution(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $contribution = $this->contributionById($id);
        if ($contribution === null) {
            throw new ApiException(404, 'Apoio não encontrado.');
        }
        if (! in_array($contribution->status, ['CANCELLED', 'EXPIRED'], true)) {
            throw new ApiException(409, 'Apenas apoios cancelados ou expirados podem ser reativados.');
        }
        $support = $this->supportById($contribution->request_id);
        if ($support === null || ! in_array($support->status, ['OPEN', 'FUNDED'], true)) {
            throw new ApiException(409, 'A solicitação vinculada não está ativa.');
        }
        DB::table('contributions')->where('id', $id)->update([
            'status' => 'PENDING_ADMIN',
            'verification_status' => 'pending_verification',
            'admin_review_required' => false,
        ]);
        $this->audit($actor?->id, 'CONTRIBUTION_ACTIVATED', $id);

        return $this->ok('Apoio reativado.');
    }

    public function runMigrations(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        Artisan::call('migrate', ['--force' => true]);

        return response()->json([
            'ok' => true,
            'message' => 'Migracoes executadas.',
            'output' => trim(Artisan::output()),
            'columns' => [
                'users.birthdate' => Schema::hasColumn('users', 'birthdate'),
                'contributions.verification_status' => Schema::hasColumn('contributions', 'verification_status'),
                'contributions.admin_review_required' => Schema::hasColumn('contributions', 'admin_review_required'),
                'contributions.rejected_reason' => Schema::hasColumn('contributions', 'rejected_reason'),
            ],
        ]);
    }

    public function checkExpiredContributions(): JsonResponse
    {
        $expirationCutoff = $this->contributionExpirationCutoffMs();
        $expiredContributions = DB::table('contributions')
            ->whereIn('status', ['PENDING_ADMIN'])
            ->where('created_at_ms', '<', $expirationCutoff)
            ->where(function ($query) {
                $query->whereNull('sender_receipt_hash')
                    ->orWhereNull('receiver_receipt_hash')
                    ->orWhereNull('transaction_id');
            })
            ->get();

        $count = 0;
        foreach ($expiredContributions as $contribution) {
            $updated = DB::table('contributions')
                ->where('id', $contribution->id)
                ->where('status', 'PENDING_ADMIN')
                ->update([
                    'status' => 'EXPIRED',
                    'verification_status' => 'insufficient_data',
                    'admin_review_required' => false,
                ]);
            if ($updated === 0) {
                continue;
            }
            $this->audit(null, 'CONTRIBUTION_EXPIRED', $contribution->id);
            $this->sendExpirationNotification($contribution);
            $count++;
        }

        return response()->json([
            'ok' => true,
            'message' => "{$count} contribuições expiradas.",
            'expiredCount' => $count,
        ]);
    }

    private function checkAndExpireContribution(object $contribution, bool $notify = true): bool
    {
        if (in_array($contribution->status, ['CONFIRMED', 'RETURNED', 'CANCELLED', 'EXPIRED'], true)) {
            return false;
        }
        if ($contribution->created_at_ms < $this->contributionExpirationCutoffMs()) {
            $hasSender = ! empty($contribution->sender_receipt_hash) || (bool) ($contribution->has_sender_receipt ?? false);
            $hasReceiver = ! empty($contribution->receiver_receipt_hash) || (bool) ($contribution->has_receiver_receipt ?? false);
            if (! $hasSender || ! $hasReceiver || empty($contribution->transaction_id)) {
                $updated = DB::table('contributions')
                    ->where('id', $contribution->id)
                    ->whereNotIn('status', ['CONFIRMED', 'RETURNED', 'CANCELLED', 'EXPIRED'])
                    ->update([
                        'status' => 'EXPIRED',
                        'verification_status' => 'insufficient_data',
                        'admin_review_required' => false,
                    ]);
                if ($updated === 0) {
                    return false;
                }
                $expired = $this->contributionById($contribution->id);
                $this->audit(null, 'CONTRIBUTION_EXPIRED', $contribution->id);
                if ($notify && $expired !== null) {
                    $this->sendExpirationNotification($expired);
                }

                return true;
            }
        }

        return false;
    }

    private function sendExpirationNotification(object $contribution): void
    {
        $support = $this->supportById($contribution->request_id);
        if ($support === null) {
            return;
        }
        $donor = $this->userById($contribution->donor_id);
        $requester = $this->userById($support->requester_id);
        if ($donor !== null) {
            $this->sendContributionExpirationEmail($donor->email, $donor->name, $contribution->id, $support->public_code);
        }
        if ($requester !== null) {
            $this->sendContributionExpirationEmail($requester->email, $requester->name, $contribution->id, $support->public_code);
        }
    }

    private function sendContributionExpirationEmail(string $to, string $name, string $contributionId, string $requestCode): void
    {
        $minutes = (int) config('nexora.contribution_expiration_minutes');
        if (! $this->mailConfigured()) {
            Log::info("NEXORA DEV EMAIL: expiration notification for {$to} - contribution {$contributionId}");

            return;
        }
        try {
            Mail::raw("Olá, {$name}.\n\nSua transação {$contributionId} na solicitação {$requestCode} expirou porque os comprovantes não foram enviados dentro de {$minutes} minutos.\n\nVocê pode tentar novamente quando uma nova solicitação estiver ativa.\n\nEquipe Nexora", function ($message) use ($to) {
                $message->to($to)->subject('Transação expirada - Nexora');
            });
        } catch (\Throwable $error) {
            Log::error('NEXORA MAIL ERROR: expiration email failed', [
                'to_hash' => hash('sha256', $this->security->normalizeEmail($to)),
                'message' => $error->getMessage(),
            ]);
        }
    }

    private function sendSupportRequestFeedbackEmail(object $user, object $support, string $subject, string $body): void
    {
        $amount = 'R$ '.number_format(((int) $support->amount_cents) / 100, 2, ',', '.');
        $this->sendUserFeedbackEmail($user, $subject, "{$body}\n\nValor: {$amount}");
    }

    private function sendUserFeedbackEmail(object $user, string $subject, string $body): void
    {
        $to = $this->security->normalizeEmail((string) ($user->email ?? ''));
        if ($to === '') {
            return;
        }
        $name = (string) (($user->name ?? '') ?: ($user->public_id ?? 'Usuario'));
        if (! $this->mailConfigured()) {
            Log::info('NEXORA DEV EMAIL: feedback notification queued', [
                'to_hash' => hash('sha256', $to),
                'subject' => $subject,
            ]);

            return;
        }

        try {
            Mail::raw("Ola, {$name}.\n\n{$body}\n\nEquipe Nexora", function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $error) {
            Log::error('NEXORA MAIL ERROR: feedback email failed', [
                'to_hash' => hash('sha256', $to),
                'subject' => $subject,
                'message' => $error->getMessage(),
            ]);
        }
    }

    private function sendContributionAcceptedEmail(object $contribution): void
    {
        $recipients = [
            [
                'email' => (string) ($contribution->donor_email ?? ''),
                'name' => (string) (($contribution->donor_name ?? '') ?: ($contribution->donor_public_id ?? 'Usuario')),
            ],
            [
                'email' => (string) ($contribution->requester_email ?? ''),
                'name' => (string) (($contribution->requester_name ?? '') ?: ($contribution->requester_public_id ?? 'Usuario')),
            ],
        ];
        $sent = [];
        foreach ($recipients as $recipient) {
            $email = $this->security->normalizeEmail($recipient['email']);
            if ($email === '' || isset($sent[$email])) {
                continue;
            }
            $this->sendAcceptedEmail(
                $email,
                $recipient['name'],
                (string) $contribution->id,
                (string) ($contribution->request_public_code ?? ''),
                (int) ($contribution->amount_cents ?? 0)
            );
            $sent[$email] = true;
        }
    }

    private function sendAcceptedEmail(string $to, string $name, string $contributionId, string $requestCode, int $amountCents): void
    {
        $amount = 'R$ '.number_format($amountCents / 100, 2, ',', '.');
        if (! $this->mailConfigured()) {
            Log::info("NEXORA DEV EMAIL: accepted notification for {$to} - contribution {$contributionId}");

            return;
        }
        try {
            Mail::raw("Ola, {$name}.\n\nA transacao {$contributionId} da solicitacao {$requestCode} foi validada pelo administrador.\n\nValor: {$amount}\n\nVoce pode acompanhar o status atualizado dentro do app.\n\nEquipe Nexora", function ($message) use ($to) {
                $message->to($to)->subject('Transacao validada - Nexora');
            });
        } catch (\Throwable $error) {
            Log::error('NEXORA MAIL ERROR: accepted email failed', [
                'to_hash' => hash('sha256', $this->security->normalizeEmail($to)),
                'message' => $error->getMessage(),
            ]);
        }
    }

    private function sendIncompleteVerificationEmail(object $contribution): void
    {
        $support = $this->supportById($contribution->request_id);
        if ($support === null) {
            return;
        }
        $donor = $this->userById($contribution->donor_id);
        $requester = $this->userById($support->requester_id);
        $missingSender = empty($contribution->sender_receipt_hash) && ! (bool) ($contribution->has_sender_receipt ?? false);
        $missingReceiver = empty($contribution->receiver_receipt_hash) && ! (bool) ($contribution->has_receiver_receipt ?? false);
        $missingTransaction = empty($contribution->transaction_id);
        $message = [];
        if ($missingSender) {
            $message[] = 'Falta o comprovante de envio (foto do remetente)';
        }
        if ($missingReceiver) {
            $message[] = 'Falta o comprovante de recebimento (foto do destinatario)';
        }
        if ($missingTransaction) {
            $message[] = 'ID da transação não detectado na imagem';
        }
        $messageText = implode("\n", $message);
        if ($donor !== null && ($missingSender || $missingTransaction)) {
            $this->sendIncompleteNotificationEmail($donor->email, $donor->name, $contribution->id, $support->public_code, $messageText);
        }
        if ($requester !== null && ($missingReceiver || $missingTransaction)) {
            $this->sendIncompleteNotificationEmail($requester->email, $requester->name, $contribution->id, $support->public_code, $messageText);
        }
    }

    private function sendIncompleteNotificationEmail(string $to, string $name, string $contributionId, string $requestCode, string $missingItems): void
    {
        if (! $this->mailConfigured()) {
            Log::info("NEXORA DEV EMAIL: incomplete verification notification for {$to} - contribution {$contributionId}");

            return;
        }
        try {
            Mail::raw("Olá, {$name}.\n\nNão foi possível validar a transação {$contributionId} na solicitação {$requestCode} porque:\n\n{$missingItems}\nPor favor, envie os comprovantes pendientes para que a transação possa ser validada.\n\nEquipe Nexora", function ($message) use ($to) {
                $message->to($to)->subject('Comprovantes pendentes - Nexora');
            });
        } catch (\Throwable $error) {
            Log::error('NEXORA MAIL ERROR: incomplete notification email failed', [
                'to_hash' => hash('sha256', $this->security->normalizeEmail($to)),
                'message' => $error->getMessage(),
            ]);
        }
    }

    public function adminRejectContribution(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $contribution = $this->contributionById($id);
        if ($contribution === null) {
            throw new ApiException(404, 'Apoio não encontrado.');
        }
        if (! in_array($contribution->status, ['PENDING_ADMIN', 'CONFIRMED'], true)) {
            throw new ApiException(409, 'Apoio não pode ser recusado no estado atual.');
        }
        $reason = Str::limit(trim((string) $request->input('reason', '')), 280, '');
        $previousStatus = $contribution->status;
        DB::table('contributions')->where('id', $id)->update([
            'status' => 'CANCELLED',
            'rejected_reason' => $reason,
            'verification_status' => 'cancelled',
            'admin_review_required' => false,
        ]);
        $this->audit($actor?->id, 'CONTRIBUTION_REJECTED', $id);
        $this->sendContributionRejectionEmail($contribution, $reason);

        return $this->ok('Apoio recusado.');
    }

    private function sendContributionRejectionEmail(object $contribution, string $reason): void
    {
        $support = $this->supportById($contribution->request_id);
        if ($support === null) {
            return;
        }
        $donor = $this->userById($contribution->donor_id);
        $requester = $this->userById($support->requester_id);
        if ($donor !== null) {
            $this->sendRejectionEmail($donor->email, $donor->name, $contribution->id, $support->public_code, $reason);
        }
        if ($requester !== null && $contribution->donor_id !== $support->requester_id) {
            $this->sendRejectionEmail($requester->email, $requester->name, $contribution->id, $support->public_code, $reason);
        }
    }

    private function sendRejectionEmail(string $to, string $name, string $contributionId, string $requestCode, string $reason): void
    {
        if (! $this->mailConfigured()) {
            Log::info("NEXORA DEV EMAIL: rejection notification for {$to} - contribution {$contributionId}");

            return;
        }
        try {
            Mail::raw("Olá, {$name}.\n\nSua transação {$contributionId} na solicitação {$requestCode} foi recusada pelo administrador.\n\nMotivo: {$reason}\n\nVocê pode tentar novamente quando uma nova solicitação estiver ativa.\n\nEquipe Nexora", function ($message) use ($to) {
                $message->to($to)->subject('Transação recusada - Nexora');
            });
        } catch (\Throwable $error) {
            Log::error('NEXORA MAIL ERROR: rejection email failed', [
                'to_hash' => hash('sha256', $this->security->normalizeEmail($to)),
                'message' => $error->getMessage(),
            ]);
        }
    }

    private function sendVerificationReviewEmail(object $contribution): void
    {
        $support = $this->supportById($contribution->request_id);
        if ($support === null) {
            return;
        }
        $requester = $this->userById($support->requester_id);
        if ($requester === null) {
            return;
        }

        $statusLabel = match ($contribution->verification_status ?? '') {
            'no_match' => 'os IDs de transação de envio e recebimento não coincidem.',
            'insufficient_data' => 'os dados do comprovante não são suficientes para determinacao automatica.',
            'review_needed' => 'os dados precisam de revisao manual antes da confirmacao.',
            default => 'a verificacao automatica nao pode ser concluida.',
        };

        if (! $this->mailConfigured()) {
            Log::info("NEXORA DEV EMAIL: verification review needed for contribution {$contribution->id} — status: {$contribution->verification_status}");

            return;
        }
        try {
            Mail::raw("Olá, {$requester->name}.\n\nNão foi possível validar automaticamente a transação {$contribution->id} da solicitação {$support->public_code} porque {$statusLabel}\n\nUm administrador irá revisar o caso e entrará em contato em breve.\n\nVocê também pode verificar o status na sua página de transações.\n\nEquipe Nexora", function ($message) use ($requester) {
                $message->to($requester->email)->subject('Verificação pendente de revisão - Nexora');
            });
        } catch (\Throwable $error) {
            Log::error('NEXORA MAIL ERROR: verification review email failed', [
                'to_hash' => hash('sha256', $this->security->normalizeEmail($requester->email)),
                'message' => $error->getMessage(),
            ]);
        }
    }

    private function requireUser(Request $request): object
    {
        $auth = $request->header('Authorization');
        if ($auth === null || ! str_starts_with($auth, 'Bearer ')) {
            throw new ApiException(401, 'Token ausente.');
        }
        $token = trim(substr($auth, 7));
        if ($token === '') {
            throw new ApiException(401, 'Token invalido.');
        }
        $row = DB::table('users')
            ->join('auth_tokens', 'auth_tokens.user_id', '=', 'users.id')
            ->where('auth_tokens.token_hash', $this->security->hashToken($token))
            ->where('auth_tokens.expires_at', '>=', $this->nowMs())
            ->select('users.*')
            ->first();
        if ($row === null) {
            throw new ApiException(401, 'Sessão inválida.');
        }

        return $row;
    }

    private function requireAdmin(Request $request): ?object
    {
        $adminHeader = $request->header('X-Admin-Token');
        if ($adminHeader !== null && hash_equals((string) config('nexora.admin_token'), $adminHeader)) {
            return null;
        }
        $user = $this->requireUser($request);
        if (! in_array($user->role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            throw new ApiException(403, 'Acesso administrativo restrito.');
        }

        return $user;
    }

    private function requireSuperAdmin(Request $request): ?object
    {
        $user = $this->requireAdmin($request);
        if ($user === null) {
            return null;
        }
        if ($user->role !== 'SUPER_ADMIN') {
            throw new ApiException(403, 'Acesso de super admin restrito.');
        }

        return $user;
    }

    private function ok(string $message): JsonResponse
    {
        return response()->json(['ok' => true, 'message' => $message]);
    }

    private function profileResponse(object $user): array
    {
        return [
            'id' => $user->id,
            'publicId' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'role' => $user->role,
            'level' => (int) $user->level,
            'xp' => (int) $user->xp,
            'xpIntoLevel' => ReputationRules::xpIntoLevel((int) $user->xp),
            'xpRequiredThisLevel' => ReputationRules::xpRequiredForLevel((int) $user->level),
            'buffBps' => (int) $user->buff_bps,
            'supportLimitCents' => ReputationRules::supportLimitCents((int) $user->level),
            'inviteCode' => $user->invite_code,
            'invitedCount' => DB::table('users')->where('invited_by', $user->id)->count(),
            'adminFeeDueCents' => (int) $user->admin_fee_due_cents,
            'adminFeeLimitCents' => ReputationRules::adminFeeLimitCents((int) $user->level),
            'pixKeyMasked' => $this->maskPix($this->security->decrypt($user->pix_cipher)),
            'adminPixKey' => (int) $user->admin_fee_due_cents > 0 ? config('nexora.admin_pix_key') : null,
        ];
    }

    private function supportResponse(object $support, object $requester, bool $includeDescription): array
    {
        return [
            'id' => $support->id,
            'publicCode' => $support->public_code,
            'requesterPublicId' => $requester->public_id,
            'requesterLevel' => (int) $requester->level,
            'amountCents' => (int) $support->amount_cents,
            'fundedCents' => (int) $support->funded_cents,
            'dueDays' => (int) $support->due_days,
            'status' => $support->status,
            'description' => $includeDescription ? $support->description : null,
            'createdAt' => (int) $support->created_at_ms,
        ];
    }

    private function adminUserResponse(object $user): array
    {
        $inviter = $user->invited_by !== null ? $this->userById($user->invited_by) : null;

        return [
            'id' => $user->id,
            'publicId' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'cpf' => $this->security->decrypt($user->cpf_cipher),
            'pixKey' => $this->security->decrypt($user->pix_cipher),
            'inviteCode' => $user->invite_code,
            'invitedByPublicId' => $inviter?->public_id,
            'invitedCount' => DB::table('users')->where('invited_by', $user->id)->count(),
            'status' => $user->status,
            'role' => $user->role,
            'level' => (int) $user->level,
            'xp' => (int) $user->xp,
            'buffBps' => (int) $user->buff_bps,
            'supportLimitCents' => ReputationRules::supportLimitCents((int) $user->level),
            'adminFeeDueCents' => (int) $user->admin_fee_due_cents,
            'adminFeeLimitCents' => ReputationRules::adminFeeLimitCents((int) $user->level),
            'adminPixKey' => config('nexora.admin_pix_key'),
            'createdAt' => (int) $user->created_at_ms,
        ];
    }

    private function adminSupportResponse(object $support, object $requester): array
    {
        return [
            'id' => $support->id,
            'publicCode' => $support->public_code,
            'requesterPublicId' => $requester->public_id,
            'requesterName' => $requester->name,
            'requesterEmail' => $requester->email,
            'requesterCpf' => $this->security->decrypt($requester->cpf_cipher),
            'requesterPixKey' => $this->security->decrypt($requester->pix_cipher),
            'amountCents' => (int) $support->amount_cents,
            'fundedCents' => (int) $support->funded_cents,
            'dueDays' => (int) $support->due_days,
            'status' => $support->status,
            'adminFeeCents' => ReputationRules::adminFeeFor((int) $support->amount_cents),
            'description' => $support->description,
            'createdAt' => (int) $support->created_at_ms,
        ];
    }

    private function instructionResponse(object $contribution, object $support): array
    {
        $reference = $this->security->paymentReference($contribution->id);
        $requester = $this->userById($support->requester_id);
        if ($requester === null) {
            throw new ApiException(404, 'Destinatário da solicitação não encontrado.');
        }
        $receiverPixKey = trim($this->security->decrypt($requester->pix_cipher));
        $receiverName = (string) $requester->name;

        try {
            $pixCode = PixCopyCode::build(
                $receiverPixKey,
                (int) $contribution->amount_cents,
                $reference,
                $receiverName,
                (string) config('nexora.pix_merchant_city'),
            );
        } catch (\InvalidArgumentException $error) {
            throw new ApiException(422, $error->getMessage());
        }

        return [
            'contributionId' => $contribution->id,
            'requestPublicCode' => $support->public_code,
            'receiverIdentifier' => $support->public_code,
            'receiverPixKey' => '',
            'pixCopyCode' => $pixCode,
            'amountCents' => (int) $contribution->amount_cents,
            'message' => 'Use o código Pix copia-e-cola para fazer a transferência. Depois, quem enviou e quem recebeu devem anexar a foto do comprovante para revisão.',
        ];
    }

    private function contributionHistoryQuery()
    {
        return DB::table('contributions')
            ->join('support_requests', 'support_requests.id', '=', 'contributions.request_id')
            ->join('users as donor', 'donor.id', '=', 'contributions.donor_id')
            ->join('users as requester', 'requester.id', '=', 'support_requests.requester_id')
            ->select(
                'contributions.*',
                'support_requests.id as request_id',
                'support_requests.public_code as request_public_code',
                'support_requests.requester_id as requester_id',
                'support_requests.amount_cents as request_amount_cents',
                'support_requests.funded_cents as request_funded_cents',
                'support_requests.status as request_status',
                'donor.public_id as donor_public_id',
                'donor.name as donor_name',
                'donor.email as donor_email',
                'requester.public_id as requester_public_id',
                'requester.name as requester_name',
                'requester.email as requester_email',
            );
    }

    private function historyResponse(object $row, string $currentUserId): array
    {
        return [
            'id' => $row->id,
            'transactionId' => $row->transaction_id,
            'requestPublicCode' => $row->request_public_code,
            'donorPublicId' => $row->donor_public_id,
            'receiverPublicId' => $row->requester_public_id,
            'direction' => $row->donor_id === $currentUserId ? 'SENT' : 'RECEIVED',
            'amountCents' => (int) $row->amount_cents,
            'status' => $row->status,
            'verificationStatus' => $row->verification_status ?? null,
            'createdAt' => (int) $row->created_at_ms,
            'confirmedAt' => $row->confirmed_at === null ? null : (int) $row->confirmed_at,
            'senderReceiptDate' => $row->sender_receipt_date,
            'receiverReceiptDate' => $row->receiver_receipt_date,
            'hasSenderReceipt' => $this->hasSenderReceipt($row),
            'hasReceiverReceipt' => $this->hasReceiverReceipt($row),
            'evidenceComplete' => $this->evidenceComplete($row),
        ];
    }

    private function adminContributionResponse(object $row): array
    {
        $this->checkAndExpireContribution($row);
        $row = $this->contributionWithJoinsById($row->id);
        if ($row === null) {
            return [];
        }

        return [
            'id' => $row->id,
            'requestId' => $row->request_id,
            'requestPublicCode' => $row->request_public_code,
            'requestAmountCents' => (int) $row->request_amount_cents,
            'requestFundedCents' => (int) $row->request_funded_cents,
            'requestStatus' => $row->request_status,
            'donorPublicId' => $row->donor_public_id,
            'donorName' => $row->donor_name,
            'donorEmail' => $row->donor_email,
            'receiverPublicId' => $row->requester_public_id,
            'receiverName' => $row->requester_name,
            'receiverEmail' => $row->requester_email,
            'amountCents' => (int) $row->amount_cents,
            'status' => $row->status,
            'verificationStatus' => $row->verification_status ?? null,
            'createdAt' => (int) $row->created_at_ms,
            'confirmedAt' => $row->confirmed_at === null ? null : (int) $row->confirmed_at,
            'transactionId' => $row->transaction_id,
            'senderReceiptHash' => $row->sender_receipt_hash,
            'senderReceiptDate' => $row->sender_receipt_date,
            'senderReceiptSubmittedAt' => $row->sender_receipt_submitted_at === null ? null : (int) $row->sender_receipt_submitted_at,
            'senderReceiptImageBase64' => $row->sender_receipt_image_base64,
            'senderReceiptMimeType' => $row->sender_receipt_mime_type,
            'receiverReceiptHash' => $row->receiver_receipt_hash,
            'receiverReceiptDate' => $row->receiver_receipt_date,
            'receiverReceiptSubmittedAt' => $row->receiver_receipt_submitted_at === null ? null : (int) $row->receiver_receipt_submitted_at,
            'receiverReceiptImageBase64' => $row->receiver_receipt_image_base64,
            'receiverReceiptMimeType' => $row->receiver_receipt_mime_type,
            'hasSenderReceipt' => ! empty($row->sender_receipt_hash) || (bool) ($row->has_sender_receipt ?? false),
            'hasReceiverReceipt' => ! empty($row->receiver_receipt_hash) || (bool) ($row->has_receiver_receipt ?? false),
            'evidenceComplete' => ! empty($row->transaction_id)
                && (! empty($row->sender_receipt_hash) || (bool) ($row->has_sender_receipt ?? false))
                && (! empty($row->receiver_receipt_hash) || (bool) ($row->has_receiver_receipt ?? false)),
            'senderOcrTransactionId' => $row->sender_ocr_transaction_id ?? null,
            'senderOcrAmountCents' => $row->sender_ocr_amount_cents ?? null,
            'senderOcrConfidence' => $row->sender_ocr_confidence ?? null,
            'senderOcrProvider' => $row->sender_ocr_provider ?? null,
            'senderOcrRawText' => $row->sender_ocr_raw_text ?? null,
            'receiverOcrTransactionId' => $row->receiver_ocr_transaction_id ?? null,
            'receiverOcrAmountCents' => $row->receiver_ocr_amount_cents ?? null,
            'receiverOcrConfidence' => $row->receiver_ocr_confidence ?? null,
            'receiverOcrProvider' => $row->receiver_ocr_provider ?? null,
            'receiverOcrRawText' => $row->receiver_ocr_raw_text ?? null,
            'ocrComparisonResult' => $row->ocr_comparison_result ?? null,
            'ocrComparisonNotes' => $row->ocr_comparison_notes ?? null,
        ];
    }

    private function applyDateFilter($query, string $column, Request $request): void
    {
        $from = $this->dateBoundaryMs($request->query('from'), false);
        if ($from !== null) {
            $query->where($column, '>=', $from);
        }
        $to = $this->dateBoundaryMs($request->query('to'), true);
        if ($to !== null) {
            $query->where($column, '<=', $to);
        }
    }

    private function dateBoundaryMs(mixed $value, bool $endOfDay): ?int
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        try {
            $date = Carbon::parse($text, 'America/Sao_Paulo');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
                $date = $endOfDay ? $date->endOfDay() : $date->startOfDay();
            }

            return $date->getTimestampMs();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dashboardStats(): array
    {
        $liquidity = (int) DB::table('support_requests')->sum('funded_cents');
        $inCirculation = (int) DB::table('support_requests')->whereIn('status', ['OPEN', 'FUNDED'])->sum('funded_cents');
        $activeRequests = DB::table('support_requests')->whereIn('status', ['OPEN', 'FUNDED'])->count();
        $completed = DB::table('support_requests')->where('status', 'RETURNED')->count();
        $delayed = DB::table('support_requests')->where('status', 'FUNDED')->whereNotNull('due_at')->where('due_at', '<', $this->nowMs())->count();

        return [
            'liquidityCents' => $liquidity,
            'inCirculationCents' => $inCirculation,
            'completionPercent' => $completed + $delayed === 0 ? 100.0 : $completed * 100.0 / ($completed + $delayed),
            'activeRequests' => $activeRequests,
            'completedOperations' => $completed,
            'activeUsers' => DB::table('users')->where('status', 'APPROVED')->count(),
        ];
    }

    private function levelCounts(): array
    {
        return DB::table('users')
            ->select('level', DB::raw('COUNT(*) as total'))
            ->where('status', 'APPROVED')
            ->groupBy('level')
            ->pluck('total', 'level')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    private function availableContributionCents(object $support): int
    {
        $reserved = (int) DB::table('contributions')
            ->where('request_id', $support->id)
            ->whereIn('status', ['PENDING_ADMIN', 'CONFIRMED'])
            ->sum('amount_cents');

        return max((int) $support->amount_cents - $reserved, 0);
    }

    private function insertContribution(string $requestId, string $donorId, int $amountCents): object
    {
        $row = [
            'id' => (string) Str::uuid(),
            'request_id' => $requestId,
            'donor_id' => $donorId,
            'amount_cents' => $amountCents,
            'status' => 'PENDING_ADMIN',
            'created_at_ms' => $this->nowMs(),
            'confirmed_at' => null,
        ];
        DB::table('contributions')->insert($row);

        return (object) array_merge($row, [
            'transaction_id' => null,
            'sender_receipt_hash' => null,
            'sender_receipt_image_base64' => null,
            'sender_receipt_mime_type' => null,
            'sender_receipt_date' => null,
            'sender_receipt_submitted_at' => null,
            'receiver_receipt_hash' => null,
            'receiver_receipt_image_base64' => null,
            'receiver_receipt_mime_type' => null,
            'receiver_receipt_date' => null,
            'receiver_receipt_submitted_at' => null,
            'sender_ocr_transaction_id' => null,
            'receiver_ocr_transaction_id' => null,
            'has_sender_receipt' => false,
            'has_receiver_receipt' => false,
            'verification_status' => null,
            'admin_review_required' => false,
        ]);
    }

    private function recalculateBuff(string $userId): void
    {
        $user = $this->userById($userId);
        if ($user === null) {
            return;
        }
        $guestsAtLevelFive = DB::table('users')->where('invited_by', $userId)->where('level', '>=', 5)->count();
        DB::table('users')->where('id', $userId)->update([
            'buff_bps' => ReputationRules::recalculateBuffBps((int) $user->on_time_returned_cents, (int) $user->early_returned_cents, $guestsAtLevelFive),
        ]);
    }

    private function validateReceiptImage(string $imageBase64, string $mimeType, string $expectedSha256): string
    {
        $cleanMime = strtolower(trim($mimeType));
        if (! in_array($cleanMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ApiException(400, 'Foto do comprovante precisa ser JPG, PNG ou WebP.');
        }
        $cleanBase64 = trim(str_contains($imageBase64, 'base64,') ? substr($imageBase64, strpos($imageBase64, 'base64,') + 7) : $imageBase64);
        if ($cleanBase64 === '') {
            throw new ApiException(400, 'Foto do comprovante ausente.');
        }
        $bytes = base64_decode($cleanBase64, true);
        if ($bytes === false) {
            throw new ApiException(400, 'Foto do comprovante invalida.');
        }
        if (strlen($bytes) === 0 || strlen($bytes) > 2500000) {
            throw new ApiException(400, 'Foto do comprovante deve ter até 2,5 MB.');
        }
        if (hash('sha256', $bytes) !== strtolower($expectedSha256)) {
            throw new ApiException(400, 'Hash da foto não confere com o comprovante enviado.');
        }

        return $cleanBase64;
    }

    private function normalizeTransactionId(string $value): string
    {
        return implode('', array_filter(str_split(strtoupper(trim($value))), fn ($char) => ctype_alnum($char) || in_array($char, ['-', '_', '.', '/'], true)));
    }

    private function hasSenderReceipt(object $contribution): bool
    {
        return ! empty($contribution->sender_receipt_hash) && ! empty($contribution->sender_receipt_image_base64);
    }

    private function hasReceiverReceipt(object $contribution): bool
    {
        return ! empty($contribution->receiver_receipt_hash) && ! empty($contribution->receiver_receipt_image_base64);
    }

    private function evidenceComplete(object $contribution): bool
    {
        return $contribution->transaction_id !== null && $this->hasSenderReceipt($contribution) && $this->hasReceiverReceipt($contribution);
    }

    private function userByEmail(string $email): ?object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function userById(string $id): ?object
    {
        return DB::table('users')->where('id', $id)->first();
    }

    private function supportById(string $id): ?object
    {
        return DB::table('support_requests')->where('id', $id)->first();
    }

    private function contributionWithJoinsById(string $id): ?object
    {
        // Load contribution with all related joined fields used in admin views
        return DB::table('contributions')
            ->where('contributions.id', $id)
            ->join('support_requests', 'support_requests.id', '=', 'contributions.request_id')
            ->join('users as donor', 'donor.id', '=', 'contributions.donor_id')
            ->join('users as requester', 'requester.id', '=', 'support_requests.requester_id')
            ->select(
                'contributions.*',
                'support_requests.public_code as request_public_code',
                'support_requests.id as request_id',
                'support_requests.amount_cents as request_amount_cents',
                'support_requests.funded_cents as request_funded_cents',
                'support_requests.status as request_status',
                'donor.public_id as donor_public_id',
                'donor.name as donor_name',
                'donor.email as donor_email',
                'requester.public_id as requester_public_id',
                'requester.name as requester_name',
                'requester.email as requester_email'
            )
            ->first();
    }

    private function uniquePublicId(): string
    {
        return $this->uniqueCode(fn () => $this->security->publicId(), 'users', 'public_id');
    }

    private function uniqueInviteCode(): string
    {
        return $this->uniqueCode(fn () => $this->security->inviteCode(), 'users', 'invite_code');
    }

    private function uniqueSupportCode(): string
    {
        return $this->uniqueCode(fn () => $this->security->supportCode(), 'support_requests', 'public_code');
    }

    private function uniqueCode(callable $factory, string $table, string $column): string
    {
        for ($i = 0; $i < 20; $i++) {
            $code = $factory();
            if (! DB::table($table)->where($column, $code)->exists()) {
                return $code;
            }
        }
        throw new ApiException(500, 'Não foi possível gerar identificador único.');
    }

    private function audit(?string $actorUserId, string $action, string $target): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target' => $target,
            'created_at_ms' => $this->nowMs(),
        ]);
    }

    private function ensureBootstrapSuperAdmin(?string $overridePixKey = null): void
    {
        $password = config('nexora.super_admin_password');
        $email = config('nexora.super_admin_email');
        $cpf = (string) config('nexora.super_admin_cpf');
        if (! $this->superAdminBootstrapReady()) {
            return;
        }
        $desiredPixKey = $this->bootstrapSuperAdminPixKey($cpf, $overridePixKey);
        $existing = $this->userByEmail($email);
        if ($existing !== null) {
            $updates = [];
            if ($existing->role !== 'SUPER_ADMIN') {
                $updates['role'] = 'SUPER_ADMIN';
            }
            if ($existing->status !== 'APPROVED') {
                $updates['status'] = 'APPROVED';
            }
            if (! (bool) $existing->email_verified) {
                $updates['email_verified'] = true;
                $updates['verification_code_hash'] = null;
                $updates['verification_expires_at'] = null;
            }
            $targetCpfHash = $this->security->hashCpf($cpf);
            if ($existing->cpf_hash !== $targetCpfHash) {
                $taken = DB::table('users')
                    ->where('cpf_hash', $targetCpfHash)
                    ->where('id', '<>', $existing->id)
                    ->exists();
                if (! $taken) {
                    $updates['cpf_hash'] = $targetCpfHash;
                    $updates['cpf_cipher'] = $this->security->encrypt($cpf);
                }
            }
            if (! isset($updates['cpf_cipher'])) {
                try {
                    if ($this->security->decrypt((string) $existing->cpf_cipher) !== $cpf) {
                        $updates['cpf_cipher'] = $this->security->encrypt($cpf);
                    }
                } catch (\Throwable) {
                    $updates['cpf_cipher'] = $this->security->encrypt($cpf);
                }
            }
            try {
                $currentPixKey = $this->security->decrypt((string) $existing->pix_cipher);
                if ($overridePixKey !== null && $currentPixKey !== $desiredPixKey) {
                    $updates['pix_cipher'] = $this->security->encrypt($desiredPixKey);
                } elseif ($overridePixKey === null && ! $this->security->isValidPixKey($currentPixKey)) {
                    $updates['pix_cipher'] = $this->security->encrypt($desiredPixKey);
                }
            } catch (\Throwable) {
                $updates['pix_cipher'] = $this->security->encrypt($desiredPixKey);
            }
            if ($updates !== []) {
                DB::table('users')->where('id', $existing->id)->update($updates);
            }

            return;
        }
        $now = $this->nowMs();
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'public_id' => $this->uniquePublicId(),
            'name' => 'Fundador Nexora',
            'email' => $email,
            'email_verified' => true,
            'verification_code_hash' => null,
            'verification_expires_at' => null,
            'cpf_hash' => $this->security->hashCpf($cpf),
            'cpf_cipher' => $this->security->encrypt($cpf),
            'pix_cipher' => $this->security->encrypt($desiredPixKey),
            'password_hash' => $this->security->hashPassword($password),
            'status' => 'APPROVED',
            'role' => 'SUPER_ADMIN',
            'xp' => 0,
            'level' => 1,
            'buff_bps' => 0,
            'on_time_returned_cents' => 0,
            'early_returned_cents' => 0,
            'invited_by' => null,
            'invite_code' => $this->uniqueInviteCode(),
            'created_at_ms' => $now,
            'admin_fee_due_cents' => 0,
        ]);
        $this->audit($id, 'SUPER_ADMIN_BOOTSTRAPPED', $id);
    }

    private function superAdminBootstrapReady(): bool
    {
        $password = (string) config('nexora.super_admin_password');
        $cpf = (string) config('nexora.super_admin_cpf');

        return strlen($password) >= 8 && CpfValidator::isValid($cpf);
    }

    private function bootstrapSuperAdminPixKey(string $cpf, ?string $overridePixKey = null): string
    {
        $candidate = trim((string) ($overridePixKey ?: config('nexora.admin_pix_key')));
        if ($candidate !== '' && $this->security->isValidPixKey($candidate)) {
            return $candidate;
        }

        return $cpf;
    }

    private function sendVerificationCode(string $to, string $name, string $code): void
    {
        if (! $this->mailConfigured()) {
            Log::info("NEXORA DEV EMAIL: verification code for {$to} is {$code}");

            return;
        }

        try {
            Mail::raw("Olá, {$name}.\n\nSeu código de verificação Nexora é: {$code}\n\nO código expira em 30 minutos.", function ($message) use ($to) {
                $message->to($to)->subject('Código de verificação Nexora');
            });
        } catch (\Throwable $error) {
            Log::error('NEXORA MAIL ERROR: verification email failed', [
                'to_hash' => hash('sha256', $this->security->normalizeEmail($to)),
                'message' => $error->getMessage(),
            ]);
        }
    }

    private function sendRecoveryCode(string $to, string $code): void
    {
        if (! $this->mailConfigured()) {
            Log::info("NEXORA DEV EMAIL: recovery code for {$to} is {$code}");

            return;
        }

        try {
            Mail::raw("Seu código de recuperação Nexora é: {$code}\n\nO código expira em 30 minutos.", function ($message) use ($to) {
                $message->to($to)->subject('Recuperação de acesso Nexora');
            });
        } catch (\Throwable $error) {
            Log::error('NEXORA MAIL ERROR: recovery email failed', [
                'to_hash' => hash('sha256', $this->security->normalizeEmail($to)),
                'message' => $error->getMessage(),
            ]);
        }
    }

    private function mailConfigured(): bool
    {
        return filled(config('mail.mailers.smtp.username')) && filled(config('mail.mailers.smtp.password'));
    }

    private function maskPix(string $value): string
    {
        $clean = trim($value);
        if (strlen($clean) <= 6) {
            return '***';
        }

        return substr($clean, 0, min(3, strlen($clean))).'***'.substr($clean, -min(3, strlen($clean)));
    }

    private function isProduction(): bool
    {
        return strtolower((string) config('nexora.env')) === 'prod';
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function contributionExpirationCutoffMs(): int
    {
        return $this->nowMs() - ((int) config('nexora.contribution_expiration_minutes') * 60 * 1000);
    }

    private function contributionById(string $id): ?object
    {
        return DB::table('contributions')->where('id', $id)->first();
    }

    private function parseDateFromRf(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }
        $clean = trim($date);
        $formats = ['d/m/Y', 'Y-m-d', 'dmY', 'Ymd'];
        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $clean);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    private function normalizeBirthdate(string $date): string
    {
        $clean = trim($date);
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $dt = \DateTime::createFromFormat($format, $clean);
            if ($dt !== false && $dt->format($format) === $clean) {
                return $dt->format('Y-m-d');
            }
        }

        throw new ApiException(400, 'Data de nascimento invalida.');
    }
}
