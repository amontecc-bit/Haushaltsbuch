<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public static function inputs(): array
    {
        return [
            ['1.234,56', 123456], ['1234,56', 123456], ['1234.56', 123456], ['1,234.56', 123456],
            ['-12', -1200], ['12,5 €', 1250], ['0,99', 99], ['1.234', 123400], ['12,50-', -1250],
            ['', null], ['abc', null], ['3.200,00', 320000],
        ];
    }

    #[DataProvider('inputs')]
    public function testParse(string $in, ?int $expected): void
    {
        self::assertSame($expected, Money::parse($in));
    }

    public function testFormatAndDecimal(): void
    {
        self::assertSame('1.234,56 €', Money::format(123456));
        self::assertSame('-0.05', Money::toDecimal(-5));
        self::assertSame(-4590, Money::toCents('-45.90'));
    }
}
