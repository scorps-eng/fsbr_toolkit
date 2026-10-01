<?php
declare(strict_types=1);

/**
 * Генерация SQL для записи результатов турнира в fsbr_copy.
 *
 * Пары: tourn_header (type=2) + tourn_pair (tpair_id=0 → AI)
 * Команды: tourn_header (type=3) + teams + team_players [+ nonqual] + tourn_team
 * results НЕ трогаем — обновляется месячными скриптами
 */
class SqlExporter
{
        /**
     * Убрать переводы строк (Excel Alt+Enter, CR/LF, Unicode LS/PS).
     * Иначе хвост после перевода строки в '-- SKIP …' становится исполняемым SQL.
     */
    public static function oneLine(string $s): string
    {
        // невалидный UTF-8 → preg_replace(/u) вернул бы null и перевод строки уцелел бы
        $s = (string)@iconv('UTF-8', 'UTF-8//IGNORE', $s);
        $s = str_replace(["\r", "\n"], ' ', $s);
        $s = preg_replace('/\R+/u', ' ', $s) ?? $s;
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    public static function escape(string $s): string
    {
        $s = self::oneLine($s);
        return str_replace(["\\", "'"], ["\\\\", "''"], $s);
    }

    /** Текст для SQL-комментария: одна строка, без -- / /* */
    public static function commentText($s): string
    {
        $s = self::oneLine((string)$s);
        $s = str_replace(['--', '/*', '*/'], ['—', '', ''], $s);
        return $s;
    }

public static function sqlNull($v): string
    {
        if ($v === null || $v === '') return 'NULL';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_int($v) || is_float($v)) {
            if (is_float($v) && (is_nan($v) || is_infinite($v))) return 'NULL';
            return (string)$v;
        }
        if (is_array($v)) {
            return 'NULL';
        }
        return "'" . self::escape((string)$v) . "'";
    }

    /** Число для SQL или NULL; Result округляем до 2 знаков */
    public static function sqlNumber($v, int $decimals = 2): string
    {
        if ($v === null || $v === '' || $v === 'NULL') return 'NULL';
        if (is_array($v)) return 'NULL';
        if (is_numeric($v)) {
            $n = 0 + $v;
            if ($decimals >= 0) {
                return number_format(round($n, $decimals), $decimals, '.', '');
            }
            return (string)$n;
        }
        return 'NULL';
    }

    public static function sqlInt($v): string
    {
        if ($v === null || $v === '' || $v === 'NULL') return 'NULL';
        if (is_array($v)) return 'NULL';
        if (is_numeric($v)) return (string)(int)$v;
        return 'NULL';
    }

    /**
     * @param array $calcOut результат RatingCalculator::compute() + meta
     * @param array $opts tourn_id?, city_id?, champ_t?, status?, stream?, result scores from JSON
     */

    /** type: 1 individ, 2 pair, 3 team, 4 session, 5 club MB */
    public static function formatToType(string $format): int
    {
        return match ($format) {
            'team' => 3,
            'individual' => 1,
            'club_mb' => 5,
            default => 2,
        };
    }

    public static function typeLabel(int $type): string
    {
        return match ($type) {
            1 => 'индивидуальный',
            2 => 'парный',
            3 => 'командный',
            4 => 'сессия',
            5 => 'клубные МБ',
            default => 'type=' . $type,
        };
    }

    /** @return array{tourn_id:int,name:?string,type:?int,type_label:string,tour_date:?string}|null */
    public static function fetchHeader(\mysqli $db, int $tournId): ?array
    {
        $st = $db->prepare('SELECT tourn_id, name, type, tour_date, prev_id, next_id, stream, champ_t, note FROM tourn_header WHERE tourn_id = ? LIMIT 1');
        if (!$st) {
            return null;
        }
        $st->bind_param('i', $tournId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            return null;
        }
        $type = isset($row['type']) ? (int)$row['type'] : null;
        // сколько заголовков сессий висит на этом турнире (они будут удалены при замене)
        $sessCnt = 0;
        $st2 = $db->prepare('SELECT COUNT(*) FROM tourn_header WHERE parent = ? AND type = 4');
        if ($st2) {
            $st2->bind_param('i', $tournId);
            $st2->execute();
            $st2->bind_result($sessCnt);
            $st2->fetch();
            $st2->close();
        }
        return [
            'tourn_id' => (int)$row['tourn_id'],
            'name' => $row['name'] ?? null,
            'type' => $type,
            'type_label' => $type === null ? '—' : self::typeLabel($type),
            'tour_date' => $row['tour_date'] ?? null,
            'prev_id' => $row['prev_id'] ?? null,
            'next_id' => $row['next_id'] ?? null,
            'stream' => $row['stream'] ?? null,
            'champ_t' => $row['champ_t'] ?? null,
            'note' => $row['note'] ?? null,
            'sessions' => (int)$sessCnt,
        ];
    }

