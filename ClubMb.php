<?php
declare(strict_types=1);
/**
 * Клубные МБ: разбор отчёта, проверка игроков, SQL type=5.
 */
require_once __DIR__ . '/names.php';
require_once __DIR__ . '/SqlExporter.php';
require_once __DIR__ . '/XlsReader.php';
require_once __DIR__ . '/bootstrap.php';

function cmb_h($s): string {
    return h($s);
}

function cmb_db(): ?mysqli {
    return db_ro();
}

function cmb_cell(array $row, int $i): string {
    if (!array_key_exists($i, $row) || $row[$i] === null) return '';
    $v = $row[$i];
    if (is_int($v) || is_float($v)) {
        if (is_float($v) && abs($v - round($v)) < 1e-9) return (string)(int)round($v);
        return (string)$v;
    }
    return trim((string)$v);
}

function cmb_parse_date($v): ?string {
    if ($v === null || $v === '') return null;
    if (is_numeric($v) && (float)$v > 20000) {
        // Excel serial
        $unix = ((int)(float)$v - 25569) * 86400;
        return gmdate('Y-m-d', $unix);
    }
    $s = trim((string)$v);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    // "1 сентября 2026"
    $months = [
        'января'=>1,'февраля'=>2,'марта'=>3,'апреля'=>4,'мая'=>5,'июня'=>6,
        'июля'=>7,'августа'=>8,'сентября'=>9,'октября'=>10,'ноября'=>11,'декабря'=>12,
    ];
    if (preg_match('/(\d{1,2})\s+([а-яё]+)\s+(\d{4})/ui', $s, $m)) {
        $mon = mb_strtolower($m[2], 'UTF-8');
        if (isset($months[$mon])) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], $months[$mon], (int)$m[1]);
        }
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * @return array{meta:array,players:list<array>,errors:list<string>}
 */
function cmb_parse_rows(array $rows): array
{
    $meta = [
        'region' => '',
        'club' => '',
        'date_from' => null,
        'date_to' => null,
        'total_declared' => null,
    ];
    $players = [];
    $errors = [];

    foreach ($rows as $ri => $row) {
        $c0 = cmb_cell($row, 0);
        $c1 = cmb_cell($row, 1);
        $c2 = cmb_cell($row, 2);
        $c3 = cmb_cell($row, 3);
        $c6 = cmb_cell($row, 6);
        $c7 = cmb_cell($row, 7);

        // meta row: Регион | name | Клуб | club | Дата с | date
        if (mb_stripos($c1, 'Регион') !== false || mb_stripos($c0, 'Регион') !== false) {
            $meta['region'] = $c2 !== '' ? $c2 : $c1;
            if (mb_stripos($c1, 'Регион') !== false) {
                $meta['region'] = $c2;
            }
            // club in col3 label "Клуб", value col4
            $c4 = cmb_cell($row, 4);
            if (mb_stripos(cmb_cell($row, 3), 'Клуб') !== false && $c4 !== '') {
                $meta['club'] = $c4;
            }
            // Дата с often col6 label area, value col7 or next
            for ($ci = 0; $ci < count($row); $ci++) {
                $cv = cmb_cell($row, $ci);
                if (mb_stripos($cv, 'Дата с') !== false || $cv === 'с') {
                    $meta['date_from'] = cmb_parse_date($row[$ci + 1] ?? null) ?? $meta['date_from'];
                }
                if ($cv === 'по' || mb_stripos($cv, 'Дата по') !== false) {
                    $meta['date_to'] = cmb_parse_date($row[$ci + 1] ?? null) ?? $meta['date_to'];
                }
            }
            // common layout: col6 = date from value when label is nearby
            if ($meta['date_from'] === null && $c6 !== '') {
                $meta['date_from'] = cmb_parse_date($row[6] ?? null);
            }
            if ($meta['date_to'] === null && $c7 !== '') {
                $meta['date_to'] = cmb_parse_date($row[7] ?? null);
            }
            continue;
        }
        if (mb_stripos($c1, 'Всего игроков') !== false || mb_stripos($c0, 'Всего') !== false) {
            if (is_numeric($c3)) {
                $meta['total_declared'] = (int)$c3;
            } elseif (is_numeric($c2)) {
                $meta['total_declared'] = (int)$c2;
            }
            // dates on this row too
            for ($ci = 0; $ci < count($row); $ci++) {
                $cv = cmb_cell($row, $ci);
                if ($cv === 'по' || mb_stripos($cv, 'по') === 0) {
                    $d = cmb_parse_date($row[$ci + 1] ?? null);
                    if ($d) $meta['date_to'] = $d;
                }
            }
            if ($meta['date_to'] === null && $c7 !== '') {
                $meta['date_to'] = cmb_parse_date($row[7] ?? null);
            }
            continue;
        }

        // header # id Игрок МБ
        if ($c0 === '#' || (mb_strtolower($c1) === 'id' && mb_stripos($c2, 'Игрок') !== false)) {
            continue;
        }
        if (mb_stripos($c0, 'Отчет по МБ') !== false) {
            continue;
        }

        // left block: #, id, name, MB
        $add = function ($num, $idRaw, $name, $mbRaw) use (&$players) {
            $name = trim((string)$name);
            $idRaw = trim((string)$idRaw);
            $mbRaw = trim((string)$mbRaw);
            if ($name === '' && $idRaw === '') return;
            if ($name === '' && !is_numeric($idRaw)) return;
            $pid = (is_numeric($idRaw) && $idRaw !== '') ? (int)$idRaw : null;
            $mb = (is_numeric($mbRaw) && $mbRaw !== '') ? (0 + $mbRaw) : null;
            if ($pid === null && $name === '') return;
            if ($mb === null || $mb === 0) {
                // skip empty MB rows
                if ($name === '' && $pid === null) return;
            }
            $players[] = [
                'n' => is_numeric($num) ? (int)$num : null,
                'player_id' => $pid,
                'name' => $name,
                'mb' => $mb,
            ];
        };

        if (is_numeric($c0) || $c0 === '') {
            if ($c1 !== '' || $c2 !== '') {
                $add($c0, $c1, $c2, $c3);
            }
        }
        // right block: col5 #, col6 id, col7 name, col8 MB
        $c5 = cmb_cell($row, 5);
        $c8 = cmb_cell($row, 8);
        if (is_numeric($c5) || ($c6 !== '' && $c7 !== '')) {
            if ($c6 !== '' || $c7 !== '') {
                $add($c5, $c6, $c7, $c8);
            }
        }
    }

    // dedupe by player_id (sum MB if duplicate)
    $byId = [];
    $noId = [];
    foreach ($players as $p) {
        if ($p['player_id'] !== null) {
            $k = (int)$p['player_id'];
            if (!isset($byId[$k])) {
                $byId[$k] = $p;
            } else {
                $byId[$k]['mb'] = (float)($byId[$k]['mb'] ?? 0) + (float)($p['mb'] ?? 0);
            }
        } else {
            $noId[] = $p;
        }
    }
    $players = array_values($byId);
    foreach ($noId as $p) {
        $players[] = $p;
    }

    return ['meta' => $meta, 'players' => $players, 'errors' => $errors];
}

