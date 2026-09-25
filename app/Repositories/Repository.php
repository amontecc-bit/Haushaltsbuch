<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

abstract class Repository
{
    protected PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    protected function one(string $sql, array $params = []): ?array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    protected function many(string $sql, array $params = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    protected function value(string $sql, array $params = []): mixed
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    protected function exec(string $sql, array $params = []): int
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    protected function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(fn ($c) => "`$c`", $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        $this->exec($sql, array_values($data));
        return (int) $this->db->lastInsertId();
    }

    /** Update mit Pflicht-Filter auf household_id (sofern Spalte vorhanden) */
    protected function updateWhere(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn ($c) => "`$c` = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(fn ($c) => "`$c` = ?", array_keys($where)));
        return $this->exec("UPDATE $table SET $set WHERE $cond", [...array_values($data), ...array_values($where)]);
    }

    /** "?, ?, ?" für IN-Listen; leere Liste ergibt eine nie zutreffende Bedingung */
    protected static function in(array $ids): string
    {
        return $ids ? implode(',', array_fill(0, count($ids), '?')) : 'NULL';
    }

    public function transaction(callable $fn): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
