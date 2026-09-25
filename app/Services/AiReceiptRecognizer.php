<?php

declare(strict_types=1);

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;

/**
 * Erkennung von Kassenbons (Fotos) und PDF-Rechnungen/Einkaufslisten über die Claude API.
 * Liefert dasselbe Format wie ReceiptTextParser::parse(), zusätzlich category_id je Posten.
 */
final class AiReceiptRecognizer
{
    public const DEFAULT_MODEL = 'claude-opus-5';

    public function __construct(private readonly string $apiKey, private readonly string $model = self::DEFAULT_MODEL)
    {
    }

    /**
     * @param array<int, array{path:string, mime:string}> $files Bilder (jpeg/png/webp/gif) oder ein PDF
     * @param array<int, array{id:int, full_name:string, type:string}> $categories
     * @return array{store:?string, date:?string, total:?float, items:array}
     */
    public function recognize(array $files, array $categories): array
    {
        $content = [];
        foreach ($files as $f) {
            $data = base64_encode((string) file_get_contents($f['path']));
            if ($f['mime'] === 'application/pdf') {
                $content[] = ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => $data]];
            } else {
                $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $f['mime'], 'data' => $data]];
            }
        }
        $catLines = [];
        foreach ($categories as $c) {
            if ($c['type'] === 'expense') {
                $catLines[] = $c['id'] . ': ' . $c['full_name'];
            }
        }
        $content[] = ['type' => 'text', 'text' => $this->prompt(implode("\n", $catLines))];

        $client = new Client(apiKey: $this->apiKey);
        $useFallback = str_starts_with($this->model, 'claude-opus-5') || str_starts_with($this->model, 'claude-fable');
        try {
            $message = $client->beta->messages->create(
                model: $this->model,
                maxTokens: 16000,
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
                fallbacks: $useFallback ? 'default' : null,
                betas: $useFallback ? ['server-side-fallback-2026-07-01'] : null,
                requestOptions: ['timeout' => 120.0],
            );
        } catch (APIStatusException $e) {
            $type = $e->type?->value ?? '';
            throw new \RuntimeException(match (true) {
                $type === 'authentication_error' => 'Der API-Schlüssel ist ungültig.',
                $type === 'rate_limit_error', $type === 'overloaded_error' => 'Der KI-Dienst ist gerade ausgelastet. Bitte gleich noch einmal versuchen.',
                default => 'Fehler beim KI-Dienst: ' . $e->getMessage(),
            }, 0, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new \RuntimeException('Die KI hat die Anfrage abgelehnt. Bitte lokal erkennen oder von Hand erfassen.');
        }
        $json = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = $block->text;
            }
        }
        $data = $json !== null ? json_decode($json, true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException('Die Antwort der KI konnte nicht gelesen werden.');
        }
        return self::normalize($data, array_column($categories, 'id'));
    }

    private function prompt(string $categories): string
    {
        return <<<TXT
            Lies diesen Kassenbon bzw. diese Rechnung/Einkaufsliste aus einem deutschen Haushalt aus.

            - store: Name des Geschäfts (z. B. "REWE", "dm", "Lidl"), sonst null.
            - date: Einkaufsdatum als YYYY-MM-DD, sonst null.
            - total: zu zahlender Gesamtbetrag in Euro, sonst null.
            - items: jeder gekaufte Artikel in der Reihenfolge des Belegs. Bezeichnung lesbar ausschreiben
              (Abkürzungen des Bons auflösen, z. B. "BIO VOLLM. 3,8%" → "Bio Vollmilch 3,8%").
              quantity = Menge oder Gewicht (kg), unit = "kg", "l" oder "" für Stück,
              unit_price = Preis pro Einheit, total_price = Zeilensumme in Euro.
              Rabatte/Preisvorteile direkt mit dem zugehörigen Artikel verrechnen. Pfand und Leergut als eigene Posten
              (Leergut mit negativem Betrag). Keine Posten für Summen, Steuern, Zahlungsart oder Rückgeld.
            - category_id: die passendste Kategorie aus dieser Liste (nur die Zahl), oder null:
            {$categories}
            TXT;
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'store' => ['type' => ['string', 'null']],
                'date'  => ['type' => ['string', 'null']],
                'total' => ['type' => ['number', 'null']],
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name'        => ['type' => 'string'],
                            'quantity'    => ['type' => 'number'],
                            'unit'        => ['type' => 'string'],
                            'unit_price'  => ['type' => 'number'],
                            'total_price' => ['type' => 'number'],
                            'category_id' => ['type' => ['integer', 'null']],
                        ],
                        'required' => ['name', 'quantity', 'unit', 'unit_price', 'total_price', 'category_id'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['store', 'date', 'total', 'items'],
            'additionalProperties' => false,
        ];
    }

    /** Werte absichern: gültiges Datum, bekannte Kategorien, Zahlen gerundet */
    private static function normalize(array $d, array $validCategoryIds): array
    {
        $valid = array_flip(array_map('intval', $validCategoryIds));
        $items = [];
        foreach ($d['items'] ?? [] as $i) {
            $name = trim((string) ($i['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $cat = isset($i['category_id']) ? (int) $i['category_id'] : null;
            $unit = in_array($i['unit'] ?? '', ['kg', 'g', 'l'], true) ? $i['unit'] : null;
            $items[] = [
                'name'        => mb_substr($name, 0, 120),
                'quantity'    => round((float) ($i['quantity'] ?? 1) ?: 1, 3),
                'unit'        => $unit,
                'unit_price'  => round((float) ($i['unit_price'] ?? 0), 2),
                'total_price' => round((float) ($i['total_price'] ?? 0), 2),
                'category_id' => $cat && isset($valid[$cat]) ? $cat : null,
            ];
        }
        return [
            'store' => isset($d['store']) ? mb_substr(trim((string) $d['store']), 0, 150) ?: null : null,
            'date'  => valid_date(isset($d['date']) ? (string) $d['date'] : null),
            'total' => isset($d['total']) ? round((float) $d['total'], 2) : null,
            'items' => $items,
        ];
    }
}
