<?php
/**
 * Space balances: who paid what, who owes what, and a greedy
 * minimum-transfer settlement plan ("simplified debts").
 *
 * Amounts are converted to the space currency with the transaction's fx_rate
 * (fx_rate = how many units of the space currency one unit of the transaction
 * currency is worth; null/0 means "same currency").
 *
 * Rules:
 *   expense  : payer paid +amount; shares per split_type
 *   income   : treated as a negative expense (payer received money on behalf of the group)
 *   transfer : ignored for balances (account-to-account move)
 *   settlement from A to B of X: A's net +X, B's net -X
 *   net = paid - share  (positive = is owed money, negative = owes money)
 */

declare(strict_types=1);

namespace App\Services;

use PDO;

class Balances
{
    public static function compute(PDO $pdo, array $space): array
    {
        $spaceId = $space['id'];
        $spaceCurrency = (string) ($space['currency'] ?? 'USD');

        $st = $pdo->prepare('SELECT m.user_id, m.role, u.name, u.avatar_color FROM space_members m JOIN users u ON u.id = m.user_id WHERE m.space_id = ? ORDER BY m.joined_at ASC');
        $st->execute([$spaceId]);
        $members = $st->fetchAll();
        $memberIds = array_column($members, 'user_id');

        $paid = [];
        $share = [];
        $settled = [];
        foreach ($memberIds as $uid) {
            $paid[$uid] = 0.0;
            $share[$uid] = 0.0;
            $settled[$uid] = 0.0;
        }
        $ensure = function (string $uid) use (&$paid, &$share, &$settled): void {
            if (!isset($paid[$uid])) {
                $paid[$uid] = 0.0;
                $share[$uid] = 0.0;
                $settled[$uid] = 0.0;
            }
        };

        $st = $pdo->prepare('SELECT id, type, amount, currency, fx_rate, payer_user_id, split_type, splits, created_by FROM sp_transactions WHERE space_id = ? AND deleted = 0');
        $st->execute([$spaceId]);
        $txCount = 0;
        $totalExpense = 0.0;
        foreach ($st->fetchAll() as $t) {
            if ($t['type'] === 'transfer') {
                continue;
            }
            $amount = (float) $t['amount'];
            $rate = (float) ($t['fx_rate'] ?? 0);
            if ($t['currency'] && $t['currency'] !== $spaceCurrency && $rate > 0) {
                $amount = $amount * $rate;
            }
            if ($t['type'] === 'income') {
                $amount = -$amount;
            }
            $payer = (string) ($t['payer_user_id'] ?: $t['created_by']);
            $ensure($payer);
            $txCount++;
            if ($amount > 0) {
                $totalExpense += $amount;
            }
            $paid[$payer] += $amount;

            $splitType = (string) ($t['split_type'] ?: 'equal');
            $splits = json_col($t['splits']);
            $shares = self::shares($splitType, $amount, $splits, $memberIds, $payer);
            foreach ($shares as $uid => $s) {
                $ensure((string) $uid);
                $share[$uid] += $s;
            }
        }

        $st = $pdo->prepare('SELECT from_user_id, to_user_id, amount, currency FROM sp_settlements WHERE space_id = ? AND deleted = 0');
        $st->execute([$spaceId]);
        $settlementCount = 0;
        foreach ($st->fetchAll() as $s) {
            $from = (string) $s['from_user_id'];
            $to = (string) $s['to_user_id'];
            $ensure($from);
            $ensure($to);
            $settled[$from] += (float) $s['amount'];
            $settled[$to] -= (float) $s['amount'];
            $settlementCount++;
        }

        $byId = [];
        foreach ($members as $m) {
            $byId[$m['user_id']] = $m;
        }
        $out = [];
        $net = [];
        foreach ($paid as $uid => $p) {
            $n = round($p - $share[$uid] + $settled[$uid], 2);
            $net[$uid] = $n;
            $out[] = [
                'user_id' => $uid,
                'name' => $byId[$uid]['name'] ?? 'Former member',
                'avatar_color' => $byId[$uid]['avatar_color'] ?? null,
                'is_member' => isset($byId[$uid]),
                'paid' => round($p, 2),
                'share' => round($share[$uid], 2),
                'settled' => round($settled[$uid], 2),
                'net' => $n,
            ];
        }

        return [
            'currency' => $spaceCurrency,
            'total_expense' => round($totalExpense, 2),
            'transaction_count' => $txCount,
            'settlement_count' => $settlementCount,
            'members' => $out,
            'simplified_debts' => self::simplify($net),
        ];
    }

