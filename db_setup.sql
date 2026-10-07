-- Учётки БД для FSBR Toolkit. Выполнить под администратором MySQL/MariaDB.
-- Замените имена БД, хоста и пароли. Таблицы results и прочие НЕ перечислены → доступа к ним нет.

CREATE USER 'fsbr_tk_ro'@'%' IDENTIFIED BY 'change_me_ro';
CREATE USER 'fsbr_tk_rw'@'%' IDENTIFIED BY 'change_me_rw';
CREATE USER 'fsbr_tk_q'@'%'  IDENTIFIED BY 'change_me_q';

-- 1) Только чтение: проверка отчётов, расчёт, подсказки prev_id/stream/city
GRANT SELECT ON fsbr_copy.players      TO 'fsbr_tk_ro'@'%';
GRANT SELECT ON fsbr_copy.cities       TO 'fsbr_tk_ro'@'%';
GRANT SELECT ON fsbr_copy.streams      TO 'fsbr_tk_ro'@'%';
GRANT SELECT ON fsbr_copy.tourn_header TO 'fsbr_tk_ro'@'%';

-- 2) Запись: выполнение SQL из вкладки «SQL».
--    SELECT нужен для WHERE/EXISTS/MAX(); DELETE — для замены турнира; INSERT — для вставки.
GRANT SELECT, INSERT, UPDATE, DELETE ON fsbr_copy.tourn_header          TO 'fsbr_tk_rw'@'%'; -- UPDATE: next_id
GRANT SELECT, INSERT, DELETE         ON fsbr_copy.tourn_pair            TO 'fsbr_tk_rw'@'%';
GRANT SELECT, INSERT, DELETE         ON fsbr_copy.tourn_ind             TO 'fsbr_tk_rw'@'%';
GRANT SELECT, INSERT, DELETE         ON fsbr_copy.tourn_team            TO 'fsbr_tk_rw'@'%';
GRANT SELECT, INSERT, DELETE         ON fsbr_copy.tourn_ses             TO 'fsbr_tk_rw'@'%';
GRANT SELECT, INSERT, DELETE         ON fsbr_copy.teams                 TO 'fsbr_tk_rw'@'%';
GRANT SELECT, INSERT, DELETE         ON fsbr_copy.team_players          TO 'fsbr_tk_rw'@'%';
GRANT SELECT, INSERT, DELETE         ON fsbr_copy.team_players_nonqual  TO 'fsbr_tk_rw'@'%';
GRANT SELECT, DELETE                 ON fsbr_copy.tds                   TO 'fsbr_tk_rw'@'%';
GRANT SELECT                         ON fsbr_copy.cities                TO 'fsbr_tk_rw'@'%'; -- карточка турнира после записи
-- results и остальное — НЕТ доступа (players/external_ids/students/aux_questionaries — только для вкладки «Анкеты», см. п. 4).

-- 3) Анкета (questionnaire.php)
GRANT SELECT ON fsbr_copy.players      TO 'fsbr_tk_q'@'%';
GRANT SELECT ON fsbr_copy.cities       TO 'fsbr_tk_q'@'%';
GRANT SELECT ON fsbr_copy.clubs        TO 'fsbr_tk_q'@'%';
GRANT SELECT ON fsbr_copy.external_ids TO 'fsbr_tk_q'@'%';
GRANT SELECT ON fsbr_copy.students     TO 'fsbr_tk_q'@'%';
GRANT SELECT, INSERT ON fsbr_copy.aux_questionaries TO 'fsbr_tk_q'@'%';

-- Если приложение и БД на одном сервере, замените '%' на 'localhost' / конкретный адрес.
-- Если в вашей схеме таблицы называются иначе или есть другие обращения — проверьте
-- журнал ошибок: «SELECT command denied …» покажет недостающее право.

-- 4) Обработка анкет (вкладка «Анкеты», учётка rw)
--    Сначала колонки статуса в очереди анкет (NULL в status = ещё не обработана):
ALTER TABLE fsbr_copy.aux_questionaries
  ADD COLUMN status           VARCHAR(10)       NULL COMMENT 'NULL — ожидает; accepted; rejected',
  ADD COLUMN processed_at     DATETIME          NULL,
  ADD COLUMN processed_by     VARCHAR(45)       NULL,
  ADD COLUMN result_player_id SMALLINT UNSIGNED NULL COMMENT 'player_id, с которым связана принятая анкета',
  ADD COLUMN email_verified   TINYINT           NOT NULL DEFAULT 0 COMMENT 'e-mail подтверждён кодом из письма',
  ADD COLUMN verify_status    VARCHAR(8)        NULL COMMENT 'ok / weak / failed / none — сверка с прошлой анкетой при обновлении',
  ADD COLUMN op_note          VARCHAR(255)      NULL COMMENT 'комментарий оператора';
--    Права для вкладки «Анкеты»: чтение справочников и запись принятых данных.
GRANT SELECT, UPDATE                 ON fsbr_copy.aux_questionaries TO 'fsbr_tk_rw'@'%';
GRANT SELECT, INSERT, UPDATE         ON fsbr_copy.players           TO 'fsbr_tk_rw'@'%';
GRANT SELECT, INSERT, UPDATE         ON fsbr_copy.external_ids      TO 'fsbr_tk_rw'@'%';
GRANT SELECT, INSERT, UPDATE         ON fsbr_copy.students          TO 'fsbr_tk_rw'@'%';
GRANT SELECT                         ON fsbr_copy.clubs             TO 'fsbr_tk_rw'@'%';
-- (cities: SELECT уже выдан выше). Анкета (учётка q) пишет и читает новые колонки в рамках своих прав INSERT/SELECT.

-- 5. Анкеты: итоговая анкета пишется в questionaries; её читает и публичная форма (сверка личности)
GRANT SELECT, INSERT ON fsbr_copy.questionaries TO 'fsbr_tk_rw'@'%';
GRANT SELECT         ON fsbr_copy.questionaries TO 'fsbr_tk_q'@'%';

-- 6. Несколько e-mail в анкете: расширить поле (иначе длина списка ограничена 80 символами)
ALTER TABLE fsbr_copy.aux_questionaries MODIFY mail VARCHAR(255) NULL;
ALTER TABLE fsbr_copy.questionaries     MODIFY mail VARCHAR(255) NULL;
-- Рекомендуется: привести типы к типам players/questionaries (signed smallint вмещает ID только до 32767)
ALTER TABLE fsbr_copy.aux_questionaries
  MODIFY player_id SMALLINT UNSIGNED NULL,
  MODIFY club_id   SMALLINT UNSIGNED NULL;

-- 7. Подробный отчёт о подтверждении личности (что ввёл человек и результат по полям)
ALTER TABLE fsbr_copy.aux_questionaries ADD COLUMN verify_data VARCHAR(1000) NULL COMMENT 'JSON: введённые данные и результат сверки по полям';

-- 8. Вкладка «Справочники»: правка городов, клубов, игроков и шапок турниров (players и tourn_header уже выданы выше)
GRANT SELECT, INSERT, UPDATE ON fsbr_copy.cities TO 'fsbr_tk_rw'@'%';
GRANT SELECT, INSERT, UPDATE ON fsbr_copy.clubs  TO 'fsbr_tk_rw'@'%';
GRANT SELECT ON fsbr_copy.streams TO 'fsbr_tk_rw'@'%';   -- список потоков в форме шапки турнира
-- tourn_header: UPDATE уже есть. Удаление строк в интерфейсе не предусмотрено.
