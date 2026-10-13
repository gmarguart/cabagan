<?php
// Copia este archivo como smtp-config.php en el servidor (junto a enviar-contacto.php)
// y escribe la contraseña real. smtp-config.php NO se sube a GitHub.
return [
    'host' => 'mail.cabagan.cl',   // o 'localhost' si el hosting lo indica
    'port' => 465,                 // 465 con 'ssl', o 587 con 'tls'
    'secure' => 'ssl',             // 'ssl' o 'tls'
    'user' => 'gestion@cabagan.cl',
    'pass' => 'CONTRASEÑA_DEL_CORREO',
    'verify_cert' => true          // false solo si usas 'localhost' y falla el certificado
];
