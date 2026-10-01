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
-- results, players и остальное — НЕТ доступа.

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