/** Прочитать лист .xls/.xlsx в массив строк (для разбора клубных МБ). */
function cmb_read_rows(string $path, string $orig): array
{
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === 'xls') {
        $xls = new XlsReader($path);
        $rows = [];
        foreach ($xls->sheetNames() as $name) {
            $r = $xls->readSheet($name);
            if (count($r) > 3) {
                $rows = $r;
                break;
            }
            if (!$rows && $r) {
                $rows = $r;
            }
        }
    } elseif ($ext === 'xlsx') {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Для .xlsx нужно расширение PHP zip (ZipArchive)');
        }
        $zip = new ZipArchive();
        if (!is_file($path) || filesize($path) < 22 || $zip->open($path) !== true) {
            throw new RuntimeException('Не удалось открыть xlsx');
        }
        if (!zip_size_ok($zip)) {
            $zip->close();
            throw new RuntimeException('XLSX слишком большой после распаковки — файл отклонён');
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false && $ss !== '') {
            $sx = @simplexml_load_string($ss);
            if ($sx !== false) {
                $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $nodes = $sx->xpath('//*[local-name()="si"]');
                if (!$nodes) {
                    $nodes = $sx->xpath('//*[local-name()="si"]');
                }
                foreach ($nodes ?: [] as $si) {
                    $parts = $si->xpath('.//*[local-name()="t"]');
                    if (!$parts) {
                        $parts = $si->xpath('.//*[local-name()="t"]');
                    }
                    $text = '';
                    foreach ($parts ?: [] as $tt) {
                        $text .= (string)$tt;
                    }
                    $shared[] = $text;
                }
            }
        }
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($xml === false || $xml === '') {
            // первая попавшаяся sheet*.xml
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (preg_match('#xl/worksheets/sheet\d+\.xml$#', (string)$n)) {
                    $xml = $zip->getFromIndex($i);
                    break;
                }
            }
        }
        $zip->close();
        if ($xml === false || $xml === '') {
            throw new RuntimeException('В xlsx нет листа с данными');
        }
        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            throw new RuntimeException('Не разобрать sheet XML');
        }
        $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rowNodes = $sx->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');
        if (!$rowNodes) {
            $rowNodes = $sx->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');
        }
        $rows = [];
        foreach ($rowNodes ?: [] as $row) {
            $cells = $row->xpath('./*[local-name()="c"]');
            if (!$cells) {
                $cells = $row->xpath('./*[local-name()="c"]');
            }
            $r = [];
            foreach ($cells ?: [] as $c) {
                $ref = (string)$c['r'];
                $col = 0;
                if (preg_match('/([A-Z]+)/', $ref, $mm)) {
                    $letters = $mm[1];
                    for ($i = 0; $i < strlen($letters); $i++) {
                        $col = $col * 26 + (ord($letters[$i]) - 64);
                    }
                    $col--;
                } else {
                    $col = count($r);
                }
                $v = null;
                $vNode = $c->xpath('./*[local-name()="v"]');
                if (!$vNode) {
                    $vNode = $c->xpath('./*[local-name()="v"]');
                }
                $isInline = ((string)$c['t'] === 'inlineStr');
                if ($isInline) {
                    $tNodes = $c->xpath('.//m:t');
                    if (!$tNodes) {
                        $tNodes = $c->xpath('.//*[local-name()="t"]');
                    }
                    $v = '';
                    foreach ($tNodes ?: [] as $tt) {
                        $v .= (string)$tt;
                    }
                } elseif ($vNode) {
                    $raw = (string)$vNode[0];
                    if ((string)$c['t'] === 's') {
                        $v = $shared[(int)$raw] ?? $raw;
                    } elseif (is_numeric($raw)) {
                        $v = 0 + $raw;
                    } else {
                        $v = $raw;
                    }
                }
                $r[$col] = $v;
            }
            if ($r) {
                $max = max(array_keys($r));
                $line = array_fill(0, $max + 1, null);
                foreach ($r as $k => $v) {
                    $line[$k] = $v;
                }
                $rows[] = $line;
            }
        }
        if (count($rows) < 2) {
            throw new RuntimeException('xlsx: не удалось прочитать строки (проверьте расширение zip / формат файла)');
        }
    } else {
        throw new RuntimeException('Нужен .xls или .xlsx');
    }
    return $rows;
}

