<?php
declare(strict_types=1);
/**
 * Анкеты: чистые функции (без БД) — сверка личности, слияние, сравнение, SQL.
 * Поля анкеты = колонки aux_questionaries:
 *   firstname=фамилия, lastname=имя, surname=отчество.
 */

const ANKETA_FIELDS = [
    'firstname', 'lastname', 'surname', 'birthdate', 'sex', 'city', 'region', 'phone', 'mail',
    'bbo', 'gambler', 'WBF', 'acbl', 'is_sputnik', 'is_sirius', 'first_tourn', 'club_id',
];

const ANKETA_LABELS = [
    'firstname' => 'Фамилия', 'lastname' => 'Имя', 'surname' => 'Отчество',
    'birthdate' => 'Дата рождения', 'sex' => 'Пол', 'city' => 'Город', 'region' => 'Регион',
    'phone' => 'Телефон', 'mail' => 'E-mail', 'bbo' => 'BBO', 'gambler' => 'Gambler',
    'WBF' => 'WBF', 'acbl' => 'ACBL', 'is_sputnik' => 'Спутник', 'is_sirius' => 'Сириус',
    'first_tourn' => 'Первый турнир', 'club_id' => 'Клуб',
];

/** Пусто ли значение поля (0 для флагов/пола — НЕ пусто, кроме is_*). */
function anketa_empty(string $field, $v): bool
{
    if ($v === null) {
        return true;
    }
    $s = trim((string)$v);
    if ($s === '') {
        return true;
    }
    if (in_array($field, ['is_sputnik', 'is_sirius'], true)) {
        return $s === '0';
    }
    return false;
}

/** Нормализация значения для сравнения/вывода. */
function anketa_norm(string $field, $v): string
{
    if ($v === null) {
        return '';
    }
    $s = trim((string)$v);
    if ($field === 'mail') {
        return mb_strtolower($s);
    }
    if ($field === 'phone') {
        // список номеров через запятую → только цифры у каждого
        $parts = preg_split('/[,;\s]+/', $s) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $d = preg_replace('/\D+/', '', $p);
            if ($d !== '') {
                $out[] = $d;
            }
        }
        return implode(',', $out);
    }
    if (in_array($field, ['birthdate', 'first_tourn'], true)) {
        return substr($s, 0, 10) === '0000-00-00' ? '' : substr($s, 0, 10);
    }
    if (in_array($field, ['sex', 'club_id', 'is_sputnik', 'is_sirius'], true)) {
        return $s === '' ? '' : (string)(int)$s;
    }
    return $s;
}

/**
 * Сверка данных «для проверки» с предыдущей анкетой.
 * @param array $prev  ['birthdate','phone','mail'] из прошлой анкеты
 * @param array $given ['birthdate','phone4','mail'] введено человеком
 * @return string 'ok' | 'weak' | 'failed' | 'none'
 */
function anketa_verify_compare(array $prev, array $given): string
{
    $avail = 0;
    $match = 0;
    // дата рождения
    $pb = anketa_norm('birthdate', $prev['birthdate'] ?? '');
    if ($pb !== '') {
        $avail++;
        if (anketa_norm('birthdate', $given['birthdate'] ?? '') === $pb) {
            $match++;
        }
    }
    // последние 4 цифры любого из телефонов
    $pp = anketa_norm('phone', $prev['phone'] ?? '');
    if ($pp !== '') {
        $avail++;
        $g = preg_replace('/\D+/', '', (string)($given['phone4'] ?? ''));
        $g = $g === null ? '' : substr($g, -4);
        if (strlen($g) === 4) {
            foreach (explode(',', $pp) as $num) {
                if (strlen($num) >= 4 && substr($num, -4) === $g) {
                    $match++;
                    break;
                }
            }
        }
    }
    // e-mail
    $pm = anketa_norm('mail', $prev['mail'] ?? '');
    if ($pm !== '') {
        $avail++;
        if (anketa_norm('mail', $given['mail'] ?? '') === $pm) {
            $match++;
        }
    }
    if ($avail === 0) {
        return 'none';
    }
    if ($match === $avail && $match >= 2) {
        return 'ok';
    }
    if ($avail === 1 && $match === 1) {
        return 'ok';
    }
    return $match >= 1 ? 'weak' : 'failed';
}

/**
 * Новая анкета = база (предыдущая/текущие данные) + явно указанные непустые поля из присланной.
 * @return array<string,mixed>
 */
function anketa_merge(array $base, array $submitted): array
{
    $new = [];
    foreach (ANKETA_FIELDS as $f) {
        $new[$f] = $base[$f] ?? null;
        if (array_key_exists($f, $submitted) && !anketa_empty($f, $submitted[$f])) {
            $new[$f] = $submitted[$f];
        }
    }
    return $new;
}

