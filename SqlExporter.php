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
    public static function escape(string $s): string
    {
        return str_replace(["\\", "'"], ["\\\\", "''"], $s);
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
    public static function build(array $calcOut, array $opts = []): string
    {
        $params = $calcOut['params'] ?? [];
        $results = $calcOut['results'] ?? [];
        $meta = $calcOut['meta'] ?? [];
        $format = $params['format'] ?? 'pair';

        $tournIdOpt = $opts['tourn_id'] ?? null;
        $fixedTourn = ($tournIdOpt !== null && $tournIdOpt !== '' && (is_int($tournIdOpt) || ctype_digit((string)$tournIdOpt)));
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

        if ($fixedTourn) {
            $tidSql = (string)(int)$tournIdOpt;
            $lines[] = "-- Замена данных существующего турнира tourn_id = {$tidSql}";
            $lines[] = "DELETE FROM tourn_pair WHERE tour_id = {$tidSql};";
            $lines[] = "DELETE FROM tourn_ses WHERE tour_id = {$tidSql} OR main_tour_id = {$tidSql};";
            $lines[] = "DELETE tp FROM team_players tp INNER JOIN tourn_team tt ON tt.team_id = tp.team_id WHERE tt.tour_id = {$tidSql};";
            $lines[] = "DELETE tn FROM team_players_nonqual tn INNER JOIN tourn_team tt ON tt.team_id = tn.team_id WHERE tt.tour_id = {$tidSql};";
            $lines[] = "DELETE t FROM teams t INNER JOIN tourn_team tt ON tt.team_id = t.team_id WHERE tt.tour_id = {$tidSql};";
            $lines[] = "DELETE FROM tourn_team WHERE tour_id = {$tidSql};";
            $lines[] = "DELETE FROM tourn_ind WHERE tour_id = {$tidSql};";
            $lines[] = "DELETE FROM tds WHERE tourn_id = {$tidSql};";
            $lines[] = "DELETE FROM tourn_header WHERE tourn_id = {$tidSql};";
            $lines[] = '';
        } else {
            $lines[] = 'SET @tourn_id = (SELECT IFNULL(MAX(tourn_id), 0) + 1 FROM tourn_header);';
            $tidSql = '@tourn_id';
        }

        $lines[] = 'INSERT INTO tourn_header (tourn_id, name, tour_date, tour_date_start, type, city_id, status, n_deals, champ_t, parent, stream, prev_id, next_id, note)';
        $lines[] = 'VALUES (';
        $lines[] = "  {$tidSql},";
        $lines[] = "  '" . self::escape($name) . "',";
        $lines[] = '  ' . self::sqlNull($dateTo) . ',';
        $lines[] = '  ' . self::sqlNull($dateFrom) . ',';
        $lines[] = "  {$type},";
        $lines[] = "  {$cityId},";
        $lines[] = "  {$status},";
        $lines[] = '  ' . self::sqlNull($d !== null ? (int)$d : null) . ',';
        $lines[] = "  {$champTSql},";
        $lines[] = '  NULL,';
        $lines[] = "  {$stream},";
        $lines[] = "  {$prevIdSql},"; // prev_id
        $lines[] = '  NULL,'; // next_id (проставит следующий турнир)
        $lines[] = '  NULL';
        $lines[] = ');';
        if ($prevIdInt !== null) {
            $lines[] = "-- Связка с предыдущим турниром prev_id={$prevIdInt}";
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
            $lines[] = '-- Пары (tpair_id=0 → auto_increment)';
            foreach ($results as $i => $r) {
                $players = $r['players'] ?? [];
                $p1 = $players[0]['player_id'] ?? null;
                $p2 = $players[1]['player_id'] ?? null;
                if ($p1 === null || $p2 === null) {
                    $lines[] = '-- SKIP rank ' . $r['rank'] . ' ' . self::escape($r['label'] ?? '') . ' (нет ID)';
                    continue;
                }
                [$placeH, $placeL] = $placeHL[$i] ?? [(int)$r['rank'], (int)$r['rank']];
                $ro = (int)($r['RO'] ?? 0);
                $pb = (int)($r['PB'] ?? 0);
                $mb = (int)($r['MB'] ?? 0);
                $resultSql = self::sqlNumber($opts['scores'][$i] ?? null);
                $p1 = (int)$p1;
                $p2 = (int)$p2;
                $lines[] = 'INSERT INTO tourn_pair (tpair_id, tour_id, player1, player2, PB, RO, MB, EMB, Result, PlaceH, PlaceL) VALUES (';
                $lines[] = "  0, {$tidSql}, {$p1}, {$p2}, {$pb}, {$ro}, {$mb}, 0, {$resultSql}, {$placeH}, {$placeL}";
                $lines[] = ');';
            }
            $lines[] = '';
                } elseif ($format === 'individual') {
            $lines[] = '-- Индивидуал (tind_id=0 → auto_increment; team_id = player_id)';
            foreach ($results as $i => $r) {
                $players = $r['players'] ?? [];
                $pl = $players[0] ?? null;
                $pid = is_array($pl) ? ($pl['player_id'] ?? null) : null;
                if ($pid === null || $pid === '' || is_array($pid)) {
                    $lines[] = '-- SKIP individual without ID: ' . self::escape($r['label'] ?? '');
                    continue;
                }
                $pid = (int)$pid;
                [$placeH, $placeL] = $placeHL[$i] ?? [(int)$r['rank'], (int)$r['rank']];
                $ro = (int)($r['RO'] ?? 0);
                $pb = (int)($r['PB'] ?? 0);
                $mb = (int)($r['MB'] ?? 0);
                $resultSql = self::sqlNumber($opts['scores'][$i] ?? null);
                $lines[] = 'INSERT INTO tourn_ind (tind_id, tour_id, team_id, PB, RO, MB, EMB, Result, PlaceH, PlaceL) VALUES (';
                $lines[] = "  0, {$tidSql}, {$pid}, {$pb}, {$ro}, {$mb}, 0, {$resultSql}, {$placeH}, {$placeL}";
                $lines[] = ');';
            }
            $lines[] = '';
        } elseif ($format === 'team') {
            $lines[] = '-- Команды (tteam_id=0 → auto_increment, team_id через переменную)';
            $lines[] = 'SET @team_id = (SELECT IFNULL(MAX(team_id), 0) FROM teams);';
            foreach ($results as $i => $r) {
                $players = $r['players'] ?? [];
                $nonCounting = $r['non_counting'] ?? [];
                [$placeH, $placeL] = $placeHL[$i] ?? [(int)$r['rank'], (int)$r['rank']];
                $ro = (int)($r['RO'] ?? 0);
                $pb = (int)($r['PB'] ?? 0);
                $mb = (int)($r['MB'] ?? 0);
                $resultSql = self::sqlNumber($opts['scores'][$i] ?? null);
                $teamName = is_array($r['label'] ?? null) ? '' : (string)($r['label'] ?? ('Команда ' . $r['rank']));

                $lines[] = 'SET @team_id = @team_id + 1;';
                $lines[] = "INSERT INTO teams (team_id, team_name) VALUES (@team_id, '" . self::escape($teamName) . "');";
                $lines[] = "INSERT INTO tourn_team (tteam_id, tour_id, team_id, PB, RO, MB, EMB, Result, PlaceH, PlaceL) VALUES (0, {$tidSql}, @team_id, {$pb}, {$ro}, {$mb}, 0, {$resultSql}, {$placeH}, {$placeL});";

                foreach ($players as $pl) {
                    if (!is_array($pl)) continue;
                    $pid = $pl['player_id'] ?? null;
                    if ($pid === null || $pid === '' || is_array($pid)) {
                        $lines[] = '-- SKIP player without ID in team ' . self::escape($teamName);
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
            $lines[] = '-- Сессии / этапы: header type=4 + tourn_ses';
            $lines[] = "DELETE FROM tourn_ses WHERE main_tour_id = {$tidSql};";
            $lines[] = "DELETE FROM tourn_header WHERE parent = {$tidSql};";
            $lines[] = 'SET @sess_id = (SELECT IFNULL(MAX(tourn_id), 0) FROM tourn_header);';
            foreach ($sessions as $sr) {
                if (!empty($sr['error'])) {
                    $lines[] = '-- SKIP ' . self::escape($sr['name'] ?? 'Сессия') . ': ' . self::escape((string)($sr['error'] ?? ''));
                    continue;
                }
                if (empty($sr['results'])) {
                    continue;
                }
                $sessName = $sr['name'] ?? 'Сессия';
                $boards = isset($sr['boards']) && is_numeric($sr['boards']) ? (int)$sr['boards'] : 'NULL';
                $lines[] = '-- ' . self::escape($sessName);
                $lines[] = 'SET @sess_id = @sess_id + 1;';
                $lines[] = 'INSERT INTO tourn_header (tourn_id, name, tour_date, tour_date_start, type, city_id, status, n_deals, champ_t, parent, stream, prev_id, next_id, note)';
                $lines[] = 'VALUES (';
                $lines[] = "  @sess_id,";
                $lines[] = "  '" . self::escape($sessName) . "',";
                $lines[] = '  ' . self::sqlNull($dateTo) . ',';
                $lines[] = '  ' . self::sqlNull($dateFrom) . ',';
                $lines[] = '  4,'; // сессия
                $lines[] = "  {$cityId},";
                $lines[] = "  {$status},";
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
        $lines[] = '-- Параметры расчёта:';
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                $vs = $v ? 'true' : 'false';
            } elseif (is_scalar($v) || $v === null) {
                $vs = (string)$v;
            } else {
                $vs = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $lines[] = '--   ' . $k . ' = ' . $vs;
        }
        return implode("\n", $lines);
    }
}