function cmb_load_file(string $path, string $orig): array
{
    $parsed = cmb_parse_rows(cmb_read_rows($path, $orig));
    $parsed['file'] = $orig;
    return $parsed;
}

function cmb_normalize_name(string $name): string {
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = str_replace(['ё','Ё'], ['е','е'], $s);
    return $s;
}

function cmb_validate(array $players): array
{
    $db = cmb_db();
    $out = [];
    foreach ($players as $p) {
        $entry = $p + ['status' => 'no_id', 'db_fio' => null, 'city' => null];
        $pid = $p['player_id'] ?? null;
        if ($pid === null || $pid === '') {
            $entry['status'] = 'no_id';
            $out[] = $entry;
            continue;
        }
        if (!$db) {
            $entry['status'] = 'ok'; // нет БД — не проверяем
            $out[] = $entry;
            continue;
        }
        $pid = (int)$pid;
        $res = $db->query("SELECT p.player_id, p.firstname AS family, p.lastname AS given, p.surname AS patronymic,
            c.city_name, p.state
            FROM players p LEFT JOIN cities c ON c.city_id = p.city_id
            WHERE p.player_id = {$pid} LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            $entry['status'] = 'unknown_id';
            $out[] = $entry;
            continue;
        }
        $dbFio = trim(($row['family'] ?? '') . ' ' . ($row['given'] ?? '') . ' ' . ($row['patronymic'] ?? ''));
        $entry['db_fio'] = $dbFio;
        $entry['city'] = $row['city_name'] ?? null;
        $entry['state'] = isset($row['state']) ? (int)$row['state'] : null;
        // то же сравнение, что при проверке турниров (порядок слов, инициалы, ё/е)
        if (trim((string)($p['name'] ?? '')) === '' || names_match((string)($p['name'] ?? ''), (string)($row['family'] ?? ''), (string)($row['given'] ?? ''), (string)($row['patronymic'] ?? ''))) {
            $entry['status'] = 'ok';
        } else {
            $entry['status'] = 'name_mismatch';
        }
        if (in_array((int)($row['state'] ?? 0), [3, 4], true)) {
            $entry['status_warn'] = (int)$row['state'] === 3 ? 'умер' : 'не активен';
        }
        $out[] = $entry;
    }
    if ($db) $db->close();
    return $out;
}

