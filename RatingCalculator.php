<?php
/**
 * Расчёт МБ, РО, ПБ по классификации ФСБР (формулы с сайта).
 */
declare(strict_types=1);

class RatingCalculator
{

    /**
     * Гарантированные ПБ (Приложение 1). Ключ → список ПБ с 1-го места.
     * null = нет гарантии.
     */
    public const GUARANTEED_PB = [
        'none' => null,
        'ru_team' => [5, 4, 3, 2, 1],
        'ru_pair' => [5, 4, 3, 2, 1, 1, 1, 1, 1, 1],
        'ru_mixed' => [4, 3, 2, 1],
        'ru_cup' => [4, 3, 2, 1],
        'ru_women_pair' => [2, 1],
        'ru_individual' => [2, 1],
        'ru_junior' => [1],
        'eu_team' => [6, 5, 4, 3, 2, 1],
        'eu_women_team' => [5, 4, 3, 2, 1],
        'eu_pair' => [6, 5, 4, 3, 2, 1, 1, 1, 1, 1],
        'eu_women_pair' => [5, 4, 3, 2, 1, 1, 1, 1, 1, 1],
        'eu_mixed_team' => [5, 4, 3, 2, 1, 1, 1, 1],
        'eu_mixed_pair' => [5, 4, 3, 2, 1],
        'eu_junior' => [3, 2, 1],
        'w_bermuda' => [8, 6, 5, 4, 2, 2, 2, 2],
        'w_venice' => [7, 5, 4, 3, 1, 1, 1, 1],
        'w_pair' => [8, 7, 6, 5, 4],  # 6-10:3, 11-20:2, 21-39:1 — упрощённо ниже
        'w_other' => [4, 3, 2, 1],
        'w_junior' => [3, 2, 1],
    ];

    public const GUARANTEED_PB_LABELS = [
        'none' => 'Нет (только по формуле)',
        'ru_team' => 'ЧР командный: 5-4-3-2-1',
        'ru_pair' => 'ЧР парный: 5-4-3-2-1-1-1-1-1-1',
        'ru_mixed' => 'ЧР микст: 4-3-2-1',
        'ru_cup' => 'Кубок России: 4-3-2-1',
        'ru_women_pair' => 'ЧР женский парный: 2-1',
        'ru_individual' => 'ЧР индивидуальный: 2-1',
        'ru_junior' => 'ЧР юниорский: 1',
        'eu_team' => 'ЧЕ командный: 6-5-4-3-2-1',
        'eu_women_team' => 'ЧЕ женский командный: 5-4-3-2-1',
        'eu_pair' => 'ЧЕ парный: 6-5-4-3-2-1×5',
        'eu_women_pair' => 'ЧЕ женский парный: 5-4-3-2-1×5',
        'eu_mixed_team' => 'ЧЕ микст командный: 5-4-3-2-1×3',
        'eu_mixed_pair' => 'ЧЕ микст парный: 5-4-3-2-1',
        'eu_junior' => 'ЧЕ юниорский: 3-2-1',
        'w_bermuda' => 'ЧМ Бермудский кубок: 8-6-5-4-2×4',
        'w_venice' => 'ЧМ Кубок Венеции: 7-5-4-3-1×4',
        'w_pair' => 'ЧМ парный (макс): 8-7-6-5-4 + зоны',
        'w_other' => 'ЧМ прочие: 4-3-2-1',
        'w_junior' => 'ЧМ юниорский: 3-2-1',
    ];

    /** @var string */
    public string $guaranteedKey = 'none';

    /** @var string pair|team|individual */
    public string $format;
    /** @var string express|rating|regional|russian|main_russian */
    public string $status;
    public float $d; // фактическая длина
    public int $N;   // число участников (пар/команд/игроков)

    /** @var list<array{rank:int,q:float,label:string,players:list}> */
    public array $entries = [];

    public float $kq = 0.0;
    public float $RC = 0.0;
    public float $RCM = 0.0;
    public float $kqn = 0.0;
    public float $kd = 0.0;
    public float $R_reg = 0.0; // коэффициент регрессии для МБ
    public int $pR = 0;
    public int $N0 = 0;
    public int $PB1 = 0;

    public array $debug = [];

    public function __construct(string $format, string $status, float $d, string $guaranteedKey = 'none')
    {
        $this->format = $format;
        $this->status = $status;
        $this->d = $d;
        $this->guaranteedKey = $guaranteedKey;
    }

