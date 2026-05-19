<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReceitaFederalService
{
    private string $provider;
    private ?string $token;

    public function __construct()
    {
        $this->provider = config('nexora.receita_api_provider', 'infosimples');
        $this->token = config('nexora.receita_api_token');
    }

    public function validateCpf(string $cpf, string $birthdate): ?array
    {
        if ($this->token === null || $this->token === '') {
            Log::warning('NEXORA: Receita Federal API token not configured, skipping validation');
            return null;
        }

        $cpf = CpfValidator::digits($cpf);
        $birthdateRaw = trim($birthdate);
        $birthdateIso = \DateTime::createFromFormat('Y-m-d', $birthdateRaw);
        if ($birthdateIso === false) {
            return null;
        }
        $birthdateBr = $birthdateIso->format('d/m/Y');

        return match ($this->provider) {
            'infosimples' => $this->validateViaInfosimples($cpf, $birthdateBr),
            'cpfcnpj' => $this->validateViaCpfCnpj($cpf),
            'directd' => $this->validateViaDirectd($cpf, $birthdateRaw),
            default => null,
        };
    }

    private function validateViaInfosimples(string $cpf, string $birthdateBr): ?array
    {
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])->post('https://api.infosimples.com/api/v2/consultas/receita-federal/cpf', [
                'cpf' => $cpf,
                'data_nascimento' => $birthdateBr,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['code']) && $data['code'] === 200 && isset($data['data'][0])) {
                    $item = $data['data'][0];
                    return [
                        'nome' => $item['nome'] ?? null,
                        'nascimento' => $item['data_nascimento'] ?? null,
                        'situacao' => $item['situacao_cadastral'] ?? null,
                        'cpf' => $item['cpf'] ?? null,
                    ];
                }
                if (isset($data['error'])) {
                    Log::warning('NEXORA: Infosimples API error', ['error' => $data['error']]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('NEXORA: Infosimples API exception', ['message' => $e->getMessage()]);
        }
        return null;
    }

    private function validateViaCpfCnpj(string $cpf): ?array
    {
        try {
            $response = Http::get("https://api.cpfcnpj.com.br/{$this->token}/7/{$cpf}");
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['status']) && $data[0]['status'] === 1) {
                    return [
                        'nome' => $data[0]['nome'] ?? null,
                        'nascimento' => $data[0]['nascimento'] ?? null,
                        'situacao' => $data[0]['situacao'] ?? null,
                        'cpf' => $data[0]['cpf'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error('NEXORA: CPF.CNPJ API exception', ['message' => $e->getMessage()]);
        }
        return null;
    }

    private function validateViaDirectd(string $cpf, string $birthdateIso): ?array
    {
        try {
            $response = Http::get("https://apiv3.directd.com.br/api/ReceitaFederalPessoaFisica", [
                'Cpf' => $cpf,
                'DataNascimento' => $birthdateIso,
                'Token' => $this->token,
            ]);
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['retorno'])) {
                    $ret = $data['retorno'];
                    return [
                        'nome' => $ret['nomePessoaFisica'] ?? null,
                        'nascimento' => $ret['dataNascimento'] ?? null,
                        'situacao' => $ret['situacaoCadastral'] ?? null,
                        'cpf' => $ret['numeroCPF'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error('NEXORA: DirectD API exception', ['message' => $e->getMessage()]);
        }
        return null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->token);
    }
}