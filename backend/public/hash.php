<?php
$passwords = ['nurse123', 'nurse123', 'nurse123', 'nurse123', 'nurse123'];
foreach ($passwords as $pwd) {
    echo password_hash($pwd, PASSWORD_DEFAULT) . "\n";
}
?>