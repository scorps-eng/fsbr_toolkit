# FSBR Toolkit — памятка для Claude Code

Чистый PHP 8.0+ (без Composer и библиотек), `mysqli`, UTF-8. Веб-инструмент федерации бриджа (ФСБР): проверка отчётов турниров и клубных МБ → расчёт РО/ПБ/МБ → SQL → анкеты игроков → справочники.
Подробности для пользователей — `README.md`. Репозиторий: https://github.com/scorps-eng/fsbr_toolkit (работаем прямо в `main`).

## Правила работы с владельцем проекта
- Общение и комментарии для пользователя — по-русски, коротко; в конце ответа — что загрузить/выполнить.
- Имя проекта `fsbr_toolkit`. Каждая версия — коммит в `main` в корень репозитория (старые файлы заменяются, копий с версиями нет). Архив `fsbr_toolkit.zip` отдаётся без `.git`, `config.php` и `data/*.json.php`.
- Трейлеры коммитов: `Co-Authored-By: Claude <noreply@anthropic.com>` (и ссылка на сессию, если есть).
- Пароли и логины БД в код и файлы НЕ записывать. Тестовую БД из чата не использовать в коде.
- Доступа к реальной MySQL из песочницы нет: код БД проверяется `php -l` и юнит-тестами на подставной БД (пример: объект-наследник `mysqli` с переопределёнными `query()`/`real_escape_string()`).

## Структура
- `index.php` — каркас с вкладками (`check, rating, sql, history, anketa, admin`), вход, CSRF. Каждая вкладка — `*_body.php`.
- `bootstrap.php` — конфиг, сессия, `auth_*`, `csrf_*`, `db_ro/db_rw/db_questionnaire`, `h()`, `throttle_hit`, почта (`app_send_mail`: smtp / mail / file), `data_read_json/data_write_json` (файлы в `data/*.json.php` с PHP-охраной).
- `check_body.php`, `XlsReader.php`, `names.php`, `ClubMb.php` — проверка отчётов (турниры, клубные МБ, zip за месяц; месяц по файлам, если не определился — спрашивается после проверки, `CmbNeedMonth`).
- `RatingCalculator.php` — РО/ПБ/МБ по Спортивной классификации ФСБР (п. 7–8, Приложение 1). Проверено по тексту классификации. RCM включает добавки за статус (+1 чемпионат России, +0.5 региональный) — решение владельца; в классификации формулировка неоднозначна.
- `rating_body.php`, `SqlExporter.php`, `sql_body.php`, `ImportHistory.php`, `history_body.php`, `club_mb_body.php`, `TournamentSuggest.php` — расчёт, генерация и выполнение SQL, история загрузок.
- `questionnaire.php` — публичная анкета игрока (подтверждение e-mail кодом, проверка личности при обновлении), стиль «как Google Forms», `banner.png`.
- `Anketa.php` + `anketa_body.php` — вкладка «Анкеты» для оператора: сверка, слияние, SQL записи анкеты.
- `Admin.php` + `admin_body.php` — вкладка «Справочники»: города, клубы, игроки, шапки турниров (классы CSS `sp-*`, не `ad-*` — блокировщики рекламы скрывают `ad-*`). Журнал правок — `data/admin_log.json.php`.
- `db_setup.sql` — учётки БД и права (разделы 1–8).

## Безопасность — не нарушать
- Вход по логину/паролю, CSRF на всех POST; публичной остаётся только `questionnaire.php`.
- Три учётки БД: `ro` (чтение), `rw` (только выполнение SQL, сформированного приложением), `q` (анкета). Права минимальные, таблицу `results` toolkit не трогает.
- SQL выполняется только из сформированного приложением текста (хеш совпадает с сессионной копией) + одноразовый nonce + подтверждающая галочка + откат при ошибке. Вручную SQL править нельзя (кроме `allow_edit_sql`).
- Файлы данных — только `data/*.json.php` (с `exit` в начале), каталоги `data/`, `tools/` закрыты `.htaccess`.
- Все значения в SQL — через `real_escape_string`/литералы из белого списка; имена таблиц и колонок — только из `ADM_TABLES`/`SHOW COLUMNS`.

## Схема БД (ключевое)
- `players`: player_id (AUTO_INCREMENT), firstname=фамилия, lastname=имя, surname=отчество, sex, birthdate, adress, phone, mail, state, city_id, club_id, razr (по умолчанию 10), lifetime, note, lastupdated. **Кодировка cp1251** — символы вне cp1251 недопустимы.
- `questionaries` (так написано в схеме, не «questionnaires»): анкеты, utf8 (3 байта: 4-байтовые символы вырезать), FK `plid`→players, `club`→clubs. Актуальные phone/mail берутся из последней строки.
- `aux_questionaries`: очередь анкет (status, processed_*, result_player_id, email_verified, verify_status, verify_data, op_note).
- `external_ids`(player_id, wbf, acbl, gambler, bbo, lastupdated), `students`(player_id, sputnik, sirius, first), `cities`(city_id, city_name, razr_coeff), `clubs`(club_id, name, shortname, city_id, abbr, lastdate, email, contact_name), `tourn_header`(tourn_id, name, tour_date, tour_date_start, type, city_id, status, n_deals, champ_t, parent, stream, prev_id, next_id, note), `streams`(stream_id, название).
- Правила записи анкеты: «актуальные данные» = players (кроме phone/mail) + последняя questionaries (phone, mail) + external_ids + students; при принятии — INSERT новой строки в questionaries (timestamp = время заполнения), обновление/вставка в players (только изменённые поля, phone/mail не трогаем, lastupdated=NOW()), external_ids, students. Новый игрок: state=2 и razr=30, если у города `razr_coeff IS NOT NULL` (0 тоже значим), иначе state=1 и razr=5; lifetime=''.
- В анкете может быть несколько e-mail (все должны быть подтверждены).

## Справочники (вкладка admin)
- Правка по схеме: форма → «Сформировать SQL» (таблица «было/станет») → выполнение с проверкой старых значений (`col <=> old`), нет удаления; вставка только для городов и клубов.
- В шапке турнира `stream` и `prev_id` — выпадающие списки с поиском по словам; `prev_id` только из турниров того же города; `champ_t` внизу формы.

## Проверки перед коммитом
- `php -l` для изменённых файлов.
- Для расчёта — прогнать `RatingCalculator` на примере (`rating_result*.json`) и сравнить с независимым пересчётом.
- Не показывать пароли/хеши в выводе; `config.php` и `data/*` в git не попадают.
