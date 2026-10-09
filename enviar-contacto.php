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

$sent = mail(
    MAIL_TO,
    $mailSubject,
    $body,
    implode("\r\n", $headers),
    '-f' . MAIL_FROM
);

if (!$sent) {
    respond(500, false, $texts[$lang]['error']);
}

respond(200, true, $texts[$lang]['ok']);
