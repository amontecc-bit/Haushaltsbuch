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

    /** EDEKA/Marktkauf-PDF: Steuerklasse am Preis, Menge vor dem Namen ("€" steht für "St") */
    public function testEdekaPdf(): void
    {
        $text = "Marktkauf  7204 Bad Salzuflen\nEUR\n10€ x 1,99E.Hygiene-Einlagen\t19,90B\nG&G Vollwaschpulv.\t3,35B\n"
            . "BANANEN EDEKA\t1,44AW\nTCM Regenjacke\t29,99*B\nOetk.Lüb.Marzipan\t3,79A\nKleberabatt 10%\t-0,38\n"
            . "----------Posten: 15\nSUMME € 58,09\nEC-Cash\t58,09€\nDatum:                        14.09.2026\n"
            . "MwSt\tNETTO MwSt UMSATZ\nA7%\t5,72 0,40 6,12";
        $r = ReceiptTextParser::parse($text);
        self::assertSame('EDEKA', $r['store']);
        self::assertSame('2026-09-14', $r['date']);
        self::assertSame(58.09, $r['total']);
        self::assertCount(5, $r['items']);
        self::assertSame(58.09, $this->sum($r['items']));
        self::assertSame(['E.Hygiene-Einlagen', 10.0, 1.99, 19.90], [$r['items'][0]['name'], $r['items'][0]['quantity'], $r['items'][0]['unit_price'], $r['items'][0]['total_price']]);
        self::assertSame(3.41, $r['items'][4]['total_price'], 'Rabatt beim vorherigen Posten abgezogen');
    }

    /** REWE-Onlinerechnung: Menge + Steuerklasse vor den Preisen, geschützte Leerzeichen, umbrochene Namen, Rückgabe */
    public function testReweInvoicePdf(): void
    {
        $nb = "\u{00A0}";
        $text = "Rechnung\nREWE Markt GmbH\nBestelldatum 05.08.2026\nProdukt\nMenge MwSt.EinzelpreisGesamt\n"
            . "Frosch Senses Sensitivseife 500ml\t1 A 2,49{$nb}€ 2,49{$nb}€\n"
            . "ja! H-Vollmilch 3,5% 1l\t12 B 0,95{$nb}€11,40{$nb}€\n"
            . "Mövenpick Gourmet-Frühstück Schwarze Johannisbeeren \nFruchtaufstrich 250g\n1 B 3,59{$nb}€ 3,59{$nb}€\n"
            . "Pfandtasche*\t8 A 0,50{$nb}€ 4,00{$nb}€\nPfandtasche*\t-9 A 0,50{$nb}€-4,50{$nb}€\n"
            . "Gesamtsumme\t16,98{$nb}€";
        $r = ReceiptTextParser::parse($text);
        self::assertSame(16.98, $r['total']);
        self::assertCount(5, $r['items']);
        self::assertSame(16.98, $this->sum($r['items']));
        self::assertSame(['ja! H-Vollmilch 3,5% 1l', 12.0, 0.95], [$r['items'][1]['name'], $r['items'][1]['quantity'], $r['items'][1]['unit_price']]);
        self::assertSame('Mövenpick Gourmet-Frühstück Schwarze Johannisbeeren Fruchtaufstrich 250g', $r['items'][2]['name']);
        self::assertSame([9.0, -0.5, -4.5], [$r['items'][4]['quantity'], $r['items'][4]['unit_price'], $r['items'][4]['total_price']]);
    }

    /** Aldi-Fotobon: Rauschen hinter "Preis € Klasse", Stiftstriche und Krümel im Namen, Datum mit Kommas */
    public function testAldiPhotoNoise(): void
    {
        $text = "ALDI\nI FRUCHTSAFT 6 X 0,33 L          3,55,€'2\nKELLOGGS CEREALIEN   1,79€. 1\n"
            . "PURINA ONE 750 ——— —— — — 3,89 € 17\nBIO MÖHREN NL ;          1,11 € 1 |\nZOTT SAHNEJOGHURT   0,99 €i1\n"
            . "KASSELER NACKENB. XXL          8,73 € |\nZU ZAHLEN                    20,06 €\n/Da um, 25,09,26   18:03 Uhr";
        $r = ReceiptTextParser::parse($text);
        self::assertSame('2026-09-25', $r['date']);
        self::assertSame(
            ['FRUCHTSAFT 6 X 0,33 L', 'KELLOGGS CEREALIEN', 'PURINA ONE 750', 'BIO MÖHREN NL', 'ZOTT SAHNEJOGHURT', 'KASSELER NACKENB. XXL'],
            array_column($r['items'], 'name')
        );
        self::assertSame(20.06, $this->sum($r['items']));
    }

    /** OCR: unlesbare Postenzeilen werden Platzhalter, geratene Preise und Ausreißer markiert, verlesene Summe beendet */
    public function testOcrPlaceholdersAndSuspects(): void
    {
        $text = "ALDI\nHerforder Str. 93 + 95, 32105 Bad\nMILCH   0,99 € 1\nPHILADELPHIA   A \\MUIt eu\n"
            . "XXL WEISSWURST - QS   3'00 € 1\nPFANDWERT 1,50   15,00 € 2\nBROT   2,49 € 1\nKAESE   1,79 € 1\nWURST   2,19 € 1\n"
            . "AHLEN   27,43 €\nKarte 5\nEUR 27,43";
        $r = ReceiptTextParser::parse($text, true);
        self::assertSame(27.43, $r['total'], 'Betrag der verlesenen Summenzeile');
        self::assertSame(['MILCH', 'PHILADELPHIA', 'XXL WEISSWURST - QS', 'PFANDWERT 1,50', 'BROT', 'KAESE', 'WURST'], array_column($r['items'], 'name'));
        self::assertTrue($r['items'][1]['missing']);
        self::assertNull($r['items'][1]['total_price']);
        self::assertSame('PHILADELPHIA  A \\MUIt eu', $r['items'][1]['ocr']);
        self::assertSame(3.0, $r['items'][2]['total_price']);
        self::assertStringContainsString('unsicher', $r['items'][2]['suspect']);
        self::assertStringContainsString('Komma', $r['items'][3]['suspect']);
        self::assertArrayNotHasKey('ocr', $r['items'][0], 'Rohzeile nur bei markierten Posten');

        // ohne OCR-Modus (PDF-Textebene) keine Platzhalter und keine geratenen Preise
        self::assertCount(5, ReceiptTextParser::parse($text)["items"]);
    }

    /** Ein Preis ab Bonsumme ist verlesen: Posten bleibt als Platzhalter stehen */
    public function testPriceAboveTotalBecomesPlaceholder(): void
    {
        $r = ReceiptTextParser::parse("ALDI\nMILCH   0,99 € 1\nPFANDWERT   1500,00 € 2\nBROT   2,49 € 1\nZU ZAHLEN   4,98 €", true);
        self::assertSame([0.99, null, 2.49], array_column($r['items'], 'total_price'));
        self::assertTrue($r['items'][1]['missing']);
    }

    /** Leergut drückt die Bonsumme unter einen Posten: Postensumme geht auf, also nichts verlesen */
    public function testDepositReturnBelowItemPriceKeepsPrices(): void
    {
        $text = "EDEKA\nKISTE WASSER   5,99 B\nLEERGUT   -3,30 A\nSUMME EUR   2,69";
        foreach ([true, false] as $ocr) {
            $r = ReceiptTextParser::parse($text, $ocr);
            self::assertSame([5.99, -3.30], array_column($r['items'], 'total_price'), $ocr ? 'OCR' : 'PDF');
            foreach ($r['items'] as $it) {
                self::assertArrayNotHasKey('missing', $it);
                self::assertArrayNotHasKey('suspect', $it);
            }
        }
    }

    /** PDF-Textebene ist exakt: Preis ab Bonsumme wird nur markiert, nicht verworfen */
    public function testPdfPriceAboveTotalIsOnlySuspect(): void
    {
        $r = ReceiptTextParser::parse("ALDI\nMILCH   0,99 € 1\nPFANDWERT   1500,00 € 2\nBROT   2,49 € 1\nZU ZAHLEN   4,98 €");
        self::assertSame([0.99, 1500.0, 2.49], array_column($r['items'], 'total_price'));
        self::assertStringContainsString('Bonsumme', $r['items'][1]['suspect']);
    }

    /** Mehrere überlappende Fotos eines langen Bons ("\f"-getrennt): doppelte Posten nur einmal, sauberste Lesung */
    public function testOverlappingPhotosAreMerged(): void
    {
        $p1 = "ALDI\nMILCH   0,99 € 1\nBROT   2,49 € 1\nKAESE   3,29 € 1\nBUTTER   1,99 € 1\nWURST   2,19 € 1";
        $p2 = "|| KA3SE   3,29 € 1\nBUTTER   1,99 € 1\nWURST   2,19 € 1\nAPFEL   1,49 € 1\nAHLEN   14,44 €\n";
        $p3 = "APFEL   1,49 € 1\nBIRNE   2,00 € 1\nZU ZAHLEN   14,44 €\nDatum 25.09.26";
        $r = ReceiptTextParser::parse($p1 . "\n\f\n" . $p2 . "\n\f\n" . $p3);
        self::assertSame(['MILCH', 'BROT', 'KAESE', 'BUTTER', 'WURST', 'APFEL', 'BIRNE'], array_column($r['items'], 'name'));
        self::assertSame(14.44, $this->sum($r['items']));
    }
}
