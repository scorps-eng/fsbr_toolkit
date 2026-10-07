<?php
declare(strict_types=1);
/**
 * Админка справочников: чистые функции (без БД).
 * Типы колонок определяются по SHOW COLUMNS, SQL строится только для белого списка таблиц и найденных колонок.
 */

const ADM_TABLES = [
    'cities' => [
        'title' => 'Города', 'pk' => 'city_id', 'list' => ['city_id', 'city_name', 'razr_coeff'],
        'search' => ['city_name'], 'order' => 'city_name', 'insert' => true, 'cp1251' => false, 'auto' => [],
    ],
    'clubs' => [
        'title' => 'Клубы', 'pk' => 'club_id', 'list' => ['club_id', 'name', 'shortname', 'city_id', 'abbr'],
        'search' => ['name', 'shortname', 'abbr'], 'order' => 'name', 'insert' => true, 'cp1251' => false, 'auto' => [],
    ],
    'players' => [
        'title' => 'Игроки', 'pk' => 'player_id', 'list' => ['player_id', 'firstname', 'lastname', 'surname', 'birthdate', 'city_id', 'club_id', 'state', 'razr'],
        'search' => ['firstname', 'lastname', 'surname'], 'order' => 'firstname, lastname', 'insert' => false, 'cp1251' => true, 'auto' => ['lastupdated'],
    ],
    'tourn_header' => [
        'title' => 'Шапки турниров', 'pk' => 'tourn_id', 'list' => ['tourn_id', 'name', 'tour_date', 'type', 'city_id', 'status'],
        'search' => ['name'], 'order' => 'tourn_id DESC', 'insert' => false, 'cp1251' => false, 'auto' => [], 'bottom' => ['champ_t'],
    ],
];

const ADM_LABELS = [
    'city_id' => 'Город', 'city_name' => 'Название города', 'razr_coeff' => 'Коэффициент разряда',
    'club_id' => 'Клуб', 'name' => 'Название', 'shortname' => 'Краткое название', 'abbr' => 'Аббревиатура',
    'lastdate' => 'Последняя дата', 'email' => 'E-mail', 'contact_name' => 'Контактное лицо',
    'player_id' => 'ID игрока', 'firstname' => 'Фамилия', 'lastname' => 'Имя', 'surname' => 'Отчество',
    'sex' => 'Пол (1 — М, 0 — Ж)', 'birthdate' => 'Дата рождения', 'adress' => 'Адрес', 'phone' => 'Телефон',
    'mail' => 'E-mail', 'state' => 'Состояние (state)', 'razr' => 'Разряд', 'lifetime' => 'lifetime', 'note' => 'Примечание',
    'lastupdated' => 'Обновлено', 'tourn_id' => 'ID турнира', 'tour_date' => 'Дата турнира', 'tour_date_start' => 'Дата начала',
    'type' => 'Тип', 'status' => 'Статус', 'n_deals' => 'Число раздач', 'champ_t' => 'champ_t', 'parent' => 'Родитель',
    'stream' => 'Поток', 'prev_id' => 'Предыдущий', 'next_id' => 'Следующий',
];

/**
 * Разбор строк SHOW COLUMNS.
 * @return array<string,array{kind:string,len:?int,unsigned:bool,null:bool,auto:bool,editable:bool,type:string}>
 *   kind: int|num|date|datetime|text|other
 */
function adm_parse_columns(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $t = strtolower((string)$r['Type']);
        $kind = 'other';
        $len = null;
        if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)/', $t)) {
            $kind = 'int';
        } elseif (preg_match('/^(decimal|numeric|float|double)/', $t)) {
            $kind = 'num';
        } elseif (str_starts_with($t, 'datetime') || str_starts_with($t, 'timestamp')) {
            $kind = 'datetime';
        } elseif (str_starts_with($t, 'date')) {
            $kind = 'date';
        } elseif (preg_match('/^(varchar|char)\((\d+)\)/', $t, $m)) {
            $kind = 'text';
            $len = (int)$m[2];
        } elseif (preg_match('/^(tinytext|text|mediumtext|longtext)/', $t, $m)) {
            $kind = 'text';
            $len = ['tinytext' => 255, 'text' => 65535, 'mediumtext' => 16777215, 'longtext' => 4294967295][$m[1]];
        }
        $out[(string)$r['Field']] = [
            'kind' => $kind, 'len' => $len, 'unsigned' => str_contains($t, 'unsigned'),
            'null' => ($r['Null'] ?? '') === 'YES', 'auto' => str_contains(strtolower((string)($r['Extra'] ?? '')), 'auto_increment'),
            'editable' => $kind !== 'other', 'type' => $t,
        ];
    }
    return $out;
}

/**
 * Проверка и нормализация введённого значения.
 * @return array{0:bool,1:mixed,2:string} [ok, value(null/string/int), error]
 */