    /** ПБ за место p (1-based) из таблицы гарантий; 0 если нет */
    public function guaranteedPbForPlace(int $p): int
    {
        $sched = self::GUARANTEED_PB[$this->guaranteedKey] ?? null;
        if ($sched === null) {
            return 0;
        }
        // спец. зоны для ЧМ парный
        if ($this->guaranteedKey === 'w_pair') {
            if ($p >= 1 && $p <= 5) return [8, 7, 6, 5, 4][$p - 1];
            if ($p >= 6 && $p <= 10) return 3;
            if ($p >= 11 && $p <= 20) return 2;
            if ($p >= 21 && $p <= 39) return 1;
            return 0;
        }
        $idx = $p - 1;
        if ($idx < 0 || $idx >= count($sched)) {
            return 0;
        }
        return (int)$sched[$idx];
    }


    public static function qFromRazr($razr): float
    {
        if ($razr === null || $razr === '') {
            return 5.0;
        }
        $r = (float)$razr;
        // razr >= 95: (razr - 100) / 2; иначе razr / 2
        if ($r >= 95) {
            return ($r - 100.0) / 2.0;
        }
        return $r / 2.0;
    }

    public static function q1(float $q): float
    {
        if ($q <= 0) {
            return 0.2 - 0.12 * $q;
        }
        return 0.2 / (pow(1.6, $q));
    }

    /** Добавить участника: rank, q (средний разряд), label, players */
    public function addEntry(int $rank, float $q, string $label, array $players = []): void
    {
        $this->entries[] = [
            'rank' => $rank,
            'q' => $q,
            'label' => $label,
            'players' => $players,
        ];
    }

