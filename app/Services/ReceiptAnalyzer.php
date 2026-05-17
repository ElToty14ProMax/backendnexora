<?php

namespace App\Services;

class ReceiptAnalyzer
{
    public function __construct(private readonly OcrService $ocr) {}

    public function analyze(string $imageBase64, string $mimeType = 'image/jpeg'): array
    {
        $rawText = $this->ocr->extractText($imageBase64, $mimeType);

        return [
            'rawText' => $rawText,
            'transactionId' => $this->extractTransactionId($rawText),
            'amountCents' => $this->extractAmountCents($rawText),
            'amountFormatted' => $this->extractAmountFormatted($rawText),
            'date' => $this->extractDate($rawText),
            'time' => $this->extractTime($rawText),
            'sender' => $this->extractSender($rawText),
            'receiver' => $this->extractReceiver($rawText),
            'confidence' => $this->calculateConfidence($rawText),
        ];
    }

    private function extractTransactionId(string $text): ?string
    {
        if (preg_match('/E\d{4}\d{2}\d{2}[\w\d]{10,}/', $text, $match)) {
            return $match[0];
        }
        if (preg_match('/EndToEnd[Ii][Dd]?[:\s]*([A-Za-z0-9\-\.\/]{10,})/', $text, $match)) {
            return trim($match[1]);
        }
        if (preg_match('/[Ii][Dd]\s*(?:da\s*)?[Tt]ransação[:\s]*([A-Za-z0-9\-\.\/]{8,})/', $text, $match)) {
            return trim($match[1]);
        }
        return null;
    }

    private function extractAmountFormatted(string $text): ?string
    {
        if (preg_match('/(?:R\$\s*|Valor[:\s]*R?\$?)\s*([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2})/', $text, $match)) {
            return $match[1];
        }
        if (preg_match('/R\$\s*([0-9]+[,\.][0-9]{1,2})/', $text, $match)) {
            return $match[1];
        }
        return null;
    }

    private function extractAmountCents(string $text): ?int
    {
        if (preg_match('/(?:R\$\s*|Valor[:\s]*R?\$?)\s*([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2})/', $text, $match)) {
            $clean = str_replace('.', '', $match[1]);
            $clean = str_replace(',', '.', $clean);
            return (int) round((float) $clean * 100);
        }
        if (preg_match('/R\$\s*([0-9]+)[,\.]([0-9]{1,2})/', $text, $match)) {
            return ((int) $match[1] * 100) + (int) str_pad($match[2], 2, '0', STR_PAD_LEFT);
        }
        return null;
    }

    private function extractDate(string $text): ?string
    {
        if (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $text, $match)) {
            return $match[1];
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $text, $match)) {
            return $match[1];
        }
        return null;
    }

    private function extractTime(string $text): ?string
    {
        if (preg_match('/(\d{2}:\d{2}:\d{2})/', $text, $match)) {
            return $match[1];
        }
        if (preg_match('/(\d{2}:\d{2})/', $text, $match)) {
            return $match[1];
        }
        return null;
    }

    private function extractSender(string $text): ?string
    {
        if (preg_match('/(?:Origem|Pagador|Enviado por|Remetente|De)[:\s]*([A-ZÀ-Úa-zà-ú\s]{3,50})/', $text, $match)) {
            return trim($match[1]);
        }
        return null;
    }

    private function extractReceiver(string $text): ?string
    {
        if (preg_match('/(?:Destino|Recebedor|Beneficiário|Favorecido|Para)[:\s]*([A-ZÀ-Úa-zà-ú\s]{3,50})/', $text, $match)) {
            return trim($match[1]);
        }
        return null;
    }

    private function calculateConfidence(string $text): string
    {
        $score = 0;
        $checks = 0;

        if ($this->extractTransactionId($text)) { $score++; }
        $checks++;
        if ($this->extractAmountCents($text)) { $score++; }
        $checks++;
        if ($this->extractDate($text)) { $score++; }
        $checks++;
        if (stripos($text, 'PIX') !== false || stripos($text, 'Pix') !== false) { $score++; }
        $checks++;
        if (stripos($text, 'transferência') !== false || stripos($text, 'Transferencia') !== false) { $score++; }
        $checks++;
        if (preg_match('/R\$/', $text)) { $score++; }
        $checks++;

        $percent = $checks > 0 ? round($score / $checks * 100) : 0;

        return match (true) {
            $percent >= 80 => 'alta',
            $percent >= 50 => 'media',
            default => 'baixa',
        };
    }
}
