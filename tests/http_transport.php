<?php
namespace WebOrgTests;
// Load only the transport function, never the exporter or local repository scanner.
$source = \file_get_contents($argv[1] ?? __DIR__ . '/../index.php');
$start = strpos($source, 'function httpGetJson(');
$end = strpos($source, 'function fetchGitHubOrgRepos(', $start);
eval('namespace WebOrgTests; ' . substr($source, $start, $end - $start));
foreach (['CURLOPT_URL', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_USERAGENT', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_TIMEOUT', 'CURLOPT_HTTPHEADER', 'CURLOPT_FOLLOWLOCATION', 'CURLINFO_RESPONSE_CODE'] as $i => $name) {
    if (!defined($name)) define($name, $i + 10000);
}
function function_exists($name) { return $GLOBALS['transport'] === 'curl'; }
function curl_init() { return new \stdClass(); }
function curl_setopt($ch, $key, $value) { $GLOBALS['options'][$key] = $value; return true; }
function curl_exec($ch) { $GLOBALS['calls']++; return $GLOBALS['response']; }
function curl_getinfo($ch, $key) { return $GLOBALS['status']; }
function curl_close($ch) {}
function stream_context_create($options) { $GLOBALS['options'] = $options; return null; }
function fopen($url, $mode, $usePath, $context) { $GLOBALS['calls']++; return $GLOBALS['response'] === false ? false : new \stdClass(); }
function stream_get_meta_data($stream) { return ['wrapper_data' => ['HTTP/1.1 ' . $GLOBALS['status'] . ' Test']]; }
function stream_get_contents($stream) { return $GLOBALS['response']; }
function fclose($stream) {}
// Legacy transport still reaches this in the red regression run.
function file_get_contents($url, $usePath, $context) { $GLOBALS['calls']++; return $GLOBALS['response']; }
function check($condition, $message) { if (!$condition) throw new \RuntimeException($message); }
putenv('GITHUB_TOKEN=test-only-not-a-credential');
foreach (['curl', 'stream'] as $transport) {
    $GLOBALS['transport'] = $transport;
    foreach ([200, 401, 302, 500] as $status) {
        $GLOBALS['status'] = $status; $GLOBALS['response'] = '[{"name":"demo"}]';
        $GLOBALS['options'] = []; $GLOBALS['calls'] = 0;
        $result = httpGetJson('https://api.github.com/orgs/demo/repos');
        check($status === 200 ? is_array($result) : $result === null, "$transport HTTP $status must be respected");
        $options = $GLOBALS['options'];
        if ($transport === 'curl') {
            check(($options[CURLOPT_SSL_VERIFYPEER] ?? null) === true, 'cURL certificate verification');
            check(($options[CURLOPT_SSL_VERIFYHOST] ?? null) === 2, 'cURL hostname verification');
            check(($options[CURLOPT_FOLLOWLOCATION] ?? null) === false, 'cURL redirects disabled');
        } else {
            check(($options['ssl']['verify_peer'] ?? null) === true, 'stream certificate verification');
            check(($options['ssl']['verify_peer_name'] ?? null) === true, 'stream hostname verification');
            check(($options['http']['follow_location'] ?? null) === 0, 'stream redirects disabled');
        }
    }
    foreach (['http://api.github.com/repos', 'https://example.test/', 'https://api.github.com.evil.test/', 'https://user:pass@api.github.com/', 'https://api.github.com:444/'] as $url) {
        $GLOBALS['calls'] = 0;
        check(httpGetJson($url) === null && $GLOBALS['calls'] === 0, "$transport rejects $url before sending credentials");
    }
    foreach ([false, 'invalid JSON'] as $body) {
        $GLOBALS['response'] = $body; $GLOBALS['status'] = 200; $GLOBALS['calls'] = 0;
        check(httpGetJson('https://api.github.com/repos') === null, "$transport fails closed");
        check($GLOBALS['calls'] === 1, "$transport must not retry through another transport");
    }
}
echo "PASS: both transports verify TLS, reject redirects/HTTP errors/foreign destinations, and fail closed\n";
