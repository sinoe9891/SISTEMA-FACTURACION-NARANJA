<?php
// dev/qa/smtp_falso.php — Servidor SMTP falso para las pruebas (127.0.0.1:2525): guarda cada mensaje en dev/qa/correos/*.eml.
// Solo acepta la contraseña de las pruebas (clave-de-prueba); cualquier otra responde 535, como un servidor real.
//   php dev/qa/smtp_falso.php &
$dir = __DIR__ . '/correos';
@mkdir($dir, 0775, true);
$srv = stream_socket_server('tcp://127.0.0.1:2525', $errno, $errstr);
if (!$srv) exit("No se pudo abrir 127.0.0.1:2525: $errstr\n");
echo "SMTP falso en 127.0.0.1:2525 → $dir\n";

while ($c = @stream_socket_accept($srv, -1)) {
    $w = function (string $t) use ($c) { fwrite($c, $t . "\r\n"); };
    $w('220 qa.local ESMTP falso');
    $datos = false;
    $msg = '';
    while (($l = fgets($c)) !== false) {
        if ($datos) {
            if (rtrim($l, "\r\n") === '.') {
                file_put_contents($dir . '/' . sprintf('%.6f', microtime(true)) . '.eml', $msg);   // el nombre ordena por hora exacta
                $datos = false;
                $msg = '';
                $w('250 OK guardado');
            } else {
                $msg .= $l;
            }
            continue;
        }
        $partes = preg_split('/\s+/', trim($l));
        $cmd = strtoupper($partes[0] ?? '');
        if ($cmd === 'EHLO') {
            $w('250-qa.local');
            $w('250-AUTH PLAIN LOGIN');
            $w('250 8BITMIME');
        } elseif ($cmd === 'HELO') {
            $w('250 qa.local');
        } elseif ($cmd === 'AUTH') {
            if (strtoupper($partes[1] ?? '') === 'LOGIN') {
                $w('334 VXNlcm5hbWU6');
                fgets($c);
                $w('334 UGFzc3dvcmQ6');
                $clave = base64_decode(trim((string)fgets($c)));
            } else {
                $b64 = $partes[2] ?? null;
                if ($b64 === null) { $w('334 '); $b64 = trim((string)fgets($c)); }
                $clave = explode("\0", (string)base64_decode($b64))[2] ?? '';
            }
            $w($clave === 'clave-de-prueba' ? '235 OK' : '535 5.7.8 Usuario o contraseña incorrectos');
        } elseif ($cmd === 'DATA') {
            $w('354 Termina con .');
            $datos = true;
        } elseif ($cmd === 'QUIT') {
            $w('221 Adiós');
            break;
        } else {
            $w('250 OK');
        }
    }
    fclose($c);
}
