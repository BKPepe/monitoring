<?php
/**
 * A webhook that takes the whole message and never answers, for the
 * escalation tests in run_api_tests.php.
 *
 * Usage:  php fixtures/webhook_no_answer_server.php
 * It listens on a free loopback port, prints that port on stdout and serves
 * connections one after another until the test terminates it (or nobody
 * connects for 30 s). Each request is read to the end of its body, then the
 * connection is closed without a byte of answer: curl reports "Empty reply
 * from server" with the whole body sent, the same `unknown` verdict a stalled
 * endpoint gives after the 5 s timeout, only without the wait.
 *
 * No functions on purpose: find_dead_code.php does not read this directory.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$listen = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$listen) {
    fwrite(STDERR, "webhook_no_answer_server: $errstr ($errno)\n");
    exit(1);
}
$name = (string)stream_socket_get_name($listen, false);
fwrite(STDOUT, substr($name, strrpos($name, ':') + 1) . "\n");

while ($conn = @stream_socket_accept($listen, 30)) {
    stream_set_timeout($conn, 10);
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && ($line = fgets($conn)) !== false) {
        $head .= $line;
    }
    // The whole body is read before closing: closing on unread data would
    // reset the connection, which is not what this endpoint plays.
    $length = preg_match('/^Content-Length:\s*(\d+)/mi', $head, $m) ? (int)$m[1] : 0;
    $body = '';
    while (strlen($body) < $length && ($chunk = fread($conn, $length - strlen($body))) !== false && $chunk !== '') {
        $body .= $chunk;
    }
    fclose($conn);
}