function cmb_region_aliases(): array
{
    // регион / написание → предпочтительное имя города в cities
    return [
        'ростовская область' => 'Ростов-На-Дону',
        'ростовская' => 'Ростов-На-Дону',
        'ростов' => 'Ростов-На-Дону',
        'ростов-на-дону' => 'Ростов-На-Дону',
        'ростов на дону' => 'Ростов-На-Дону',
        'с.-петербург' => 'С.-Петербург',
        'санкт-петербург' => 'С.-Петербург',
        'спб' => 'С.-Петербург',
        'питер' => 'С.-Петербург',
        'ленинградская область' => 'С.-Петербург',
        'московская область' => 'Москва',
        'москва' => 'Москва',
        'нижний новгород' => 'Н. Новгород',
        'н.новгород' => 'Н. Новгород',
        'н. новгород' => 'Н. Новгород',
        'нижегородская область' => 'Н. Новгород',
        'екатеринбург' => 'Екатеринбург',
        'свердловская область' => 'Екатеринбург',
        'новосибирск' => 'Новосибирск',
        'новосибирская область' => 'Новосибирск',
        'челябинск' => 'Челябинск',
        'челябинская область' => 'Челябинск',
        'самара' => 'Самара',
        'самарская область' => 'Самара',
        'пермь' => 'Пермь',
        'пермский край' => 'Пермь',
        'красноярск' => 'Красноярск',
        'красноярский край' => 'Красноярск',
        'омск' => 'Омск',
        'омская область' => 'Омск',
        'новокузнецк' => 'Новокузнецк',
        'кемеровская область' => 'Новокузнецк',
        'астана' => 'Астана',
        'нур-султан' => 'Астана',
        'алматы' => 'Алматы',
        'алма-ата' => 'Алматы',
        'ереван' => 'Ереван',
        'минск' => 'Минск',
        'сочи' => 'Сочи',
        'краснодар' => 'Краснодар',
        'краснодарский край' => 'Краснодар',
        'воронеж' => 'Воронеж',
        'воронежская область' => 'Воронеж',
        'казань' => 'Казань',
        'татарстан' => 'Казань',
        'уфа' => 'Уфа',
        'башкортостан' => 'Уфа',
        'тюмень' => 'Тюмень',
        'тюменская область' => 'Тюмень',
        'иркутск' => 'Иркутск',
        'иркутская область' => 'Иркутск',
        'хабаровск' => 'Хабаровск',
        'владивосток' => 'Владивосток',
        'приморский край' => 'Владивосток',
        'калининград' => 'Калининград',
        'калининградская область' => 'Калининград',
        'ярославль' => 'Ярославль',
        'тула' => 'Тула',
        'тверь' => 'Тверь',
        'рязань' => 'Рязань',
        'пенза' => 'Пенза',
        'саратов' => 'Саратов',
        'волгоград' => 'Волгоград',
        'томск' => 'Томск',
        'барнаул' => 'Барнаул',
        'владикавказ' => 'Владикавказ',
        'ставрополь' => 'Ставрополь',
        'ставропольский край' => 'Ставрополь',
    ];
}

function cmb_norm_place(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = str_replace(['ё', 'Ё'], ['е', 'е'], $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return $s;
}

/** @return list<array{city_id:int,city_name:string}> */
function cmb_load_cities(?mysqli $db): array
{
    if (!$db) return [];
    $res = @$db->query('SELECT city_id, city_name FROM cities ORDER BY city_name');
    if (!$res) return [];
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = ['city_id' => (int)$row['city_id'], 'city_name' => (string)$row['city_name']];
    }
    $res->free();
    return $out;
}

/**
 * @return array{city_id:?int, city_name:?string, matched_by:?string}
 */
function cmb_resolve_city(?mysqli $db, array $meta, ?int $forcedId = null): array
{
    if ($forcedId && $db) {
        $id = (int)$forcedId;
        $res = @$db->query("SELECT city_id, city_name FROM cities WHERE city_id = {$id} LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return ['city_id' => (int)$row['city_id'], 'city_name' => (string)$row['city_name'], 'matched_by' => 'manual'];
        }
    }
    if (!$db) {
        return ['city_id' => null, 'city_name' => null, 'matched_by' => null];
    }

    $rawList = [];
    foreach (['club', 'region'] as $k) {
        $s = trim((string)($meta[$k] ?? ''));
        if ($s !== '') $rawList[] = $s;
    }
    $aliases = cmb_region_aliases();
    $tryNames = [];
    foreach ($rawList as $raw) {
        $n = cmb_norm_place($raw);
        if (isset($aliases[$n])) {
            $tryNames[] = $aliases[$n];
        }
        // убрать «область/край/…»
        $stripped = preg_replace('/\s*(область|край|республика|город|г\.|р-н|район)\s*/ui', ' ', $raw);
        $stripped = trim($stripped ?? '');
        if ($stripped !== '') {
            $ns = cmb_norm_place($stripped);
            if (isset($aliases[$ns])) {
                $tryNames[] = $aliases[$ns];
            }
            $tryNames[] = $stripped;
        }
        $tryNames[] = $raw;
    }
    $tryNames = array_values(array_unique(array_filter($tryNames)));

    // сначала точные совпадения по всем вариантам, и только потом приблизительные
    foreach ($tryNames as $q) {
        $eq = $db->real_escape_string($q);
        $res = @$db->query("SELECT city_id, city_name FROM cities WHERE city_name = '{$eq}' LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return ['city_id' => (int)$row['city_id'], 'city_name' => (string)$row['city_name'], 'matched_by' => 'exact:' . $q];
        }
    }
    foreach ($tryNames as $q) {
        $eq = $db->real_escape_string($q);
        // LIKE с приоритетом более короткого имени (город, не область)
        $res = @$db->query("SELECT city_id, city_name FROM cities WHERE city_name LIKE '{$eq}%' ORDER BY LENGTH(city_name) ASC LIMIT 5");
        if ($res) {
            $rows = [];
            while ($row = $res->fetch_assoc()) $rows[] = $row;
            $res->free();
            if ($rows) {
                // предпочесть без «область»
                usort($rows, function ($a, $b) {
                    $sa = preg_match('/область|край|респ/ui', $a['city_name']) ? 1 : 0;
                    $sb = preg_match('/область|край|респ/ui', $b['city_name']) ? 1 : 0;
                    if ($sa !== $sb) return $sa - $sb;
                    return strlen($a['city_name']) - strlen($b['city_name']);
                });
                $row = $rows[0];
                return ['city_id' => (int)$row['city_id'], 'city_name' => (string)$row['city_name'], 'matched_by' => 'like:' . $q];
            }
        }
    }
    return ['city_id' => null, 'city_name' => null, 'matched_by' => null];
}