/** Список полей, в которых $new отличается от $base. */
function anketa_diff(array $base, array $new): array
{
    $d = [];
    foreach (ANKETA_FIELDS as $f) {
        if (anketa_norm($f, $base[$f] ?? null) !== anketa_norm($f, $new[$f] ?? null)) {
            $d[] = $f;
        }
    }
    return $d;
}

/**
 * Кандидаты-дубликаты для новой анкеты.
 * @param array $a   присланная анкета
 * @param array $players строки players (player_id, firstname, lastname, surname, mail, ...)
 * @param array $pending другие необработанные/принятые анкеты (id, player_id, result_player_id, firstname, lastname, surname, birthdate, mail, status)
 * @return array список ['kind'=>'player'|'pending','id'=>?int,'label'=>..., 'score'=>int]
 */
function anketa_candidates(array $a, array $players, array $pending): array
{
    $fam = mb_strtolower(trim((string)($a['firstname'] ?? '')));
    $giv = mb_strtolower(trim((string)($a['lastname'] ?? '')));
    $mail = anketa_norm('mail', $a['mail'] ?? '');
    $bd = anketa_norm('birthdate', $a['birthdate'] ?? '');
    $score = function (array $r) use ($fam, $giv, $mail, $bd): int {
        $s = 0;
        $rf = mb_strtolower(trim((string)($r['firstname'] ?? '')));
        $rg = mb_strtolower(trim((string)($r['lastname'] ?? '')));
        if ($fam !== '' && $rf === $fam) {
            $s += 3;
            if ($giv !== '' && $rg === $giv) {
                $s += 3;
            } elseif ($giv !== '' && $rg !== '' && mb_substr($rg, 0, 1) === mb_substr($giv, 0, 1)) {
                $s += 1;
            }
        }
        if ($mail !== '' && anketa_norm('mail', $r['mail'] ?? '') === $mail) {
            $s += 4;
        }
        if ($bd !== '' && anketa_norm('birthdate', $r['birthdate'] ?? '') === $bd) {
            $s += 3;
        }
        return $s;
    };
    $out = [];
    $known = [];
    foreach ($players as $p) {
        $sc = $score($p);
        if ($sc >= 4 || ($sc >= 3 && $fam !== '')) {
            $known[(int)$p['player_id']] = true;
            $out[] = ['kind' => 'player', 'id' => (int)$p['player_id'], 'score' => $sc,
                'label' => trim(($p['firstname'] ?? '') . ' ' . ($p['lastname'] ?? '') . ' ' . ($p['surname'] ?? ''))];
        }
    }
    foreach ($pending as $p) {
        $sc = $score($p);
        if ($sc < 4 && !($sc >= 3 && $fam !== '')) {
            continue;
        }
        $pid = $p['result_player_id'] ?? null;
        if (($p['status'] ?? null) === 'rejected') {
            continue;
        }
        if ($pid !== null && $pid !== '' && isset($known[(int)$pid])) {
            continue; // уже есть в players
        }
        $out[] = ['kind' => 'pending', 'id' => ($pid !== null && $pid !== '') ? (int)$pid : null,
            'aux_id' => (int)$p['id'], 'score' => $sc,
            'label' => trim(($p['firstname'] ?? '') . ' ' . ($p['lastname'] ?? '') . ' ' . ($p['surname'] ?? '')),
            'status' => $p['status'] ?? null];
    }
    usort($out, fn($x, $y) => $y['score'] <=> $x['score']);
    return $out;
}

/**
 * SQL принятия анкеты.
 * @param array  $new      итоговая анкета (поля ANKETA_FIELDS, city уже сопоставлен: ключ city_id)
 * @param array  $changed  изменённые поля (для type 'e'); для 'a' — все
 * @param callable $esc    строка → экранированный литерал (с кавычками) или NULL
 * @param int|null $playerId существующий ID (иначе новый)
 * @param array{aux_id:int,type:string,user:string,city_id:?int,exists:array} $ctx
 *   exists: ['ext'=>bool,'stu'=>bool] — есть ли строки external_ids/students (null → INSERT…WHERE NOT EXISTS)
 * @return string
 */
