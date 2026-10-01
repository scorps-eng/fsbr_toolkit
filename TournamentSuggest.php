<?php
declare(strict_types=1);

/**
 * Эвристики: предыдущий турнир по названию, гарантированные ПБ, статус.
 */
class TournamentSuggest
{
    private static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    private static function len(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    /** Убрать год, даты, лишние пробелы для сравнения */
    public static function normalizeName(string $name): string
    {
        $s = self::lower(trim($name));
        // годы
        $s = preg_replace('/\b(19|20)\d{2}\b/u', '', $s) ?? $s;
        // даты вида 01.09, 1-2 сентября
        $s = preg_replace('/\b\d{1,2}[.\-\/]\d{1,2}([.\-\/]\d{2,4})?\b/u', '', $s) ?? $s;
        $s = preg_replace('/\b\d{1,2}\s*[–\-—]\s*\d{1,2}\b/u', '', $s) ?? $s;
        // римские / «этап N», «сессия N»
        $s = preg_replace('/\b(сессия|session|этап|stage|тур|tour)\s*\d+\b/ui', '', $s) ?? $s;
        $s = preg_replace('/\b[ivxlcdm]+\b/ui', '', $s) ?? $s;
        // пунктуация
        $s = preg_replace('/[«»"\'()\[\].,;:!?\/\\\\]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Ключевые токены названия (длина ≥ 3) */
    public static function tokens(string $name): array
    {
        $n = self::normalizeName($name);
        $parts = preg_split('/\s+/u', $n, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['и', 'в', 'на', 'по', 'для', 'the', 'of', 'a', 'an', 'парный', 'парная', 'командный', 'командная', 'турнир', 'чемпионат'];
        $out = [];
        foreach ($parts as $p) {
            if (self::len($p) < 3) continue;
            if (in_array($p, $stop, true)) continue;
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }


    /**
     * Подобрать city_id по строке «Место проведения».
     * @return list<array{city_id:int,city_name:string,score:float,reason:string}>
     */
    public static function suggestCity(mysqli $db, string $place, int $limit = 12): array
    {
        $place = trim($place);
        if ($place === '') {
            return [];
        }
        $norm = self::lower($place);
        // убрать «г.», скобки со страной иногда полезны
        $norm = preg_replace('/\bг\.?\s*/ui', '', $norm) ?? $norm;
        $norm = preg_replace('/\s+/u', ' ', $norm) ?? $norm;
        $norm = trim($norm);

        $tokens = self::tokens($place);
        // для городов стоп-слова другие — не режем «москва»
        $tokUse = array_slice($tokens, 0, 4);
        if (!$tokUse) {
            $tokUse = preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokUse = array_slice($tokUse, 0, 3);
        }

        $likeParts = [];
        foreach ($tokUse as $tok) {
            $tok = trim($tok);
            if (self::len($tok) < 2) continue;
            $esc = $db->real_escape_string($tok);
            $likeParts[] = "city_name LIKE '%{$esc}%'";
        }
        // также полное вхождение нормализованного куска
        if ($norm !== '') {
            $esc = $db->real_escape_string(mb_substr($norm, 0, 40));
            $likeParts[] = "city_name LIKE '%{$esc}%'";
        }
        if (!$likeParts) {
            return [];
        }
        $likeSql = '(' . implode(' OR ', array_unique($likeParts)) . ')';
        $sql = "SELECT city_id, city_name FROM cities WHERE {$likeSql} ORDER BY city_name ASC LIMIT 80";
        $res = @$db->query($sql);
        if (!$res) {
            // fallback: все города (мало — ~300)
            $res = @$db->query("SELECT city_id, city_name FROM cities ORDER BY city_name ASC LIMIT 400");
            if (!$res) {
                return [];
            }
        }

        $cands = [];
        while ($row = $res->fetch_assoc()) {
            $cn = (string)($row['city_name'] ?? '');
            $cnl = self::lower($cn);
            $score = 0.0;
            $reasons = [];
            if ($cnl === $norm || $cnl === self::lower($place)) {
                $score += 100;
                $reasons[] = 'точное совпадение';
            } elseif ($norm !== '' && (str_contains($norm, $cnl) || str_contains($cnl, $norm))) {
                $score += 70;
                $reasons[] = 'входит в место проведения';
            }
            foreach ($tokUse as $tok) {
                $tok = self::lower($tok);
                if ($tok !== '' && str_contains($cnl, $tok)) {
                    $score += 15;
                    $reasons[] = 'слово «' . $tok . '»';
                }
            }
            // «Москва» vs «г. Москва»
            if ($score < 30) {
                continue;
            }
            $cands[] = [
                'city_id' => (int)$row['city_id'],
                'city_name' => $cn,
                'score' => $score,
                'reason' => implode(', ', array_unique($reasons)),
            ];
        }
        $res->free();
        usort($cands, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['city_name'], $b['city_name']));
        return array_slice($cands, 0, $limit);
    }

    /** Полный список городов для select */
    public static function allCities(mysqli $db): array
    {
        $res = @$db->query("SELECT city_id, city_name FROM cities ORDER BY city_name ASC");
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'city_id' => (int)$row['city_id'],
                'city_name' => (string)$row['city_name'],
            ];
        }
        $res->free();
        return $out;
    }

    /** Название серии stream по id */
    public static function fetchStreamName(mysqli $db, int $streamId): ?string
    {
        $streamId = (int)$streamId;
        if ($streamId < 1) {
            return null;
        }
        // разные схемы: name / title / stream_name
        $sql = "SELECT * FROM streams WHERE stream_id = {$streamId} LIMIT 1";
        $res = @$db->query($sql);
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        $res->free();
        if (!$row) {
            return null;
        }
        foreach (['name', 'stream_name', 'title', 'descr', 'description', 'label'] as $k) {
            if (!empty($row[$k])) {
                return (string)$row[$k];
            }
        }
        // fallback: первое строковое поле кроме id
        foreach ($row as $k => $v) {
            if (is_string($v) && $v !== '' && !preg_match('/_id$|id$/i', $k)) {
                return $v;
            }
        }
        return null;
    }

    /** Список streams для подсказок */
    public static function allStreams(mysqli $db, int $limit = 200): array
    {
        $res = @$db->query("SELECT * FROM streams ORDER BY stream_id DESC LIMIT " . (int)$limit);
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $id = (int)($row['stream_id'] ?? $row['id'] ?? 0);
            if ($id < 1) continue;
            $name = null;
            foreach (['name', 'stream_name', 'title', 'descr', 'description', 'label'] as $k) {
                if (!empty($row[$k])) {
                    $name = (string)$row[$k];
                    break;
                }
            }
            $out[] = ['stream_id' => $id, 'name' => $name ?: ('stream #' . $id)];
        }
        $res->free();
        return $out;
    }

    /** Карточка турнира по id (stream, name, dates) */
    public static function fetchHeader(mysqli $db, int $tournId): ?array
    {
        $tournId = (int)$tournId;
        if ($tournId < 1) {
            return null;
        }
        $sql = "SELECT tourn_id, name, tour_date, stream, champ_t, type, parent, prev_id, next_id
                FROM tourn_header WHERE tourn_id = {$tournId} LIMIT 1";
        $res = @$db->query($sql);
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        $res->free();
        return $row ?: null;
    }

    /**
     * Кандидаты предыдущего турнира из tourn_header.
     * @param int|null $preferStream если задан — выше rank у того же stream
     * @return list<array{tourn_id:int,name:string,tour_date:?string,stream:?int,score:float,reason:string}>
     */
    public static function suggestPrev(mysqli $db, string $title, int $limit = 8, ?int $preferStream = null, ?int $excludeId = null): array
    {
        $title = trim($title);
        if ($title === '') {
            return [];
        }
        $norm = self::normalizeName($title);
        $tokens = self::tokens($title);
        if ($norm === '' && !$tokens) {
            return [];
        }

        // Узкий SQL: LIKE по 1–3 токенам, без выгрузки сотен строк
        $likeParts = [];
        $tokUse = array_slice($tokens, 0, 3);
        if (!$tokUse && $norm !== '') {
            $tokUse = [mb_substr($norm, 0, 40)];
        }
        foreach ($tokUse as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;
            $esc = $db->real_escape_string($tok);
            $likeParts[] = "name LIKE '%{$esc}%'";
        }
        if (!$likeParts) {
            return [];
        }
        $likeSql = '(' . implode(' OR ', $likeParts) . ')';
        $sql = "SELECT tourn_id, name, tour_date, stream, next_id
                FROM tourn_header
                WHERE (type IS NULL OR type IN (1,2,3,5,6))
                  AND (parent IS NULL OR parent = 0)
                  AND {$likeSql}
                ORDER BY tour_date DESC, tourn_id DESC
                LIMIT 80";
        $res = @$db->query($sql);
        if (!$res) {
            return [];
        }

        $cands = [];
        while ($row = $res->fetch_assoc()) {
            if ($excludeId !== null && (int)$row['tourn_id'] === $excludeId) {
                continue; // сам редактируемый турнир не может быть своим prev
            }
            $name = (string)($row['name'] ?? '');
            $nid = self::normalizeName($name);
            $score = 0.0;
            $reasons = [];

            if ($norm !== '' && $nid !== '' && $norm === $nid) {
                $score += 100;
                $reasons[] = 'точное совпадение названия (без года)';
            } elseif ($norm !== '' && $nid !== '' && (str_contains($nid, $norm) || str_contains($norm, $nid))) {
                $score += 60;
                $reasons[] = 'название содержит общее ядро';
            }

            if ($tokens) {
                $nt = self::tokens($name);
                $inter = array_intersect($tokens, $nt);
                if ($inter) {
                    $score += 12 * count($inter);
                    $reasons[] = 'общие слова: ' . implode(', ', $inter);
                }
            }

            $rowStream = isset($row['stream']) && $row['stream'] !== null && $row['stream'] !== ''
                ? (int)$row['stream'] : null;
            if ($preferStream !== null && $rowStream !== null && $rowStream === $preferStream) {
                $score += 40;
                $reasons[] = 'тот же stream=' . $preferStream;
            }

            if ($score < 20) {
                continue;
            }
            $cands[] = [
                'tourn_id' => (int)$row['tourn_id'],
                'name' => $name,
                'tour_date' => $row['tour_date'] ?? null,
                'stream' => $rowStream,
                'next_id' => isset($row['next_id']) && $row['next_id'] !== '' ? (int)$row['next_id'] : null,
                'score' => $score,
                'reason' => implode('; ', $reasons),
            ];
        }
        $res->free();

        usort($cands, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return strcmp((string)($b['tour_date'] ?? ''), (string)($a['tour_date'] ?? ''));
        });

        // предпочитаем самый свежий среди топа с близким score
        return array_slice($cands, 0, $limit);
    }

