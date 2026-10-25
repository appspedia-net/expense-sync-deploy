<?php
/**
 * Delta sync engine for per-space entities.
 *
 * Every synced table shares the columns:
 *   id (client uuid), space_id, created_by, updated_by, updated_at (ms, server-assigned),
 *   client_updated_at (ms, from client), version (int), deleted (0/1)
 * plus entity-specific payload columns declared in ENTITIES below.
 *
 * Conflict rule (last-write-wins by client_updated_at):
 *   - incoming client_updated_at >= server client_updated_at  => apply, version+1
 *   - otherwise                                                => reject, return server row as conflict
 *   - if base_version is given and < server version AND the client row is older => conflict (same path)
 */

declare(strict_types=1);

namespace App\Services;

use PDO;

class Sync
{
    /** Column types: str, text, num, int, bool, json, date, time */
    public const ENTITIES = [
        'transactions' => [
            'table' => 'sp_transactions',
            'cols' => [
                'type' => 'str', 'amount' => 'num', 'currency' => 'str', 'fx_rate' => 'num',
                'title' => 'str', 'note' => 'text', 'category_id' => 'str', 'date' => 'date', 'time' => 'time',
                'payer_user_id' => 'str', 'payment' => 'str', 'split_type' => 'str', 'splits' => 'json',
                'receipt_url' => 'str', 'tags' => 'json', 'location' => 'str', 'recurring_rule_id' => 'str',
            ],
            'required' => ['type', 'amount'],
            'enums' => ['type' => ['expense', 'income', 'transfer'], 'split_type' => ['equal', 'exact', 'percent', 'shares', 'none']],
        ],
        'categories' => [
            'table' => 'sp_categories',
            'cols' => ['name' => 'str', 'icon' => 'str', 'color' => 'str', 'type' => 'str', 'sort_order' => 'int', 'parent_id' => 'str'],
            'required' => ['name'],
            'enums' => ['type' => ['expense', 'income', 'both']],
        ],
        'budgets' => [
            'table' => 'sp_budgets',
            'cols' => ['category_id' => 'str', 'amount' => 'num', 'currency' => 'str', 'period' => 'str', 'start_date' => 'date', 'end_date' => 'date', 'rollover' => 'bool', 'alert_80' => 'bool', 'alert_100' => 'bool', 'name' => 'str'],
            'required' => ['amount'],
            'enums' => ['period' => ['weekly', 'monthly', 'yearly', 'custom']],
        ],
        'settlements' => [
            'table' => 'sp_settlements',
            'cols' => ['from_user_id' => 'str', 'to_user_id' => 'str', 'amount' => 'num', 'currency' => 'str', 'date' => 'date', 'note' => 'text', 'payment' => 'str'],
            'required' => ['from_user_id', 'to_user_id', 'amount'],
            'enums' => [],
        ],
        'comments' => [
            'table' => 'sp_comments',
            'cols' => ['transaction_id' => 'str', 'user_id' => 'str', 'text' => 'text'],
            'required' => ['transaction_id', 'text'],
            'enums' => [],
        ],
        'recurring_rules' => [
            'table' => 'sp_recurring_rules',
            'cols' => [
                'title' => 'str', 'type' => 'str', 'amount' => 'num', 'currency' => 'str', 'category_id' => 'str',
                'frequency' => 'str', 'interval_n' => 'int', 'start_date' => 'date', 'end_date' => 'date', 'next_run' => 'date',
                'payer_user_id' => 'str', 'payment' => 'str', 'split_type' => 'str', 'splits' => 'json', 'note' => 'text', 'active' => 'bool',
            ],
            'required' => ['amount', 'frequency'],
            'enums' => ['type' => ['expense', 'income', 'transfer'], 'frequency' => ['daily', 'weekly', 'monthly', 'yearly']],
        ],
    ];

    public const SYNC_COLS = ['id', 'space_id', 'created_by', 'updated_by', 'updated_at', 'client_updated_at', 'version', 'deleted'];

    public static function entityNames(): array
    {
        return array_keys(self::ENTITIES);
    }

    public static function table(string $entity): string
    {
        return self::ENTITIES[$entity]['table'];
    }

    // -------------------------------------------------------------- reading

