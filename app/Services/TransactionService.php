<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Money;
use App\Repositories\TransactionRepository;

/**
 * Anlegen von Buchungen inkl. Umbuchungen (zwei gegenläufige Buchungen mit gemeinsamer transfer_group).
 */
final class TransactionService
{
    private TransactionRepository $repo;

    public function __construct(?TransactionRepository $repo = null)
    {
        $this->repo = $repo ?? new TransactionRepository();
    }

    /** @param array $data Spalten der Tabelle transactions (amount als Dezimal-String) */
    public function create(int $householdId, array $data): int
    {
        return $this->repo->create($householdId, $data);
    }

    /**
     * Umbuchung von $fromAccount nach $toAccount über einen positiven Betrag (Cent).
     * $outExtra/$inExtra: Felder nur für eine Seite (z. B. import_hash – der gehört immer nur zu einem Konto).
     * @return array{0:int, 1:int} IDs der Belastung und Gutschrift
     */
    public function createTransfer(int $householdId, int $fromAccount, int $toAccount, int $cents, string $date, array $extra = [],
                                   array $outExtra = [], array $inExtra = []): array
    {
        $group = bin2hex(random_bytes(16));
        $cents = abs($cents);
        $base = ['booking_date' => $date, 'transfer_group' => $group, 'category_id' => null];
        $out = $this->repo->create($householdId, $outExtra + $extra + $base + ['account_id' => $fromAccount, 'amount' => Money::toDecimal(-$cents)]);
        $in = $this->repo->create($householdId, $inExtra + $extra + $base + ['account_id' => $toAccount, 'amount' => Money::toDecimal($cents)]);
        return [$out, $in];
    }

    /** Zwei vorhandene, gegenläufige Buchungen auf verschiedenen Konten zu einer Umbuchung verbinden */
    public function joinAsTransfer(int $householdId, int $txId, int $counterpartId): void
    {
        $group = bin2hex(random_bytes(16));
        foreach ([$txId, $counterpartId] as $id) {
            $this->repo->update($id, $householdId, ['transfer_group' => $group, 'category_id' => null]);
        }
    }

    public function createFromRecurring(int $householdId, array $tpl, string $date): void
    {
        $extra = [
            'payee'        => $tpl['payee'],
            'purpose'      => $tpl['purpose'],
            'source'       => 'recurring',
            'recurring_id' => (int) $tpl['id'],
            'created_by'   => $tpl['created_by'] ? (int) $tpl['created_by'] : null,
        ];
        if (!empty($tpl['to_account_id'])) {
            $this->createTransfer($householdId, (int) $tpl['account_id'], (int) $tpl['to_account_id'], Money::toCents($tpl['amount']), $date, $extra);
            return;
        }
        $this->repo->create($householdId, $extra + [
            'account_id'   => (int) $tpl['account_id'],
            'booking_date' => $date,
            'amount'       => $tpl['amount'],
            'category_id'  => $tpl['category_id'] ? (int) $tpl['category_id'] : null,
        ]);
    }
}