    /**
     * Per-user share of one transaction.
     *
     * @return array<string,float>
     */
    public static function shares(string $splitType, float $amount, array $splits, array $memberIds, string $payer): array
    {
        $out = [];
        $ids = [];
        foreach ($splits as $s) {
            if (is_array($s) && !empty($s['user_id'])) {
                $ids[] = (string) $s['user_id'];
            }
        }
        switch ($splitType) {
            case 'none':
                return [$payer => $amount];

            case 'exact':
                foreach ($splits as $s) {
                    if (is_array($s) && !empty($s['user_id'])) {
                        $out[(string) $s['user_id']] = ($out[(string) $s['user_id']] ?? 0) + (float) ($s['amount'] ?? 0) * ($amount < 0 ? -1 : 1);
                    }
                }
                return $out ?: [$payer => $amount];

            case 'percent':
                foreach ($splits as $s) {
                    if (is_array($s) && !empty($s['user_id'])) {
                        $out[(string) $s['user_id']] = ($out[(string) $s['user_id']] ?? 0) + $amount * (float) ($s['percent'] ?? 0) / 100.0;
                    }
                }
                return $out ?: [$payer => $amount];

            case 'shares':
                $total = 0.0;
                foreach ($splits as $s) {
                    if (is_array($s) && !empty($s['user_id'])) {
                        $total += (float) ($s['shares'] ?? 0);
                    }
                }
                if ($total <= 0) {
                    return [$payer => $amount];
                }
                foreach ($splits as $s) {
                    if (is_array($s) && !empty($s['user_id'])) {
                        $out[(string) $s['user_id']] = ($out[(string) $s['user_id']] ?? 0) + $amount * (float) ($s['shares'] ?? 0) / $total;
                    }
                }
                return $out;

            case 'equal':
            default:
                $people = $ids ?: $memberIds;
                if (!$people) {
                    return [$payer => $amount];
                }
                $each = $amount / count($people);
                foreach ($people as $uid) {
                    $out[$uid] = ($out[$uid] ?? 0) + $each;
                }
                return $out;
        }
    }

    /**
     * Greedy debt simplification: repeatedly match the largest debtor with the
     * largest creditor. Produces at most (n-1) transfers.
     *
     * @param array<string,float> $net  user_id => net (positive = owed)
     * @return array<int, array{from:string,to:string,amount:float}>
     */
    public static function simplify(array $net): array
    {
        $creditors = [];
        $debtors = [];
        foreach ($net as $uid => $n) {
            $n = round($n, 2);
            if ($n > 0.009) {
                $creditors[] = ['id' => (string) $uid, 'amt' => $n];
            } elseif ($n < -0.009) {
                $debtors[] = ['id' => (string) $uid, 'amt' => -$n];
            }
        }
        $byAmt = function (array $a, array $b): int {
            return $b['amt'] <=> $a['amt'];
        };
        usort($creditors, $byAmt);
        usort($debtors, $byAmt);

        $transfers = [];
        $i = 0;
        $j = 0;
        while ($i < count($debtors) && $j < count($creditors)) {
            $pay = round(min($debtors[$i]['amt'], $creditors[$j]['amt']), 2);
            if ($pay > 0) {
                $transfers[] = ['from' => $debtors[$i]['id'], 'to' => $creditors[$j]['id'], 'amount' => $pay];
            }
            $debtors[$i]['amt'] = round($debtors[$i]['amt'] - $pay, 2);
            $creditors[$j]['amt'] = round($creditors[$j]['amt'] - $pay, 2);
            if ($debtors[$i]['amt'] <= 0.009) {
                $i++;
            }
            if ($creditors[$j]['amt'] <= 0.009) {
                $j++;
            }
        }
        return $transfers;
    }
}