    /**
     * Rows changed after $since (ms) across all entities, oldest first.
     *
     * @return array{changes: array, next_since: int, has_more: bool}
     */
    public static function changesSince(PDO $pdo, string $spaceId, int $since, int $limit, ?array $onlyEntities = null): array
    {
        $limit = max(1, min(1000, $limit));
        $all = [];
        foreach (self::ENTITIES as $name => $def) {
            if ($onlyEntities !== null && !in_array($name, $onlyEntities, true)) {
                continue;
            }
            $st = $pdo->prepare("SELECT * FROM {$def['table']} WHERE space_id = ? AND updated_at > ? ORDER BY updated_at ASC, id ASC LIMIT " . ($limit + 1));
            $st->execute([$spaceId, $since]);
            foreach ($st->fetchAll() as $row) {
                $all[] = ['entity' => $name, 'row' => self::outRow($name, $row)];
            }
        }
        usort($all, function (array $a, array $b): int {
            $d = $a['row']['updated_at'] <=> $b['row']['updated_at'];
            return $d !== 0 ? $d : strcmp($a['row']['id'], $b['row']['id']);
        });
        $hasMore = count($all) > $limit;
        if ($hasMore) {
            $all = array_slice($all, 0, $limit);
            // Never split a millisecond across pages: drop trailing rows that share
            // the last timestamp (they come back on the next page) unless that
            // would empty the page.
            $lastTs = $all[$limit - 1]['row']['updated_at'];
            $firstWithLastTs = $limit - 1;
            while ($firstWithLastTs > 0 && $all[$firstWithLastTs - 1]['row']['updated_at'] === $lastTs) {
                $firstWithLastTs--;
            }
            if ($firstWithLastTs > 0) {
                $all = array_slice($all, 0, $firstWithLastTs);
            }
        }
        $nextSince = $all ? (int) end($all)['row']['updated_at'] : $since;
        return ['changes' => $all, 'next_since' => $nextSince, 'has_more' => $hasMore];
    }

    /** Fetch one server row in output shape. */
    public static function fetchRow(PDO $pdo, string $entity, string $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ' . self::table($entity) . ' WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ? self::outRow($entity, $row) : null;
    }

    /** Normalise DB types for JSON output. */
    public static function outRow(string $entity, array $row): array
    {
        $def = self::ENTITIES[$entity];
        foreach ($def['cols'] as $col => $type) {
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $v = $row[$col];
            if ($v === null) {
                continue;
            }
            switch ($type) {
                case 'json':
                    $row[$col] = json_col(is_string($v) ? $v : json_encode($v));
                    break;
                case 'num':
                    $row[$col] = (float) $v;
                    break;
                case 'int':
                    $row[$col] = (int) $v;
                    break;
                case 'bool':
                    $row[$col] = ((int) $v) === 1;
                    break;
                default:
                    $row[$col] = (string) $v;
            }
        }
        $row['updated_at'] = (int) $row['updated_at'];
        $row['client_updated_at'] = (int) $row['client_updated_at'];
        $row['version'] = (int) $row['version'];
        $row['deleted'] = (int) $row['deleted'];
        return $row;
    }

    // -------------------------------------------------------------- writing