/** SQL: tourn_header type=5 + tourn_ind (team_id=player_id). results не трогаем. */
function cmb_comment_text($s): string
{
    $s = (string)$s;
    // невалидный UTF-8 ломал бы preg_replace(/u) → null → строка с \n осталась бы как есть
    $s = (string)@iconv('UTF-8', 'UTF-8//IGNORE', $s);
    $s = str_replace(["\r", "\n"], ' ', $s);
    $s = preg_replace('/\R+/u', ' ', $s) ?? $s;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = str_replace(['--', '/*', '*/'], ['—', '', ''], $s);
    return trim($s);
}

/**
 * Игроки, которых по умолчанию НЕ включаем в SQL: имя не совпало с базой или игрок «умер» / «не активен».
 * Включаются только по явному подтверждению (player_id в $includeIds).
 * @return list<array>
 */
function cmb_questionable(array $validated): array
{
    $out = [];
    foreach ($validated as $p) {
        if (($p['player_id'] ?? null) === null || ($p['status'] ?? '') === 'unknown_id') {
            continue; // эти пропускаются всегда
        }
        if (($p['status'] ?? '') === 'name_mismatch' || !empty($p['status_warn'])) {
            $out[] = $p;
        }
    }
    return $out;
}

function cmb_build_sql(array $meta, array $validated, ?int $cityId = null, ?int $tournId = null, ?string $cityName = null, array $includeIds = []): string
{
    $includeIds = array_map('intval', $includeIds);
    $lines = [];
    $region = trim((string)($meta['region'] ?? ''));
    $club = trim((string)($meta['club'] ?? ''));
    $df = $meta['date_from'] ?? null;
    $dt = $meta['date_to'] ?? null;
    // Имя всегда: «{Город} клубный»
    $nameBase = trim((string)($cityName ?? ''));
    if ($nameBase === '') {
        $nameBase = $club !== '' ? $club : $region;
        $nameBase = preg_replace('/\s*(область|край|республика)\s*/ui', '', $nameBase) ?? $nameBase;
        $nameBase = trim($nameBase);
    }
    $nameBase = preg_replace('/\s*клубн\w*\s*$/ui', '', $nameBase) ?? $nameBase;
    $nameBase = trim($nameBase);
    if ($nameBase === '') {
        $nameBase = 'Клубный';
    } else {
        $nameBase .= ' клубный';
    }
    $esc = function ($s) {
        $s = cmb_comment_text((string)$s);
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $s);
    };

    $lines[] = 'START TRANSACTION;';
    $lines[] = '';

    if ($tournId) {
        $tid = (string)(int)$tournId;
        // Только type=5 (клубные МБ). results не трогаем.
        // Если турнир другого типа — DELETE затронет 0 строк, INSERT упадёт на PK (защита).
        $lines[] = "DELETE FROM tourn_ind WHERE tour_id = {$tid}"
            . " AND EXISTS (SELECT 1 FROM tourn_header h WHERE h.tourn_id = {$tid} AND h.type = 5);";
        $lines[] = "DELETE FROM tourn_header WHERE tourn_id = {$tid} AND type = 5;";
        $lines[] = '';
        $tidSql = $tid;
    } else {
        foreach (SqlExporter::allocTournIdLines() as $ln) {
            $lines[] = $ln;
        }
        $tidSql = '@tourn_id';
    }

    if ($cityId === null || (int)$cityId < 1) {
        throw new InvalidArgumentException('city_id обязателен для клубных МБ');
    }
    $citySql = (string)(int)$cityId;
    $dateEnd = $dt ? "'{$dt}'" : 'NULL';
    $dateStart = $df ? "'{$df}'" : 'NULL';

    $lines[] = 'INSERT INTO tourn_header (tourn_id, name, tour_date, tour_date_start, type, city_id, status, n_deals, champ_t, parent, stream, prev_id, next_id, note)';
    $lines[] = 'VALUES (';
    $lines[] = "  {$tidSql},";
    $lines[] = "  '" . $esc($nameBase) . "',";
    $lines[] = "  {$dateEnd},";
    $lines[] = "  {$dateStart},";
    $lines[] = '  5,';
    $lines[] = "  {$citySql},";
    $lines[] = '  NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL';
    $lines[] = ');';
    $lines[] = '';

    $n = 0;
    foreach ($validated as $p) {
        if (($p['status'] ?? '') === 'unknown_id') {
            $lines[] = '-- SKIP unknown id ' . ($p['player_id'] ?? '') . ' ' . cmb_comment_text($p['name'] ?? '');
            continue;
        }
        if (($p['player_id'] ?? null) === null) {
            $lines[] = '-- SKIP no id: ' . cmb_comment_text($p['name'] ?? '');
            continue;
        }
        $isQuestionable = (($p['status'] ?? '') === 'name_mismatch') || !empty($p['status_warn']);
        if ($isQuestionable && !in_array((int)$p['player_id'], $includeIds, true)) {
            $why = (($p['status'] ?? '') === 'name_mismatch' ? 'имя не совпало с базой' : '') .
                   (!empty($p['status_warn']) ? ((($p['status'] ?? '') === 'name_mismatch') ? ', ' : '') . $p['status_warn'] : '');
            $lines[] = '-- SKIP id=' . (int)$p['player_id'] . ' (' . cmb_comment_text($why) . ') ' . cmb_comment_text($p['name'] ?? '') . ' — нужно подтверждение';
            continue;
        }
        $mb = (float)($p['mb'] ?? 0);
        if ($mb <= 0) {
            $lines[] = '-- SKIP zero MB id=' . (int)$p['player_id'];
            continue;
        }
        $pid = (int)$p['player_id'];
        $mbInt = (int)round($mb);
        $lines[] = "INSERT INTO tourn_ind (tind_id, tour_id, team_id, PB, RO, MB, EMB, Result, PlaceH, PlaceL) VALUES (0, {$tidSql}, {$pid}, 0, 0, {$mbInt}, 0, 0, 0, 0);";
        $n++;
    }
    $lines[] = '';
    $lines[] = 'COMMIT;';
    return implode("\n", $lines) . "\n";
}



