<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AiReceiptRecognizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiReceiptRecognizerTest extends TestCase
{
    public static function models(): array
    {
        return [
            ['claude-opus-5', true], ['claude-opus-5-5', true], ['claude-fable-5-1', true], ['claude-sonnet-5-5', true],
            ['claude-sonnet-4-6', true], ['claude-opus-4-5', true], ['claude-opus-4-5-20251101', true],
            ['claude-opus-4-8', true], ['claude-haiku-4-5', false], ['claude-haiku-4-5-20251001', false],
            ['claude-3-5-haiku-20241022', false], ['claude-sonnet-4-5', false], ['claude-sonnet-4-5-20250929', false],
            ['claude-sonnet-4-20250514', false], ['claude-sonnet-4-0', false], ['claude-opus-4-1', false],
            ['claude-opus-4-20250514', false],
        ];
    }

    #[DataProvider('models')]
    public function testSupportsEffort(string $model, bool $expected): void
    {
        $this->assertSame($expected, AiReceiptRecognizer::supportsEffort($model));
    }
}