function adm_value(string $raw, array $meta): array
{
    $v = trim($raw);
    $kind = $meta['kind'];
    if ($v === '') {
        if ($meta['null']) {
            return [true, null, ''];
        }
        if ($kind === 'text') {
            return [true, '', ''];
        }
        return [false, null, 'обязательное поле'];
    }
    switch ($kind) {
        case 'int':
            if (!preg_match('/^-?\d+$/', $v) || ($meta['unsigned'] && $v[0] === '-')) {
                return [false, null, 'нужно целое число' . ($meta['unsigned'] ? ' (≥ 0)' : '')];
            }
            return [true, (string)(int)$v, ''];
        case 'num':
            $v = str_replace(',', '.', $v);
            return preg_match('/^-?\d+(\.\d+)?$/', $v) ? [true, $v, ''] : [false, null, 'нужно число'];
        case 'date':
            $d = DateTime::createFromFormat('!Y-m-d', $v);
            return ($d && $d->format('Y-m-d') === $v) ? [true, $v, ''] : [false, null, 'дата в формате ГГГГ-ММ-ДД'];
        case 'datetime':
            $v = str_replace('T', ' ', $v);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $v .= ' 00:00:00';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) {
                $v .= ':00';
            }
            $d = DateTime::createFromFormat('!Y-m-d H:i:s', $v);
            return ($d && $d->format('Y-m-d H:i:s') === $v) ? [true, $v, ''] : [false, null, 'дата и время: ГГГГ-ММ-ДД ЧЧ:ММ:СС'];
        case 'text':
            if ($meta['len'] !== null && mb_strlen($v) > $meta['len']) {
                return [false, null, 'не длиннее ' . $meta['len'] . ' символов'];
            }
            return [true, $v, ''];
    }
    return [false, null, 'поле не редактируется'];
}

/** Приведение значения из БД к тому же виду, что и adm_value, для сравнения. */
function adm_db_norm($v, array $meta): ?string
{
    if ($v === null) {
        return null;
    }
    $s = (string)$v;
    switch ($meta['kind']) {
        case 'int':
            return $s === '' ? null : (string)(int)$s;
        case 'num':
            return $s;
        case 'date':
            return substr($s, 0, 10) === '0000-00-00' ? null : substr($s, 0, 10);
        case 'datetime':
            return str_starts_with($s, '0000-00-00') ? null : substr($s, 0, 19);
    }
    return $s;
}

/** Равны ли значения (null и '' для текстовых nullable-колонок считаем одинаковыми). */
function adm_equal(?string $a, ?string $b, array $meta): bool
{
    if ($meta['kind'] === 'num' && $a !== null && $b !== null) {
        return abs((float)$a - (float)$b) < 1e-9;
    }
    if ($meta['kind'] === 'text') {
        return ($a ?? '') === ($b ?? '');
    }
    return $a === $b;
}

/** SQL-литерал значения. */
function adm_lit(?string $v, array $meta, callable $esc): string
{
    if ($v === null) {
        return 'NULL';
    }
    if ($meta['kind'] === 'int' || $meta['kind'] === 'num') {
        return $v;
    }
    return $esc($v);
}

/** Есть ли в строке символы вне cp1251. */
function adm_not_cp1251(string $s): bool
{
    $c = @mb_convert_encoding($s, 'CP1251', 'UTF-8');
    return $c === false || @mb_convert_encoding($c, 'UTF-8', 'CP1251') !== $s;
}

/**
 * UPDATE с проверкой, что строка не менялась с момента показа формы (сравнение старых значений через <=>).
 * @param array<string,array{0:?string,1:?string}> $changed колонка => [старое, новое]
 */
function adm_build_update(string $table, string $pk, int $id, array $changed, array $cols, array $auto, callable $esc): string
{
    $set = [];
    $where = [];
    foreach ($changed as $c => [$old, $new]) {
        $set[] = "`{$c}` = " . adm_lit($new, $cols[$c], $esc);
        $where[] = "`{$c}` <=> " . adm_lit($old, $cols[$c], $esc);
    }
    foreach ($auto as $a) {
        if (isset($cols[$a])) {
            $set[] = "`{$a}` = NOW()";
        }
    }
    return "UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE `{$pk}` = {$id} AND " . implode(' AND ', $where) . ' LIMIT 1;';
}

/**
 * INSERT новой строки.
 * @param array<string,?string> $values колонка => новое значение
 */
function adm_build_insert(string $table, array $values, array $cols, array $auto, callable $esc): string
{
    $names = [];
    $vals = [];
    foreach ($values as $c => $v) {
        $names[] = "`{$c}`";
        $vals[] = adm_lit($v, $cols[$c], $esc);
    }
    foreach ($auto as $a) {
        if (isset($cols[$a])) {
            $names[] = "`{$a}`";
            $vals[] = 'NOW()';
        }
    }
    return "INSERT INTO `{$table}` (" . implode(', ', $names) . ') VALUES (' . implode(', ', $vals) . ');';
}

/** Ключ сортировки названий: кириллица (в т.ч. после цифр) — первой, латиница и прочее — в конец. */
function adm_name_key(string $n): string
{
    $n = str_replace('ё', 'е', mb_strtolower($n));
    return (preg_match('/^[^\p{L}]*[а-я]/u', $n) ? '0' : '1') . $n;
}

/** Журнал правок справочников (отдельный от истории загрузок), data/admin_log.json.php. */
function adm_log_path(): string
{
    require_once __DIR__ . '/bootstrap.php';
    return app_data_dir() . '/admin_log.json.php';
}

function adm_log_add(array $e): void
{
    require_once __DIR__ . '/bootstrap.php';
    $f = adm_log_path();
    $d = data_read_json($f);
    $e['ts'] = date('c');
    $d[] = $e;
    if (count($d) > 1000) {
        $d = array_slice($d, -1000);
    }
    data_write_json($f, $d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/** @return list<array> новые сверху */
function adm_log_all(int $limit = 300): array
{
    $f = adm_log_path();
    $d = is_file($f) ? data_read_json($f) : [];
    return array_slice(array_reverse($d), 0, $limit);
}
