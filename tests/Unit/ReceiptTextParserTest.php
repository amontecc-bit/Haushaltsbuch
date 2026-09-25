<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReceiptTextParser;
use PHPUnit\Framework\TestCase;

final class ReceiptTextParserTest extends TestCase
{
    private function sum(array $items): float
    {
        return round(array_sum(array_column($items, 'total_price')), 2);
    }

    public function testRewe(): void
    {
        $r = ReceiptTextParser::parse((string) file_get_contents(dirname(__DIR__) . '/fixtures/receipts/rewe.txt'));
        self::assertSame('REWE', $r['store']);
        self::assertSame('2026-09-21', $r['date']);
        self::assertSame(14.92, $r['total']);
        self::assertCount(6, $r['items']);
        self::assertSame(14.92, $this->sum($r['items']), 'Summe der Posten entspricht der Bonsumme');
        self::assertSame(0.812, $r['items'][1]['quantity']);
        self::assertSame('kg', $r['items'][1]['unit']);
        self::assertSame(5.0, $r['items'][2]['quantity']);
        self::assertSame(10.99, $r['items'][3]['total_price'], 'Rabatt wird mit dem Artikel verrechnet');
    }

    public function testLidlQuantityAfterItem(): void
    {
        $r = ReceiptTextParser::parse((string) file_get_contents(dirname(__DIR__) . '/fixtures/receipts/lidl.txt'));
        self::assertSame('Lidl', $r['store']);
        self::assertSame('2026-09-22', $r['date']);
        self::assertSame(8.36, $r['total']);
        self::assertSame(8.36, $this->sum($r['items']));
        self::assertSame(2.0, $r['items'][0]['quantity']);
        self::assertSame(1.29, $r['items'][0]['unit_price']);
    }

    public function testPdfTableLines(): void
    {
        $text = "Bestellung Nr. 4711 vom 12.08.2026\nArtikel Menge Einzelpreis Gesamt\nBio Bananen 2 0,99 1,98\n3 x Joghurt Natur 0,59 1,77\nSpülmittel 1 1,45 1,45\nGesamtbetrag 5,20 €";
        $r = ReceiptTextParser::parse($text);
        self::assertSame('2026-08-12', $r['date']);
        self::assertSame(5.20, $r['total']);
        self::assertCount(3, $r['items']);
        self::assertSame(2.0, $r['items'][0]['quantity']);
        self::assertSame('Joghurt Natur', $r['items'][1]['name']);
        self::assertSame(3.0, $r['items'][1]['quantity']);
    }

    /** Echte Tesseract.js-Ausgabe eines fotografierten Bons (mit typischen Lesefehlern) */
    public function testRealTesseractOutput(): void
    {
        $r = ReceiptTextParser::parse((string) file_get_contents(dirname(__DIR__) . '/fixtures/receipts/rewe_ocr.txt'));
        self::assertSame(14.92, $r['total']);
        self::assertSame(1.19, $r['items'][0]['total_price'], '"1,198" → 1,19 + Steuerklasse');
        self::assertSame(10.99, $r['items'][3]['total_price'], '"12:99" → 12,99, abzüglich Rabatt');
        self::assertSame(-0.75, $r['items'][4]['total_price'], 'Leergut ist ein Abzug');
        self::assertSame(14.92, $this->sum($r['items']));
    }

    public function testSingleDigitMisreadIsHealedByTotal(): void
    {
        $text = str_replace('0,50 A', '9,50 A', (string) file_get_contents(dirname(__DIR__) . '/fixtures/receipts/rewe_ocr.txt'));
        $r = ReceiptTextParser::parse($text);
        self::assertSame(0.50, $r['items'][5]['total_price']);
        self::assertTrue($r['items'][5]['corrected']);
        self::assertSame(14.92, $this->sum($r['items']));
    }

    public function testOcrNoiseIsTolerated(): void
    {
        $r = ReceiptTextParser::parse("EDEKA\nGURKE   0, 79 B\nTOMATEN   2,4O B\nSUMME  3,19");
        self::assertSame(3.19, $this->sum($r['items']));
    }
}
