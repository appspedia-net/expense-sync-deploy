<?php
/**
 * Delta sync per space.
 *
 *  GET  /v1/spaces/{id}/changes?since={ms}&limit=500&entities=transactions,comments
 *  POST /v1/spaces/{id}/changes {changes:[{entity, op: upsert|delete, row, base_version?}]}
 *       -> {applied:[ids], conflicts:[{id, entity, server_row}], errors:[{id, entity, message}], server_time}
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Activity;
use App\Services\Push;
use App\Services\Sync;
use Throwable;

class SyncController extends Controller
{
    /** GET changes since a server timestamp. */
    public function pull(array $params): void
    {
        [$space] = $this->requireMember($params['id']);
        $since = isset($_GET['since']) ? max(0, (int) $_GET['since']) : 0;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 500;
        $only = null;
        if (!empty($_GET['entities'])) {
            $only = array_values(array_intersect(explode(',', (string) $_GET['entities']), Sync::entityNames()));
        }
        $res = Sync::changesSince($this->db, $space['id'], $since, $limit, $only);
        $res['server_time'] = now_ms();
        $res['space_updated_at'] = (int) $space['updated_at'];
        json($res);
    }

    /** POST a batch of changes. */
    public function push(array $params): void
    {
        [$space, $m] = $this->requireMember($params['id'], 'editor', false);
        $u = auth();
        $in = input();
        $changes = $in['changes'] ?? null;
        if (!is_array($changes)) {
            fail('validation', 'Body must be {changes:[...]}', 400);
        }
        $max = (int) config('limits.max_changes_per_push', 500);
        if (count($changes) > $max) {
            fail('validation', "At most {$max} changes per request", 413);
        }

        $applied = [];
        $conflicts = [];
        $errors = [];
        $events = []; // for activity + push
        $ts = now_ms();

        $pdo = $this->db;
        $pdo->beginTransaction();
        try {
            foreach ($changes as $i => $c) {
                if (!is_array($c)) {
                    $errors[] = ['index' => $i, 'message' => 'change must be an object'];
                    continue;
                }
                $entity = (string) ($c['entity'] ?? '');
                $op = (string) ($c['op'] ?? 'upsert');
                $row = $c['row'] ?? null;
                if (!isset(Sync::ENTITIES[$entity])) {
                    $errors[] = ['index' => $i, 'message' => 'unknown entity ' . $entity];
                    continue;
                }
                if (!in_array($op, ['upsert', 'delete'], true)) {
                    $errors[] = ['index' => $i, 'entity' => $entity, 'message' => 'op must be upsert|delete'];
                    continue;
                }
                if (!is_array($row)) {
                    $errors[] = ['index' => $i, 'entity' => $entity, 'message' => 'row missing'];
                    continue;
                }
                $baseVersion = isset($c['base_version']) && $c['base_version'] !== null ? (int) $c['base_version'] : null;

                // Strictly increasing server timestamps inside one request so delta
                // paging never splits or loses rows.
                $ts++;
                $res = Sync::apply($pdo, $entity, $space['id'], $u['id'], $op, $row, $baseVersion, $ts);

                if (isset($res['error'])) {
                    $errors[] = ['index' => $i, 'entity' => $entity, 'id' => $row['id'] ?? null, 'message' => $res['error']];
                } elseif (isset($res['conflict'])) {
                    $conflicts[] = ['id' => $res['conflict']['id'], 'entity' => $entity, 'server_row' => $res['conflict']];
                } else {
                    $applied[] = $res['applied']['id'];
                    if (empty($res['noop'])) {
                        $events[] = ['entity' => $entity, 'op' => $op, 'row' => $res['applied'], 'previous' => $res['previous'] ?? null];
                    }
                }
            }
            if ($applied) {
                $pdo->prepare('UPDATE spaces SET updated_at = ? WHERE id = ?')->execute([$ts, $space['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->recordEvents($space, $u, $events);

        json([
            'applied' => $applied,
            'conflicts' => $conflicts,
            'errors' => $errors,
            'server_time' => now_ms(),
        ]);
    }

    /** Write activity rows and send one push summarising the batch. */
    private function recordEvents(array $space, array $u, array $events): void
    {
        if (!$events) {
            return;
        }
        $pushLines = [];
        foreach ($events as $ev) {
            $row = $ev['row'];
            $isDelete = $ev['op'] === 'delete' || (int) $row['deleted'] === 1;
            $isNew = $ev['previous'] === null;
            switch ($ev['entity']) {
                case 'transactions':
                    $kind = $isDelete ? 'transaction_deleted' : ($isNew ? 'transaction_added' : 'transaction_edited');
                    $data = ['title' => $row['title'] ?? null, 'amount' => $row['amount'] ?? null, 'currency' => $row['currency'] ?? $space['currency'], 'type' => $row['type'] ?? null, 'date' => $row['date'] ?? null];
                    Activity::log($this->db, $space['id'], $u['id'], $kind, 'transaction', $row['id'], $data);
                    if (!$isDelete && $isNew && !empty($data['amount'])) {
                        $pushLines[] = $u['name'] . ' added ' . $this->money((float) $data['amount'], (string) $data['currency']) . ($data['title'] ? ' – ' . $data['title'] : '');
                    } elseif ($isDelete) {
                        $pushLines[] = $u['name'] . ' deleted ' . ($data['title'] ?: 'a transaction');
                    } else {
                        $pushLines[] = $u['name'] . ' edited ' . ($data['title'] ?: 'a transaction');
                    }
                    break;
                case 'settlements':
                    if (!$isDelete && $isNew) {
                        Activity::log($this->db, $space['id'], $u['id'], 'settlement_added', 'settlement', $row['id'], ['from' => $row['from_user_id'], 'to' => $row['to_user_id'], 'amount' => $row['amount'], 'currency' => $row['currency'] ?? $space['currency']]);
                        $pushLines[] = $u['name'] . ' recorded a settlement of ' . $this->money((float) $row['amount'], (string) ($row['currency'] ?? $space['currency']));
                    } elseif ($isDelete) {
                        Activity::log($this->db, $space['id'], $u['id'], 'settlement_deleted', 'settlement', $row['id']);
                    }
                    break;
                case 'comments':
                    if (!$isDelete && $isNew) {
                        Activity::log($this->db, $space['id'], $u['id'], 'comment_added', 'transaction', $row['transaction_id'] ?? null, ['text' => mb_substr((string) ($row['text'] ?? ''), 0, 140)]);
                        $pushLines[] = $u['name'] . ' commented: ' . mb_substr((string) ($row['text'] ?? ''), 0, 80);
                    }
                    break;
                default:
                    // categories / budgets / recurring rules: silent sync, no feed noise
                    break;
            }
        }

        if ($pushLines) {
            $body = count($pushLines) === 1 ? $pushLines[0] : $u['name'] . ' made ' . count($pushLines) . ' changes';
            Push::toSpace($space['id'], $u['id'], $space['name'], $body, ['kind' => 'space_changed']);
        }
    }

    private function money(float $amount, string $currency): string
    {
        return number_format($amount, 2, '.', ',') . ' ' . strtoupper($currency);
    }
}
