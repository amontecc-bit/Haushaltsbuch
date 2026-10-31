<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Schutz vor Formel-Injection im CSV-Export (Verwendungszweck/Empfänger kommen aus Kontoauszügen) */
final class CsvExportTest extends TestCase
{
    public static function cells(): array
    {
        return [
            // Formeln werden entschärft
            ['=HYPERLINK("https://example.com","Klick")', '\'=HYPERLINK("https://example.com","Klick")'],
            ['+49 170 1234567', "'+49 170 1234567"],
            ['-2+3+cmd|\' /C calc\'!A0', "'-2+3+cmd|' /C calc'!A0"],
            ['@SUM(A1:A2)', "'@SUM(A1:A2)"],
            ["\t=1+1", "'\t=1+1"],
            ["\r=1+1", "'\r=1+1"],
            // Zahlen und normale Texte bleiben unverändert
            ['-12,50', '-12,50'],
            ['1234.56', '1234.56'],
            ['+5', '+5'],
            ['REWE Markt', 'REWE Markt'],
            ['Miete 1=2', 'Miete 1=2'],
            ['', ''],
            [null, ''],
            [42, '42'],
        ];
    }

    #[DataProvider('cells')]
    public function testCsvCell(mixed $in, string $expected): void
    {
        $this->assertSame($expected, Response::csvCell($in));
    }
}
