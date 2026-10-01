<?php
// check body
/**
 * Проверка отчёта парного турнира FSBR — чистый PHP (без Python).
 * Для виртуального хостинга Windows/Linux.
 *
 * Формат файла: .xlsx (Excel → «Сохранить как» → Книга Excel).
 * Старый .xls: откройте в Excel и сохраните как .xlsx.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/names.php';
require_once __DIR__ . '/XlsReader.php';
require_once __DIR__ . '/ClubMb.php';


function db(): mysqli {
    $m = db_ro();
    if ($m === null) {
        throw new RuntimeException('Нет подключения к базе данных (проверьте config.php и доступность сервера БД)');
    }
    return $m;
}

function get_player(mysqli $db, int $id): ?array {
    $st = $db->prepare(
        'SELECT p.player_id, p.firstname AS family, p.lastname AS given_name, p.surname AS patronymic,
                p.razr, p.state, p.city_id, c.city_name, c.razr_coeff
         FROM players p
         LEFT JOIN cities c ON c.city_id = p.city_id
         WHERE p.player_id = ?'
    );
    if (!$st) {
        throw new RuntimeException('Ошибка запроса к таблице players (проверьте права учётки БД)');
    }
    $st->bind_param('i', $id);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();
    return $res ?: null;
}

/**
 * @return array{0:?float,1:?string} [q, error message]
 */
function q_from_razr_checked($razr, $razrCoeff = null): array
{
    if ($razr === null || $razr === '') {
        return [null, 'нет разряда (razr)'];
    }
    $r = (float)$razr;
    if ($r >= -10.0 && $r <= 10.0) {
        return [$r / 2.0, null];
    }
    if ($r >= 90.0) {
        return [($r - 100.0) / 2.0, null];
    }
    if (abs($r - 30.0) < 1e-9) {
        if ($razrCoeff === null || $razrCoeff === '') {
            return [null, 'razr=30, у города нет razr_coeff'];
        }
        return [0.3 * (float)$razrCoeff, null];
    }
    return [null, 'недопустимый razr=' . $razr . ' (нужно −10…10, ≥90 или 30)'];
}

function q_from_razr($razr, $razrCoeff = null): ?float
{
    [$q, $err] = q_from_razr_checked($razr, $razrCoeff);
    return $q;
}

function state_label($state): string
{
    $s = $state === null || $state === '' ? null : (int)$state;
    return match ($s) {
        3 => 'умер (state=3)',
        4 => 'не активен (state=4)',
        1 => 'активен',
        0 => 'state=0',
        2 => 'state=2',
        default => $s === null ? '—' : ('state=' . $s),
    };
}

/** Поиск кандидатов в БД по фамилии (и опционально имени) */
function search_players_by_name(mysqli $db, string $reportName, int $limit = 8): array
{
    $tokens = tokenize_name($reportName);
    if (!$tokens) return [];
    $family = null;
    $given = null;
    // берём самый длинный токен как фамилию-кандидата
    $sorted = $tokens;
    usort($sorted, fn($a, $b) => mb_strlen(norm_token($b), 'UTF-8') <=> mb_strlen(norm_token($a), 'UTF-8'));
    $family = $sorted[0];
    foreach ($tokens as $tok) {
        if (norm_token($tok) !== norm_token($family) && mb_strlen(norm_token($tok), 'UTF-8') > 1) {
            $given = $tok;
            break;
        }
    }
    $famLike = norm_token($family) . '%';
    // firstname в БД = фамилия
    $st = $db->prepare('SELECT player_id, firstname AS family, lastname AS given_name, surname AS patronymic, razr FROM players WHERE firstname LIKE ? ORDER BY player_id LIMIT ?');
    if (!$st) {
        return []; // подсказки необязательны
    }
    $lim = $limit * 3;
    $st->bind_param('si', $famLike, $lim);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $out = [];
    foreach ($rows as $row) {
        if (names_match($reportName, $row['family'] ?? '', $row['given_name'] ?? '', $row['patronymic'] ?? '')) {
            $out[] = [
                'player_id' => (int)$row['player_id'],
                'db_fio' => trim(implode(' ', array_filter([$row['family'] ?? '', $row['given_name'] ?? '', $row['patronymic'] ?? '']))),
                'razr' => $row['razr'] ?? null,
            ];
        }
        if (count($out) >= $limit) break;
    }
    // если точного names_match мало — добавить просто по фамилии
    if (count($out) < 3) {
        foreach ($rows as $row) {
            $pid = (int)$row['player_id'];
            if (array_filter($out, fn($x) => $x['player_id'] === $pid)) continue;
            if (norm_token($row['family'] ?? '') === norm_token($family)) {
                $out[] = [
                    'player_id' => $pid,
                    'db_fio' => trim(implode(' ', array_filter([$row['family'] ?? '', $row['given_name'] ?? '', $row['patronymic'] ?? '']))),
                    'razr' => $row['razr'] ?? null,
                ];
            }
            if (count($out) >= $limit) break;
        }
    }
    return $out;
}

/** Read shared strings from xlsx */
function xlsx_shared_strings(ZipArchive $zip): array {
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) return [];
    $sx = @simplexml_load_string($xml);
    if (!$sx) return [];
    $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $out = [];
    foreach ($sx->si as $si) {
        if (isset($si->t)) {
            $out[] = (string)$si->t;
        } else {
            $text = '';
            foreach ($si->r as $r) {
                $text .= (string)$r->t;
            }
            $out[] = $text;
        }
    }
    return $out;
}

function xlsx_sheet_names(ZipArchive $zip): array {
    $xml = $zip->getFromName('xl/workbook.xml');
    if ($xml === false) return [];
    $wb = @simplexml_load_string($xml);
    if (!$wb) return [];
    $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $names = [];
    $i = 1;
    foreach ($wb->sheets->sheet as $sheet) {
        $attrs = $sheet->attributes();
        $names[(string)$attrs['name']] = 'xl/worksheets/sheet' . $i . '.xml';
        // better: use rels
        $i++;
    }
    // resolve real paths via rels
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($rels !== false) {
        $rx = @simplexml_load_string($rels);
        if ($rx) {
            $idToTarget = [];
            foreach ($rx->Relationship as $rel) {
                $a = $rel->attributes();
                $idToTarget[(string)$a['Id']] = (string)$a['Target'];
            }
            $names = [];
            foreach ($wb->sheets->sheet as $sheet) {
                $a = $sheet->attributes();
                $state = strtolower((string)($a['state'] ?? 'visible'));
                if ($state === 'hidden' || $state === 'veryhidden') {
                    continue;
                }
                $rAttr = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $rid = (string)($rAttr['id'] ?? '');
                $target = $idToTarget[$rid] ?? '';
                if ($target !== '') {
                    if (strpos($target, 'xl/') !== 0) {
                        $target = 'xl/' . ltrim($target, '/');
                    }
                    $names[(string)$a['name']] = $target;
                }
            }
        }
    }
    return $names;
}

function col_letter_to_index(string $cell): int {
    if (!preg_match('/^([A-Z]+)/', $cell, $m)) return 0;
    $letters = $m[1];
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/** @return list<list<string|null>> rows of cells */
function xlsx_read_sheet(ZipArchive $zip, string $sheetPath, array $shared): array {
    $xml = $zip->getFromName($sheetPath);
    if ($xml === false) return [];
    $ss = @simplexml_load_string($xml);
    if (!$ss) return [];
    $rows = [];
    foreach ($ss->sheetData->row as $row) {
        $rIdx = (int)$row['r'] - 1;
        while (count($rows) <= $rIdx) {
            $rows[] = [];
        }
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = col_letter_to_index($ref);
            $type = (string)($c['t'] ?? '');
            $val = null;
            if ($type === 's') {
                $si = (int)($c->v ?? 0);
                $val = $shared[$si] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)($c->is->t ?? '');
            } else {
                $val = isset($c->v) ? (string)$c->v : null;
            }
            while (count($rows[$rIdx]) <= $col) {
                $rows[$rIdx][] = null;
            }
            $rows[$rIdx][$col] = $val;
        }
    }
    return $rows;
}


function excel_serial_to_date($v): ?string
{
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n > 20000 && $n < 100000) {
            $ts = (int)round(($n - 25569) * 86400);
            return gmdate('Y-m-d', $ts);
        }
        return (string)$v;
    }
    $s = trim((string)$v);
    $s = preg_replace('/\s*г\.?\s*$/u', '', $s);
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s)) {
        return $s;
    }
    // "21 марта 2026"
    $months = [
        'января'=>1,'февраля'=>2,'марта'=>3,'апреля'=>4,'мая'=>5,'июня'=>6,
        'июля'=>7,'августа'=>8,'сентября'=>9,'октября'=>10,'ноября'=>11,'декабря'=>12,
    ];
    if (preg_match('/^(\d{1,2})\s+(\p{L}+)\s+(\d{4})/u', $s, $m)) {
        $mon = mb_strtolower($m[2], 'UTF-8');
        if (isset($months[$mon])) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], $months[$mon], (int)$m[1]);
        }
    }
    return $s;
}

/** Parse pair standings table from rows; returns list of pairs */

/**
 * Разбор ячейки места.
 * Поддерживается: 7, "=", "7-8", "7_8", "7–8", Excel-дата вместо 7-8.
 * Возвращает [rank:int|null, tied:bool, range_end:int|null]
 */
function parse_rank_cell($raw, $lastRank, $rawCell = null): array
{
    $s = trim((string)$raw);
    if ($s === '' && $rawCell !== null && $rawCell !== '') {
        // числовой raw (в т.ч. serial даты Excel)
        if (is_numeric($rawCell)) {
            $n = 0 + $rawCell;
            // Excel date serial: 1 = 1899-12-31; типичные «даты» из 7-8 → день/месяц
            // Если это «дата» с малой целой частью как день-месяц: берём через php
            if ($n >= 1 && $n < 100000) {
                // Попробуем интерпретировать как Excel serial → d-m
                $unix = (int)(($n - 25569) * 86400);
                if ($unix > 0) {
                    $d = (int)gmdate('j', $unix);
                    $m = (int)gmdate('n', $unix);
                    // 7-8 → 7 августа или 8 июля — оба разумны для дележки мест
                    if ($d >= 1 && $d <= 50 && $m >= 1 && $m <= 50 && $d !== $m) {
                        // эвристика: меньшее = начало диапазона мест
                        $a = min($d, $m);
                        $b = max($d, $m);
                        if ($b - $a <= 20 && $a >= 1) {
                            return [$a, true, $b];
                        }
                    }
                }
            }
            if (abs($n - (int)$n) < 1e-9) {
                $rk = (int)$n;
                return [$rk, false, null];
            }
        }
    }
    if ($s === '' ) {
        return [null, false, null];
    }
    if ($s === '=' || $s === '＝') {
        return [$lastRank, true, null];
    }
    // 7-8 / 7_8 / 7–8 / 7—8
    if (preg_match('/^(\d+)\s*[-_–—]\s*(\d+)$/u', $s, $m)) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        if ($a > $b) { $tmp = $a; $a = $b; $b = $tmp; }
        return [$a, true, $b];
    }
    if (is_numeric($s)) {
        return [(int)$s, false, null];
    }
    // Excel иногда отдаёт дату строкой "08.07.2026" или "7/8/26"
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})(?:[.\/]\d{2,4})?$/', $s, $m)) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        if ($a >= 1 && $b >= 1 && max($a, $b) <= 80 && $a !== $b) {
            return [min($a, $b), true, max($a, $b)];
        }
    }
    return [null, false, null];
}

/**
 * Определить раскладку таблицы результатов по строке заголовка.
 * pair: rk | Игрок1 | Игрок2 | id1 | id2 | Рез
 * individual: rk | Игрок1 | id1 | Рез
 */
