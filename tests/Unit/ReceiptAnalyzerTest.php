<?php

namespace Tests\Unit;

use App\Services\OcrService;
use App\Services\ReceiptAnalyzer;
use PHPUnit\Framework\TestCase;

class ReceiptAnalyzerTest extends TestCase
{
    public function test_it_extracts_pix_receipt_data_from_visible_text(): void
    {
        $text = <<<'TEXT'
Comprovante Pix
Transferencia enviada
Valor: R$ 123,45
Data: 20/05/2026
EndToEndId: E1234567820260520ABCDEF123456789
Recebedor: Pessoa Teste
TEXT;

        $result = (new ReceiptAnalyzer($this->ocrWithText($text)))->analyze('', 'image/jpeg');

        $this->assertTrue($result['isPixReceipt']);
        $this->assertSame('E1234567820260520ABCDEF123456789', $result['transactionId']);
        $this->assertSame(12345, $result['amountCents']);
    }

    public function test_it_does_not_create_a_transaction_id_when_ocr_text_has_none(): void
    {
        $text = <<<'TEXT'
Comprovante Pix
Transferencia enviada
Valor: R$ 50,00
Data: 20/05/2026
Recebedor: Pessoa Teste
TEXT;

        $result = (new ReceiptAnalyzer($this->ocrWithText($text)))->analyze('', 'image/jpeg');

        $this->assertFalse($result['isPixReceipt']);
        $this->assertNull($result['transactionId']);
        $this->assertTrue(
            collect($result['validationErrors'])->contains(fn (string $error): bool => str_contains($error, 'ID da trans')),
            'Expected a missing transaction ID validation error.'
        );
    }

    private function ocrWithText(string $text): OcrService
    {
        return new class($text) extends OcrService {
            public function __construct(private readonly string $text) {}

            public function extractText(string $imageBase64, string $mimeType = 'image/jpeg'): string
            {
                return $this->text;
            }
        };
    }
}
