<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OcrService
{
    private string $provider;
    private string $googleApiKey;
    private string $openAiApiKey;
    private string $tesseractCmd;

    public function __construct()
    {
        $this->googleApiKey = env('GOOGLE_VISION_API_KEY', '');
        $this->openAiApiKey = env('OPENAI_API_KEY', '');
        $this->tesseractCmd = env('TESSERACT_CMD', 'tesseract');
        $this->provider = $this->resolveProvider();
    }

    private function resolveProvider(): string
    {
        $configured = env('OCR_PROVIDER', 'mock');

        if ($configured === 'tesseract') {
            return 'tesseract';
        }
        if ($configured === 'google' && $this->googleApiKey) {
            return 'google';
        }
        if ($configured === 'openai' && $this->openAiApiKey) {
            return 'openai';
        }
        return 'mock';
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function extractText(string $imageBase64, string $mimeType = 'image/jpeg'): string
    {
        return match ($this->provider) {
            'tesseract' => $this->tesseractOcr($imageBase64, $mimeType),
            'google' => $this->googleVision($imageBase64),
            'openai' => $this->openAiVision($imageBase64, $mimeType),
            default => $this->mockExtract(),
        };
    }

    private function tesseractOcr(string $imageBase64, string $mimeType): string
    {
        try {
            $extension = match ($mimeType) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };

            $filename = 'ocr_' . uniqid() . '.' . $extension;
            $path = storage_path('app/temp/' . $filename);

            if (!is_dir(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }

            $imageData = base64_decode($imageBase64);
            if ($imageData === false) {
                Log::warning('Tesseract: failed to decode base64 image');
                return '';
            }

            file_put_contents($path, $imageData);

            $outputFile = $path . '.txt';

            $cmd = sprintf(
                '%s -l por %s stdout 2>&1',
                escapeshellcmd($this->tesseractCmd),
                escapeshellarg($path)
            );

            $output = shell_exec($cmd);

            @unlink($path);

            if (empty($output)) {
                Log::warning('Tesseract: no text extracted');
                return '';
            }

            return trim($output);
        } catch (\Throwable $e) {
            Log::error('Tesseract OCR exception', ['error' => $e->getMessage()]);
            return '';
        }
    }

    private function googleVision(string $imageBase64): string
    {
        try {
            $response = Http::post("https://vision.googleapis.com/v1/images:annotate?key={$this->googleApiKey}", [
                'requests' => [[
                    'image' => ['content' => $imageBase64],
                    'features' => [['type' => 'TEXT_DETECTION', 'maxResults' => 1]],
                ]],
            ]);
            if ($response->failed()) {
                Log::warning('Google Vision API error', ['status' => $response->status(), 'body' => $response->body()]);
                return '';
            }
            $data = $response->json();
            return $data['responses'][0]['textAnnotations'][0]['description'] ?? '';
        } catch (\Throwable $e) {
            Log::error('Google Vision API exception', ['error' => $e->getMessage()]);
            return '';
        }
    }

    private function openAiVision(string $imageBase64, string $mimeType): string
    {
        try {
            $imageDataUrl = "data:{$mimeType};base64,{$imageBase64}";
            $response = Http::withToken($this->openAiApiKey)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Extraia TODO o texto visível neste comprovante Pix. Inclua números, datas, valores e IDs. Retorne apenas o texto extraído, sem comentários.'],
                        ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl, 'detail' => 'high']],
                    ],
                ]],
                'max_tokens' => 1000,
            ]);
            if ($response->failed()) {
                Log::warning('OpenAI Vision API error', ['status' => $response->status(), 'body' => $response->body()]);
                return '';
            }
            $data = $response->json();
            return $data['choices'][0]['message']['content'] ?? '';
        } catch (\Throwable $e) {
            Log::error('OpenAI Vision API exception', ['error' => $e->getMessage()]);
            return '';
        }
    }

    private function mockExtract(): string
    {
        $endToEndId = 'E' . date('Ymd') . sprintf('%010d', random_int(100000, 9999999999)) . random_int(10, 99);
        $amount = number_format(random_int(50, 500) + random_int(0, 99) / 100, 2, ',', '.');
        $date = date('d/m/Y');
        $time = date('H:i:s');

        return <<<TEXT
COMPROVANTE DE PIX

Transferência enviada
Valor: R$ {$amount}
Data: {$date}
Hora: {$time}

ID da transação (EndToEndId):
{$endToEndId}

Recebedor:
NEXORA PLATAFORMA LTDA
CPF/CNPJ: 00.000.000/0001-00

Sua transação foi concluída com sucesso.
TEXT;
    }
}