function detect_results_layout(array $headerRow): string
{
    $cells = [];
    for ($i = 0; $i < 8; $i++) {
        $cells[$i] = mb_strtolower(cell($headerRow, $i));
    }
    $joined = implode(' ', $cells);
    // явный id2 → парный
    if (preg_match('/\bid2\b/u', $joined) || str_contains($joined, 'игрок 2') || str_contains($joined, 'player 2')) {
        return 'pair';
    }
    // individual: id1 сразу после имени (col2 = id1), Рез в col3
    if (preg_match('/\bid1\b/u', $cells[2] ?? '') || preg_match('/\bid\b/u', $cells[2] ?? '')) {
        return 'individual';
    }
    // если в col2 только «рез»/result — тоже индивидуал
    if (preg_match('/^рез|result|score|imp|vp/u', $cells[2] ?? '')) {
        return 'individual';
    }
    // если есть id1 в col3 — классическая парная таблица
    if (preg_match('/\bid1\b/u', $cells[3] ?? '')) {
        return 'pair';
    }
    return 'pair';
}

function parse_pairs_table(array $rows): array
{
    $headerRow = null;
    $headerIdx = null;
    foreach ($rows as $i => $row) {
        $c0 = mb_strtolower(cell($row, 0));
        $joined = mb_strtolower(implode(' ', array_map(fn($x) => (string)($x ?? ''), $row)));
        if ($c0 === 'rk' || strpos($joined, 'id1') !== false || preg_match('/\bid\b/u', $joined)) {
            $headerRow = $row;
            $headerIdx = $i;
            break;
        }
    }
    if ($headerIdx === null) return [];
    $layout = detect_results_layout($headerRow);
    $pairs = [];
    $lastRank = null;
    for ($i = $headerIdx + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        if ($layout === 'individual') {
            $name1 = cell($row, 1);
            $name2 = '';
            $id1raw = cell($row, 2);
            $id2raw = '';
            $resRaw = $row[3] ?? null;
            if ($name1 === '') continue;
            // строка-подсказка без места/результата
            if (($id1raw === '' || !is_numeric($id1raw)) && ($resRaw === null || $resRaw === '')) {
                // возможно имя в col1, но это не результат — пропуск пустых
                if (!is_numeric(cell($row, 0)) && cell($row, 0) !== '=' && cell($row, 0) !== '') {
                    // не строка таблицы
                }
            }
        } else {
            $name1 = cell($row, 1);
            $name2 = cell($row, 2);
            if ($name1 === '' && $name2 === '') continue;
            $id1raw = cell($row, 3);
            $id2raw = cell($row, 4);
            $resRaw = $row[5] ?? null;
        }
        $result = null;
        if ($resRaw !== null && $resRaw !== '' && is_numeric($resRaw)) {
            $result = 0 + $resRaw;
        } elseif ($layout === 'individual' && cell($row, 3) !== '' && is_numeric(cell($row, 3))) {
            $result = 0 + cell($row, 3);
        } elseif ($layout === 'pair' && cell($row, 5) !== '' && is_numeric(cell($row, 5))) {
            $result = 0 + cell($row, 5);
        }
        $rkRaw = trim(cell($row, 0));
        $rawCell0 = $row[0] ?? null;
        [$rk, $rankTied, $rankEnd] = parse_rank_cell($rkRaw, $lastRank, $rawCell0);
        if ($rk !== null) {
            $lastRank = $rk;
        }
        if ($name1 === '' && $name2 === '') {
            continue;
        }
        // для индивидуала: если нет ни id ни result ни rank — мусор
        if ($layout === 'individual' && $name1 !== '' && $id1raw === '' && $result === null && $rk === null) {
            continue;
        }
        $entry = [
            'rank' => $rk,
            'name1' => $name1,
            'name2' => $name2,
            'id1' => ($id1raw !== '' && is_numeric($id1raw)) ? (int)$id1raw : null,
            'id2' => ($id2raw !== '' && is_numeric($id2raw)) ? (int)$id2raw : null,
            'result' => $result,
            'layout' => $layout,
        ];
        if ($rankTied) {
            $entry['rank_tied'] = true;
        }
        if ($rankEnd !== null) {
            $entry['rank_end'] = $rankEnd;
        }
        $pairs[] = $entry;
    }
    return $pairs;
}

function results_table_is_individual(array $pairs): bool
{
    if (!$pairs) return false;
    $ind = 0;
    $pair = 0;
    foreach ($pairs as $p) {
        if (($p['layout'] ?? '') === 'individual') $ind++;
        else $pair++;
    }
    return $ind > $pair;
}


/** Лист этапа парного турнира (не Sum, не мета) */
function is_pair_stage_sheet(string $name): bool
{
    $n = trim($name);
    if ($n === '') return false;
    if (strcasecmp($n, 'Sum') === 0) return false;
    if (preg_match('/общая\s*информ|general\s*info/ui', $n)) return false;
    if (preg_match('/результат/ui', $n) && preg_match('/команд/ui', $n)) return false;
    if (preg_match('/не\s*сыгравш/ui', $n)) return false;
    // явные этапы
    if (preg_match('/сесси|session|финал|final|отбор|qualif|полуфинал|semi|утешит|consol|группа\s*\d|group\s*\d|этап|stage|swiss|швейцар|round\s*robin|круг/ui', $n)) {
        return true;
    }
    return false;
}

function parse_session_sheet(string $sheetName, array $rows): array
{
    $session = [
        'name' => $sheetName,
        'boards' => null,
        'pairs_count' => null,
        'date_from' => null,
        'date_to' => null,
        'pairs' => [],
    ];
    // header meta in first rows
    for ($i = 0; $i < min(6, count($rows)); $i++) {
        $row = $rows[$i];
        for ($c = 0; $c < count($row); $c++) {
            $v = cell($row, $c);
            if ($session['boards'] === null) {
                if (preg_match('/сдач\s*=\s*(\d+)/ui', $v, $bm)) {
                    $session['boards'] = (int)$bm[1];
                } elseif (preg_match('/(сдач|boards|board|длина)/ui', $v) && isset($row[$c + 1]) && is_numeric($row[$c + 1])) {
                    $session['boards'] = (int)$row[$c + 1];
                } elseif (preg_match('/(сдач|boards)\s*[:=]?\s*(\d+)/ui', $v, $bm)) {
                    $session['boards'] = (int)$bm[2];
                }
            }
            if (preg_match('/пар\s*=/ui', $v) && isset($row[$c + 1]) && is_numeric($row[$c + 1])) {
                $session['pairs_count'] = (int)$row[$c + 1];
            }
            if (mb_stripos($v, 'Дата начала') !== false && isset($rows[$i + 1])) {
                $session['date_from'] = excel_serial_to_date($rows[$i + 1][$c] ?? null);
            }
            if (mb_stripos($v, 'Дата окончания') !== false && isset($rows[$i + 1])) {
                $session['date_to'] = excel_serial_to_date($rows[$i + 1][$c] ?? null);
            }
        }
        // dates often on next row under those headers (col 1 and 2)
        if ($i >= 1 && cell($row, 1) !== '' && $session['date_from'] === null) {
            $maybe = excel_serial_to_date($row[1] ?? null);
            if ($maybe && preg_match('/^\d{4}-\d{2}-\d{2}$/', $maybe)) {
                $session['date_from'] = $maybe;
            }
        }
        if ($i >= 1 && cell($row, 2) !== '' && $session['date_to'] === null) {
            $maybe = excel_serial_to_date($row[2] ?? null);
            if ($maybe && preg_match('/^\d{4}-\d{2}-\d{2}$/', $maybe)) {
                $session['date_to'] = $maybe;
            }
        }
    }
    $session['pairs'] = parse_pairs_table($rows);
    return $session;
}

function collect_players_from_pairs(array $pairs): array
{
    $players = [];
    foreach ($pairs as $pair) {
        if (!empty($pair['name1'])) {
            $players[] = [
                'name' => $pair['name1'],
                'player_id' => $pair['id1'],
                'role' => 'player',
                'rank' => $pair['rank'],
                'partner' => $pair['name2'] ?? '',
                'result' => $pair['result'],
            ];
        }
        if (!empty($pair['name2'])) {
            $players[] = [
                'name' => $pair['name2'],
                'player_id' => $pair['id2'],
                'role' => 'player',
                'rank' => $pair['rank'],
                'partner' => $pair['name1'] ?? '',
                'result' => $pair['result'],
            ];
        }
    }
    return $players;
}


function extract_meta_from_rows(array $rows): array
{
    $meta = [];
    foreach ($rows as $i => $row) {
        $label = cell($row, 0);
        $label2 = cell($row, 1);
        $val2 = cell($row, 2);
        if (mb_stripos($label, 'Название') !== false && $val2 !== '') {
            $meta['title'] = $val2;
        } elseif (mb_stripos($label, 'Место') !== false && $val2 !== '') {
            $meta['place'] = $val2;
        } elseif (mb_stripos($label, 'Дата') !== false) {
            if (mb_stripos($label2, 'с') !== false || trim($label2) === 'с') {
                $d = excel_serial_to_date($row[2] ?? $val2);
                if ($d) $meta['date_from'] = $d;
            } elseif ($val2 !== '' && empty($meta['date_from'])) {
                $meta['date_from'] = excel_serial_to_date($row[2] ?? $val2);
            }
        } elseif ($label === '' && (mb_stripos($label2, 'по') !== false || trim($label2) === 'по')) {
            $d = excel_serial_to_date($row[2] ?? null);
            if ($d) $meta['date_to'] = $d;
        } elseif (mb_stripos($label, 'Способ') !== false || mb_stripos($label, 'подсчет') !== false || mb_stripos($label, 'подсчёт') !== false) {
            if ($val2 !== '') $meta['scoring'] = $val2;
        } elseif (mb_stripos($label, 'Фактическая длина') !== false) {
            if ($val2 !== '' && is_numeric($val2)) {
                $meta['length_actual'] = 0 + $val2;
            }
        } elseif (preg_match('/^\s*Длина турнира\s*$/ui', $label)) {
            if ($val2 !== '' && is_numeric($val2)) {
                $meta['length'] = 0 + $val2;
            }
        }
    }
    return $meta;
}

function cell(array $row, int $i): string {
    if (!array_key_exists($i, $row) || $row[$i] === null) return '';
    $v = $row[$i];
    if (is_int($v) || is_float($v)) {
        if (is_float($v) && abs($v - round($v)) < 1e-9) return (string)(int)round($v);
        return (string)$v;
    }
    return trim((string)$v);
}

function parse_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Нужно расширение PHP zip (ZipArchive)');
    }
    if (!is_file($path) || filesize($path) < 22) {
        throw new RuntimeException('Файл пустой или не является XLSX');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Не удалось открыть XLSX');
    }
    if (!zip_size_ok($zip)) {
        $zip->close();
        throw new RuntimeException('XLSX слишком большой после распаковки (больше 200 МБ) — файл отклонён');
    }
    $shared = xlsx_shared_strings($zip);
    $sheets = xlsx_sheet_names($zip);
    $meta = [];
    $judges = [];
    $sumPairs = [];
    $sessions = [];
    $allPlayers = [];

    foreach ($sheets as $name => $pathSheet) {
        $rows = xlsx_read_sheet($zip, $pathSheet, $shared);
        if (mb_stripos($name, 'Общая') !== false || mb_stripos($name, 'General') !== false) {
            $meta = array_merge($meta, extract_meta_from_rows($rows));
            foreach ($rows as $row) {
                $jname = cell($row, 6);
                $jpos = cell($row, 7);
                if ($jname !== '' && $jpos !== ''
                    && mb_strtoupper($jname) !== 'ФИО'
                    && mb_strtoupper($jpos) !== 'ПОЗИЦИЯ') {
                    $jidRaw = cell($row, 5);
                    $jid = ($jidRaw !== '' && is_numeric($jidRaw)) ? (int)$jidRaw : null;
                    $judges[] = ['name' => rtrim($jname, ','), 'player_id' => $jid, 'role' => $jpos];
                }
            }
        } elseif (strcasecmp($name, 'Sum') === 0) {
            $sumPairs = parse_pairs_table($rows);
            $allPlayers = array_merge($allPlayers, collect_players_from_pairs($sumPairs));
        } elseif (is_pair_stage_sheet($name)) {
            $sess = parse_session_sheet($name, $rows);
            $sessions[] = $sess;
            $allPlayers = array_merge($allPlayers, collect_players_from_pairs($sess['pairs']));
        }
    }
    $zip->close();
    if (!$sumPairs && !$sessions) {
        throw new RuntimeException('В файле не найдены Sum / сессии');
    }
    return [
        'meta' => $meta,
        'judges' => $judges,
        'sum_pairs' => $sumPairs,
        'sessions' => $sessions,
        'players' => $allPlayers,
    ];
}



