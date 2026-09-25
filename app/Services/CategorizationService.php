<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\RuleRepository;
use App\Repositories\TransactionRepository;

/**
 * Automatische Kategorie-Vorschläge für Buchungen und Einkaufsposten.
 *
 * Reihenfolge Buchung:  eigene Regeln → Historie beim selben Empfänger → eingebaute Schlüsselwörter
 * Reihenfolge Posten:   bekanntes Produkt → eigene Regeln → ähnliches bekanntes Produkt → eingebaute Schlüsselwörter
 */
final class CategorizationService
{
    private ?array $rules = null;
    private ?array $nameIndex = null;
    private ?array $products = null;
    private array $payeeCache = [];

    public function __construct(private readonly int $householdId)
    {
    }

    /** @return array{category_id:?int, source:?string} */
    public function suggestForTransaction(?string $payee, ?string $purpose): array
    {
        $payee = trim((string) $payee);
        $purpose = trim((string) $purpose);

        foreach ($this->rules('transaction') as $rule) {
            if (self::ruleMatches($rule, ['payee' => $payee, 'purpose' => $purpose])) {
                return ['category_id' => (int) $rule['category_id'], 'source' => 'rule'];
            }
        }

        if ($payee !== '') {
            if (!array_key_exists($payee, $this->payeeCache)) {
                $this->payeeCache[$payee] = (new TransactionRepository())->mostUsedCategoryForPayee($this->householdId, $payee);
            }
            if ($this->payeeCache[$payee]) {
                return ['category_id' => $this->payeeCache[$payee], 'source' => 'history'];
            }
        }

        $name = self::keywordMatch(mb_strtolower($payee . ' ' . $purpose . ' '), CategoryKeywords::TRANSACTIONS, false);
        if ($name && ($id = $this->categoryIdByName($name))) {
            return ['category_id' => $id, 'source' => 'keyword'];
        }
        return ['category_id' => null, 'source' => null];
    }

    /** @return array{category_id:?int, source:?string, product_id:?int} */
    public function suggestForItem(string $name): array
    {
        $norm = self::normalizeProduct($name);
        if ($norm === '') {
            return ['category_id' => null, 'source' => null, 'product_id' => null];
        }
        $products = $this->products();

        if (isset($products[$norm]) && $products[$norm]['default_category_id']) {
            return ['category_id' => (int) $products[$norm]['default_category_id'], 'source' => 'product', 'product_id' => (int) $products[$norm]['id']];
        }

        foreach ($this->rules('item') as $rule) {
            if (self::ruleMatches($rule, ['name' => $name . ' ' . $norm])) {
                return ['category_id' => (int) $rule['category_id'], 'source' => 'rule', 'product_id' => null];
            }
        }

        $best = self::closestProduct($norm, $products);
        if ($best) {
            return ['category_id' => (int) $best['default_category_id'], 'source' => 'similar', 'product_id' => null];
        }

        $cat = self::keywordMatch($norm, CategoryKeywords::ITEMS, true);
        if ($cat && ($id = $this->categoryIdByName($cat))) {
            return ['category_id' => $id, 'source' => 'keyword', 'product_id' => null];
        }
        return ['category_id' => null, 'source' => null, 'product_id' => null];
    }

