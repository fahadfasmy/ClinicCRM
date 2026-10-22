<?php
// local/smtp_mock.php — LOCAL TEST ONLY. A stand-in mail server on 127.0.0.1:2525 (no SSL).
// Accepts AUTH LOGIN with password "good-pass", refuses anything else; saves each message to local/smtp_mock_out/.
$srv = stream_socket_server('tcp://127.0.0.1:2525', $no, $err) or die("$err\n");
@mkdir(__DIR__ . '/smtp_mock_out');
while ($c = stream_socket_accept($srv, -1)) {
    $say = fn($s) => fwrite($c, "$s\r\n");
    $say('220 mock ESMTP');
    $step = ''; $data = null; $from = $to = '';
    while (($line = fgets($c)) !== false) {
        if ($data !== null) {
            if (rtrim($line, "\r\n") === '.') {
                file_put_contents(__DIR__ . '/smtp_mock_out/' . microtime(true) . '.eml', "X-Mock-From: $from\r\nX-Mock-To: $to\r\n" . $data);
                $data = null; $say('250 queued');
            } else $data .= $line;
            continue;
        }
        $l = rtrim($line, "\r\n");
        if ($step === 'user') { $step = 'pass'; $say('334 UGFzc3dvcmQ6'); continue; }
        if ($step === 'pass') { $step = ''; $say(base64_decode($l) === 'good-pass' ? '235 ok' : '535 5.7.8 Authentication failed'); continue; }
        if (stripos($l, 'EHLO') === 0) { fwrite($c, "250-mock\r\n250 AUTH LOGIN\r\n"); }
        elseif ($l === 'AUTH LOGIN') { $step = 'user'; $say('334 VXNlcm5hbWU6'); }
        elseif (stripos($l, 'MAIL FROM') === 0) { $from = $l; $say('250 ok'); }
        elseif (stripos($l, 'RCPT TO') === 0) { $to = $l; $say(str_contains($l, 'bounce@') ? '550 no such user' : '250 ok'); }
        elseif ($l === 'DATA') { $data = ''; $say('354 go'); }
        elseif ($l === 'QUIT') { $say('221 bye'); break; }
        else $say('500 ?');
    }
    fclose($c);
}
