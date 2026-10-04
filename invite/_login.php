<?php require __DIR__.'/functions.php'; $_SESSION['customer_id']=(int)($_GET['c']??1); header('Location: dashboard.php');
