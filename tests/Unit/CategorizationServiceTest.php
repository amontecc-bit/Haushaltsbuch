<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CategorizationService;
use App\Services\CategoryKeywords;
use PHPUnit\Framework\TestCase;

final class CategorizationServiceTest extends TestCase
{
    public function testNormalizeProduct(): void
    {
        self::assertSame('bio vollmilch', CategorizationService::normalizeProduct('BIO VOLLMILCH 3,8% 1L'));
        self::assertSame('vollmilch', CategorizationService::normalizeProduct('Vollm. 1,5%'));
        self::assertSame('bananen', CategorizationService::normalizeProduct('Bananen 0,650 kg'));
    }

    public function testItemKeywordsPreferLongestAndCompounds(): void
    {
        $m = fn (string $n) => CategorizationService::keywordMatch(CategorizationService::normalizeProduct($n), CategoryKeywords::ITEMS, true);
        self::assertSame('Milchprodukte & Eier', $m('Bio Vollmilch'));
        self::assertSame('Vorrat & Konserven', $m('Basmati Reis'), '"reis" darf nicht als "eis" erkannt werden');
        self::assertSame('Obst & Gemüse', $m('Bananen'));
        self::assertSame('Haushaltswaren', $m('Toilettenpapier 8x150'));
        self::assertSame('Pfand', $m('Leergut'));
        self::assertSame('Reinigung', $m('Spuelmittel Zitrone'), 'umgeschriebene Umlaute');
        self::assertNull($m('XYZ 123'));
    }

    public function testTransactionKeywords(): void
    {
        $m = fn (string $t) => CategorizationService::keywordMatch(mb_strtolower($t), CategoryKeywords::TRANSACTIONS, false);
        self::assertSame('Lebensmittel', $m('REWE Markt GmbH'));
        self::assertSame('Streaming & Abos', $m('Netflix International B.V.'));
        self::assertSame('Gehalt', $m('Lohn/Gehalt 09/2026'));
    }

    public function testRuleMatching(): void
    {
        $rule = ['field' => 'payee', 'operator' => 'contains', 'value' => 'aral'];
        self::assertTrue(CategorizationService::ruleMatches($rule, ['payee' => 'ARAL Station 12', 'purpose' => '']));
        self::assertFalse(CategorizationService::ruleMatches($rule, ['payee' => 'Shell', 'purpose' => 'aral']));
        $regex = ['field' => 'any', 'operator' => 'regex', 'value' => '^miete\s+\d{2}'];
        self::assertTrue(CategorizationService::ruleMatches($regex, ['payee' => 'Miete 09/2026', 'purpose' => '']));
    }

    public function testSamePayee(): void
    {
        self::assertTrue(CategorizationService::samePayee('REWE', 'REWE Markt GmbH Berlin'));
        self::assertTrue(CategorizationService::samePayee('dm-drogerie markt', 'DM Drogeriemarkt SAGT DANKE'));
        self::assertTrue(CategorizationService::samePayee('Lidl', 'LIDL DIENSTLEISTUNG GMBH'));
        self::assertTrue(CategorizationService::samePayee('Bäckerei Kornblume', 'BAECKEREI KORNBLUME FIL. 3'));
        // Allgemeine Wörter allein genügen nicht
        self::assertFalse(CategorizationService::samePayee('Markt', 'EDEKA Markt Müller'));
        self::assertFalse(CategorizationService::samePayee('ALDI', 'Stadtwerke Aldingen'));
        self::assertFalse(CategorizationService::samePayee('', 'REWE'));
        self::assertFalse(CategorizationService::samePayee('REWE', null));
    }

    public function testClosestProduct(): void
    {
        $products = [
            'bio vollmilch' => ['id' => 1, 'normalized' => 'bio vollmilch', 'default_category_id' => 4],
            'bananen' => ['id' => 2, 'normalized' => 'bananen', 'default_category_id' => 2],
        ];
        self::assertSame(1, CategorizationService::closestProduct('bio vollmilc', $products)['id']);
        self::assertNull(CategorizationService::closestProduct('schokolade', $products));
    }
}
