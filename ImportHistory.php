<?php
declare(strict_types=1);

/**
 * Лог загрузок турниров / клубных МБ (JSON-файл).
 */
class ImportHistory
{
    public static function path(): string
    {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/import_log.json';
    }

    /** @return list<array> */
    public static function all(int $limit = 200): array
    {
        $file = self::path();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return [];
        }
        // newest first
        usort($data, function ($a, $b) {
            return strcmp((string)($b['ts'] ?? ''), (string)($a['ts'] ?? ''));
        });
        return array_slice($data, 0, $limit);
    }

    public static function add(array $entry): void
    {
        $file = self::path();
        $data = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $entry['ts'] = $entry['ts'] ?? date('c');
        $entry['id'] = $entry['id'] ?? bin2hex(random_bytes(6));
        $data[] = $entry;
        // keep last 500
        if (count($data) > 500) {
            $data = array_slice($data, -500);
        }
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * После multi_query: узнать tourn_id (из SQL или @tourn_id / MAX).
     */
    public static function resolveTournId(mysqli $db, string $sql): ?int
    {
        if (preg_match('/DELETE FROM tourn_header WHERE tourn_id\s*=\s*(\d+)/i', $sql, $m)) {
            return (int)$m[1];
        }
        if (preg_match('/INSERT INTO tourn_header\s*\([^)]*\)\s*VALUES\s*\(\s*(\d+)\s*,/i', $sql, $m)) {
            return (int)$m[1];
        }
        // SET @tourn_id = ...
        $res = @$db->query('SELECT @tourn_id AS tid');
        if ($res) {
            $row = $res->fetch_assoc();
            $res->free();
            if (!empty($row['tid']) && is_numeric($row['tid'])) {
                return (int)$row['tid'];
            }
        }
        $res = @$db->query('SELECT MAX(tourn_id) AS tid FROM tourn_header');
        if ($res) {
            $row = $res->fetch_assoc();
            $res->free();
            if (!empty($row['tid'])) {
                return (int)$row['tid'];
            }
        }
        return null;
    }

    public static function fetchTournCard(mysqli $db, int $tournId): ?array
    {
        $tournId = (int)$tournId;
        $res = @$db->query("SELECT h.*, c.city_name
            FROM tourn_header h
            LEFT JOIN cities c ON c.city_id = h.city_id
            WHERE h.tourn_id = {$tournId} LIMIT 1");
        if (!$res) {
            return null;
        }
        $h = $res->fetch_assoc();
        $res->free();
        if (!$h) {
            return null;
        }
        $counts = [];
        foreach (['tourn_pair' => 'tour_id', 'tourn_team' => 'tour_id', 'tourn_ind' => 'tour_id', 'tourn_ses' => 'main_tour_id'] as $tbl => $col) {
            $r = @$db->query("SELECT COUNT(*) AS n FROM {$tbl} WHERE {$col} = {$tournId}");
            $counts[$tbl] = $r ? (int)$r->fetch_assoc()['n'] : 0;
            if ($r) $r->free();
        }
        // also ses by tour_id
        $r = @$db->query("SELECT COUNT(*) AS n FROM tourn_ses WHERE tour_id = {$tournId} OR main_tour_id = {$tournId}");
        if ($r) {
            $counts['tourn_ses'] = max($counts['tourn_ses'] ?? 0, (int)$r->fetch_assoc()['n']);
            $r->free();
        }
        $typeLabels = [1 => 'индивидуал', 2 => 'парный', 3 => 'командный', 4 => 'сессия', 5 => 'клубный', 6 => 'фестиваль'];
        $type = (int)($h['type'] ?? 0);
        return [
            'tourn_id' => $tournId,
            'name' => $h['name'] ?? '',
            'type' => $type,
            'type_label' => $typeLabels[$type] ?? ('type=' . $type),
            'tour_date' => $h['tour_date'] ?? null,
            'tour_date_start' => $h['tour_date_start'] ?? null,
            'city_id' => $h['city_id'] ?? null,
            'city_name' => $h['city_name'] ?? null,
            'stream' => $h['stream'] ?? null,
            'prev_id' => $h['prev_id'] ?? null,
            'next_id' => $h['next_id'] ?? null,
            'champ_t' => $h['champ_t'] ?? null,
            'counts' => $counts,
        ];
    }
}