    /**
     * Normalisiert Kassenbon-Bezeichnungen: "BIO VOLLMILCH 3,8% 1L" → "bio vollmilch"
     */
    public static function normalizeProduct(string $name): string
    {
        $s = mb_strtolower(trim($name));
        // Mengen/Gewichte/Prozente entfernen
        $s = preg_replace('/\b\d+([.,]\d+)?\s*(x\s*\d+([.,]\d+)?\s*)?(kg|g|gr|mg|l|ltr|ml|cl|st|stk|stück|pack|pck|er|%)\b\.?/u', ' ', $s);
        $s = preg_replace('/\b\d+([.,]\d+)?\s*%/u', ' ', $s);
        $s = preg_replace('/\b\d+\s*x\b/u', ' ', $s);
        // Abkürzungen auf Kassenbons
        $abbr = [
            '/\bvollm\b\.?/u' => 'vollmilch', '/\bfettarme?\s*m\b\.?/u' => 'fettarme milch', '/\bh-milch\b/u' => 'h milch',
            '/\bjoghurt\b|\bjogh\b\.?/u' => 'joghurt', '/\bkart\b\.?/u' => 'kartoffeln', '/\bbroetchen\b/u' => 'brötchen',
            '/\bkaese\b/u' => 'käse', '/\bhaehnchen\b/u' => 'hähnchen', '/\bfrischk\b\.?/u' => 'frischkäse',
            '/\bnat\b\.?/u' => 'natur', '/\bgem\b\.?/u' => 'gemischt', '/\bmineralw\b\.?/u' => 'mineralwasser',
        ];
        $s = preg_replace(array_keys($abbr), array_values($abbr), $s);
        $s = preg_replace('/[^\p{L}\p{N}& ]+/u', ' ', $s);
        $s = preg_replace('/\b\d+\b/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Findet das längste passende Schlüsselwort.
     * $compound=true: Schlüsselwort darf Wortanfang oder -ende eines Wortes sein ("vollmilch" ← "milch").
     */
    public static function keywordMatch(string $text, array $map, bool $compound): ?string
    {
        // Kassenbons schreiben Umlaute oft um ("Spuelmittel", "Kaese") – beide Schreibweisen prüfen, längster Treffer gewinnt
        if (preg_match('/ae|oe|ue|ss/i', $text)) {
            $text .= ' ' . str_replace(['ae', 'oe', 'ue', 'ss'], ['ä', 'ö', 'ü', 'ß'], mb_strtolower($text));
        }
        return self::keywordMatchRaw($text, $map, $compound);
    }

    private static function keywordMatchRaw(string $text, array $map, bool $compound): ?string
    {
        $text = ' ' . mb_strtolower($text) . ' ';
        $tokens = preg_split('/\s+/u', trim($text)) ?: [];
        $bestLen = 0;
        $best = null;
        foreach ($map as $category => $keywords) {
            foreach ($keywords as $kw) {
                $len = mb_strlen($kw);
                if ($len <= $bestLen) {
                    continue;
                }
                $hit = false;
                if (str_contains($kw, ' ') || !$compound) {
                    // Phrasen bzw. Buchungstexte: Teilstring, bei kurzen Wörtern nur am Wortanfang
                    $hit = $len >= 5 ? str_contains($text, $kw) : (bool) preg_match('/(^|[^\p{L}])' . preg_quote($kw, '/') . '/u', $text);
                } else {
                    foreach ($tokens as $t) {
                        if ($t === $kw || ($len >= 3 && str_ends_with($t, $kw)) || ($len >= 4 && str_starts_with($t, $kw))) {
                            $hit = true;
                            break;
                        }
                    }
                }
                if ($hit) {
                    $bestLen = $len;
                    $best = $category;
                }
            }
        }
        return $best;
    }

    public static function ruleMatches(array $rule, array $fields): bool
    {
        $value = mb_strtolower((string) $rule['value']);
        $haystacks = match ($rule['field']) {
            'payee'   => [$fields['payee'] ?? ''],
            'purpose' => [$fields['purpose'] ?? ''],
            'name'    => [$fields['name'] ?? ''],
            default   => [implode(' ', $fields)],
        };
        foreach ($haystacks as $h) {
            $h = mb_strtolower((string) $h);
            $ok = match ($rule['operator']) {
                'equals' => trim($h) === $value,
                'starts' => str_starts_with(trim($h), $value),
                'regex'  => @preg_match('/' . str_replace('/', '\/', (string) $rule['value']) . '/iu', $h) === 1,
                default  => $value !== '' && str_contains($h, $value),
            };
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** Ähnlichstes bekanntes Produkt mit Kategorie (≥ 80 % Übereinstimmung) */
    public static function closestProduct(string $norm, array $products): ?array
    {
        $best = null;
        $bestScore = 0.0;
        $len = mb_strlen($norm);
        if ($len < 4) {
            return null;
        }
        foreach ($products as $p) {
            if (!$p['default_category_id']) {
                continue;
            }
            $pl = mb_strlen($p['normalized']);
            if (abs($pl - $len) > max(4, $len * 0.4)) {
                continue;
            }
            similar_text($norm, $p['normalized'], $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best = $p;
            }
        }
        return $bestScore >= 80 ? $best : null;
    }

    public function categoryIdByName(string $name): ?int
    {
        $this->nameIndex ??= (new CategoryRepository())->nameIndex($this->householdId);
        return $this->nameIndex[mb_strtolower($name)] ?? null;
    }

    private function rules(string $target): array
    {
        $this->rules ??= (new RuleRepository())->all($this->householdId);
        return array_filter($this->rules, fn ($r) => $r['target'] === $target);
    }

    private function products(): array
    {
        return $this->products ??= (new ProductRepository())->indexByNormalized($this->householdId);
    }
}