// ============================================================ сборка клубных МБ из нескольких файлов

function cmb_to_num($v): ?float
{
    if (is_int($v) || is_float($v)) {
        return (float)$v;
    }
    $s = str_replace([',', ' ', "\xC2\xA0"], ['.', '', ''], trim((string)$v));
    return ($s !== '' && is_numeric($s)) ? (float)$s : null;
}

/**
 * Простая таблица «ФИ(О) / id / МБ» (.xls/.xlsx). Если над заголовком в колонке МБ есть «сумма…» — берётся она,
 * иначе суммируются все колонки «МБ».
 * @return array{players:list<array>,note:string}|null null — это не такая таблица
 */
function cmb_parse_simple_table(array $rows): ?array
{
    $hdr = null;
    $cols = ['name' => null, 'id' => null, 'mb' => []];
    foreach (array_slice($rows, 0, 12, true) as $ri => $row) {
        $name = $id = null;
        $mbs = [];
        foreach ($row as $ci => $cell) {
            $t = mb_strtolower(trim((string)($cell ?? '')), 'UTF-8');
            if ($name === null && preg_match('/^(фи|фио|фамилия[\s,]*имя|игрок)/u', $t)) {
                $name = $ci;
            } elseif ($id === null && $t === 'id') {
                $id = $ci;
            } elseif (preg_match('/^мб$/u', $t)) {
                $mbs[] = $ci;
            }
        }
        if ($name !== null && $id !== null && $mbs) {
            $hdr = $ri;
            $cols = ['name' => $name, 'id' => $id, 'mb' => $mbs];
            break;
        }
    }
    if ($hdr === null) {
        return null;
    }
    $use = $cols['mb'];
    $note = count($use) > 1 ? 'сумма колонок МБ' : 'колонка МБ';
    foreach ($cols['mb'] as $ci) {
        for ($r = max(0, $hdr - 3); $r <= $hdr; $r++) {
            if (preg_match('/сумм/ui', (string)($rows[$r][$ci] ?? ''))) {
                $use = [$ci];
                $note = 'колонка «' . trim((string)$rows[$r][$ci]) . '»';
                break 2;
            }
        }
    }
    $players = [];
    foreach ($rows as $ri => $row) {
        if ($ri <= $hdr) {
            continue;
        }
        $name = trim((string)($row[$cols['name']] ?? ''));
        $idRaw = trim((string)($row[$cols['id']] ?? ''));
        if ($name === '' && $idRaw === '') {
            continue;
        }
        $mb = 0.0;
        foreach ($use as $ci) {
            $mb += cmb_to_num($row[$ci] ?? null) ?? 0.0;
        }
        $players[] = [
            'player_id' => ($idRaw !== '' && ctype_digit($idRaw)) ? (int)$idRaw : null,
            'name' => $name,
            'mb' => $mb,
        ];
    }
    return ['players' => $players, 'note' => $note];
}

