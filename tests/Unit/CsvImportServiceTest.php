<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CsvImportService;
use PHPUnit\Framework\TestCase;

final class CsvImportServiceTest extends TestCase
{
    private function load(string $name, array $mapping): array
    {
        $content = CsvImportService::readFile(dirname(__DIR__) . "/fixtures/$name.csv");
        $rows = CsvImportService::parse($content, CsvImportService::detectDelimiter($content));
        $header = CsvImportService::locateHeader($rows, $mapping);
        self::assertNotNull($header, "Kopfzeile in $name nicht gefunden");
        return CsvImportService::records($rows, $header);
    }

    public function testSparkasseWindows1252TwoDigitYears(): void
    {
        $r = $this->load('sparkasse', ['date' => 'Buchungstag', 'amount' => 'Betrag', 'payee' => 'Beguenstigter/Zahlungspflichtiger',
            'purpose' => 'Verwendungszweck', 'status' => 'Info']);
        self::assertCount(6, $r);
        self::assertSame('2026-09-22', $r[0]['date']);
        self::assertSame(-4590, $r[0]['cents']);
        self::assertSame('TSV Müllerhausen e.V.', $r[2]['payee']);
        self::assertSame(320000, $r[4]['cents']);
        self::assertTrue($r[5]['pending']);
    }

    public function testIngWithPreambleAndIdenticalRows(): void
    {
        $r = $this->load('ing', ['date' => 'Buchung', 'amount' => 'Betrag', 'payee' => 'Auftraggeber/Empfänger', 'purpose' => 'Verwendungszweck']);
        self::assertCount(3, $r);
        $hashed = CsvImportService::assignHashes(1, $r);
        self::assertNotSame($hashed[1]['hash'], $hashed[2]['hash'], 'gleiche Zeilen müssen unterschiedliche Hashes bekommen');
        self::assertSame(CsvImportService::assignHashes(1, $r)[1]['hash'], $hashed[1]['hash'], 'Hashes sind stabil');
    }

    public function testDkbPayeeByDirection(): void
    {
        $r = $this->load('dkb', ['date' => 'Buchungsdatum', 'amount' => 'Betrag (€)', 'payee_in' => 'Zahlungspflichtige*r',
            'payee_out' => 'Zahlungsempfänger*in', 'status' => 'Status']);
        self::assertSame('Stadtwerke Musterstadt', $r[0]['payee']);
        self::assertSame('Familienkasse', $r[1]['payee']);
        self::assertTrue($r[2]['pending']);
    }

    public function testDetectProfileAndGuessMapping(): void
    {
        $content = CsvImportService::readFile(dirname(__DIR__) . '/fixtures/ing.csv');
        $rows = CsvImportService::parse($content, ';');
        $profiles = [
            ['id' => 1, 'name' => 'Sparkasse', 'mapping' => '{"date":"Buchungstag","amount":"Betrag"}'],
            ['id' => 2, 'name' => 'ING', 'mapping' => '{"date":"Buchung","amount":"Betrag","payee":"Auftraggeber/Empfänger"}'],
        ];
        self::assertSame('ING', CsvImportService::detectProfile($rows, $profiles)['name']);

        $generic = CsvImportService::parse(CsvImportService::readFile(dirname(__DIR__) . '/fixtures/generic.csv'), ',');
        $m = CsvImportService::guessMapping($generic);
        self::assertSame('Date', $m['date']);
        self::assertSame('Amount', $m['amount']);
        self::assertSame('Description', $m['purpose']);
    }

    public function testParseAmountVariants(): void
    {
        self::assertSame(-1250, CsvImportService::parseAmount('12,50 S'));
        self::assertSame(1250, CsvImportService::parseAmount('12,50 H'));
        self::assertSame(-350, CsvImportService::parseAmount('-3.50', '.'));
        self::assertSame(123456, CsvImportService::parseAmount('1,234.56', '.'));
    }

    public function testSameAccountByIbanOrOldAccountNumber(): void
    {
        $iban = 'DE89 3704 0044 0532 0130 00';
        self::assertTrue(CsvImportService::sameAccount('DE89370400440532013000', $iban));
        self::assertTrue(CsvImportService::sameAccount('de89 3704 0044 0532 0130 00', $iban));
        self::assertTrue(CsvImportService::sameAccount('532013000', $iban), 'alte Kontonummer ohne führende Nullen');
        self::assertTrue(CsvImportService::sameAccount('0532013000', $iban));
        self::assertFalse(CsvImportService::sameAccount('532013001', $iban));
        self::assertFalse(CsvImportService::sameAccount('DE02120300000000202051', $iban));
        self::assertFalse(CsvImportService::sameAccount('', $iban));
        self::assertFalse(CsvImportService::sameAccount('DE89370400440532013000', null), 'Konto ohne IBAN');
        self::assertFalse(CsvImportService::sameAccount('0000000000', $iban));
    }

    public function testIsOwnHashDetectsHashInheritedFromTransferPartner(): void
    {
        $record = ['date' => '2026-09-01', 'cents' => -50000, 'amount' => '-500.00', 'payee' => 'Max Muster', 'purpose' => 'Sparen'];
        $hash = CsvImportService::assignHashes(7, [$record])[0]['hash'];
        $leg = ['account_id' => 7, 'booking_date' => '2026-09-01', 'amount' => '-500.00', 'payee' => 'Max Muster', 'purpose' => 'Sparen', 'import_hash' => $hash];
        self::assertTrue(CsvImportService::isOwnHash($leg), 'importierte Seite');
        // Gegenseite einer früher bearbeiteten Umbuchung: anderes Konto, Gegenbetrag, aber derselbe Hash
        self::assertFalse(CsvImportService::isOwnHash(['account_id' => 9, 'amount' => '500.00'] + $leg), 'geerbter Hash');
    }
}
