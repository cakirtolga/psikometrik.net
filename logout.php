<?php
session_start();
session_unset();
session_destroy();

// Ana dizine (site anasayfasına) yönlendir
header('Location: ../index.php');
exit();
?>