/**
 * JSON «results»: пары (name1,id1,name2,id2,mb) или одиночные (name,id,mb). МБ пары начисляется каждому игроку.
 * @return array{players:list<array>,note:string,city:?string,date:?string}
 */
function cmb_parse_json_report(string $text): array
{
    $d = json_decode(json_text_to_utf8($text), true);
    if (!is_array($d)) {
        throw new RuntimeException('Некорректный JSON');
    }
    $list = $d['results'] ?? ($d['players'] ?? null);
    if (!is_array($list)) {
        throw new RuntimeException('В JSON нет списка results');
    }
    $players = [];
    foreach ($list as $r) {
        if (!is_array($r)) {
            continue;
        }
        $mb = cmb_to_num($r['mb'] ?? null) ?? 0.0;
        foreach ([['name1', 'id1'], ['name2', 'id2'], ['name', 'id']] as [$nk, $ik]) {
            if (!array_key_exists($nk, $r) && !array_key_exists($ik, $r)) {
                continue;
            }
            $idRaw = trim((string)($r[$ik] ?? ''));
            $players[] = [
                'player_id' => ($idRaw !== '' && ctype_digit($idRaw)) ? (int)$idRaw : null,
                'name' => trim((string)($r[$nk] ?? '')),
                'mb' => $mb,
            ];
        }
    }
    $date = isset($d['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d['date']) ? (string)$d['date'] : null;
    return [
        'players' => $players,
        'note' => 'JSON, МБ пары каждому игроку',
        'city' => isset($d['city']) ? trim((string)$d['city']) : null,
        'date' => $date,
    ];
}

/**
 * Собрать один отчёт клубных МБ за период из нескольких файлов (JSON, простые таблицы, официальные отчёты).
 * Одинаковые игроки (по id) суммируются.
 * @param list<array{path:string,name:string}> $files
 * @param string $month 'YYYY-MM' или '' (тогда берётся из дат в JSON)
 */
function cmb_process_multi(array $files, string $month, ?int $forcedCity = null, ?int $tournId = null): array
{
    if (!$files) {
        throw new RuntimeException('Выберите файлы');
    }
    $byId = [];
    $noId = [];
    $sources = [];
    $city = null;
    $dates = [];
    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $players = [];
        $note = '';
        try {
            if ($ext === 'json') {
                $r = cmb_parse_json_report((string)file_get_contents($f['path']));
                $players = $r['players'];
                $note = $r['note'];
                $city = $city ?? ($r['city'] ?: null);
                if ($r['date']) {
                    $dates[] = $r['date'];
                }
            } elseif ($ext === 'xls' || $ext === 'xlsx') {
                if (cmb_is_club_mb_file($f['path'], $f['name'])) {
                    $r = cmb_load_file($f['path'], $f['name']);
                    $players = array_map(fn($p) => ['player_id' => $p['player_id'], 'name' => (string)$p['name'], 'mb' => (float)($p['mb'] ?? 0)], $r['players']);
                    $note = 'официальный отчёт по МБ';
                    $city = $city ?? (trim((string)($r['meta']['region'] ?? '')) ?: null);
                } else {
                    $r = cmb_parse_simple_table(cmb_read_rows($f['path'], $f['name']));
                    if ($r === null) {
                        throw new RuntimeException('не найдена таблица «ФИ / id / МБ»');
                    }
                    $players = $r['players'];
                    $note = $r['note'];
                }
            } else {
                throw new RuntimeException('нужен .json, .xls или .xlsx');
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Файл «' . $f['name'] . '»: ' . $e->getMessage());
        }
        $sum = 0.0;
        foreach ($players as $p) {
            $mb = (float)$p['mb'];
            $sum += $mb;
            if ($p['player_id'] !== null) {
                $k = (int)$p['player_id'];
                if (!isset($byId[$k])) {
                    $byId[$k] = ['n' => null, 'player_id' => $k, 'name' => $p['name'], 'mb' => 0.0, 'src' => []];
                }
                $byId[$k]['mb'] += $mb;
                $byId[$k]['src'][$f['name']] = ($byId[$k]['src'][$f['name']] ?? 0.0) + $mb;
            } else {
                $noId[] = ['n' => null, 'player_id' => null, 'name' => $p['name'], 'mb' => $mb, 'src' => [$f['name'] => $mb]];
            }
        }
        $sources[] = ['file' => $f['name'], 'note' => $note, 'players' => count($players), 'sum_mb' => $sum];
    }

    if ($month === '' && $dates) {
        $month = substr(min($dates), 0, 7);
    }
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m)) {
        throw new RuntimeException('Укажите месяц (ГГГГ-ММ)');
    }
    $dateFrom = $month . '-01';
    $dateTo = date('Y-m-t', strtotime($dateFrom));
    foreach ($dates as $dt) {
        if (substr($dt, 0, 7) !== $month) {
            throw new RuntimeException('В файлах есть дата ' . $dt . ' вне выбранного месяца ' . $month . ' — проверьте набор файлов');
        }
    }

    $merged = array_merge(array_values($byId), $noId);
    $parsed = [
        'meta' => ['region' => (string)$city, 'club' => '', 'date_from' => $dateFrom, 'date_to' => $dateTo],
        'players' => $merged,
    ];
    return cmb_process_multi_parsed($parsed, $sources, $forcedCity, $tournId);
}