    /**
     * Строки SQL, берущие advisory-lock на выдачу tourn_id (до конца соединения, т.е. после COMMIT).
     * Без этого два одновременных импорта получают одинаковый MAX(tourn_id)+1.
     * Если lock не получен за 30 с — продолжаем как раньше (худший случай — конфликт PK и ROLLBACK, данные не портятся).
     * @return string[]
     */
    public static function lockLines(): array
    {
        return [
            "SET @lk = GET_LOCK('fsbr_tourn_id', 30);",
        ];
    }

    /** SET @tourn_id = следующий свободный номер (под lock). @return string[] */
    public static function allocTournIdLines(): array
    {
        return array_merge(self::lockLines(), [
            'SET @tourn_id = (SELECT IFNULL(MAX(tourn_id), 0) + 1 FROM tourn_header);',
        ]);
    }

    /**
     * Проверка перед перезаписью. $allowMissing=true — tourn_id можно как новый.
     * @return array{exists:bool,header:?array,preview:string}
     */
    public static function checkOverwrite(\mysqli $db, int $tournId, int $expectedType, bool $allowMissing = false): array
    {
        $h = self::fetchHeader($db, $tournId);
        if ($h === null) {
            if ($allowMissing) {
                return [
                    'exists' => false,
                    'header' => null,
                    'preview' => "tourn_id={$tournId} в базе нет — будет создан как новый с этим ID",
                ];
            }
            throw new InvalidArgumentException("Турнир tourn_id={$tournId} не найден в базе");
        }
        $t = $h['type'];
        if ($t !== null && (int)$t !== (int)$expectedType) {
            throw new InvalidArgumentException(
                "tourn_id={$tournId} — «{$h['name']}» ({$h['type_label']}), ожидается "
                . self::typeLabel($expectedType)
                . '. Перезапись чужого типа запрещена.'
            );
        }
        $keep = [];
        foreach (['next_id' => 'next_id', 'prev_id' => 'prev_id', 'stream' => 'stream', 'champ_t' => 'champ_t'] as $k => $lbl) {
            if (isset($h[$k]) && $h[$k] !== null && $h[$k] !== '') {
                $keep[] = "{$lbl}={$h[$k]}";
            }
        }
        if (!empty($h['note'])) {
            $keep[] = 'note';
        }
        $preview = "Будет заменён tourn_id={$tournId}: «{$h['name']}» ({$h['type_label']})"
            . ($h['tour_date'] ? ", дата {$h['tour_date']}" : '')
            . ($keep ? '. Сохранятся (если не заданы в форме): ' . implode(', ', $keep) : '')
            . ($h['sessions'] > 0 ? ". Будут удалены заголовки сессий: {$h['sessions']}" : '');
        return ['exists' => true, 'header' => $h, 'preview' => $preview];
    }

