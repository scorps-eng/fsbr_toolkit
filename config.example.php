<?php
return [
    'db_host' => 'db.bridgesport.ru',
    'db_name' => 'fsbr_copy',

    // Три отдельные учётки БД (права — в db_setup.sql)
    'db_ro_user' => 'fsbr_tk_ro',   // только чтение: проверка, расчёт, подсказки
    'db_ro_pass' => 'change_me_ro',
    'db_rw_user' => 'fsbr_tk_rw',   // запись: только выполнение сформированного SQL
    'db_rw_pass' => 'change_me_rw',
    'db_q_user'  => 'fsbr_tk_q',    // анкета: INSERT в aux_questionaries + чтение справочников
    'db_q_pass'  => 'change_me_q',
    // Старый вариант: одна пара 'db_user' / 'db_pass' — используется, если нужной пары выше нет.

    // Письма (код подтверждения e-mail в анкете). mail_from обязателен.
    'mail_from'      => '',          // например 'anketa@ваш-сайт.ru' (должен принадлежать вашему домену)
    'mail_transport' => 'smtp',      // 'smtp' — через SMTP-сервер (рекомендуется); 'mail' — PHP mail(); 'file' — не отправлять, писать в data/mail_outbox.json.php (для проверки)
    'smtp_host'   => '',             // например 'smtp.yandex.ru'
    'smtp_port'   => 465,            // 465 (ssl) или 587 (tls)
    'smtp_secure' => 'ssl',          // 'ssl' — SSL с первого пакета (465); 'tls' — STARTTLS (587); '' — без шифрования
    'smtp_user'   => '',             // логин (обычно полный адрес ящика)
    'smtp_pass'   => '',             // пароль ящика / пароль приложения
    // mail_from должен совпадать с ящиком smtp_user (большинство серверов иначе отклоняют письмо)

    // Адрес для фото игроков (показывается в анкете; пусто — без адреса)
    'photo_mail' => '',

    // Вход в тулкит. Хеш:  php tools/hash_password.php
    // Пока не заданы — доступ закрыт.
    'auth_user'      => 'admin',
    'auth_pass_hash' => '',

    'session_idle_seconds' => 28800, // автовыход после 8 ч бездействия
    'allow_edit_sql'       => false, // true — разрешить выполнять SQL, правленный вручную в поле
];
