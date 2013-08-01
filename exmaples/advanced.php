<?php

if (!@include __DIR__ . '/../ProxyHandler.class.php') {
    die('Could not load proxy');
}

/* EXPECTED RESPONSE:
{
   "object_or_array": "object",
   "empty": false,
   "parse_time_nanoseconds": <int>,
   "validate": true,
   "size": 1
}
*/

$proxy = new ProxyHandler(array(
    'proxyUri' => 'http://validate.jsontest.com',
    'requestMethod' => 'POST',
    'data' => 'json=' . json_encode(array('some_key' => 'some_value'))
));

// Prevents cURL from hanging on errors
$proxy->setCurlOption(CURLOPT_CONNECTTIMEOUT, 1);
$proxy->setCurlOption(CURLOPT_TIMEOUT, 5);

// Check for a success
if ($proxy->execute() === false) {

    // Set status header
    $protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
    header($protocol . ' 502: Bad Gateway');

    // Output error page
?><html>
<head><title>502 Bad Gateway</title></head>
<body bgcolor="white">
<center><h1>502 Bad Gateway</h1></center>
<hr><center>proxy handler</center>
</body>
</html>
<?php
    // You've probably seen this in other servers ;)
    echo str_repeat("<!-- a padding to disable MSIE and Chrome friendly error page -->\n", 6);
}

$proxy->close();