function parse_teams_table(array $rows): array
{
    $headerRow = null;
    $col = [];
    foreach ($rows as $i => $row) {
        $joined = implode(' ', array_map(fn($x) => (string)($x ?? ''), $row));
        if (preg_match('/команда/ui', $joined) && (preg_match('/id\s*1/ui', $joined) || preg_match('/игрок\s*1/ui', $joined))) {
            $headerRow = $i;
            // map headers
            foreach ($row as $ci => $h) {
                $hl = trim((string)($h ?? ''));
                if ($hl === '#' || strcasecmp($hl, 'rk') === 0 || preg_match('/^место$/ui', $hl)) $col['rank'] = $ci;
                elseif (preg_match('/^команда$/ui', $hl)) $col['team'] = $ci;
                elseif (preg_match('/^игрок\s*(\d+)$/ui', $hl, $m)) $col['p'.$m[1]] = $ci;
                elseif (preg_match('/^id\s*(\d+)$/ui', $hl, $m)) $col['id'.$m[1]] = $ci;
                elseif (preg_match('/^(vp|рез|результат|imps?)$/ui', $hl)) $col['result'] = $ci;
            }
            break;
        }
    }
    if ($headerRow === null) return [];
    // defaults if header mapping incomplete
    if (!isset($col['rank'])) $col['rank'] = 0;
    if (!isset($col['team'])) $col['team'] = 1;
    if (!isset($col['result'])) $col['result'] = 14;
    for ($n = 1; $n <= 6; $n++) {
        if (!isset($col['p'.$n])) $col['p'.$n] = 1 + $n;
        if (!isset($col['id'.$n])) $col['id'.$n] = 7 + $n;
    }

    $teams = [];
    $lastRank = null;
    for ($i = $headerRow + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $teamName = cell($row, $col['team']);
        $rkRaw = trim(cell($row, $col['rank']));
        $rawRankCell = $row[$col['rank']] ?? null;
        $hasPlayer = false;
        for ($n = 1; $n <= 6; $n++) {
            if (cell($row, $col['p'.$n]) !== '' || cell($row, $col['id'.$n]) !== '') {
                $hasPlayer = true;
                break;
            }
        }
        if ($teamName === '' && !$hasPlayer) continue;

        [$rk, $rankTied, $rankEnd] = parse_rank_cell($rkRaw, $lastRank, $rawRankCell);
        if ($rk !== null) {
            $lastRank = $rk;
        }
        if ($rk === null && $teamName === '' && !$hasPlayer) continue;

        $players = [];
        for ($n = 1; $n <= 6; $n++) {
            $nm = cell($row, $col['p'.$n]);
            $idRaw = cell($row, $col['id'.$n]);
            if ($nm === '' && $idRaw === '') continue;
            $players[] = [
                'name' => $nm,
                'player_id' => ($idRaw !== '' && is_numeric($idRaw)) ? (int)$idRaw : null,
            ];
        }
        $resRaw = $row[$col['result']] ?? null;
        $result = null;
        if ($resRaw !== null && $resRaw !== '' && is_numeric($resRaw)) {
            $result = 0 + $resRaw;
        }

        $entry = [
            'rank' => $rk,
            'team' => $teamName,
            'result' => $result,
            'players' => $players,
        ];
        if ($rankTied) $entry['rank_tied'] = true;
        if ($rankEnd !== null) $entry['rank_end'] = $rankEnd;
        $teams[] = $entry;
    }
    return $teams;
}

function parse_non_counting_table(array $rows): array
{
    if (!$rows) return [];
    $headerRow = 0;
    foreach ($rows as $i => $row) {
        $joined = implode(' ', array_map(fn($x) => (string)($x ?? ''), $row));
        if (preg_match('/команда/ui', $joined) && preg_match('/игрок|id/ui', $joined)) {
            $headerRow = $i;
            break;
        }
    }
    $out = [];
    for ($i = $headerRow + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $team = cell($row, 0);
        $players = [];
        // Игрок 1..3 в колонках 1..3, id1..3 в 4..6
        for ($n = 0; $n < 3; $n++) {
            $nm = cell($row, 1 + $n);
            $idRaw = cell($row, 4 + $n);
            if ($nm === '' && $idRaw === '') continue;
            $players[] = [
                'name' => $nm,
                'player_id' => ($idRaw !== '' && is_numeric($idRaw)) ? (int)$idRaw : null,
            ];
        }
        if (!$players) continue;
        $out[] = ['team' => $team, 'players' => $players];
    }
    return $out;
}

function is_team_report(array $sheetNames): bool
{
    foreach ($sheetNames as $n) {
        if (mb_stripos($n, 'Результат') !== false) return true;
    }
    return false;
}

function parse_xls_native(string $path): array
{
    $xls = new XlsReader($path);
    $meta = [];
    $judges = [];
    $sumPairs = [];
    $sessions = [];
    $allPlayers = [];
    $teams = [];
    $nonCounting = [];
    $names = $xls->sheetNames();
    $teamMode = is_team_report($names);

    foreach ($names as $name) {
        $rows = $xls->readSheet($name);
        if (mb_stripos($name, 'Общая') !== false || mb_stripos($name, 'General') !== false) {
            $meta = array_merge($meta, extract_meta_from_rows($rows));
            foreach ($rows as $row) {
                $jname = cell($row, 6);
                $jpos = cell($row, 7);
                if ($jname !== '' && $jpos !== ''
                    && mb_strtoupper($jname) !== 'ФИО'
                    && mb_strtoupper($jpos) !== 'ПОЗИЦИЯ') {
                    $jidRaw = cell($row, 5);
                    $jid = ($jidRaw !== '' && is_numeric($jidRaw)) ? (int)$jidRaw : null;
                    $judges[] = ['name' => rtrim($jname, ','), 'player_id' => $jid, 'role' => $jpos];
                }
            }
        } elseif ($teamMode && mb_stripos($name, 'Результат') !== false) {
            $teams = parse_teams_table($rows);
            foreach ($teams as $tm) {
                foreach ($tm['players'] as $pl) {
                    $allPlayers[] = [
                        'name' => $pl['name'],
                        'player_id' => $pl['player_id'],
                        'role' => 'player',
                        'rank' => $tm['rank'],
                        'partner' => $tm['team'],
                        'result' => $tm['result'],
                    ];
                }
            }
        } elseif ($teamMode && mb_stripos($name, 'не сыгравш') !== false) {
            $nonCounting = parse_non_counting_table($rows);
            foreach ($nonCounting as $nc) {
                foreach ($nc['players'] as $pl) {
                    $allPlayers[] = [
                        'name' => $pl['name'],
                        'player_id' => $pl['player_id'],
                        'role' => 'non_counting',
                        'rank' => null,
                        'partner' => $nc['team'],
                        'result' => null,
                    ];
                }
            }
        } elseif (!$teamMode && strcasecmp($name, 'Sum') === 0) {
            $sumPairs = parse_pairs_table($rows);
            $allPlayers = array_merge($allPlayers, collect_players_from_pairs($sumPairs));
        } elseif (!$teamMode && (is_pair_stage_sheet($name))) {
            $sess = parse_session_sheet($name, $rows);
            $sessions[] = $sess;
            $allPlayers = array_merge($allPlayers, collect_players_from_pairs($sess['pairs']));
        }
    }
    if ($teamMode) {
        if (!$teams) {
            throw new RuntimeException('В командном отчёте не найден лист «Результаты» с командами');
        }
        return [
            'format' => 'team',
            'meta' => $meta,
            'judges' => $judges,
            'teams' => $teams,
            'non_counting' => $nonCounting,
            'sum_pairs' => [],
            'sessions' => [],
            'players' => $allPlayers,
        ];
    }
    if (!$sumPairs && !$sessions) {
        throw new RuntimeException('В .xls не найдены данные Sum / сессий');
    }
    $fmt = 'pair';
    $title = (string)($meta['title'] ?? '');
    if (preg_match('/индивидуальн/ui', $title) || results_table_is_individual($sumPairs)) {
        $fmt = 'individual';
    } elseif ($sessions) {
        $sessPairs = $sessions[0]['pairs'] ?? [];
        if (results_table_is_individual($sessPairs)) {
            $fmt = 'individual';
        }
    }
    return [
        'format' => $fmt,
        'meta' => $meta,
        'judges' => $judges,
        'sum_pairs' => $sumPairs,
        'sessions' => $sessions,
        'teams' => [],
        'non_counting' => [],
        'players' => $allPlayers,
    ];
}

function parse_report(string $path, string $origName): array
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'xls') {
        return parse_xls_native($path);
    }
    if ($ext === 'xlsx') {
        return parse_xlsx($path);
    }
    throw new RuntimeException('Нужен файл .xls или .xlsx');
}


