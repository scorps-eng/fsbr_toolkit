<?php
declare(strict_types=1);
/**
 * Сравнение имён из отчёта с ФИО из базы (общее для проверки турниров и клубных МБ).
 */

function normalize(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = str_replace('ё', 'е', $s);
    $s = preg_replace('/[^\p{L}\p{N}\s.\-]/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

function tokenize_name(string $name): array
{
    $name = trim(rtrim(trim($name), '*'));
    if ($name === '') return [];
    // "И.О." / "И. О." / "А.С." → отдельные инициалы
    $name = preg_replace('/(\p{L})\s*\.\s*(\p{L})\s*\./u', '$1. $2.', $name);
    $name = preg_replace('/(\p{L})\s*\./u', '$1. ', $name);
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $tokens = [];
    foreach ($parts as $p) {
        $p = trim($p, " \t-,");
        if ($p === '') continue;
        $tokens[] = $p;
    }
    return $tokens;
}

/** Нормализация токена: нижний регистр, ё→е, без точек на конце инициала */
function norm_token(string $s): string
{
    $s = normalize($s);
    $s = rtrim($s, '.');
    return $s;
}

/**
 * Гибкое сравнение имени из отчёта с ФИО в базе.
 * Допустимы любые порядок и регистр, если есть фамилия и имя (или инициал).
 *
 * Примеры:
 *   Фамилия Имя | Фамилия И.О. | Фамилия И. | Имя ФАМИЛИЯ
 *   И.О. Фамилия | Имя Отчество Фамилия | ФАМИЛИЯ Имя Отчество
 *   фамилия имя | ИМЯ фамилия
 */
function names_match(string $report, ?string $dbFamily, ?string $dbGiven, ?string $dbPatr): bool
{
    $tokens = tokenize_name($report);
    if (!$tokens) return false;

    $dbF = norm_token((string)($dbFamily ?? ''));
    $dbG = norm_token((string)($dbGiven ?? ''));
    $dbP = norm_token((string)($dbPatr ?? ''));
    if ($dbF === '') return false;

    $familyOk = false;
    $givenOk = ($dbG === ''); // если в базе нет имени — не требуем
    $used = []; // индексы токенов, уже сопоставленных с фамилией

    $isInitial = function (string $t): bool {
        $t = norm_token($t);
        return $t !== '' && mb_strlen($t, 'UTF-8') === 1;
    };

    $tokenMatchesGiven = function (string $tok) use ($dbG, $isInitial): bool {
        if ($dbG === '') return false;
        $t = norm_token($tok);
        if ($t === '') return false;
        if ($isInitial($tok)) {
            return mb_substr($dbG, 0, 1, 'UTF-8') === $t;
        }
        return $t === $dbG
            || str_starts_with($dbG, $t)
            || str_starts_with($t, $dbG);
    };

    $tokenMatchesFamily = function (string $tok) use ($dbF): bool {
        $t = norm_token($tok);
        if ($t === '' || mb_strlen($t, 'UTF-8') <= 1) return false; // инициал ≠ фамилия
        return $t === $dbF
            || str_replace('-', '', $t) === str_replace('-', '', $dbF);
    };

    // 1) Найти фамилию среди токенов
    foreach ($tokens as $i => $tok) {
        if ($tokenMatchesFamily($tok)) {
            $familyOk = true;
            $used[$i] = true;
            break;
        }
    }
    if (!$familyOk) return false;

    // Только фамилия в отчёте — достаточно совпадения фамилии
    if (count($tokens) === 1 && $familyOk) {
        return true;
    }

    // 2) Имя / инициал среди оставшихся токенов
    if ($dbG !== '') {
        foreach ($tokens as $i => $tok) {
            if (!empty($used[$i])) continue;
            if ($tokenMatchesGiven($tok)) {
                $givenOk = true;
                $used[$i] = true;
                break;
            }
        }
        // "И.О." могло остаться одним токеном до split — перепроверим склеенные инициалы
        if (!$givenOk) {
            $joined = norm_token(implode('', $tokens));
            // fallback: любой инициал в строке
            $reportNorm = normalize($report);
            if (preg_match('/(?:^|[\s.])' . preg_quote(mb_substr($dbG, 0, 1, 'UTF-8'), '/') . '(?:\.|$|[\s])/u', $reportNorm)) {
                $givenOk = true;
            }
        }
    }

    return $familyOk && $givenOk;
}
