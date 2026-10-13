<?php
header('Content-Type: application/json; charset=utf-8');

const MAIL_TO = 'contacto@cabagan.cl';
const MAIL_FROM = 'contacto@cabagan.cl';

$lang = (isset($_POST['lang']) && $_POST['lang'] === 'en') ? 'en' : 'es';

$texts = [
    'es' => [
        'method' => 'Método no permitido.',
        'invalid' => 'Revisa los datos: nombre, correo válido, asunto y mensaje son obligatorios.',
        'error' => 'No se pudo enviar la solicitud. Inténtalo nuevamente o escríbenos a contacto@cabagan.cl.',
        'ok' => 'Solicitud enviada. Te contactaremos pronto.'
    ],
    'en' => [
        'method' => 'Method not allowed.',
        'invalid' => 'Please check your details: name, a valid email, subject and message are required.',
        'error' => 'We could not send your request. Please try again or email us at contacto@cabagan.cl.',
        'ok' => 'Request sent. We will contact you soon.'
    ]
];

function respond($status, $success, $message)
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function post_value($key, $limit, $multiline = false)
{
    if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
        return '';
    }

    $value = strip_tags(str_replace("\0", '', $_POST[$key]));
    // Los campos de una línea no pueden llevar saltos (evita inyección de cabeceras)
    $value = $multiline ? str_replace("\r\n", "\n", $value) : preg_replace('/[\r\n\t]+/', ' ', $value);
    $value = trim($value);

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    return substr($value, 0, $limit);
}

/**
 * Envía un correo de texto por SMTP autenticado (sin librerías externas).
 * Devuelve true si el servidor lo aceptó; si no, registra el motivo en error_log.
 */
function smtp_send($cfg, $to, $subject, $body, $replyTo)
{
    $secure = isset($cfg['secure']) ? $cfg['secure'] : 'ssl';
    $host = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'];
    $verify = !isset($cfg['verify_cert']) || $cfg['verify_cert'];
    $context = stream_context_create(['ssl' => [
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'allow_self_signed' => !$verify
    ]]);

    $socket = @stream_socket_client($host, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        error_log("enviar-contacto.php: no se pudo conectar a SMTP $host ($errno $errstr)");
        return false;
    }
    stream_set_timeout($socket, 15);

    $read = function () use ($socket) {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    // $label se usa en el log en vez del comando (para no registrar usuario, contraseña ni el mensaje)
    $step = function ($command, $expected, $label = null) use ($socket, $read) {
        if ($command !== null) {
            fwrite($socket, $command . "\r\n");
        }
        $reply = $read();
        if (!in_array((int) substr($reply, 0, 3), (array) $expected, true)) {
            $shown = $label !== null ? $label : ($command !== null ? $command : 'saludo inicial');
            error_log('enviar-contacto.php: SMTP rechazó ' . $shown . ' -> ' . trim($reply));
            return false;
        }
        return true;
    };

    $domain = substr(strrchr($cfg['user'], '@'), 1);
    $ok = $step(null, 220) && $step('EHLO ' . $domain, 250);

    if ($ok && $secure === 'tls') {
        $ok = $step('STARTTLS', 220)
            && stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
            && $step('EHLO ' . $domain, 250);
    }

    $headers = [
        'Date: ' . date('r'),
        'From: Cabagan <' . $cfg['user'] . '>',
        'To: <' . $to . '>',
        'Reply-To: ' . $replyTo,
        'Subject: ' . $subject,
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64'
    ];
    $data = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));

    $ok = $ok
        && $step('AUTH LOGIN', 334)
        && $step(base64_encode($cfg['user']), 334, 'usuario')
        && $step(base64_encode($cfg['pass']), 235, 'contraseña')
        && $step('MAIL FROM:<' . $cfg['user'] . '>', 250)
        && $step('RCPT TO:<' . $to . '>', [250, 251])
        && $step('DATA', 354)
        && $step($data . "\r\n.", 250, 'contenido del mensaje');

    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return $ok;
}

function text_length($value)
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, $texts[$lang]['method']);
}

// Campo trampa para bots: las personas no lo ven ni lo completan
if (post_value('website', 200) !== '') {
    respond(200, true, $texts[$lang]['ok']);
}

$name = post_value('nombre', 120);
$email = post_value('email', 254);
$phone = post_value('telefono', 60);
$subject = post_value('asunto', 160);
$message = post_value('mensaje', 5000, true);

if (
    text_length($name) < 2
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || text_length($subject) < 3
    || text_length($message) < 10
) {
    respond(422, false, $texts[$lang]['invalid']);
}

$body = implode("\n", [
    'Nueva solicitud desde el formulario de cabagan.cl',
    '',
    'Nombre: ' . $name,
    'Correo electrónico: ' . $email,
    'Teléfono: ' . ($phone !== '' ? $phone : 'No indicado'),
    'Asunto: ' . $subject,
    'Idioma del sitio: ' . ($lang === 'en' ? 'Inglés' : 'Español'),
    '',
    'Mensaje:',
    $message
]);

$mailSubject = '=?UTF-8?B?' . base64_encode('Cotización web: ' . $subject) . '?=';

$headers = [
    'From: Cabagan <' . MAIL_FROM . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . phpversion()
];

// Si existe smtp-config.php (no se sube a GitHub), se envía por SMTP autenticado
$smtpConfig = __DIR__ . '/smtp-config.php';
if (is_file($smtpConfig)) {
    $sent = smtp_send(require $smtpConfig, MAIL_TO, $mailSubject, $body, $email);
    if (!$sent) {
        respond(500, false, $texts[$lang]['error']);
    }
    respond(200, true, $texts[$lang]['ok']);
}

if (!function_exists('mail')) {
    error_log('enviar-contacto.php: la función mail() está deshabilitada en este servidor.');
    respond(500, false, $texts[$lang]['error']);
}

$sent = mail(
    MAIL_TO,
    $mailSubject,
    $body,
    implode("\r\n", $headers),
    '-f' . MAIL_FROM
);

// Algunos servidores rechazan el parámetro -f: se reintenta sin él
if (!$sent) {
    $sent = mail(MAIL_TO, $mailSubject, $body, implode("\r\n", $headers));
}

if (!$sent) {
    $last = error_get_last();
    error_log('enviar-contacto.php: mail() falló. ' . ($last ? $last['message'] : 'Sin detalle del servidor.'));
    respond(500, false, $texts[$lang]['error']);
}

respond(200, true, $texts[$lang]['ok']);
