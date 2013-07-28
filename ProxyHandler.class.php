<?php

class ProxyHandler
{
    const RN = "\r\n";

    private $chunked = false;
    private $url;
    private $proxy_url;
    private $translated_url;
    private $curl_handler;
    private $cache_control = false;
    private $pragma = false;
    private $client_headers = array();

    function __construct($url, $proxy_url, $base_uri = null)
    {
        // Strip the trailing '/' from the URLs so they are the same.
        $this->url = rtrim($url,'/');
        $this->proxy_url = rtrim($proxy_url,'/');

        if ($base_uri === null && isset($_SERVER['REDIRECT_URL'])) {
            $base_uri = dirname($_SERVER['REDIRECT_URL']);
        }

        // Parse all the parameters for the URL
        if (isset($_SERVER['REQUEST_URI'])) {
            $request_uri = $_SERVER['REQUEST_URI'];
            if ($base_uri && strpos($request_uri, $base_uri) === 0) {
                $request_uri = substr($request_uri, strlen($base_uri));
            }
            $proxy_url .= $request_uri;
        }
        else {
            // Add the '/' at the end
            $proxy_url .= '/';
        }

        if ($_SERVER['QUERY_STRING'] !== '') {
            $proxy_url .= "?{$_SERVER['QUERY_STRING']}";
        }

        $this->translated_url = $proxy_url;

        $this->curl_handler = curl_init($this->translated_url);

        // Set various options
        $this->setCurlOption(CURLOPT_RETURNTRANSFER, true);
        $this->setCurlOption(CURLOPT_BINARYTRANSFER, true); // For images, etc.
        $this->setCurlOption(CURLOPT_USERAGENT,$_SERVER['HTTP_USER_AGENT']);
        $this->setCurlOption(CURLOPT_WRITEFUNCTION, array($this,'readResponse'));
        $this->setCurlOption(CURLOPT_HEADERFUNCTION, array($this,'readHeaders'));

        // Process post data.
        if (count($_POST)) {
            // Empty the post data
            $post = array();

            // Set the post data
            $this->setCurlOption(CURLOPT_POST, true);

            // Encode and form the post data
            if (!isset($HTTP_RAW_POST_DATA)) {
                $HTTP_RAW_POST_DATA = file_get_contents("php://input");
            }

            $this->setCurlOption(CURLOPT_POSTFIELDS, $HTTP_RAW_POST_DATA);

            unset($post);
        }
        elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') { // Default request method is 'get'
            // Set the request method
            $this->setCurlOption(CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);
        }
        elseif ($_SERVER['REQUEST_METHOD'] == 'PUT') {
            // Set the request method.
            $this->setCurlOption(CURLOPT_UPLOAD, 1);

            // PUT data comes in on the stdin stream.
            $putdata = fopen("php://input", "r");
            $this->setCurlOption(CURLOPT_READDATA, $putdata);
            // TODO: set CURLOPT_INFILESIZE to the value of Content-Length.
        }

        // Handle the client headers.
        $this->handleClientHeaders();
    }

    public function setClientHeader($header)
    {
        $this->client_headers[] = $header;
    }

    // Executes the proxy.
    public function execute()
    {
        $this->setCurlOption(CURLOPT_HTTPHEADER, $this->client_headers);
        curl_exec($this->curl_handler);
    }

    public function close()
    {
        if ($this->chunked) {
            echo '0' . self::RN . self::RN;
        }
        curl_close($this->curl_handler);
    }

    // Get the information about the request.
    // Should not be called before exec.
    public function getCurlInfo()
    {
        return curl_getinfo($this->curl_handler);
    }

    // Sets a curl option.
    public function setCurlOption($option, $value)
    {
        curl_setopt($this->curl_handler, $option, $value);
    }

    protected function readHeaders(&$cu, $string)
    {
        $length = strlen($string);
        if (preg_match(',^Location:,', $string)) {
            $string = str_replace($this->proxy_url, $this->url, $string);
        }
        elseif (preg_match(',^Cache-Control:,', $string)) {
            $this->cache_control = true;
        }
        elseif (preg_match(',^Pragma:,', $string)) {
            $this->pragma = true;
        }
        elseif (preg_match(',^Transfer-Encoding:,', $string)) {
            $this->chunked = strpos($string, 'chunked') !== false;
        }
        if ($string !== "\r\n") {
            header(rtrim($string));
        }
        return $length;
    }

    protected function handleClientHeaders()
    {
        $headers = $this->request_headers();
        $xForwardedFor = array();

        foreach ($headers as $header => $value) {
            switch($header) {
                case 'Host':
                case 'X-Real-IP':
                    break;
                case 'X-Forwarded-For':
                    $xForwardedFor[] = $value;
                    break;
                default:
                    $this->setClientHeader(sprintf('%s: %s', $header, $value));
                    break;
            }
        }

        $xForwardedFor[] = $_SERVER['REMOTE_ADDR'];
        $this->setClientHeader('X-Forwarded-For: ' . implode(',', $xForwardedFor));
        $this->setClientHeader('X-Real-IP: ' . $xForwardedFor[0]);
    }

    protected function readResponse(&$cu, $string)
    {
        static $headersParsed = false;

        // Clear the Cache-Control and Pragma headers
        // if they aren't passed from the proxy application.
        if ($headersParsed === false) {
            if (!$this->cache_control) {
                header('Cache-Control: ');
            }
            if (!$this->pragma) {
                header('Pragma: ');
            }
            $headersParsed = true;
        }

        $length = strlen($string);
        if ($this->chunked) {
            echo dechex($length) . self::RN . $string . self::RN;
        } else {
            echo $string;
        }
        return $length;
    }

    function request_headers()
    {
        if (function_exists("apache_request_headers")) { // If apache_request_headers() exists
            if ($headers = apache_request_headers()) { // And works...
                return $headers; // Use it
            }
        }

        $headers = array();
        foreach (array_keys($_SERVER) as $skey) {
            if (substr($skey, 0, 5) == 'HTTP_') {
                $headername = substr($skey, 5, strlen($skey));
                $headername = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $headername))));
                $headers[$headername] = $_SERVER[$skey];
            }
        }
        return $headers;
    }
}