function anketa_build_sql(array $new, array $changed, callable $esc, ?int $playerId, array $ctx): string
{
    $isNew = $ctx['type'] === 'a' && $playerId === null;
    $int = fn($v) => ($v === null || $v === '') ? 'NULL' : (string)(int)$v;
    $L = [];
    $L[] = '-- Анкета #' . $ctx['aux_id'] . ($isNew ? ' (новый игрок)' : ' (игрок ' . $playerId . ')');
    $L[] = 'START TRANSACTION;';
    if ($isNew) {
        $L[] = "SELECT GET_LOCK('fsbr_player_id',30);";
        $L[] = 'SET @pid = (SELECT IFNULL(MAX(player_id),0)+1 FROM players);';
    } else {
        $L[] = 'SET @pid = ' . (int)$playerId . ';';
    }

    // players
    $map = [
        'firstname' => ['firstname', fn($v) => $esc($v)],
        'lastname' => ['lastname', fn($v) => $esc($v)],
        'surname' => ['surname', fn($v) => $esc($v)],
        'sex' => ['sex', $int],
        'phone' => ['phone', fn($v) => $esc($v)],
        'mail' => ['mail', fn($v) => $esc($v)],
        'club_id' => ['club_id', $int],
    ];
    $cols = [];
    foreach ($map as $f => [$col, $fmt]) {
        if ($isNew || in_array($f, $changed, true)) {
            $cols[$col] = $fmt($new[$f] ?? null);
        }
    }
    if ($isNew || in_array('city', $changed, true)) {
        $cols['city_id'] = $int($ctx['city_id'] ?? null);
    }
    if (!empty($ctx['birth_col']) && ($isNew || in_array('birthdate', $changed, true))) {
        $cols[$ctx['birth_col']] = $esc(anketa_norm('birthdate', $new['birthdate'] ?? '') ?: null);
    }
    if (!empty($ctx['players_lu']) && ($cols || $isNew)) {
        $cols['lastupdated'] = 'NOW()';
    }
    if ($isNew) {
        $cols = ['player_id' => '@pid'] + $cols;
        $L[] = 'INSERT INTO players (' . implode(', ', array_keys($cols)) . ')';
        $L[] = 'VALUES (' . implode(', ', array_values($cols)) . ');';
    } elseif ($cols) {
        $set = [];
        foreach ($cols as $c => $v) {
            $set[] = "{$c} = {$v}";
        }
        $L[] = 'UPDATE players SET ' . implode(', ', $set) . ' WHERE player_id = @pid;';
    }

    // external_ids
    $extMap = ['bbo' => 'bbo', 'gambler' => 'gambler', 'WBF' => 'wbf', 'acbl' => 'acbl'];
    $extCols = [];
    foreach ($extMap as $f => $col) {
        if ($isNew ? !anketa_empty($f, $new[$f] ?? null) : in_array($f, $changed, true)) {
            $extCols[$col] = $esc($new[$f] ?? null);
        }
    }
    if ($extCols && !empty($ctx['ext_lu'])) {
        $extCols['lastupdated'] = 'NOW()';
    }
    if ($extCols) {
        $set = [];
        foreach ($extCols as $c => $v) {
            $set[] = "{$c} = {$v}";
        }
        $L[] = 'UPDATE external_ids SET ' . implode(', ', $set) . ' WHERE player_id = @pid;';
        $L[] = 'INSERT INTO external_ids (player_id, ' . implode(', ', array_keys($extCols)) . ')';
        $L[] = 'SELECT @pid, ' . implode(', ', array_values($extCols))
            . ' FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM external_ids WHERE player_id = @pid);';
    }

    // students
    $stuMap = ['is_sputnik' => ['sputnik', $int], 'is_sirius' => ['sirius', $int], 'first_tourn' => ['first', fn($v) => $esc($v)]];
    $stuCols = [];
    foreach ($stuMap as $f => [$col, $fmt]) {
        $has = !anketa_empty($f, $new[$f] ?? null);
        if ($isNew ? $has : in_array($f, $changed, true)) {
            $stuCols[$col] = $has ? $fmt($new[$f]) : ($f === 'first_tourn' ? 'NULL' : '0');
        }
    }
    if ($stuCols) {
        $set = [];
        foreach ($stuCols as $c => $v) {
            $set[] = "{$c} = {$v}";
        }
        $L[] = 'UPDATE students SET ' . implode(', ', $set) . ' WHERE player_id = @pid;';
        $L[] = 'INSERT INTO students (player_id, ' . implode(', ', array_keys($stuCols)) . ')';
        $L[] = 'SELECT @pid, ' . implode(', ', array_values($stuCols))
            . ' FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM students WHERE player_id = @pid);';
    }

    $L[] = 'UPDATE aux_questionaries SET status = ' . $esc('accepted') . ', processed_at = NOW(), processed_by = '
        . $esc($ctx['user']) . ', result_player_id = @pid WHERE id = ' . (int)$ctx['aux_id'] . ' AND status IS NULL;';
    $L[] = 'COMMIT;';
    return implode("\n", $L) . "\n";
}