    public static function build(array $calcOut, array $opts = []): string
    {
        $params = $calcOut['params'] ?? [];
        $results = $calcOut['results'] ?? [];
        $meta = $calcOut['meta'] ?? [];
        $format = $params['format'] ?? 'pair';

        $tournIdOpt = $opts['tourn_id'] ?? null;
        $fixedTourn = ($tournIdOpt !== null && $tournIdOpt !== '' && (is_int($tournIdOpt) || ctype_digit((string)$tournIdOpt)) && (int)$tournIdOpt >= 1);
        if ($tournIdOpt !== null && $tournIdOpt !== '' && !$fixedTourn) {
            throw new InvalidArgumentException('tourn_id должен быть целым числом ≥ 1');
        }
        $useVar = !$fixedTourn;

        $name = $opts['name'] ?? ($meta['title'] ?? 'Турнир');
        if ($name === '') {
            $name = $meta['title'] ?? 'Турнир';
        }
        $dateFrom = $opts['date_from'] ?? ($meta['date_from'] ?? null);
        if ($dateFrom === '') $dateFrom = $meta['date_from'] ?? null;
        $dateTo = $opts['date_to'] ?? ($meta['date_to'] ?? $dateFrom);
        if ($dateTo === '') $dateTo = $meta['date_to'] ?? $dateFrom;
        $d = $params['d'] ?? ($meta['length_actual'] ?? $meta['length'] ?? null);
        $cityId = $opts['city_id'] ?? 'NULL';
        $champTRaw = $opts['champ_t'] ?? null;
        if ($champTRaw === null || $champTRaw === '' || $champTRaw === 'NULL') {
            $champTSql = 'NULL';
        } else {
            $champTSql = (string)(int)$champTRaw;
        }
        $status = $opts['status_db'] ?? 'NULL';
        $stream = $opts['stream'] ?? 'NULL';
        $prevIdRaw = $opts['prev_id'] ?? null;
        if ($prevIdRaw === null || $prevIdRaw === '' || $prevIdRaw === 'NULL') {
            $prevIdSql = 'NULL';
            $prevIdInt = null;
        } else {
            $prevIdInt = (int)$prevIdRaw;
            $prevIdSql = (string)$prevIdInt;
        }

        $type = match ($format) {
            'team' => 3,
            'individual' => 1,
            default => 2,
        };

        $lines = [];
        $lines[] = '-- SQL вставки результатов турнира (сгенерировано автоматически)';
        $lines[] = '-- results не обновляем (месячные скрипты); tpair_id/tteam_id = 0 (auto_increment)';
        $lines[] = '-- champ_t = ' . $champTSql;
        $lines[] = 'START TRANSACTION;';
        $lines[] = '';

        // при замене: пустые поля формы берём из старой записи, служебные (next_id, note, parent) — всегда
        $vCity = $cityId; $vStatus = $status; $vStream = $stream; $vChamp = $champTSql;
        $vPrev = $prevIdSql; $vNext = 'NULL'; $vNote = 'NULL'; $vParent = 'NULL';
        if ($fixedTourn) {
            $tidSql = (string)(int)$tournIdOpt;
            $lines[] = '-- Сохраняем поля старой записи (связи серии, note, parent)';
            foreach (['next_id', 'note', 'parent', 'prev_id', 'stream', 'champ_t', 'status', 'city_id'] as $col) {
                $lines[] = "SET @old_{$col} = (SELECT {$col} FROM tourn_header WHERE tourn_id = {$tidSql} AND type = {$type});";
            }
            $vNext = '@old_next_id'; $vNote = '@old_note'; $vParent = '@old_parent';
            if ($vCity === 'NULL') $vCity = '@old_city_id';
            if ($vStatus === 'NULL') $vStatus = '@old_status';
            if ($vStream === 'NULL') $vStream = '@old_stream';
            if ($vChamp === 'NULL') $vChamp = '@old_champ_t';
            if ($vPrev === 'NULL') $vPrev = '@old_prev_id';
            $lines[] = '';
            // results не трогаем. Удаление только если type совпадает.
            $g = " AND EXISTS (SELECT 1 FROM tourn_header h WHERE h.tourn_id = {$tidSql} AND h.type = {$type})";
            $lines[] = "DELETE FROM tourn_pair WHERE tour_id = {$tidSql}" . $g . ";";
            $lines[] = "DELETE FROM tourn_ses WHERE (tour_id = {$tidSql} OR main_tour_id = {$tidSql})" . $g . ";";
            $lines[] = "DELETE tp FROM team_players tp INNER JOIN tourn_team tt ON tt.team_id = tp.team_id WHERE tt.tour_id = {$tidSql}" . $g . ";";
            $lines[] = "DELETE tn FROM team_players_nonqual tn INNER JOIN tourn_team tt ON tt.team_id = tn.team_id WHERE tt.tour_id = {$tidSql}" . $g . ";";
            $lines[] = "DELETE t FROM teams t INNER JOIN tourn_team tt ON tt.team_id = t.team_id WHERE tt.tour_id = {$tidSql}" . $g . ";";
            $lines[] = "DELETE FROM tourn_team WHERE tour_id = {$tidSql}" . $g . ";";
            $lines[] = "DELETE FROM tourn_ind WHERE tour_id = {$tidSql}" . $g . ";";
            $lines[] = "DELETE FROM tds WHERE tourn_id = {$tidSql}" . $g . ";";
            // заголовки сессий удаляем всегда (иначе старые сессии прилипнут к новой версии турнира)
            // (MySQL не разрешает подзапрос к той же таблице в DELETE — проверяем тип через переменную)
            $lines[] = "SET @main_ok = (SELECT COUNT(*) FROM tourn_header WHERE tourn_id = {$tidSql} AND type = {$type});";
            $lines[] = "DELETE FROM tourn_header WHERE parent = {$tidSql} AND type = 4 AND @main_ok = 1;";
            $lines[] = "DELETE FROM tourn_header WHERE tourn_id = {$tidSql} AND type = {$type};";
            $lines[] = '';
        } else {
            foreach (self::allocTournIdLines() as $ln) {
                $lines[] = $ln;
            }
            $tidSql = '@tourn_id';
        }

        $lines[] = 'INSERT INTO tourn_header (tourn_id, name, tour_date, tour_date_start, type, city_id, status, n_deals, champ_t, parent, stream, prev_id, next_id, note)';
        $lines[] = 'VALUES (';
        $lines[] = "  {$tidSql},";
        $lines[] = "  '" . self::escape($name) . "',";
        $lines[] = '  ' . self::sqlNull($dateTo) . ',';
        $lines[] = '  ' . self::sqlNull($dateFrom) . ',';
        $lines[] = "  {$type},";
        $lines[] = "  {$vCity},";
        $lines[] = "  {$vStatus},";
        $lines[] = '  ' . self::sqlNull($d !== null ? (int)$d : null) . ',';
        $lines[] = "  {$vChamp},";
        $lines[] = "  {$vParent},";
        $lines[] = "  {$vStream},";
        $lines[] = "  {$vPrev},"; // prev_id
        $lines[] = "  {$vNext},"; // next_id: у нового NULL (проставит следующий турнир), при замене — старый
        $lines[] = "  {$vNote}";
        $lines[] = ');';
        if ($prevIdInt !== null) {
            if ($fixedTourn) {
                // prev мог смениться: снять устаревшие ссылки next_id на этот турнир
                $lines[] = "UPDATE tourn_header SET next_id = NULL WHERE next_id = {$tidSql} AND tourn_id <> {$prevIdInt};";
            }
            $lines[] = "UPDATE tourn_header SET next_id = {$tidSql} WHERE tourn_id = {$prevIdInt};";
        }
        $lines[] = '';

        // group ranks for PlaceH/PlaceL
        $byRank = [];
        foreach ($results as $i => $r) {
            $byRank[(int)$r['rank']][] = $i;
        }
        $placeHL = [];
        foreach ($byRank as $rank => $idxs) {
            $cnt = count($idxs);
            $h = $rank;
            $l = $rank + $cnt - 1;
            foreach ($idxs as $ii) {
                $placeHL[$ii] = [$h, $l];
            }
        }

        if ($format === 'pair') {
                foreach ($results as $i => $r) {
                $players = $r['players'] ?? [];
                $p1 = $players[0]['player_id'] ?? null;
                $p2 = $players[1]['player_id'] ?? null;
                if ($p1 === null || $p2 === null) {
                    $lines[] = '-- SKIP rank ' . $r['rank'] . ' ' . self::commentText($r['label'] ?? '') . ' (нет ID)';
                    continue;
                }
                [$placeH, $placeL] = $placeHL[$i] ?? [(int)$r['rank'], (int)$r['rank']];
                $ro = (int)($r['RO'] ?? 0);
                $pb = (int)($r['PB'] ?? 0);
                $mb = (int)($r['MB'] ?? 0);
                $resultSql = self::sqlNumber($r['result'] ?? ($opts['scores'][$i] ?? null));
                $p1 = (int)$p1;
                $p2 = (int)$p2;
                $lines[] = 'INSERT INTO tourn_pair (tpair_id, tour_id, player1, player2, PB, RO, MB, EMB, Result, PlaceH, PlaceL) VALUES (';
                $lines[] = "  0, {$tidSql}, {$p1}, {$p2}, {$pb}, {$ro}, {$mb}, 0, {$resultSql}, {$placeH}, {$placeL}";
                $lines[] = ');';
            }
            $lines[] = '';
                } elseif ($format === 'individual') {
                foreach ($results as $i => $r) {
                $players = $r['players'] ?? [];
                $pl = $players[0] ?? null;
                $pid = is_array($pl) ? ($pl['player_id'] ?? null) : null;
                if ($pid === null || $pid === '' || is_array($pid)) {
                    $lines[] = '-- SKIP individual without ID: ' . self::commentText($r['label'] ?? '');
                    continue;
                }
                $pid = (int)$pid;
                [$placeH, $placeL] = $placeHL[$i] ?? [(int)$r['rank'], (int)$r['rank']];
                $ro = (int)($r['RO'] ?? 0);
                $pb = (int)($r['PB'] ?? 0);
                $mb = (int)($r['MB'] ?? 0);
                $resultSql = self::sqlNumber($r['result'] ?? ($opts['scores'][$i] ?? null));
                $lines[] = 'INSERT INTO tourn_ind (tind_id, tour_id, team_id, PB, RO, MB, EMB, Result, PlaceH, PlaceL) VALUES (';
                $lines[] = "  0, {$tidSql}, {$pid}, {$pb}, {$ro}, {$mb}, 0, {$resultSql}, {$placeH}, {$placeL}";
                $lines[] = ');';
            }
            $lines[] = '';
        } elseif ($format === 'team') {
                $lines[] = 'SET @team_id = (SELECT IFNULL(MAX(team_id), 0) FROM teams);';
            foreach ($results as $i => $r) {
                $players = $r['players'] ?? [];
                $nonCounting = $r['non_counting'] ?? [];
                [$placeH, $placeL] = $placeHL[$i] ?? [(int)$r['rank'], (int)$r['rank']];
                $ro = (int)($r['RO'] ?? 0);
                $pb = (int)($r['PB'] ?? 0);
                $mb = (int)($r['MB'] ?? 0);
                $resultSql = self::sqlNumber($r['result'] ?? ($opts['scores'][$i] ?? null));
                $teamName = is_array($r['label'] ?? null) ? '' : (string)($r['label'] ?? ('Команда ' . $r['rank']));

                $lines[] = 'SET @team_id = @team_id + 1;';
                $lines[] = "INSERT INTO teams (team_id, team_name) VALUES (@team_id, '" . self::escape($teamName) . "');";
                $lines[] = "INSERT INTO tourn_team (tteam_id, tour_id, team_id, PB, RO, MB, EMB, Result, PlaceH, PlaceL) VALUES (0, {$tidSql}, @team_id, {$pb}, {$ro}, {$mb}, 0, {$resultSql}, {$placeH}, {$placeL});";

                foreach ($players as $pl) {
                    if (!is_array($pl)) continue;
                    $pid = $pl['player_id'] ?? null;
                    if ($pid === null || $pid === '' || is_array($pid)) {
                        $lines[] = '-- SKIP player without ID in team ' . self::commentText($teamName);
                        continue;
                    }
                    $pid = (int)$pid;
                    $lines[] = "INSERT INTO team_players (team_id, player_id) VALUES (@team_id, {$pid});";
                }
                foreach ($nonCounting as $pl) {
                    if (!is_array($pl)) continue;
                    $pid = $pl['player_id'] ?? null;
                    if ($pid === null || $pid === '' || is_array($pid)) continue;
                    $pid = (int)$pid;
                    $lines[] = "INSERT INTO team_players_nonqual (team_id, player_id) VALUES (@team_id, {$pid});";
                }
                $lines[] = '';
            }
        }


        // сессии: отдельный tourn_header (type=4, parent=main) + tourn_ses
        // tour_id = id сессии, main_tour_id = id основного турнира
        $sessions = $calcOut['sessions'] ?? [];
        if ($sessions && ($format === 'pair' || $format === 'individual')) {
                $lines[] = "DELETE FROM tourn_ses WHERE main_tour_id = {$tidSql};";
            $lines[] = "DELETE FROM tourn_header WHERE parent = {$tidSql} AND type = 4;";
            foreach (self::lockLines() as $ln) {
                $lines[] = $ln;
            }
            $lines[] = 'SET @sess_id = (SELECT IFNULL(MAX(tourn_id), 0) FROM tourn_header);';
            foreach ($sessions as $sr) {
                if (!empty($sr['error'])) {
                    $lines[] = '-- SKIP ' . self::commentText($sr['name'] ?? 'Сессия') . ': ' . self::commentText((string)($sr['error'] ?? ''));
                    continue;
                }
                if (empty($sr['results'])) {
                    continue;
                }
                $sessName = $sr['name'] ?? 'Сессия';
                $boards = isset($sr['boards']) && is_numeric($sr['boards']) ? (int)$sr['boards'] : 'NULL';
                $lines[] = 'SET @sess_id = @sess_id + 1;';
                $lines[] = 'INSERT INTO tourn_header (tourn_id, name, tour_date, tour_date_start, type, city_id, status, n_deals, champ_t, parent, stream, prev_id, next_id, note)';
                $lines[] = 'VALUES (';
                $lines[] = "  @sess_id,";
                $lines[] = "  '" . self::escape($sessName) . "',";
                $lines[] = '  ' . self::sqlNull($dateTo) . ',';
                $lines[] = '  ' . self::sqlNull($dateFrom) . ',';
                $lines[] = '  4,'; // сессия
                $lines[] = "  {$vCity},";
                $lines[] = "  {$vStatus},";
                $lines[] = "  {$boards},";
                $lines[] = '  NULL,'; // champ_t сессии
                $lines[] = "  {$tidSql},"; // parent = основной турнир
                $lines[] = '  NULL,'; // stream сессии ВСЕГДА NULL
                $lines[] = '  NULL,'; // prev_id
                $lines[] = '  NULL,'; // next_id
                $lines[] = '  NULL';
                $lines[] = ');';

                $byRankS = [];
                foreach ($sr['results'] as $ii => $rr) {
                    $byRankS[(int)($rr['rank'] ?? 0)][] = $ii;
                }
                $placeHLS = [];
                foreach ($byRankS as $rank => $idxs) {
                    $cnt = count($idxs);
                    $h = $rank;
                    $l = $rank + $cnt - 1;
                    foreach ($idxs as $jj) {
                        $placeHLS[$jj] = [$h, $l];
                    }
                }

                foreach ($sr['results'] as $ii => $rr) {
                    $players = $rr['players'] ?? [];
                    $p1 = $players[0]['player_id'] ?? null;
                    $p2 = $players[1]['player_id'] ?? null;
                    if ($p1 === null || $p1 === '') {
                        $lines[] = '-- SKIP without ID rank ' . ($rr['rank'] ?? '');
                        continue;
                    }
                    // индивидуал: player2 = 0
                    if ($format === 'individual') {
                        $p2 = 0;
                    } elseif ($p2 === null || $p2 === '') {
                        $lines[] = '-- SKIP pair without second ID rank ' . ($rr['rank'] ?? '');
                        continue;
                    }
                    $mb = (int)($rr['MB'] ?? 0);
                    [$ph, $pl] = $placeHLS[$ii] ?? [(int)($rr['rank'] ?? 0), (int)($rr['rank'] ?? 0)];
                    $res = self::sqlNumber($rr['result'] ?? null);
                    $lines[] = 'INSERT INTO tourn_ses (tses_id, tour_id, main_tour_id, player1, player2, MB, Result, PlaceH, PlaceL) VALUES (';
                    $lines[] = '  0, @sess_id, ' . $tidSql . ', ' . (int)$p1 . ', ' . (int)$p2 . ", {$mb}, {$res}, {$ph}, {$pl}";
                    $lines[] = ');';
                }
                $lines[] = '';
            }
        }

        $lines[] = 'COMMIT;';
        $lines[] = '';
        return implode("\n", $lines);
    }
}