    /**
     * Угадать ключ гарантированных ПБ по названию, формату и статусу.
     * @return array{key:string,label:string,confidence:string,reason:string}
     */
    public static function suggestGuaranteed(string $title, string $format = 'pair', string $status = 'rating'): array
    {
        $t = self::lower($title);
        $isTeam = $format === 'team';
        $isInd = $format === 'individual';
        $isPair = !$isTeam && !$isInd;

        $reason = [];
        $key = 'none';

        $isWomen = (bool)preg_match('/женск|женщин|women|ladies/ui', $t);
        $isMixed = (bool)preg_match('/микст|mixed|mix\b/ui', $t);
        $isJunior = (bool)preg_match('/юниор|junior|молод[её]ж|school|школьн/ui', $t);
        $isCup = (bool)preg_match('/кубок\s+росси|cup\s+of\s+russia/ui', $t);
        $isOpen = (bool)preg_match('/открыт|\bopen\b/ui', $t);
        $isPlayoff = (bool)preg_match('/плей-?офф|play-?off/ui', $t);
        $isRosenblum = (bool)preg_match('/розенблюм|rosenblum/ui', $t);
        $isMcConnell = (bool)preg_match('/макконн?ел+|mc ?connell/ui', $t);
        $isOlympiad = (bool)preg_match('/олимпиад|olympiad/ui', $t);
        $isChampCup = (bool)preg_match('/кубок\s+европейск\w*\s+чемпион|european\s+champions.{0,3}\s+cup|champions.{0,3}\s+cup/ui', $t);
        $isBermuda = (bool)preg_match('/бермуд|bermuda/ui', $t);
        $isVenice = (bool)preg_match('/венеци|venice/ui', $t);
        $isWorld = (bool)preg_match('/\bчм\b|чемпионат\s+мира|world\s+champ/ui', $t);
        $isEuro = (bool)preg_match('/\bче\b|чемпионат\s+европ|european\s+champ/ui', $t);
        $isRuMain = (bool)preg_match('/чемпионат\s+росси|чр\b|russian\s+champ/ui', $t)
            || in_array($status, ['russian', 'main_russian'], true);
        $isRegional = (bool)preg_match('/региональн|\bрч\b|областн|краев/ui', $t)
            || $status === 'regional';

        if ($isRosenblum) {
            $key = 'w_rosenblum';
            $reason[] = 'Кубок Розенблюма';
        } elseif ($isMcConnell) {
            $key = 'w_mcconnell';
            $reason[] = 'Кубок Макконелл';
        } elseif ($isOlympiad) {
            $key = $isWomen ? 'olympiad_women' : 'olympiad_open';
            $reason[] = 'Олимпиада';
        } elseif ($isChampCup) {
            $key = 'eu_champions_cup';
            $reason[] = 'Кубок Европейских Чемпионов';
        } elseif ($isBermuda) {
            $key = 'w_bermuda';
            $reason[] = 'Бермудский кубок';
        } elseif ($isVenice) {
            $key = 'w_venice';
            $reason[] = 'Кубок Венеции';
        } elseif ($isWorld) {
            if ($isJunior) {
                $key = 'w_junior';
                $reason[] = 'ЧМ юниоры';
            } elseif ($isMixed && $isTeam) {
                $key = $isPlayoff ? 'w_mixed_team_po' : 'w_mixed_team';
                $reason[] = 'ЧМ микст командный' . ($isPlayoff ? ' (с плей-офф)' : ' (без плей-офф)');
            } elseif ($isMixed && $isPair) {
                $key = 'w_mixed_pair';
                $reason[] = 'ЧМ микст парный';
            } elseif ($isWomen && $isPair) {
                $key = 'w_women_pair';
                $reason[] = 'ЧМ женский парный';
            } elseif ($isPair) {
                $key = 'w_pair';
                $reason[] = 'ЧМ парный';
            } else {
                $key = 'w_other';
                $reason[] = 'ЧМ прочее';
            }
        } elseif ($isEuro) {
            if ($isJunior) {
                $key = 'eu_junior';
            } elseif ($isOpen && $isTeam && $isWomen) {
                $key = 'eu_open_women_team';
            } elseif ($isOpen && $isTeam) {
                $key = 'eu_open_team';
            } elseif ($isMixed && $isTeam) {
                $key = 'eu_mixed_team';
            } elseif ($isMixed && $isPair) {
                $key = 'eu_mixed_pair';
            } elseif ($isWomen && $isTeam) {
                $key = 'eu_women_team';
            } elseif ($isWomen && $isPair) {
                $key = 'eu_women_pair';
            } elseif ($isTeam) {
                $key = 'eu_team';
            } else {
                $key = 'eu_pair';
            }
            $reason[] = 'чемпионат Европы / ЧЕ';
        } elseif ($isCup) {
            $key = 'ru_cup';
            $reason[] = 'Кубок России';
        } elseif ($isRuMain) {
            if ($isJunior) {
                $key = 'ru_junior';
            } elseif ($isMixed) {
                $key = 'ru_mixed';
            } elseif ($isWomen && $isPair) {
                $key = 'ru_women_pair';
            } elseif ($isInd) {
                $key = 'ru_individual';
            } elseif ($isTeam) {
                $key = 'ru_team';
            } else {
                $key = 'ru_pair';
            }
            $reason[] = 'чемпионат России / статус ЧР';
        } else {
            $key = 'none';
            if ($isRegional) {
                $reason[] = 'региональный — гарантированных ПБ по умолчанию нет';
            } else {
                $reason[] = 'обычный рейтинговый / без спецстатуса';
            }
        }

        $label = RatingCalculator::GUARANTEED_PB_LABELS[$key] ?? $key;
        $confidence = $key === 'none' ? 'low' : (count($reason) ? 'medium' : 'low');
        if (preg_match('/чемпионат\s+росси|бермуд|венеци|кубок\s+росси|розенблюм|макконн|олимпиад/ui', $t)) {
            $confidence = 'high';
        }

        return [
            'key' => $key,
            'label' => $label,
            'confidence' => $confidence,
            'reason' => implode('; ', $reason),
        ];
    }