function validate(array $players, array $judges): array
{
    $db = db();
    $r = [
        'ok' => [], 'name_mismatch' => [], 'no_id' => [], 'unknown_id' => [],
        'status_warn' => [], // state 3 или 4
        'judges_ok' => [], 'judges_issues' => [],
        'json_players' => [], 'json_judges' => [],
        'city_stats' => [],
    ];
    $seen = [];
    $reportKnown = [];
    foreach ($players as $pp) {
        $ppid = $pp['player_id'] ?? null;
        if ($ppid === null || $ppid === '') continue;
        $dbp0 = get_player($db, (int)$ppid);
        if (!$dbp0) continue;
        $reportKnown[] = [
            'player_id' => (int)$ppid,
            'db_fio' => trim(implode(' ', array_filter([$dbp0['family'] ?? '', $dbp0['given_name'] ?? '', $dbp0['patronymic'] ?? '']))),
            'family' => $dbp0['family'] ?? '',
            'given_name' => $dbp0['given_name'] ?? '',
            'patronymic' => $dbp0['patronymic'] ?? '',
            'razr' => $dbp0['razr'] ?? null,
        ];
    }
    $mergeSugg = function(string $name) use ($db, $reportKnown): array {
        $sugg = [];
        foreach ($reportKnown as $rk) {
            if (names_match($name, $rk['family'], $rk['given_name'], $rk['patronymic'])) {
                $sugg[] = [
                    'player_id' => $rk['player_id'],
                    'db_fio' => $rk['db_fio'],
                    'razr' => $rk['razr'],
                    'from' => 'report',
                ];
            }
        }
        foreach (search_players_by_name($db, $name) as $sg) {
            $pid = (int)$sg['player_id'];
            if (array_filter($sugg, fn($x) => (int)$x['player_id'] === $pid)) continue;
            $sg['from'] = 'db';
            $sugg[] = $sg;
        }
        return $sugg;
    };

    foreach ($players as $p) {
        $key = ($p['player_id'] ?? 'x') . '|' . normalize($p['name'] ?? '');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $pid = $p['player_id'] ?? null;
        $name = $p['name'] ?? '';
        $entry = [
            'rank' => $p['rank'] ?? null,
            'name' => $name,
            'partner' => $p['partner'] ?? null,
            'player_id' => $pid,
            'status' => null,
            'db_fio' => null,
            'db_family' => null,
            'db_given' => null,
            'db_patronymic' => null,
            'razr' => null,
        ];
        if ($pid === null || $pid === '') {
            $entry['status'] = 'no_id';
            $sugg = $mergeSugg($name);
            $entry['suggestions'] = $sugg;
            $p['suggestions'] = $sugg;
            $r['no_id'][] = $p;
            $r['json_players'][] = $entry;
            continue;
        }
        $pid = (int)$pid;
        $entry['player_id'] = $pid;
        $dbp = get_player($db, $pid);
        if (!$dbp) {
            $entry['status'] = 'unknown_id';
            $sugg = $mergeSugg($name);
            $entry['suggestions'] = $sugg;
            $p['suggestions'] = $sugg;
            $r['unknown_id'][] = $p;
            $r['json_players'][] = $entry;
            continue;
        }
        $entry['db_family'] = $dbp['family'] ?? null;
        $entry['db_given'] = $dbp['given_name'] ?? null;
        $entry['db_patronymic'] = $dbp['patronymic'] ?? null;
        $entry['db_fio'] = trim(implode(' ', array_filter([
            $dbp['family'] ?? '', $dbp['given_name'] ?? '', $dbp['patronymic'] ?? '',
        ])));
        $entry['razr'] = isset($dbp['razr']) ? (is_numeric($dbp['razr']) ? (0 + $dbp['razr']) : $dbp['razr']) : null;
        $entry['razr_coeff'] = isset($dbp['razr_coeff']) && $dbp['razr_coeff'] !== null && $dbp['razr_coeff'] !== ''
            ? (0 + $dbp['razr_coeff']) : null;
        [$entry['q'], $qErr] = q_from_razr_checked($entry['razr'], $entry['razr_coeff']);
        if ($qErr !== null) {
            $entry['q_error'] = $qErr;
            $entry['status_warn'] = trim(($entry['status_warn'] ?? '') . ' ' . $qErr);
        }
        $entry['state'] = isset($dbp['state']) && $dbp['state'] !== null && $dbp['state'] !== '' ? (int)$dbp['state'] : null;
        $entry['city'] = $dbp['city_name'] ?? null;
        $entry['city_id'] = isset($dbp['city_id']) ? (0 + $dbp['city_id']) : null;
        $p['db_fio'] = $entry['db_fio'];
        $p['razr'] = $entry['razr'];
        $p['state'] = $entry['state'];
        $p['city'] = $entry['city'];
        if (names_match($name, $dbp['family'] ?? '', $dbp['given_name'] ?? '', $dbp['patronymic'] ?? '')) {
            $entry['status'] = 'ok';
            $r['ok'][] = $p;
        } else {
            $entry['status'] = 'name_mismatch';
            $r['name_mismatch'][] = $p;
        }
        // state 3 = умер, 4 = не активен
        if ($entry['state'] === 3 || $entry['state'] === 4) {
            $warn = $p;
            $warn['state'] = $entry['state'];
            $warn['state_label'] = state_label($entry['state']);
            $warn['db_fio'] = $entry['db_fio'];
            $r['status_warn'][] = $warn;
            $entry['status_flag'] = $entry['state'] === 3 ? 'deceased' : 'inactive';
        }
        $r['json_players'][] = $entry;
    }
    // статистика по городам
    $cityCounts = [];
    foreach ($r['json_players'] as $jp) {
        if (($jp['status'] ?? '') === 'no_id' || ($jp['status'] ?? '') === 'unknown_id') {
            $city = 'неизвестно';
        } else {
            $city = trim((string)($jp['city'] ?? ''));
            if ($city === '') $city = 'без города';
        }
        $cityCounts[$city] = ($cityCounts[$city] ?? 0) + 1;
    }
    arsort($cityCounts);
    $r['city_stats'] = [];
    foreach ($cityCounts as $city => $cnt) {
        $r['city_stats'][] = ['city' => $city, 'count' => $cnt];
    }

    foreach ($judges as $j) {
        $pid = $j['player_id'] ?? null;
        $name = $j['name'] ?? '';
        $entry = [
            'name' => $name,
            'player_id' => $pid,
            'role' => $j['role'] ?? null,
            'status' => null,
            'db_fio' => null,
            'razr' => null,
        ];
        if ($pid === null || $pid === '') {
            $entry['status'] = 'no_id';
            $j['issue'] = 'no_id';
            $r['judges_issues'][] = $j;
            $r['json_judges'][] = $entry;
            continue;
        }
        $pid = (int)$pid;
        $entry['player_id'] = $pid;
        $dbp = get_player($db, $pid);
        if (!$dbp) {
            $entry['status'] = 'unknown_id';
            $j['issue'] = 'unknown_id';
            $r['judges_issues'][] = $j;
            $r['json_judges'][] = $entry;
            continue;
        }
        $entry['db_fio'] = trim(implode(' ', array_filter([
            $dbp['family'] ?? '', $dbp['given_name'] ?? '', $dbp['patronymic'] ?? '',
        ])));
        $entry['razr'] = isset($dbp['razr']) ? (is_numeric($dbp['razr']) ? (0 + $dbp['razr']) : $dbp['razr']) : null;
        $j['db_fio'] = $entry['db_fio'];
        $j['razr'] = $entry['razr'];
        if (names_match($name, $dbp['family'] ?? '', $dbp['given_name'] ?? '', $dbp['patronymic'] ?? '')) {
            $entry['status'] = 'ok';
            $r['judges_ok'][] = $j;
        } else {
            $entry['status'] = 'name_mismatch';
            $j['issue'] = 'name_mismatch';
            $r['judges_issues'][] = $j;
        }
        $r['json_judges'][] = $entry;
    }
    $db->close();
    return $r;
}


