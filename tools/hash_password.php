<?php
// Использование:  php tools/hash_password.php
// Печатает значение для 'auth_pass_hash' в config.php. Только из командной строки.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDERR, "Пароль (ввод виден; минимум 12 символов): ");
$pw = rtrim((string)fgets(STDIN), "\r\n");
if (mb_strlen($pw) < 12) { fwrite(STDERR, "Слишком короткий пароль.\n"); exit(1); }
echo password_hash($pw, PASSWORD_DEFAULT), "\n";
