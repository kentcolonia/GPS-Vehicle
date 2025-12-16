<?php
session_start();
session_destroy();
// Redirect to the new homepage after logging out
header("Location: index.php"); 
exit();
?>
