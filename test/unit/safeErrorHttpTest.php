<?php

// HTTP contract checks inside one disposable network-isolated container.
$router = __DIR__.'/safeErrorRepresentationTest.php';
$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:18795', $router),
    array(0 => array('pipe', 'r'), 1 => array('file', '/tmp/atom195-http.log', 'a'), 2 => array('file', '/tmp/atom195-http.log', 'a')), $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start fixture server'); }
fclose($pipes[0]);
$failures = array();
$results = array();
try {
    $ready = false;
    for ($i = 0; $i < 30; ++$i) {
        $socket = @fsockopen('127.0.0.1', 18795, $errno, $error, 0.1);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) { throw new RuntimeException('Fixture server did not start'); }
    $cases = array();
    foreach (array('html', 'xml', 'json', '335545696', '../xml') as $format) {
        $cases[] = array('missing', $format, 404, false);
        $cases[] = array('exception', $format, 500, false);
        if ('html' !== $format) { $cases[] = array('unsupported', $format, 406, false); }
    }
    $cases[] = array('forbidden', 'xml', 403, false);
    $cases[] = array('valid', 'xml', 200, false);
    $cases[] = array('valid', 'html', 200, false);
    $cases[] = array('exception', 'xml', 500, true);
    foreach ($cases as list($case, $format, $expected, $debug)) {
        $query = array('case' => $case, 'format' => $format);
        if ($debug) { $query['debug'] = 1; }
        $context = stream_context_create(array('http' => array('ignore_errors' => true, 'timeout' => 5)));
        $body = file_get_contents('http://127.0.0.1:18795/?'.http_build_query($query), false, $context);
        $headers = $http_response_header;
        preg_match('/HTTP\/\S+ (\d+)/', $headers[0], $match);
        $status = (int) $match[1];
        $type = '';
        foreach ($headers as $header) {
            if (0 === stripos($header, 'Content-Type:')) { $type = trim(substr($header, 13)); }
        }
        $expectedType = in_array($format, array('html', 'xml', 'json'), true)
            ? array('html' => 'text/html', 'xml' => 'text/xml', 'json' => 'application/json')[$format]
            : 'text/plain';
        $canary = false !== strpos($body, 'PRIVATE_CANARY_195');
        $hasDebug = false !== strpos($body, '<debug>') || false !== strpos($body, '<trace>');
        $valid = $status === $expected && strlen($body) > 0 && 0 === strpos($type, $expectedType)
            && ($debug ? $canary : (!$canary && !$hasDebug));
        if ('xml' === $format) { $valid = $valid && false !== simplexml_load_string($body); }
        if ('json' === $format) { $valid = $valid && is_array(json_decode($body, true)); }
        $result = array('case' => $case, 'format' => $format, 'debug' => $debug, 'status' => $status, 'expected_status' => $expected,
            'content_type' => $type, 'body_bytes' => strlen($body), 'has_canary' => $canary, 'has_debug' => $hasDebug, 'pass' => $valid);
        $results[] = $result;
        if (!$valid) { $failures[] = $case.' '.$format.' debug='.(int) $debug; }
    }
} finally {
    proc_terminate($process);
    for ($i = 0; $i < 20 && proc_get_status($process)['running']; ++$i) { usleep(50000); }
    $exited = !proc_get_status($process)['running'];
    if (!$exited) { proc_terminate($process, 9); $failures[] = 'fixture server did not exit normally'; }
    proc_close($process);
}
echo json_encode(array('checks' => $results, 'failures' => $failures, 'server_exited' => $exited), JSON_PRETTY_PRINT)."\n";
exit(count($failures) ? 1 : 0);
