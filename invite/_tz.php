<?php require __DIR__.'/functions.php'; header('Content-Type:text/plain');
echo date_default_timezone_get(), ' php=', date('Y-m-d H:i:s'), ' db=', get_pdo()->query("select concat(@@session.time_zone,' ',NOW())")->fetchColumn();