/** Вторая половина сборки (без повторной загрузки файлов — например, после выбора города). */
function cmb_process_multi_parsed(array $parsed, array $sources, ?int $forcedCity = null, ?int $tournId = null): array
{
    $res = cmb_finalize($parsed, count($sources) . ' файл(ов)', $forcedCity, $tournId);
    $res['sources'] = $sources;
    $res['parsed'] = $parsed;
    return $res;
}

/** Быстрая проверка: это отчёт по клубным МБ? */
function cmb_is_club_mb_file(string $path, string $origName): bool
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    try {
        if ($ext === 'xls') {
            $xls = new XlsReader($path);
            foreach ($xls->sheetNames() as $name) {
                $rows = $xls->readSheet($name);
                foreach (array_slice($rows, 0, 5) as $row) {
                    $line = implode(' ', array_map(fn($x) => (string)($x ?? ''), $row));
                    if (preg_match('/Отчет по МБ|набранным в локальных/ui', $line)) {
                        return true;
                    }
                }
            }
        } elseif ($ext === 'xlsx' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $ss = $zip->getFromName('xl/sharedStrings.xml') ?: '';
                $zip->close();
                if (preg_match('/Отчет по МБ|набранным в локальных/ui', $ss)) {
                    return true;
                }
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

/**
 * Полный разбор + проверка для шага «Проверка отчёта».
 * @return array{format:string,meta:array,players:array,city:array,city_error:?string,sql:?string}
 */
function cmb_process_report(string $path, string $origName, ?int $forcedCity = null, ?int $tournId = null): array
{
    return cmb_finalize(cmb_load_file($path, $origName), $origName, $forcedCity, $tournId);
}

/** Проверка игроков, определение города и SQL для уже разобранного отчёта. */
function cmb_finalize(array $parsed, string $origName, ?int $forcedCity = null, ?int $tournId = null): array
{
    $validated = cmb_validate($parsed['players']);
    $db = cmb_db();
    $cityInfo = cmb_resolve_city($db, $parsed['meta'], $forcedCity);
    if ($db) {
        $db->close();
    }
    $cityId = $cityInfo['city_id'] ?? null;
    $cityError = null;
    $sql = null;
    $by = (string)($cityInfo['matched_by'] ?? '');
    if ($cityId === null) {
        $cityError = 'Не удалось определить город по региону «' . ($parsed['meta']['region'] ?? '')
            . '». Укажите city_id вручную.';
    } elseif ($by !== 'manual' && !str_starts_with($by, 'exact:')) {
        // приблизительное совпадение: SQL не строим, пока человек не подтвердит город
        $cityError = 'Город определён приблизительно (по региону «' . ($parsed['meta']['region'] ?? '')
            . '» → «' . ($cityInfo['city_name'] ?? '') . '»). Выберите город вручную и загрузите файл снова.';
        $cityInfo['city_id'] = null;
        $cityInfo['suggested'] = $cityId;
    } else {
        $sql = cmb_build_sql($parsed['meta'], $validated, (int)$cityId, $tournId, $cityInfo['city_name'] ?? null);
    }
    return [
        'format' => 'club_mb',
        'meta' => array_merge($parsed['meta'], [
            'title' => 'Клубные МБ: ' . ($parsed['meta']['region'] ?? ''),
            'date_from' => $parsed['meta']['date_from'] ?? null,
            'date_to' => $parsed['meta']['date_to'] ?? null,
        ]),
        'players' => $validated,
        'judges' => [],
        'city' => $cityInfo,
        'city_error' => $cityError,
        'sql' => $sql,
        'file' => $origName,
    ];
}
