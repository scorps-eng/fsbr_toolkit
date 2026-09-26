# FSBR Toolkit

Веб-инструмент Федерации спортивного бриджа России для:

1. **Проверка отчёта** — загрузка XLS/XLSX, сверка ID/имён с БД, JSON
2. **Расчёт РО / ПБ / МБ** — по JSON и спортивной классификации
3. **SQL** — вставка турнира в базу (`tourn_header`, `tourn_pair` / `tourn_team`, `tourn_ses`)

## Требования

- PHP 8.0+
- расширения: `mysqli`, `mbstring`, `zip`
- доступ к MySQL базе игроков ФСБР

## Установка

```bash
git clone <url-репозитория> fsbr_toolkit
cd fsbr_toolkit
cp config.example.php config.php
# отредактируйте config.php
```

Откройте `index.php` на веб-сервере (Apache/nginx + PHP или встроенный сервер):

```bash
php -S localhost:8080
```

## Структура

| Файл | Назначение |
|------|------------|
| `index.php` | Оболочка с вкладками |
| `check_body.php` | Проверка отчёта |
| `rating_body.php` | Расчёт рейтинга |
| `sql_body.php` | Генерация SQL |
| `XlsReader.php` | Чтение .xls (BIFF8) |
| `RatingCalculator.php` | Формулы классификации |
| `SqlExporter.php` | SQL для БД |
| `config.php` | Доступ к MySQL (не в git) |

## Поток работы

Проверка XLS → JSON → расчёт РО/ПБ/МБ (+ сессии) → SQL.

Таблица `results` не заполняется (обновляется месячными скриптами).