    /**
     * Validate and normalise an incoming payload for an entity.
     * Returns [values, error]; values keyed by column with DB-ready scalars.
     */
    public static function normalisePayload(string $entity, array $row, bool $isInsert): array
    {
        $def = self::ENTITIES[$entity];
        $out = [];
        foreach ($def['cols'] as $col => $type) {
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $v = $row[$col];
            if ($v === null || $v === '') {
                $out[$col] = null;
                continue;
            }
            switch ($type) {
                case 'num':
                    if (!is_numeric($v)) {
                        return [null, "{$col} must be numeric"];
                    }
                    $out[$col] = round((float) $v, 6);
                    break;
                case 'int':
                    $out[$col] = (int) $v;
                    break;
                case 'bool':
                    $out[$col] = ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0;
                    break;
                case 'json':
                    if (!is_array($v)) {
                        return [null, "{$col} must be an array/object"];
                    }
                    $out[$col] = json_encode($v, JSON_UNESCAPED_UNICODE);
                    break;
                case 'date':
                    $s = (string) $v;
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                        return [null, "{$col} must be YYYY-MM-DD"];
                    }
                    $out[$col] = $s;
                    break;
                case 'time':
                    $s = (string) $v;
                    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $s)) {
                        return [null, "{$col} must be HH:MM"];
                    }
                    $out[$col] = strlen($s) === 5 ? $s . ':00' : $s;
                    break;
                case 'text':
                    $out[$col] = mb_substr((string) (is_scalar($v) ? $v : json_encode($v)), 0, 5000);
                    break;
                default: // str
                    $out[$col] = mb_substr((string) (is_scalar($v) ? $v : json_encode($v)), 0, 255);
            }
            if (isset($def['enums'][$col]) && $out[$col] !== null && !in_array($out[$col], $def['enums'][$col], true)) {
                return [null, "{$col} must be one of " . implode('|', $def['enums'][$col])];
            }
        }
        if ($isInsert) {
            foreach ($def['required'] as $req) {
                if (!isset($out[$req])) {
                    return [null, "missing {$req}"];
                }
            }
        }
        return [$out, null];
    }

    /**
     * Apply one change. Returns ['applied' => row] or ['conflict' => serverRow] or ['error' => msg].
     *
     * @param int $serverTs the updated_at (ms) to stamp — caller guarantees monotonic per request
     */
    public static function apply(PDO $pdo, string $entity, string $spaceId, string $userId, string $op, array $row, ?int $baseVersion, int $serverTs): array
    {
        $table = self::table($entity);
        $id = (string) ($row['id'] ?? '');
        if ($id === '' || strlen($id) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            return ['error' => 'invalid id'];
        }
        $clientTs = isset($row['client_updated_at']) ? (int) $row['client_updated_at'] : $serverTs;

        $st = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $server = $st->fetch() ?: null;

        if ($server && $server['space_id'] !== $spaceId) {
            return ['error' => 'id belongs to another space'];
        }

        if ($server) {
            $serverClientTs = (int) $server['client_updated_at'];
            $serverVersion = (int) $server['version'];
            // Last-write-wins: an incoming row older than what the server holds is
            // rejected and the client gets the server row back. (With base_version
            // the client additionally learns it was editing a stale version.)
            $older = $clientTs < $serverClientTs;
            $stale = $baseVersion !== null && $baseVersion < $serverVersion;
            if ($older) {
                return ['conflict' => self::outRow($entity, $server), 'reason' => $stale ? 'stale_and_older' : 'older'];
            }
            // idempotent replay: same client ts + same version already applied
            if ($baseVersion !== null && $baseVersion === $serverVersion && $clientTs === $serverClientTs && $op === 'upsert' && (int) $server['deleted'] === 0) {
                return ['applied' => self::outRow($entity, $server), 'noop' => true];
            }
        }

        if ($op === 'delete') {
            if (!$server) {
                // Deleting a row the server never had: nobody else can have it either
                // (rows only reach other devices through the server), so there is
                // nothing to tombstone. Report it as applied so the client clears its queue.
                $ghost = array_merge($row, [
                    'id' => $id, 'space_id' => $spaceId, 'created_by' => $userId, 'updated_by' => $userId,
                    'updated_at' => $serverTs, 'client_updated_at' => $clientTs, 'version' => 0, 'deleted' => 1,
                ]);
                return ['applied' => $ghost, 'noop' => true, 'previous' => null];
            } else {
                $pdo->prepare("UPDATE {$table} SET deleted = 1, updated_by = ?, updated_at = ?, client_updated_at = ?, version = version + 1 WHERE id = ?")
                    ->execute([$userId, $serverTs, $clientTs, $id]);
            }
            return ['applied' => self::fetchRow($pdo, $entity, $id), 'previous' => $server ? self::outRow($entity, $server) : null];
        }

        // upsert
        [$payload, $err] = self::normalisePayload($entity, $row, $server === null);
        if ($err !== null) {
            return ['error' => $err];
        }
        $deleted = isset($row['deleted']) ? ((int) $row['deleted'] === 1 ? 1 : 0) : 0;

        if (!$server) {
            $cols = ['id', 'space_id', 'created_by', 'updated_by', 'updated_at', 'client_updated_at', 'version', 'deleted'];
            $vals = [$id, $spaceId, $userId, $userId, $serverTs, $clientTs, 1, $deleted];
            foreach ($payload as $c => $v) {
                $cols[] = $c;
                $vals[] = $v;
            }
            $pdo->prepare("INSERT INTO {$table} (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')')->execute($vals);
        } else {
            $sets = ['updated_by = ?', 'updated_at = ?', 'client_updated_at = ?', 'version = version + 1', 'deleted = ?'];
            $vals = [$userId, $serverTs, $clientTs, $deleted];
            foreach ($payload as $c => $v) {
                $sets[] = "{$c} = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $pdo->prepare("UPDATE {$table} SET " . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        }
        return ['applied' => self::fetchRow($pdo, $entity, $id), 'previous' => $server ? self::outRow($entity, $server) : null];
    }
}