    /**
     * Угадать статус турнира для формул RC.
     * @return array{status:string,reason:string}
     */
    /**
     * Статус по названию и фактической длине d.
     * 16–47: нерейтинговый; 48–65 (пары)/87 (команды): экспресс;
     * длиннее: рейтинговый (или ЧР/РЧ по названию).
     * @return array{status:string,reason:string}
     */
    public static function suggestStatus(string $title, string $fallback = 'rating', ?float $d = null, string $format = 'pair'): array
    {
        $t = self::lower($title);
        // сначала спец. статусы по названию (если длина позволяет «рейтинговую» зону)
        $named = null;
        if (preg_match('/основн\w*\s+чемпионат\s+росси/ui', $t) || preg_match('/чемпионат\s+росси\w*\s+основ/ui', $t)) {
            $named = ['status' => 'main_russian', 'reason' => 'основной ЧР по названию'];
        } elseif (preg_match('/чемпионат\s+росси/ui', $t) || preg_match('/\bчр\b/ui', $t)) {
            $named = ['status' => 'russian', 'reason' => 'ЧР по названию'];
        } elseif (preg_match('/региональн/ui', $t) || preg_match('/чемпионат\s+(области|края|республик)/ui', $t)) {
            $named = ['status' => 'regional', 'reason' => 'региональный по названию'];
        } elseif (preg_match('/экспресс/ui', $t)) {
            $named = ['status' => 'express', 'reason' => 'экспресс по названию'];
        }

        $dMaxExpress = ($format === 'team') ? 87.0 : 65.0;
        if ($d !== null && $d > 0) {
            if ($d >= 16 && $d <= 47) {
                return ['status' => 'non_rating', 'reason' => "фактическая длина {$d} (16–47) → только МБ"];
            }
            if ($d >= 48 && $d <= $dMaxExpress) {
                if ($named && $named['status'] === 'express') {
                    return $named;
                }
                return ['status' => 'express', 'reason' => "фактическая длина {$d} (48–" . (int)$dMaxExpress . ") → экспресс"];
            }
            // d > dMaxExpress (или d < 16): рейтинговая зона
            if ($named && in_array($named['status'], ['main_russian', 'russian', 'regional', 'rating'], true)) {
                return $named;
            }
            if ($named && $named['status'] === 'express') {
                // название «экспресс», но длина уже рейтинговая
                return ['status' => 'rating', 'reason' => "длина {$d} выше экспресса → рейтинговый"];
            }
            return ['status' => 'rating', 'reason' => "фактическая длина {$d} → рейтинговый и выше"];
        }

        if ($named) {
            return $named;
        }
        return ['status' => $fallback, 'reason' => 'по умолчанию'];
    }

}