$error = null;
$meta = null;
$results = null;
$total = 0;
$jsonReport = null;
$clubMbMode = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($upErr = upload_error_message($_FILES['file'] ?? null)) !== null) {
            throw new RuntimeException($upErr);
        }
        if (empty($_FILES['file']['tmp_name'])) {
            throw new RuntimeException('Выберите файл');
        }
        $orig = $_FILES['file']['name'] ?? 'report.xlsx';
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xls', 'xlsx'], true)) {
            throw new RuntimeException('Нужен файл .xlsx (лучше) или .xls');
        }
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fsbr_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmp)) {
            throw new RuntimeException('Не удалось сохранить файл');
        }
        // Клубные МБ — отдельный формат, без расчёта рейтинга
        if (cmb_is_club_mb_file($tmp, $orig)) {
            $forcedCity = (!empty($_POST['city_id']) && ctype_digit((string)$_POST['city_id']))
                ? (int)$_POST['city_id'] : null;
            $tournIdOpt = (!empty($_POST['tourn_id']) && ctype_digit((string)$_POST['tourn_id']))
                ? (int)$_POST['tourn_id'] : null;
            $club = cmb_process_report($tmp, $orig, $forcedCity, $tournIdOpt);
            @unlink($tmp);
            $parsed = [
                'format' => 'club_mb',
                'meta' => $club['meta'],
                'players' => array_map(function ($p) {
                    return [
                        'name' => $p['name'] ?? '',
                        'player_id' => $p['player_id'] ?? null,
                        'rank' => $p['n'] ?? null,
                        'role' => 'player',
                        'mb' => $p['mb'] ?? null,
                    ];
                }, $club['players']),
                'judges' => [],
                'sum_pairs' => [],
                'sessions' => [],
                'teams' => [],
            ];
            $meta = $club['meta'];
            $results = validate($parsed['players'], []);
            // enrich validated with club mb status from cmb_validate already done
            // rebuild json for club
            $jsonReport = [
                'format' => 'club_mb',
                'meta' => $club['meta'],
                'city' => $club['city'],
                'city_error' => $club['city_error'],
                'sql' => $club['sql'],
                'players' => $club['players'],
                'summary' => [
                    'players_total' => count($club['players']),
                    'ok' => count(array_filter($club['players'], fn($p) => ($p['status'] ?? '') === 'ok')),
                    'sum_mb' => array_sum(array_map(fn($p) => (float)($p['mb'] ?? 0), $club['players'])),
                ],
                'warnings' => $club['city_error'] ? [['message' => $club['city_error']]] : [],
            ];
            $_SESSION['report_json'] = json_encode($jsonReport, JSON_UNESCAPED_UNICODE);
            $_SESSION['club_mb_sql'] = $club['sql'];
            $_SESSION['club_mb_report'] = $jsonReport;
            unset($_SESSION['rating_out']); // не смешивать с обычным турниром
            $clubMbMode = true;
            $total = count($club['players']);
            $results = [
                'ok' => array_values(array_filter($club['players'], fn($p) => ($p['status'] ?? '') === 'ok')),
                'name_mismatch' => array_values(array_filter($club['players'], fn($p) => ($p['status'] ?? '') === 'name_mismatch')),
                'no_id' => array_values(array_filter($club['players'], fn($p) => ($p['status'] ?? '') === 'no_id')),
                'unknown_id' => array_values(array_filter($club['players'], fn($p) => ($p['status'] ?? '') === 'unknown_id')),
                'status_warn' => [],
                'judges_ok' => [],
                'judges_issues' => [],
                'json_players' => $club['players'],
                'json_judges' => [],
                'city_stats' => [],
            ];
        } else {
        // обычный турнир — сбрасываем клубный контекст
        unset($_SESSION['club_mb_report'], $_SESSION['club_mb_sql']);
        $parsed = parse_report($tmp, $orig);
        @unlink($tmp);
        $meta = $parsed['meta'] ?? [];
        $results = validate($parsed['players'] ?? [], $parsed['judges'] ?? []);
        $clubMbMode = false;
        $total = count($results['ok']) + count($results['name_mismatch'])
               + count($results['no_id']) + count($results['unknown_id']);
        $playerInfo = [];
        foreach ($results['json_players'] as $jp) {
            $pid = $jp['player_id'] ?? null;
            $key = ($pid !== null ? 'id:'.$pid : 'name:'.normalize($jp['name'] ?? ''));
            $playerInfo[$key] = $jp;
        }
        $enrichPair = function(array $pair) use ($playerInfo) {
            $p1key = $pair['id1'] !== null ? 'id:'.$pair['id1'] : 'name:'.normalize($pair['name1'] ?? '');
            $i1 = $playerInfo[$p1key] ?? null;
            $mk = function($name, $id, $info) {
                $info = is_array($info) ? $info : [];
                return [
                    'name' => $name,
                    'player_id' => $id,
                    'status' => $info['status'] ?? null,
                    'db_fio' => $info['db_fio'] ?? null,
                    'razr' => $info['razr'] ?? null,
                    'razr_coeff' => $info['razr_coeff'] ?? null,
                    'city_id' => $info['city_id'] ?? null,
                    'q' => $info['q'] ?? (isset($info['razr']) ? q_from_razr($info['razr'], $info['razr_coeff'] ?? null) : null),
                ];
            };
            $out = [
                'rank' => $pair['rank'],
                'result' => $pair['result'],
                'player1' => $mk($pair['name1'] ?? '', $pair['id1'] ?? null, $i1),
            ];
            $name2 = trim((string)($pair['name2'] ?? ''));
            $id2 = $pair['id2'] ?? null;
            // индивидуал / пустой второй игрок — не создаём player2
            if ($name2 !== '' || ($id2 !== null && $id2 !== '')) {
                $p2key = $id2 !== null ? 'id:'.$id2 : 'name:'.normalize($name2);
                $i2 = $playerInfo[$p2key] ?? null;
                $out['player2'] = $mk($name2, $id2, $i2);
            }
            if (!empty($pair['rank_tied'])) {
                $out['rank_tied'] = true;
            }
            if (($pair['layout'] ?? '') === 'individual') {
                $out['layout'] = 'individual';
            }
            return $out;
        };
        $filterPairs = function(array $pairs) {
            // все строки с участниками; места по порядку, если не указаны
            $out = [];
            $lastRank = 0;
            $pending = 0;
            foreach ($pairs as $p) {
                $name1 = $p['name1'] ?? '';
                $name2 = $p['name2'] ?? '';
                if ($name1 === '' && $name2 === '') continue;
                $rk = $p['rank'] ?? null;
                if ($rk === null || $rk === '') {
                    if (!empty($p['rank_tied']) && $lastRank > 0) {
                        $rk = $lastRank;
                    } else {
                        $rk = $lastRank + 1;
                    }
                    $p['rank'] = $rk;
                    $p['rank_inferred'] = true;
                } else {
                    $rk = (int)$rk;
                    $p['rank'] = $rk;
                }
                $lastRank = (int)$rk;
                $out[] = $p;
            }
            return $out;
        };
        $format = $parsed['format'] ?? 'pair';
        $sumPairsOut = [];
        $sessionsOut = [];
        $teamsOut = [];
        $nonCountingOut = [];
        $warnings = [];

        $lookupPlayer = function ($id, $name) use ($playerInfo) {
            $key = $id !== null ? 'id:'.$id : 'name:'.normalize($name ?? '');
            return $playerInfo[$key] ?? null;
        };

        if ($format === 'team') {
            // индекс команд по нормализованному имени
            $teamIndex = [];
            foreach ($parsed['teams'] ?? [] as $tm) {
                // места: если нет — по порядку строк
                if (!isset($tm['rank']) || $tm['rank'] === null || $tm['rank'] === '') {
                    static $teamLastRank = 0;
                    if (!empty($tm['rank_tied']) && $teamLastRank > 0) {
                        $tm['rank'] = $teamLastRank;
                    } else {
                        $tm['rank'] = $teamLastRank + 1;
                    }
                    $tm['rank_inferred'] = true;
                }
                $teamLastRank = (int)$tm['rank'];
                $playersOut = [];
                foreach ($tm['players'] as $pl) {
                    $info = $lookupPlayer($pl['player_id'] ?? null, $pl['name'] ?? '');
                    $playersOut[] = [
                        'name' => $pl['name'] ?? '',
                        'player_id' => $pl['player_id'] ?? null,
                        'status' => $info['status'] ?? null,
                        'db_fio' => $info['db_fio'] ?? null,
                        'razr' => $info['razr'] ?? null,
                        'razr_coeff' => $info['razr_coeff'] ?? null,
                        'city_id' => $info['city_id'] ?? null,
                        'q' => $info['q'] ?? (isset($info['razr']) ? q_from_razr($info['razr'], $info['razr_coeff'] ?? null) : null),
                    ];
                }
                $entry = [
                    'rank' => $tm['rank'],
                    'team' => $tm['team'] ?? '',
                    'result' => $tm['result'] ?? null,
                    'players' => $playersOut,
                    'non_counting' => [],
                ];
                if (!empty($tm['rank_tied'])) {
                    $entry['rank_tied'] = true;
                }
                $teamIndex[normalize(trim($tm['team'] ?? ''))] = count($teamsOut);
                $teamsOut[] = $entry;
            }
            // Игроки вне зачёта — внутрь соответствующей команды
            $orphanNonCounting = [];
            $isBadTeamName = function (string $name): bool {
                $n = trim($name);
                if ($n === '') return true;
                // ошибки формул Excel
                if (preg_match('/^#?(REF|NULL|DIV\/0|VALUE|NAME\?|NUM|N\/A|ERR)!?$/i', $n)) return true;
                if (preg_match('/^#REF/i', $n)) return true;
                return false;
            };
            foreach ($parsed['non_counting'] ?? [] as $nc) {
                $teamName = trim($nc['team'] ?? '');
                $playersOut = [];
                foreach ($nc['players'] as $pl) {
                    $info = $lookupPlayer($pl['player_id'] ?? null, $pl['name'] ?? '');
                    $playersOut[] = [
                        'name' => $pl['name'] ?? '',
                        'player_id' => $pl['player_id'] ?? null,
                        'status' => $info['status'] ?? null,
                        'db_fio' => $info['db_fio'] ?? null,
                        'razr' => $info['razr'] ?? null,
                        'razr_coeff' => $info['razr_coeff'] ?? null,
                        'city_id' => $info['city_id'] ?? null,
                        'q' => $info['q'] ?? (isset($info['razr']) ? q_from_razr($info['razr'], $info['razr_coeff'] ?? null) : null),
                    ];
                }
                if (!$playersOut) continue;
                $key = normalize($teamName);
                if ($isBadTeamName($teamName)) {
                    $orphanNonCounting[] = [
                        'team' => $teamName,
                        'players' => $playersOut,
                        'reason' => ($teamName === '' || $teamName === null)
                            ? 'не указана команда (пустая ячейка или формула без значения)'
                            : 'в ячейке команды ошибка формулы Excel («' . $teamName . '») — укажите название команды текстом',
                    ];
                    continue;
                }
                if (!isset($teamIndex[$key])) {
                    // иногда в «Результаты» лишний пробел — уже normalize; попробуем без пробелов
                    $found = null;
                    foreach ($teamIndex as $tk => $ti) {
                        if (str_replace(' ', '', $tk) === str_replace(' ', '', $key)) {
                            $found = $ti;
                            break;
                        }
                    }
                    if ($found === null) {
                        $orphanNonCounting[] = [
                            'team' => $teamName,
                            'players' => $playersOut,
                            'reason' => 'команда «' . $teamName . '» не найдена в таблице «Результаты»',
                        ];
                        continue;
                    }
                    $ti = $found;
                } else {
                    $ti = $teamIndex[$key];
                }
                foreach ($playersOut as $po) {
                    $teamsOut[$ti]['non_counting'][] = $po;
                    // убрать из основного состава, если там тот же игрок
                    $pid = $po['player_id'] ?? null;
                    $pname = normalize($po['name'] ?? '');
                    $teamsOut[$ti]['players'] = array_values(array_filter(
                        $teamsOut[$ti]['players'],
                        function ($tp) use ($pid, $pname) {
                            $tpid = $tp['player_id'] ?? null;
                            if ($pid !== null && $pid !== '' && $tpid !== null && $tpid !== '' && (int)$tpid === (int)$pid) {
                                return false;
                            }
                            if ($pid === null && $pname !== '' && normalize($tp['name'] ?? '') === $pname) {
                                return false;
                            }
                            return true;
                        }
                    ));
                }
            }
            if ($orphanNonCounting) {
                $warnings[] = [
                    'code' => 'non_counting_no_team',
                    'message' => 'На листе «Игроки не сыгравшие в зачет» есть игроки, для которых нельзя определить команду.',
                    'count' => count($orphanNonCounting),
                    'items' => $orphanNonCounting,
                ];
            }
            // дубли ID в результатах
            $seen = [];
            $dups = [];
            foreach ($parsed['teams'] ?? [] as $tm) {
                foreach ($tm['players'] as $pl) {
                    $pid = $pl['player_id'] ?? null;
                    if ($pid === null || $pid === '') continue;
                    $pid = (int)$pid;
                    if (isset($seen[$pid])) {
                        $dups[] = [
                            'player_id' => $pid,
                            'name' => $pl['name'] ?? '',
                            'rank' => $tm['rank'] ?? null,
                            'also_at_rank' => $seen[$pid]['rank'] ?? null,
                            'also_name' => $seen[$pid]['name'] ?? '',
                        ];
                    } else {
                        $seen[$pid] = ['rank' => $tm['rank'] ?? null, 'name' => $pl['name'] ?? ''];
                    }
                }
            }
            if ($dups) {
                $warnings[] = [
                    'code' => 'duplicate_player_id',
                    'message' => 'В таблице «Результаты» один и тот же ID игрока встречается больше одного раза. В одной таблице каждый ID может быть только у одного участника.',
                    'table' => 'Результаты',
                    'count' => count($dups),
                    'duplicates' => $dups,
                ];
            }
            // порядок мест (результаты в командном нокауте не проверяем)
            $rankPairs = [];
            foreach ($parsed['teams'] ?? [] as $tm) {
                if ($tm['rank'] === null) continue;
                $rankPairs[] = [
                    'rank' => $tm['rank'],
                    'name1' => $tm['team'] ?? '',
                    'name2' => '',
                    'result' => $tm['result'] ?? null,
                ];
            }
            // reuse rank sequence logic inline (start at 1, non-decreasing, gaps)
            $issues = [];
            if ($rankPairs) {
                $first = $rankPairs[0]['rank'] ?? null;
                if ($first === null || (int)$first !== 1) {
                    $issues[] = [
                        'type' => 'rank_not_start_at_1',
                        'detail' => 'Список должен начинаться с 1-го места, сейчас первое указанное место: ' . ($first === null ? 'не задано' : (string)$first),
                        'name1' => $rankPairs[0]['name1'] ?? '',
                        'name2' => '',
                    ];
                }
                $prevRank = null;
                $runCount = 0;
                foreach ($rankPairs as $idx => $pair) {
                    $r = $pair['rank'] ?? null;
                    if ($r === null) continue;
                    $r = (int)$r;
                    if ($prevRank !== null) {
                        if ($r < $prevRank) {
                            $issues[] = [
                                'type' => 'rank_decreasing',
                                'detail' => "Место идёт не по порядку: было {$prevRank}, затем {$r} (места не должны уменьшаться вниз по таблице)",
                                'name1' => $pair['name1'] ?? '',
                                'name2' => '',
                            ];
                        } elseif ($r === $prevRank) {
                            $runCount++;
                        } elseif ($r > $prevRank) {
                            $dense = $prevRank + 1;
                            $competition = $prevRank + $runCount;
                            if ($r !== $dense && $r !== $competition) {
                                $issues[] = [
                                    'type' => 'rank_gap',
                                    'detail' => "После места {$prevRank} (команд с этим местом: {$runCount}) ожидалось следующее место {$dense} или {$competition}, а указано {$r}",
                                    'name1' => $pair['name1'] ?? '',
                                    'name2' => '',
                                ];
                            }
                            $runCount = 1;
                        }
                    } else {
                        $runCount = 1;
                    }
                    $prevRank = $r;
                }
            }
            if ($issues) {
                $warnings[] = [
                    'code' => 'rank_sequence',
                    'message' => 'В таблице «Результаты» места указаны не по порядку: должны начинаться с 1 и не уменьшаться вниз по списку (знак «=» означает то же место, что у предыдущей команды).',
                    'table' => 'Результаты',
                    'count' => count($issues),
                    'issues' => $issues,
                ];
            }

            $jsonReport = [
                'format' => 'team',
                'meta' => $meta,
                'summary' => [
                    'teams' => count($teamsOut),
                    'players_total' => $total,
                    'ok' => count($results['ok']),
                    'name_mismatch' => count($results['name_mismatch']),
                    'no_id' => count($results['no_id']),
                    'unknown_id' => count($results['unknown_id']),
                    'judges_ok' => count($results['judges_ok']),
                    'judges_issues' => count($results['judges_issues']),
                'status_warn' => count($results['status_warn'] ?? []),
                    'warnings' => count($warnings),
                ],
                'warnings' => $warnings,
                'judges' => $results['json_judges'],
                'teams' => $teamsOut,
            ];
        } else {
        $sumPairsOut = array_map($enrichPair, $filterPairs($parsed['sum_pairs'] ?? []));
        $sessionsOut = [];
        foreach ($parsed['sessions'] ?? [] as $sess) {
            $sessionsOut[] = [
                'name' => $sess['name'],
                'boards' => $sess['boards'],
                'pairs_count' => $sess['pairs_count'],
                'date_from' => $sess['date_from'],
                'date_to' => $sess['date_to'],
                'pairs' => array_map($enrichPair, $filterPairs($sess['pairs'] ?? [])),
            ];
        }

        $warnings = [];

        // Sum: rank "=" недопустим
        $sumTied = [];
        foreach ($parsed['sum_pairs'] ?? [] as $pair) {
            if (!empty($pair['rank_tied'])) {
                $sumTied[] = [
                    'rank' => $pair['rank'],
                    'name1' => $pair['name1'] ?? '',
                    'name2' => $pair['name2'] ?? '',
                    'id1' => $pair['id1'] ?? null,
                    'id2' => $pair['id2'] ?? null,
                ];
            }
        }
        if ($sumTied) {
            $warnings[] = [
                'code' => 'sum_rank_tied',
                'message' => 'В итоговом протоколе (Sum) указано место «=» (как у предыдущей пары). В сумме турнира так делать нельзя — у каждой пары должно быть своё числовое место.',
                'count' => count($sumTied),
                'pairs' => $sumTied,
            ];
        }

        // Дубли ID внутри одной таблицы (Sum и каждая сессия)
        $checkDupIds = function (string $tableName, array $pairs) use (&$warnings) {
            $seen = [];
            $dups = [];
            foreach ($pairs as $pair) {
                foreach (['id1' => $pair['id1'] ?? null, 'id2' => $pair['id2'] ?? null] as $field => $pid) {
                    if ($pid === null || $pid === '') continue;
                    $pid = (int)$pid;
                    $name = $field === 'id1' ? ($pair['name1'] ?? '') : ($pair['name2'] ?? '');
                    if (isset($seen[$pid])) {
                        $dups[] = [
                            'player_id' => $pid,
                            'name' => $name,
                            'rank' => $pair['rank'] ?? null,
                            'also_at_rank' => $seen[$pid]['rank'] ?? null,
                            'also_name' => $seen[$pid]['name'] ?? '',
                        ];
                    } else {
                        $seen[$pid] = ['rank' => $pair['rank'] ?? null, 'name' => $name];
                    }
                }
            }
            if ($dups) {
                $warnings[] = [
                    'code' => 'duplicate_player_id',
                    'message' => "В таблице «{$tableName}» один и тот же ID игрока встречается больше одного раза. В одной таблице каждый ID может быть только у одного участника.",
                    'table' => $tableName,
                    'count' => count($dups),
                    'duplicates' => $dups,
                ];
            }
        };
        $checkDupIds('Sum', $parsed['sum_pairs'] ?? []);
        foreach ($parsed['sessions'] ?? [] as $sess) {
            $checkDupIds($sess['name'] ?? 'Сессия', $sess['pairs'] ?? []);
        }

        // Результаты должны убывать с ростом места; при одинаковом месте — равны
        $checkResultOrder = function (string $tableName, array $pairs) use (&$warnings) {
            $issues = [];
            $n = count($pairs);
            for ($i = 0; $i < $n - 1; $i++) {
                $a = $pairs[$i];
                $b = $pairs[$i + 1];
                $ra = $a['rank'] ?? null;
                $rb = $b['rank'] ?? null;
                $sa = $a['result'] ?? null;
                $sb = $b['result'] ?? null;
                if ($ra === null || $rb === null || $sa === null || $sb === null) {
                    continue;
                }
                $ra = (int)$ra;
                $rb = (int)$rb;
                $sa = 0 + $sa;
                $sb = 0 + $sb;
                $eps = 1e-9;
                if ($ra === $rb) {
                    // ничья: результаты должны быть равны
                    if (abs($sa - $sb) > $eps) {
                        $issues[] = [
                            'type' => 'tied_rank_different_result',
                            'rank' => $ra,
                            'pair_a' => [
                                'rank' => $ra,
                                'result' => $sa,
                                'name1' => $a['name1'] ?? '',
                                'name2' => $a['name2'] ?? '',
                                'id1' => $a['id1'] ?? null,
                                'id2' => $a['id2'] ?? null,
                            ],
                            'pair_b' => [
                                'rank' => $rb,
                                'result' => $sb,
                                'name1' => $b['name1'] ?? '',
                                'name2' => $b['name2'] ?? '',
                                'id1' => $b['id1'] ?? null,
                                'id2' => $b['id2'] ?? null,
                            ],
                            'detail' => "Одинаковое место {$ra}, но результаты разные ({$sa} и {$sb}). При ничьей результаты должны быть одинаковыми.",
                        ];
                    }
                } elseif ($rb > $ra) {
                    // место хуже — результат должен быть строго меньше
                    if ($sb > $sa + $eps) {
                        $issues[] = [
                            'type' => 'result_not_decreasing',
                            'rank_a' => $ra,
                            'rank_b' => $rb,
                            'pair_a' => [
                                'rank' => $ra,
                                'result' => $sa,
                                'name1' => $a['name1'] ?? '',
                                'name2' => $a['name2'] ?? '',
                                'id1' => $a['id1'] ?? null,
                                'id2' => $a['id2'] ?? null,
                            ],
                            'pair_b' => [
                                'rank' => $rb,
                                'result' => $sb,
                                'name1' => $b['name1'] ?? '',
                                'name2' => $b['name2'] ?? '',
                                'id1' => $b['id1'] ?? null,
                                'id2' => $b['id2'] ?? null,
                            ],
                            'detail' => "У пары на месте {$rb} результат {$sb} лучше, чем у пары на более высоком месте {$ra} (результат {$sa}).",
                        ];
                    } elseif (abs($sa - $sb) <= $eps && $ra !== $rb) {
                        // Sum: одинаковый результат при разных местах — нормально (критерии разделения)
                        // Сессии: предупреждение
                        if ($tableName !== 'Sum') {
                            $issues[] = [
                                'type' => 'same_result_different_rank',
                                'rank_a' => $ra,
                                'rank_b' => $rb,
                                'pair_a' => [
                                    'rank' => $ra,
                                    'result' => $sa,
                                    'name1' => $a['name1'] ?? '',
                                    'name2' => $a['name2'] ?? '',
                                    'id1' => $a['id1'] ?? null,
                                    'id2' => $a['id2'] ?? null,
                                ],
                                'pair_b' => [
                                    'rank' => $rb,
                                    'result' => $sb,
                                    'name1' => $b['name1'] ?? '',
                                    'name2' => $b['name2'] ?? '',
                                    'id1' => $b['id1'] ?? null,
                                    'id2' => $b['id2'] ?? null,
                                ],
                                'detail' => "Одинаковый результат {$sa} при разных местах {$ra} и {$rb}.",
                            ];
                        }
                    }
                }
            }
            if ($issues) {
                $msg = ($tableName === 'Sum')
                    ? 'В итоговом протоколе (Sum) результаты идут не по убыванию мест. Иногда так бывает, если в сумме смешаны разные группы — проверьте, всё ли верно.'
                    : "В таблице «{$tableName}» результаты не согласованы с местами: при более высоком месте результат должен быть лучше; при одинаковом месте («=») результаты должны совпадать.";
                $warnings[] = [
                    'code' => 'result_order',
                    'message' => $msg,
                    'table' => $tableName,
                    'count' => count($issues),
                    'issues' => $issues,
                ];
            }
        };
        $checkResultOrder('Sum', $parsed['sum_pairs'] ?? []);
        foreach ($parsed['sessions'] ?? [] as $sess) {
            $checkResultOrder($sess['name'] ?? 'Сессия', $sess['pairs'] ?? []);
        }

        // Места идут по порядку (не убывают; старт с 1; пропуски только из-за ничьих)
        $checkRankSequence = function (string $tableName, array $pairs) use (&$warnings) {
            $issues = [];
            if (!$pairs) {
                return;
            }
            $first = $pairs[0]['rank'] ?? null;
            if ($first === null || (int)$first !== 1) {
                $issues[] = [
                    'type' => 'rank_not_start_at_1',
                    'detail' => 'Список должен начинаться с 1-го места, сейчас первое указанное место: ' . ($first === null ? 'не задано' : (string)$first),
                    'rank' => $first,
                    'name1' => $pairs[0]['name1'] ?? '',
                    'name2' => $pairs[0]['name2'] ?? '',
                ];
            }
            $prevRank = null;
            $runCount = 0; // сколько пар подряд с текущим prevRank
            foreach ($pairs as $idx => $pair) {
                $r = $pair['rank'] ?? null;
                if ($r === null) {
                    $issues[] = [
                        'type' => 'rank_missing',
                        'detail' => 'У пары в строке ' . ($idx + 1) . ' не указано место',
                        'name1' => $pair['name1'] ?? '',
                        'name2' => $pair['name2'] ?? '',
                    ];
                    continue;
                }
                $r = (int)$r;
                if ($prevRank !== null) {
                    if ($r < $prevRank) {
                        $issues[] = [
                            'type' => 'rank_decreasing',
                            'detail' => "Место идёт не по порядку: было {$prevRank}, затем {$r} (места не должны уменьшаться вниз по таблице)",
                            'rank' => $r,
                            'prev_rank' => $prevRank,
                            'name1' => $pair['name1'] ?? '',
                            'name2' => $pair['name2'] ?? '',
                        ];
                    } elseif ($r === $prevRank) {
                        $runCount++;
                    } elseif ($r > $prevRank) {
                        // допустимо: dense prev+1 или competition prev+runCount
                        $dense = $prevRank + 1;
                        $competition = $prevRank + $runCount;
                        if ($r !== $dense && $r !== $competition) {
                            $issues[] = [
                                'type' => 'rank_gap',
                                'detail' => "После места {$prevRank} (пар с этим местом: {$runCount}) ожидалось следующее место {$dense} или {$competition}, а указано {$r}",
                                'rank' => $r,
                                'prev_rank' => $prevRank,
                                'name1' => $pair['name1'] ?? '',
                                'name2' => $pair['name2'] ?? '',
                            ];
                        }
                        $runCount = 1;
                    }
                } else {
                    $runCount = 1;
                }
                $prevRank = $r;
            }
            if ($issues) {
                $warnings[] = [
                    'code' => 'rank_sequence',
                    'message' => "В таблице «{$tableName}» места указаны не по порядку: должны начинаться с 1 и не уменьшаться вниз по списку (знак «=» означает то же место, что у предыдущей пары).",
                    'table' => $tableName,
                    'count' => count($issues),
                    'issues' => $issues,
                ];
            }
        };
        $checkRankSequence('Sum', $parsed['sum_pairs'] ?? []);
        foreach ($parsed['sessions'] ?? [] as $sess) {
            $checkRankSequence($sess['name'] ?? 'Сессия', $sess['pairs'] ?? []);
        }

        $jsonReport = [
            'format' => $format,
            'meta' => $meta,
            'summary' => [
                'players_total' => $total,
                'pairs_sum' => count($sumPairsOut),
                'sessions' => count($sessionsOut),
                'ok' => count($results['ok']),
                'name_mismatch' => count($results['name_mismatch']),
                'no_id' => count($results['no_id']),
                'unknown_id' => count($results['unknown_id']),
                'judges_ok' => count($results['judges_ok']),
                'judges_issues' => count($results['judges_issues']),
                'status_warn' => count($results['status_warn'] ?? []),
                'warnings' => count($warnings),
            ],
            'warnings' => $warnings,
            'judges' => $results['json_judges'],
            'sum' => [
                'pairs' => $sumPairsOut,
            ],
            'sessions' => $sessionsOut,
        ];

        } // end format team/pair
        } // end non-club_mb else
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (isset($tmp) && is_file($tmp)) @unlink($tmp);
    }
}
?>
<style>
.card{background:var(--card);border-radius:16px;padding:28px;max-width:560px;margin:20px auto}
.drop{border:2px dashed #334155;border-radius:12px;padding:28px;text-align:center;cursor:pointer}
.drop:hover{border-color:var(--accent)}
input[type=file]{display:none}
.btn{display:block;width:100%;margin-top:16px;background:var(--accent);color:#fff;border:0;padding:12px;border-radius:8px;font-size:1rem;cursor:pointer}
.btn-next{background:var(--ok);margin-top:12px}
.flash{background:rgba(239,68,68,.15);color:#fca5a5;padding:12px;border-radius:8px;margin-bottom:16px;line-height:1.4}
.note{font-size:.85rem;color:var(--muted);margin-top:14px;line-height:1.4}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin:20px 0}
.stat{background:var(--card);border-radius:12px;padding:14px;text-align:center}
.stat .n{font-size:1.7rem;font-weight:700}
.stat .l{font-size:.75rem;color:var(--muted);margin-top:4px}
.ok .n{color:var(--ok)}.err .n{color:var(--err)}.warn .n{color:var(--warn)}
section{background:var(--card);border-radius:12px;padding:18px;margin-bottom:16px}
section h2{margin:0 0 10px;font-size:1.05rem}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #2a3548}
th{color:var(--muted);font-size:.72rem;text-transform:uppercase}
a{color:var(--accent)}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.75rem}
.badge-err{background:rgba(239,68,68,.15);color:var(--err)}
.badge-warn{background:rgba(245,158,11,.15);color:var(--warn)}
.wrap{max-width:1100px;margin:0 auto}
h1{font-size:1.4rem;margin:0 0 8px}
.sub{color:var(--muted);margin-bottom:20px;line-height:1.45}
</style>

<?php if ($results === null): ?>
<div class="card">
  <h1>Проверка отчёта</h1>
  <p class="sub">Сверка ID и имён с базой FSBR. Подходят <b>турнирные протоколы</b> (пары / команды / индивидуал) и <b>клубные МБ</b> («Отчет по МБ…»). Файлы <b>.xls</b> и <b>.xlsx</b>.</p>
  <?php if ($error): ?><div class="flash"><?= h($error) ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
    <label class="drop" id="drop">
      <div id="label">Выберите файл .xls или .xlsx</div>
      <input type="file" name="file" accept=".xlsx,.xls" id="file" required>
    </label>
    <button class="btn" type="submit">Проверить</button>
  </form>
  <p class="note">Турнир: вкладки Sum, «Общая информация», сессии. Клубные МБ: регион, период, id / игрок / МБ — дальше сразу SQL (без расчёта рейтинга).</p>
</div>
<script>
const f=document.getElementById('file'),l=document.getElementById('label'),d=document.getElementById('drop');
f.onchange=()=>{if(f.files[0])l.textContent=f.files[0].name};
d.ondragover=e=>e.preventDefault();d.ondrop=e=>{e.preventDefault();if(e.dataTransfer.files[0]){f.files=e.dataTransfer.files;l.textContent=e.dataTransfer.files[0].name}};
</script>
<?php else: ?>
<div class="wrap">
  <p><a href="?tab=check">← Загрузить другой файл</a>
    <?php if (!empty($jsonReport)): ?>
    · <button type="button" id="btn-json" style="background:var(--accent);color:#fff;border:0;padding:6px 12px;border-radius:6px;cursor:pointer">Скачать JSON</button>
    <?php endif; ?>
  </p>
  <?php if (!empty($jsonReport)): ?>
  <script>
  document.getElementById('btn-json').onclick = function() {
    var data = <?= json_encode($jsonReport, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>;
    var blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json;charset=utf-8'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'tournament_report.json';
    a.click();
  };
  </script>
  <?php endif; ?>

  <h1>Результат проверки</h1>
  <p class="sub">
    <?= h($meta['title'] ?? 'Отчёт') ?>
    <?php if (!empty($meta['place'])): ?> · <?= h($meta['place']) ?><?php endif; ?>
    <?php if (!empty($meta['date_from']) || !empty($meta['date_to'])): ?>
      · <?= h($meta['date_from'] ?? '?') ?><?php if (!empty($meta['date_to'])): ?> — <?= h($meta['date_to']) ?><?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($meta['scoring'])): ?> · <?= h($meta['scoring']) ?><?php endif; ?>
    <?php if (isset($meta['length'])): ?> · длина <?= h((string)$meta['length']) ?><?php if (isset($meta['length_actual'])): ?> (факт. <?= h((string)$meta['length_actual']) ?>)<?php endif; ?><?php endif; ?>
    · игроков: <?= (int)$total ?>
  </p>
  <?php if (!empty($jsonReport['warnings'])): ?>
  <section style="border:1px solid var(--warn);">
    <h2>⚠ Предупреждения (<?= count($jsonReport['warnings']) ?>)</h2>
    <?php foreach ($jsonReport['warnings'] as $w): ?>
      <p style="margin:8px 0;"><?= h($w['message'] ?? '') ?>
        <?php if (!empty($w['count'])): ?> <b>(<?= (int)$w['count'] ?>)</b><?php endif; ?>
      </p>
      <?php if (!empty($w['pairs'])): ?>
      <table>
        <tr><th>Место</th><th>Игрок 1</th><th>ID1</th><th>Игрок 2</th><th>ID2</th></tr>
        <?php foreach ($w['pairs'] as $pp): ?>
        <tr>
          <td><?= h((string)($pp['rank'] ?? '=')) ?></td>
          <td><?= h($pp['name1'] ?? '') ?></td>
          <td><?= h((string)($pp['id1'] ?? '')) ?></td>
          <td><?= h($pp['name2'] ?? '') ?></td>
          <td><?= h((string)($pp['id2'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
      <?php if (!empty($w['duplicates'])): ?>
      <table>
        <tr><th>ID</th><th>Имя</th><th>Место</th><th>Также на месте</th><th>Также имя</th></tr>
        <?php foreach ($w['duplicates'] as $d): ?>
        <tr>
          <td><?= h((string)($d['player_id'] ?? '')) ?></td>
          <td><?= h($d['name'] ?? '') ?></td>
          <td><?= h((string)($d['rank'] ?? '')) ?></td>
          <td><?= h((string)($d['also_at_rank'] ?? '')) ?></td>
          <td><?= h($d['also_name'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
      <?php if (!empty($w['items'])): ?>
      <table>
        <tr><th>Команда</th><th>Причина</th><th>Игроки</th></tr>
        <?php foreach ($w['items'] as $it): ?>
        <tr>
          <td><?= h($it['team'] ?? '—') ?></td>
          <td><?= h($it['reason'] ?? '') ?></td>
          <td><?php
            $names = [];
            foreach ($it['players'] ?? [] as $pl) {
              $names[] = ($pl['name'] ?? '') . (isset($pl['player_id']) ? ' (ID '.$pl['player_id'].')' : '');
            }
            echo h(implode(', ', $names));
          ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
      <?php if (!empty($w['issues'])): ?>
      <table>
        <tr><th>Описание</th><th>Пары / участники</th></tr>
        <?php foreach ($w['issues'] as $iss): ?>
        <tr>
          <td><?= h($iss['detail'] ?? '') ?></td>
          <td><?php
            if (!empty($iss['pair_a'])) {
              echo h(($iss['pair_a']['name1'] ?? '') . ' / ' . ($iss['pair_a']['name2'] ?? '')
                . ' (' . ($iss['pair_a']['result'] ?? '') . ') → '
                . ($iss['pair_b']['name1'] ?? '') . ' / ' . ($iss['pair_b']['name2'] ?? '')
                . ' (' . ($iss['pair_b']['result'] ?? '') . ')');
            } else {
              echo h(trim(($iss['name1'] ?? '') . ' / ' . ($iss['name2'] ?? ''), ' /'));
            }
          ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <div class="stats">
    <div class="stat ok"><div class="n"><?= count($results['ok']) ?></div><div class="l">OK</div></div>
    <div class="stat err"><div class="n"><?= count($results['name_mismatch']) ?></div><div class="l">Имя ≠ ID</div></div>
    <div class="stat warn"><div class="n"><?= count($results['no_id']) ?></div><div class="l">Нет ID</div></div>
    <div class="stat err"><div class="n"><?= count($results['unknown_id']) ?></div><div class="l">ID нет в базе</div></div>
    <div class="stat warn"><div class="n"><?= count($results['status_warn'] ?? []) ?></div><div class="l">Статус 3/4</div></div>
    <div class="stat ok"><div class="n"><?= count($results['judges_ok']) ?></div><div class="l">Судьи OK</div></div>
    <div class="stat warn"><div class="n"><?= count($results['judges_issues']) ?></div><div class="l">Судьи — проблемы</div></div>
  </div>
  <?php
  // --- Отчёт о проверке сессий / этапов ---
  $sessionsCheck = $jsonReport['sessions'] ?? [];
  $isClubReport = !empty($clubMbMode) || (($jsonReport['format'] ?? '') === 'club_mb');
  if ($sessionsCheck && !$isClubReport): ?>
  <section>
    <h2>Проверка сессий / этапов (<?= count($sessionsCheck) ?>)</h2>
    <?php foreach ($sessionsCheck as $sess):
      $spairs = $sess['pairs'] ?? [];
      $okN = 0;
      $iss = [];
      $unitCount = 0;
      foreach ($spairs as $pair) {
        foreach (['player1', 'player2'] as $side) {
          $pl = $pair[$side] ?? null;
          if (!$pl || !is_array($pl)) continue;
          $pname = trim((string)($pl['name'] ?? ''));
          $pid = $pl['player_id'] ?? null;
          // пустой слот (типично player2 у индивидуала) — пропускаем
          if ($pname === '' && ($pid === null || $pid === '')) continue;
          $unitCount++;
          $st = $pl['status'] ?? null;
          if ($st === null) {
            if ($pid === null || $pid === '') $st = 'no_id';
            else $st = 'ok';
          }
          if ($st === 'ok') {
            $okN++;
          } else {
            $iss[] = [
              'rank' => $pair['rank'] ?? '',
              'name' => $pname,
              'id' => $pid,
              'db' => $pl['db_fio'] ?? '',
              'status' => $st,
            ];
          }
        }
      }
      $isIndSess = !empty($spairs[0]['layout']) && $spairs[0]['layout'] === 'individual';
      if (!$isIndSess && isset($spairs[0]) && empty($spairs[0]['player2'])) {
        $isIndSess = true;
      }
    ?>
    <h3 style="margin:16px 0 8px;font-size:1rem"><?= h($sess['name'] ?? 'Этап') ?>
      · <?= $isIndSess ? 'игроков' : 'пар' ?>: <?= count($spairs) ?>
      <?php if (!empty($sess['boards'])): ?> · сдач: <?= (int)$sess['boards'] ?><?php endif; ?>
      · OK: <?= (int)$okN ?> · проблем: <?= count($iss) ?>
    </h3>
    <?php if ($iss): ?>
    <table>
      <tr><th>Место</th><th>ID</th><th>В отчёте</th><th>В базе</th><th>Проблема</th></tr>
      <?php foreach ($iss as $row): ?>
      <tr>
        <td><?= h((string)$row['rank']) ?></td>
        <td><?= h((string)($row['id'] ?? '—')) ?></td>
        <td><?= h($row['name'] ?? '') ?></td>
        <td><?= h($row['db'] ?? '—') ?></td>
        <td><?php
          $st = $row['status'] ?? '';
          echo $st === 'no_id' ? '<span class="badge badge-warn">нет ID</span>'
            : ($st === 'unknown_id' ? '<span class="badge badge-err">ID нет в базе</span>'
            : ($st === 'name_mismatch' ? '<span class="badge badge-err">имя ≠ база</span>'
            : h($st)));
        ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="note" style="margin:4px 0 12px">Замечаний по ID/именам нет.</p>
    <?php endif; ?>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php
  $fmtSugg = function($p) {
    $s = $p['suggestions'] ?? [];
    if (!$s) return '—';
    $bits = [];
    foreach ($s as $sg) {
      $bits[] = ($sg['player_id'] ?? '') . ' ' . ($sg['db_fio'] ?? '');
    }
    return implode('; ', $bits);
  };
  $blocks = [
    ['no_id', 'Нет ID', ['Место','Имя','Партнёр','Возможные ID'], fn($p)=>[$p['rank']??'—',$p['name']??'',$p['partner']??'—', $fmtSugg($p)]],
    ['unknown_id', 'ID нет в базе', ['Место','ID','Имя','Партнёр','Возможные ID'], fn($p)=>[$p['rank']??'—',$p['player_id']??'',$p['name']??'',$p['partner']??'—', $fmtSugg($p)]],
    ['name_mismatch', 'Несовпадение имени', ['Место','ID','В отчёте','В базе'], fn($p)=>[$p['rank']??'—',$p['player_id']??'',$p['name']??'',$p['db_fio']??'']],
  ];
  $isClubReport = !empty($clubMbMode) || (($jsonReport['format'] ?? '') === 'club_mb');
  foreach ($blocks as [$key,$title,$headers,$fn]):
    if ($isClubReport) continue; // у клубных свой отчёт с МБ
    if (empty($results[$key])) continue; ?>
  <section>
    <h2><?= h($title) ?> (<?= count($results[$key]) ?>)</h2>
    <table>
      <tr><?php foreach ($headers as $hh): ?><th><?= h($hh) ?></th><?php endforeach; ?></tr>
      <?php foreach ($results[$key] as $row): ?><tr><?php foreach ($fn($row) as $c): ?><td><?= h((string)$c) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </table>
  </section>
  <?php endforeach; ?>

  <?php if (!empty($results['status_warn'])): ?>
  <section style="border:1px solid var(--warn);">
    <h2>⚠ Статус игрока (умер / не активен) — <?= count($results['status_warn']) ?></h2>
    <table>
      <tr><th>ID</th><th>ФИО (база)</th><th>В отчёте</th><th>Статус</th><th>Город</th></tr>
      <?php foreach ($results['status_warn'] as $sw): ?>
      <tr>
        <td><?= h((string)($sw['player_id'] ?? '')) ?></td>
        <td><?= h($sw['db_fio'] ?? '') ?></td>
        <td><?= h($sw['name'] ?? '') ?></td>
        <td><span class="badge badge-warn"><?= h($sw['state_label'] ?? state_label($sw['state'] ?? null)) ?></span></td>
        <td><?= h($sw['city'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </section>
  <?php endif; ?>

  <?php if (empty($clubMbMode) && (($jsonReport['format'] ?? '') !== 'club_mb')): ?>
  <section>
    <h2>Участники (<?= count($results['json_players'] ?? []) ?>)</h2>
    <table>
      <tr><th>ID</th><th>ФИО (база)</th><th>В отчёте</th><th>Разряд q</th><th>Город</th><th>Статус</th><th>Проверка</th></tr>
      <?php foreach ($results['json_players'] as $jp): ?>
      <tr>
        <td><?= h((string)($jp['player_id'] ?? '—')) ?></td>
        <td><?= h($jp['db_fio'] ?? '—') ?></td>
        <td><?= h($jp['name'] ?? '') ?></td>
        <td><?php
          $qq = $jp['q'] ?? null;
          if ($qq === null && array_key_exists('razr', $jp)) {
            $qq = q_from_razr($jp['razr']);
          }
          echo $qq === null ? '—' : h(rtrim(rtrim(number_format((float)$qq, 2, '.', ''), '0'), '.'));
        ?></td>
        <td><?= h($jp['city'] ?? '—') ?></td>
        <td><?= h(state_label($jp['state'] ?? null)) ?></td>
        <td><?php
          $st = $jp['status'] ?? '';
          echo $st === 'ok' ? '<span class="badge" style="background:rgba(34,197,94,.15);color:var(--ok)">OK</span>'
            : ($st === 'no_id' ? '<span class="badge badge-warn">нет ID</span>'
            : ($st === 'unknown_id' ? '<span class="badge badge-err">ID нет в базе</span>'
            : ($st === 'name_mismatch' ? '<span class="badge badge-err">имя ≠ база</span>'
            : h($st))));
        ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </section>
  <?php endif; /* not club_mb participants */ ?>

<?php if (!empty($results['city_stats']) && empty($clubMbMode) && (($jsonReport['format'] ?? '') !== 'club_mb')): ?>
  <section>
    <h2>Статистика по городам</h2>
    <table>
      <tr><th>Город</th><th>Игроков</th></tr>
      <?php foreach ($results['city_stats'] as $cs): ?>
      <tr>
        <td><?= h($cs['city'] ?? '') ?></td>
        <td><?= (int)($cs['count'] ?? 0) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </section>
  <?php endif; ?>

  <?php if (false && $results['judges_issues']): ?>
  <section>
    <h2>Судьи — проблемы</h2>
    <table>
      <tr><th>ID</th><th>Имя</th><th>Позиция</th><th>Проблема</th><th>В базе</th></tr>
      <?php foreach ($results['judges_issues'] as $j): ?>
      <tr>
        <td><?= h((string)($j['player_id'] ?? '—')) ?></td>
        <td><?= h($j['name'] ?? '') ?></td>
        <td><?= h($j['role'] ?? '') ?></td>
        <td><?php
          $iss=$j['issue']??'';
          echo $iss==='no_id'?'<span class="badge badge-warn">нет ID</span>':($iss==='unknown_id'?'<span class="badge badge-err">ID нет в базе</span>':'<span class="badge badge-err">имя ≠ база</span>');
        ?></td>
        <td><?= h($j['db_fio'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </section>
  <?php endif; ?>

  <?php if (!empty($results['judges_ok']) && empty($clubMbMode) && (($jsonReport['format'] ?? '') !== 'club_mb')): ?>
  <section>
    <h2>Судьи (<?= count($results['judges_ok']) + count($results['judges_issues'] ?? []) ?>)</h2>
    <table>
      <tr><th>ID</th><th>В отчёте</th><th>Позиция</th><th>В базе</th><th>Статус</th></tr>
      <?php foreach ($results['judges_ok'] as $j): ?>
      <tr>
        <td><?= h((string)($j['player_id'] ?? '—')) ?></td>
        <td><?= h($j['name'] ?? '') ?></td>
        <td><?= h($j['role'] ?? '') ?></td>
        <td><?= h($j['db_fio'] ?? '—') ?></td>
        <td><span class="badge" style="background:rgba(34,197,94,.15);color:var(--ok)">OK</span></td>
      </tr>
      <?php endforeach; ?>
      <?php foreach ($results['judges_issues'] as $j): ?>
      <tr>
        <td><?= h((string)($j['player_id'] ?? '—')) ?></td>
        <td><?= h($j['name'] ?? '') ?></td>
        <td><?= h($j['role'] ?? '') ?></td>
        <td><?= h($j['db_fio'] ?? '—') ?></td>
        <td><?php
          $iss=$j['issue']??'';
          echo $iss==='no_id'?'<span class="badge badge-warn">нет ID</span>':($iss==='unknown_id'?'<span class="badge badge-err">ID нет в базе</span>':'<span class="badge badge-err">имя ≠ база</span>');
        ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </section>
  <?php elseif (!empty($results['judges_issues']) && empty($clubMbMode)): ?>
  <?php /* problems already listed above as «Судьи — проблемы» */ ?>
  <?php endif; ?>

  <?php if (!empty($clubMbMode) || (($jsonReport['format'] ?? '') === 'club_mb')): ?>
  <section>
    <h2>Клубные МБ — игроки (<?= (int)count($jsonReport['players'] ?? $results['ok'] ?? []) ?>)</h2>
    <p class="sub">
      Регион: <b><?= h($jsonReport['meta']['region'] ?? $meta['region'] ?? '—') ?></b>
      · период: <?= h(($jsonReport['meta']['date_from'] ?? '?') . ' — ' . ($jsonReport['meta']['date_to'] ?? '?')) ?>
      <?php if (!empty($jsonReport['city']['city_name'])): ?>
        · город: <b><?= h($jsonReport['city']['city_name']) ?></b>
      <?php endif; ?>
      · сумма МБ: <b><?= h((string)($jsonReport['summary']['sum_mb'] ?? '')) ?></b>
    </p>
    <table>
      <tr><th>#</th><th>ID</th><th>В отчёте</th><th>МБ</th><th>В базе</th><th>Город</th><th>Статус</th></tr>
      <?php
        $clubPlayers = $jsonReport['players'] ?? [];
        usort($clubPlayers, function ($a, $b) {
            return ((float)($b['mb'] ?? 0)) <=> ((float)($a['mb'] ?? 0));
        });
        foreach ($clubPlayers as $i => $p):
          $st = $p['status'] ?? '';
      ?>
      <tr>
        <td><?= (int)($p['n'] ?? $i + 1) ?></td>
        <td><?= h((string)($p['player_id'] ?? '—')) ?></td>
        <td><?= h($p['name'] ?? '') ?></td>
        <td><b><?= h((string)($p['mb'] ?? '0')) ?></b></td>
        <td><?= h($p['db_fio'] ?? '—') ?></td>
        <td><?= h($p['city'] ?? '—') ?></td>
        <td><?php
          if ($st === 'ok') echo '<span class="badge badge-ok">OK</span>';
          elseif ($st === 'name_mismatch') echo '<span class="badge badge-warn">имя ≠ база</span>';
          elseif ($st === 'unknown_id') echo '<span class="badge badge-err">ID нет в базе</span>';
          elseif ($st === 'no_id') echo '<span class="badge badge-warn">нет ID</span>';
          else echo h($st);
          if (!empty($p['status_warn'])) echo ' <span class="badge badge-warn">' . h($p['status_warn']) . '</span>';
        ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </section>
  <?php endif; /* club players table */ ?>
</div>
<?php endif; ?>

<?php if (!empty($jsonReport)):
  if (!isset($jsonReport['city_stats']) && !empty($results['city_stats'])) {
    $jsonReport['city_stats'] = $results['city_stats'];
  }
  if (!isset($jsonReport['status_warn']) && !empty($results['status_warn'])) {
    $jsonReport['status_warn'] = $results['status_warn'];
  }
  $_SESSION['report_json'] = json_encode($jsonReport, JSON_UNESCAPED_UNICODE);
endif; ?>
<?php if (!empty($jsonReport)): ?>
<section>
  <?php if (($jsonReport['format'] ?? '') === 'club_mb'): ?>
  <h2>Клубные МБ — расчёт не нужен</h2>
  <p class="sub">Формат type=5: только проверка игроков и SQL. Этап расчёта РО/ПБ/МБ пропускается.</p>
  <?php if (!empty($jsonReport['city']['city_name'])): ?>
  <p class="sub">Город: <b><?= h($jsonReport['city']['city_name']) ?></b> (id <?= (int)$jsonReport['city']['city_id'] ?>)
    · сумма МБ: <b><?= h((string)($jsonReport['summary']['sum_mb'] ?? '')) ?></b>
  </p>
  <?php endif; ?>
  <?php if (!empty($jsonReport['city_error'])): ?>
  <div class="flash"><?= h($jsonReport['city_error']) ?></div>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
    <input type="hidden" name="tab" value="check">
    <label>Город</label>
    <select name="city_id" style="width:100%;max-width:400px;padding:8px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)">
      <option value="">— выберите —</option>
      <?php
        $dbC = cmb_db();
        $cities = $dbC ? cmb_load_cities($dbC) : [];
        if ($dbC) $dbC->close();
        foreach ($cities as $c):
      ?>
      <option value="<?= (int)$c['city_id'] ?>"><?= h($c['city_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="note">Загрузите тот же файл снова с выбранным городом.</p>
    <input type="file" name="file" accept=".xls,.xlsx" required>
    <input type="hidden" name="tourn_id" value="<?= h($_POST['tourn_id'] ?? '') ?>">
    <button class="btn" type="submit">Проверить снова</button>
  </form>
  <?php elseif (!empty($jsonReport['sql'])): ?>
  <form method="post" action="?tab=sql"><?= csrf_field() ?>
    <input type="hidden" name="tab" value="sql">
    <input type="hidden" name="from_club_mb" value="1">
    <button class="btn btn-next" type="submit">Перейти к SQL →</button>
  </form>
  <?php endif; ?>
  <?php else: ?>
  <h2>Дальше: расчёт рейтинга</h2>
  <p class="sub">Отчёт уже сохранён в сессии — можно перейти к расчёту РО / ПБ / МБ.</p>
  <p><a class="btn btn-next" href="?tab=rating" style="display:inline-block;text-decoration:none;text-align:center">Перейти к расчёту →</a></p>
  <?php endif; ?>
</section>
<?php endif; ?>