    public function compute(): array
    {
        $this->N = count($this->entries);
        if ($this->N < 1) {
            throw new RuntimeException('Нет участников для расчёта');
        }

        // --- kq (п.7.5) ---
        // индекс -> q2
        $q2ByIndex = array_fill(0, count($this->entries), 0.0);
        $order = range(0, count($this->entries) - 1);
        usort($order, function ($i, $j) {
            return $this->entries[$i]['q'] <=> $this->entries[$j]['q'];
        });

        $take = ($this->format === 'team') ? 16 : 32;
        $q2sum = 0.0;
        foreach ($order as $pos => $idx) {
            $q1 = self::q1($this->entries[$idx]['q']);
            if ($this->format === 'team') {
                $q2 = $q1 * pow(0.9, $pos);
            } elseif ($this->format === 'pair') {
                $q2 = $q1 * pow(0.95, $pos);
            } else {
                $q2 = $q1;
            }
            // q2 для kq только у первых take, но в отчёт пишем q2 всем по полной шкале
            $q2ByIndex[$idx] = $q2;
            if ($pos < $take) {
                $q2sum += $q2;
            }
        }
        if ($this->format === 'team') {
            $this->kq = $q2sum;
        } elseif ($this->format === 'pair') {
            $this->kq = $q2sum / 2.0;
        } else {
            $this->kq = $q2sum / 4.0;
        }

        // N0: участники с разрядом 0 и лучше (q <= 0)
        $this->N0 = 0;
        foreach ($this->entries as $e) {
            if ($e['q'] <= 0) {
                $this->N0++;
            }
        }

        $dq = ($this->format === 'team') ? 88.0 : 66.0;
        $Nq = ($this->format === 'team') ? 16.0 : 32.0;

        $lg = fn(float $x) => log10(max($x, 1e-12));

        // RC
        $termN = 0.5 * $lg(max(1.0, $this->N0 / $Nq));
        if ($this->status === 'express') {
            $this->RC = ($this->kq + $termN - 0.5) / 2.0;
        } else {
            $this->RC = $this->kq + $lg($this->d / $dq) + $termN;
        }
        // статусы чемпионатов
        if (in_array($this->status, ['russian', 'main_russian'], true)) {
            $this->RC += 1.0;
        } elseif ($this->status === 'regional') {
            $this->RC += 0.5;
        }

        // RCM
        if ($this->status === 'express') {
            $this->RCM = $this->RC;
        } else {
            if ($dq <= $this->d && $this->d < 120) {
                $this->RCM = $this->kq + $termN; // без lg(d/dq)
                if (in_array($this->status, ['russian', 'main_russian'], true)) {
                    $this->RCM += 1.0;
                } elseif ($this->status === 'regional') {
                    $this->RCM += 0.5;
                }
            } else {
                $this->RCM = $this->RC;
            }
        }

        // pR
        if ($this->format === 'team') {
            $pRraw = min($this->N, 20 * atan($this->N * ($this->RC + 1) / 100));
        } else {
            $pRraw = min($this->N, 40 * atan($this->N * ($this->RC + 1) / 200));
        }
        $this->pR = (int)ceil($pRraw);
        if ($this->pR < 2) {
            $this->pR = 2; // избегаем lg(1)=0
        }

        // kqn, kd для МБ
        $N0mb = ($this->format === 'team') ? 2 * $this->N : $this->N;
        $kq1 = ($this->kq < 1) ? (1 - 0.7 * $lg($this->kq)) : 1.0;
        if ($N0mb >= 32) {
            $this->kqn = 0.6 * ($this->kq + $lg($N0mb) - 1.5) * $kq1;
        } else {
            $this->kqn = 0.4 * $this->kq * $lg($N0mb) * $kq1;
        }
        $this->kd = 2.2 * $lg($this->d) - 2.0;
        if ($this->kd < 0) {
            $this->kd = 0.0;
        }
        $t = $this->N / 8.0 * (3 + $this->kq - 0.5 * $lg($this->N));
        if ($t <= 0) {
            $t = 1.0;
        }
        $this->R_reg = 1.1 * pow(100 * $this->kqn * $this->kd, 1.0 / $t);

        // PB1 по формуле
        $this->PB1 = (int)self::roundHalfUp($this->RCM);

        // Гарантированные ПБ: если RC/RCM не дают нужный ПБ1 — поднять RC
        $gPb1 = $this->guaranteedPbForPlace(1);
        if ($gPb1 > 0 && $this->PB1 < $gPb1) {
            // RC := ПБ1_гарант − 0.5 (п. 8.10)
            $this->RC = $gPb1 - 0.5;
            // пересчёт pR и RCM-зависимых
            if ($this->format === 'team') {
                $pRraw = min($this->N, 20 * atan($this->N * ($this->RC + 1) / 100));
            } else {
                $pRraw = min($this->N, 40 * atan($this->N * ($this->RC + 1) / 200));
            }
            $this->pR = (int)ceil($pRraw);
            if ($this->pR < 2) {
                $this->pR = 2;
            }
            // RCM для ПБ: не ниже гарантии
            $this->RCM = max($this->RCM, (float)$gPb1);
            $this->PB1 = $gPb1;
            $this->debug['rc_boosted_for_guaranteed_pb'] = true;
        }


        // Группы по местам (ничьи)
        $byRank = [];
        foreach ($this->entries as $idx => $e) {
            $byRank[$e['rank']][] = $idx;
        }
        ksort($byRank);

        $results = [];
        foreach ($this->entries as $idx => $e) {
            $p = $e['rank'];
            // ничьи: все места в группе
            $groupRanks = [];
            foreach ($byRank as $r => $idxs) {
                if (in_array($idx, $idxs, true)) {
                    $groupRanks = array_keys(array_fill(0, count($idxs), 0));
                    // фактические места p, p+1, ... p+k-1
                    $groupRanks = [];
                    for ($j = 0; $j < count($idxs); $j++) {
                        $groupRanks[] = $r + $j;
                    }
                    // wait - for tied rank all have same rank number from report
                    // RO: average of RO(p) for all divided places
                    // PB: as for highest place among ties
                    break;
                }
            }
            // Rebuild: for rank value $p with $cnt ties, places are p, p+1, ..., p+cnt-1
            $cnt = count($byRank[$p]);
            $places = [];
            for ($j = 0; $j < $cnt; $j++) {
                $places[] = $p + $j;
            }

            // RO average
            $roSum = 0.0;
            foreach ($places as $pl) {
                if ($pl >= $this->pR) {
                    $roSum += 0.0;
                } else {
                    $roSum += 50 * $this->RC * (1 - $lg($pl) / $lg($this->pR));
                }
            }
            $ro = self::roundHalfUp($roSum / count($places));
            if ($ro < 0) {
                $ro = 0;
            }

            // PB: по формуле; при ничьей — как за лучшее место группы ($p)
            $pb = 0;
            if ($this->RCM >= 0.5) {
                $pb = max(0, $this->PB1 - $p + 1);
            }
            // гарантированные ПБ — не меньше таблицы
            $gPb = $this->guaranteedPbForPlace((int)$p);
            if ($gPb > $pb) {
                $pb = $gPb;
            }


            // MB average over places
            $mbSum = 0.0;
            if ($this->R_reg > 0 && $this->kqn > 0 && $this->kd > 0) {
                foreach ($places as $pl) {
                    $mbSum += 50 * $this->kqn * $this->kd / pow($this->R_reg, $pl - 1);
                }
            }
            $mb = (int)self::roundHalfUp($mbSum / max(1, count($places)));
            if ($mb < 0) {
                $mb = 0;
            }

            $results[] = [
                'rank' => $p,
                'label' => $e['label'],
                'q' => round($e['q'], 4),
                'q1' => round(self::q1($e['q']), 6),
                'q2' => round($q2ByIndex[$idx] ?? 0, 6),
                'RO' => (int)$ro,
                'PB' => (int)$pb,
                'MB' => $mb,
                'players' => $e['players'],
            ];
        }

        // sort by rank
        usort($results, fn($a, $b) => $a['rank'] <=> $b['rank']);

        $this->debug = [
            'N' => $this->N,
            'd' => $this->d,
            'kq' => round($this->kq, 6),
            'N0' => $this->N0,
            'dq' => $dq,
            'Nq' => $Nq,
            'RC' => round($this->RC, 6),
            'RCM' => round($this->RCM, 6),
            'pR' => $this->pR,
            'PB1' => $this->PB1,
            'kqn' => round($this->kqn, 6),
            'kd' => round($this->kd, 6),
            'R' => round($this->R_reg, 6),
            'format' => $this->format,
            'status' => $this->status,
            'guaranteed' => $this->guaranteedKey,
            'guaranteed_pb1' => $this->guaranteedPbForPlace(1),
        ];

        return [
            'params' => $this->debug,
            'results' => $results,
        ];
    }

    public static function roundHalfUp(float $x): float
    {
        // 0.5 → вверх для положительных
        return floor($x + 0.5);
    }
    /**
     * МБ за сессию/этап: kd = lg(d) - 0.7 (п. 7.6).
     * РО и ПБ за сессии не начисляются.
     * @return array{params: array, results: list}
     */
    public function computeSessionMb(): array
    {
        $this->N = count($this->entries);
        if ($this->N < 1) {
            throw new RuntimeException('Нет участников сессии');
        }
        $lg = fn(float $x) => log10(max($x, 1e-12));

        // kq как для турнира (п.7.5) по составу сессии
        $q2ByIndex = array_fill(0, count($this->entries), 0.0);
        $order = range(0, count($this->entries) - 1);
        usort($order, function ($i, $j) {
            return $this->entries[$i]['q'] <=> $this->entries[$j]['q'];
        });
        $take = ($this->format === 'team') ? 16 : 32;
        $q2sum = 0.0;
        foreach ($order as $pos => $idx) {
            $q1 = self::q1($this->entries[$idx]['q']);
            if ($this->format === 'team') {
                $q2 = $q1 * pow(0.9, $pos);
            } elseif ($this->format === 'pair') {
                $q2 = $q1 * pow(0.95, $pos);
            } else {
                $q2 = $q1;
            }
            $q2ByIndex[$idx] = $q2;
            if ($pos < $take) {
                $q2sum += $q2;
            }
        }
        if ($this->format === 'team') {
            $this->kq = $q2sum;
        } elseif ($this->format === 'pair') {
            $this->kq = $q2sum / 2.0;
        } else {
            $this->kq = $q2sum / 4.0;
        }

        $N0mb = ($this->format === 'team') ? 2 * $this->N : $this->N;
        $kq1 = ($this->kq < 1) ? (1 - 0.7 * $lg(max($this->kq, 1e-12))) : 1.0;
        if ($N0mb >= 32) {
            $this->kqn = 0.6 * ($this->kq + $lg($N0mb) - 1.5) * $kq1;
        } else {
            $this->kqn = 0.4 * $this->kq * $lg(max($N0mb, 1)) * $kq1;
        }
        // сессия: kd = lg d - 0.7
        $this->kd = $lg(max($this->d, 1)) - 0.7;
        if ($this->kd < 0) {
            $this->kd = 0.0;
        }
        $t = $this->N / 8.0 * (3 + $this->kq - 0.5 * $lg(max($this->N, 1)));
        if ($t <= 0) {
            $t = 1.0;
        }
        $this->R_reg = 1.1 * pow(max(100 * $this->kqn * $this->kd, 1e-12), 1.0 / $t);

        $byRank = [];
        foreach ($this->entries as $idx => $e) {
            $byRank[$e['rank']][] = $idx;
        }
        ksort($byRank);

        $results = [];
        foreach ($this->entries as $idx => $e) {
            $p = $e['rank'];
            $cnt = count($byRank[$p]);
            $places = [];
            for ($j = 0; $j < $cnt; $j++) {
                $places[] = $p + $j;
            }
            $mbSum = 0.0;
            if ($this->R_reg > 0 && $this->kqn > 0 && $this->kd > 0) {
                foreach ($places as $pl) {
                    $mbSum += 50 * $this->kqn * $this->kd / pow($this->R_reg, $pl - 1);
                }
            }
            $mb = (int)self::roundHalfUp($mbSum / max(1, count($places)));
            if ($mb < 0) {
                $mb = 0;
            }
            $results[] = [
                'rank' => $p,
                'label' => $e['label'],
                'q' => round($e['q'], 4),
                'q2' => round($q2ByIndex[$idx] ?? 0, 6),
                'MB' => $mb,
                'RO' => 0,
                'PB' => 0,
                'players' => $e['players'],
            ];
        }
        usort($results, fn($a, $b) => $a['rank'] <=> $b['rank']);

        return [
            'params' => [
                'N' => $this->N,
                'd' => $this->d,
                'kq' => round($this->kq, 6),
                'kqn' => round($this->kqn, 6),
                'kd' => round($this->kd, 6),
                'R' => round($this->R_reg, 6),
                'mode' => 'session',
            ],
            'results' => $results,
        ];
    }


}
