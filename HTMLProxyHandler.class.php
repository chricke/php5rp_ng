<?php
include 'ProxyHandler.class.php';

class HTMLProxyHandler extends ProxyHandler {
    /**
     * @type string
     */
    private $_proxyBaseUri = '';

    /**
     * @type string
     */
    private $_baseUri;

    /**
     * @type string
     */
    private $_anchorTarget;

    public function __construct($options)
    {
        if (isset($options['proxyBaseUri']))
            $this->_proxyBaseUri = $options['proxyBaseUri'];
        if (!isset($options['bufferedContentTypes']))
            $options['bufferedContentTypes'] = array('text/html', 'text/css', 'text/javascript', 'application/javascript');
        if (isset($options['anchorTarget']))
            $this->_anchorTarget = $options['anchorTarget'];
        parent::__construct($options);

        // build base URI
        $translatedUri = $this->getTranslatedUri();
        $parsed_url = parse_url($translatedUri);
        if (!isset($parsed_url['scheme']))
            $parsed_url['scheme'] = 'http';
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '/';
        $this->_baseUri = self::unparse_url_base($parsed_url) . $path;
    }

    protected static function unparse_url_base($parsed_url)
    { 
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : ''; 
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : ''; 
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : ''; 
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : ''; 
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : ''; 
        $pass     = ($user || $pass) ? "$pass@" : ''; 
        return "$scheme$user$pass$host$port";
    } 
    
    protected static function unparse_url_request($parsed_url)
    {
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : ''; 
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : ''; 
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : ''; 
        return "$path$query$fragment"; 
    }

    public function getProxyBaseUri()
    {
        return $this->_proxyBaseUri;
    }

    /**
    /* Converts relative URLs to absolute ones, given a base URL.
    /* Modified version of code found at http://nashruddin.com/PHP_Script_for_Converting_Relative_to_Absolute_URL
    /*
    /* taken from miniProxy: https://github.com/joshdick/miniProxy
     */
    public function rel2abs($rel)
    {
        if (empty($rel))
            $rel = ".";
        if (parse_url($rel, PHP_URL_SCHEME) != "") {
            // return if already an absolute URL
            return $rel;
        }
        if (strpos($rel, '//') === 0) {
            // prepend scheme
            return parse_url($this->_baseUri, PHP_URL_SCHEME) . ':' . $rel;
        }
    
        if ($rel[0] == "#" || $rel[0] == "?")
            return $this->_baseUri.$rel; //Queries and anchors
    
        extract(parse_url($this->_baseUri)); //Parse base URL and convert to local variables: $scheme, $host, $path
        $path = isset($path) ? preg_replace('#/[^/]*$#', "", $path) : "/"; //Remove non-directory element from path
        if ($rel[0] == '/') $path = ""; //Destroy path if relative url points to root
        $port = isset($port) && $port != 80 ? ":" . $port : "";
        $auth = "";
        if (isset($user)) {
            $auth = $user;
            if (isset($pass)) {
              $auth .= ":" . $pass;
            }
            $auth .= "@";
        }
        $abs = "$auth$host$path$port/$rel"; //Dirty absolute URL
        for ($n = 1; $n > 0; $abs = preg_replace(array("#(/\.?/)#", "#/(?!\.\.)[^/]+/\.\./#"), "/", $abs, -1, $n)) {} //Replace '//' or '/./' or '/foo/../' with '/'
        return $scheme . "://" . $abs; //Absolute URL is ready.
    }

    /**
    /* Replace text content of DOMNode
     */
    public static function replaceTextContent($n, $value)
    {
        // $n->nodeValue = htmlspecialchars($value, ENT_NOQUOTES);
        while ($n->hasChildNodes()) {
               $n->removeChild($n->firstChild);
        }
    
        $n->appendChild($n->ownerDocument->createTextNode($value));
    }

    /**
     * Proxify URL
     */
    public function proxifyURL($url, $parsed_url = null, $is_redirect = false)
    {
        $proxy_base_uri = $this->getProxyBaseUri();
        if (substr($url, 0, strlen($proxy_base_uri)) == $proxy_base_uri)
            return $url;

        if (!$parsed_url)
            $parsed_url = parse_url($url);

        $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] : '';
        if ($scheme != "http" && $scheme != "https" && $scheme != "ftp")
            return $url;
    
        return $proxy_base_uri . $url;
    }
    
    /**
     * Proxify CSS
     *
     * taken from miniProxy https://github.com/joshdick/miniProxy
     */
    protected function proxifyCSS($buffer)
    {
        $proxy = $this;
        return preg_replace_callback(
            '/url\((.*?)\)/i',
            function($matches) use ($proxy) {
                $url = $matches[1];
                // Remove any surrounding single or double quotes from the URL so it can be passed to rel2abs - the quotes are optional in CSS
                // Assume that if there is a leading quote then there should be a trailing quote, so just use trim() to remove them
                if (strpos($url, "'") === 0) {
                    $url = trim($url, "'");
                }
                if (strpos($url, "\"") === 0) {
                    $url = trim($url, "\"");
                }
                if (stripos($url, "data:") === 0) {
                    // the url isn't an HTTP URL but is actual binary data. Don't proxify it
                    return "url($url)";
                }
                $new_url = $proxy->proxifyURL($proxy->rel2abs($url));
                return "url($new_url)";
            },
            $buffer);
    }

    // Proxify XMLHttpRequest
    protected function proxifyXMLHttpRequest($elem)
    {
        // Attempt to force AJAX requests to be made through the proxy by
        // wrapping window.XMLHttpRequest.prototype.open in order to make
        // all request URLs absolute and point back to the proxy.
        // The rel2abs() JavaScript function serves the same purpose as the server-side one in this file,
        // but is used in the browser to ensure all AJAX request URLs are absolute and not relative.
        // Uses code from these sources:
        // http://stackoverflow.com/questions/7775767/javascript-overriding-xmlhttprequest-open
        // https://gist.github.com/1088850
        // TODO: This is obviously only useful for browsers that use XMLHttpRequest but
        // it's better than nothing.
      
        //Only bother trying to apply this hack if the DOM has a <head> or <body> element;
        //insert some JavaScript at the top of whichever is available first.
        //Protects against cases where the server sends a Content-Type of "text/html" when
        //what's coming back is most likely not actually HTML.
        //TODO: Do this check before attempting to do any sort of DOM parsing?
        $script = $elem->ownerDocument->createElement("script");
        self::replaceTextContent($script,
          '(function() {
              window.proxy_map_url = function(url) {
                function parseURI(url) {
                  var m = String(url).replace(/^\s+|\s+$/g, "").match(/^([^:\/?#]+:)?(\/\/(?:[^:@]*(?::[^:@]*)?@)?(([^:\/?#]*)(?::(\d*))?))?([^?#]*)(\?[^#]*)?(#[\s\S]*)?/);
                  // authority = "//" + user + ":" + pass "@" + hostname + ":" port
                  return (m ? {
                    href : m[0] || "",
                    protocol : m[1] || "",
                    authority: m[2] || "",
                    host : m[3] || "",
                    hostname : m[4] || "",
                    port : m[5] || "",
                    pathname : m[6] || "",
                    search : m[7] || "",
                    hash : m[8] || ""
                  } : null);
                }
    
                function rel2abs(base, href) { // RFC 3986
                    function removeDotSegments(input) {
                        var output = [];
                        input.replace(/^(\.\.?(\/|$))+/, "")
                            .replace(/\/(\.(\/|$))+/g, "/")
                            .replace(/\/\.\.$/, "/../")
                            .replace(/\/?[^\/]*/g, function (p) {
                                if (p === "/..") {
                                    output.pop();
                                } else {
                                    output.push(p);
                                }
                            });
                        return output.join("").replace(/^\//, input.charAt(0) === "/" ? "/" : "");
                    }
    
                    base = parseURI(base || "");
    
                    return !href || !base ? null : (href.protocol || base.protocol) +
                        (href.protocol || href.authority ? href.authority : base.authority) +
                        removeDotSegments(href.protocol || href.authority || href.pathname.charAt(0) === "/" ? href.pathname : (href.pathname ? ((base.authority && !base.pathname ? "/" : "") + base.pathname.slice(0, base.pathname.lastIndexOf("/") + 1) + href.pathname) : base.pathname)) +
                        (href.protocol || href.authority || href.pathname ? href.search : (href.search || base.search)) +
                        href.hash;
                }
    
                if (url == null || url == "" || url.indexOf("'.$this->getProxyBaseUri().'") === 0)
                    return url;
                href = parseURI(url || "");
                if (href.protocol && href.protocol != "http:" && href.protocol != "https:" && href.protocol != "ftp:")
                    return url;
    
                return "'.$this->getProxyBaseUri().'" + rel2abs("'.$this->_baseUri.'", href);
            };
    
            function set_property_descriptor(name, property, descriptor)
            {
                obj = document.createElement(name);
                try {
                    Object.defineProperty(Object.getPrototypeOf(obj), property, descriptor);
                } catch (err) {
                    //console.log("Failed to set property descriptor for " + name + " (" + property + ")");
                }
            }

            if (window.XMLHttpRequest) {
              var proxied = window.XMLHttpRequest.prototype.open;
              window.XMLHttpRequest.prototype.open = function() {
                  arguments[1] = window.proxy_map_url(arguments[1]);
                  return proxied.apply(this, [].slice.call(arguments));
              };
            }

            var src_descriptor = {
                get: function() {
                    return this.getAttribute("src");
                },
                set: function(val) {
                    this.setAttribute("src", window.proxy_map_url(val));
                },
            };
            set_property_descriptor("img", "src", src_descriptor);
            set_property_descriptor("script", "src", src_descriptor);

            var href_descriptor = {
                get: function() {
                    return this.getAttribute("href");
                },
                set: function(val) {
                    this.setAttribute("href", window.proxy_map_url(val));
                },
            };
            set_property_descriptor("a", "href", href_descriptor);
          })();'
        );
        $script->setAttribute("type", "text/javascript");
    
        $elem->insertBefore($script, $elem->firstChild);
    }

    /**
     * Proxify HTML
     *
     * partially taken from miniProxy https://github.com/joshdick/miniProxy
     */
    protected function proxifyHTML($buffer)
    {
        static $html_links = array(
            'a' => 'href',
            'area' => 'href',
            'link' => 'href',
            'img' => array('src', 'longdesc', 'usemap'),
            'object' => array('classid', 'codebase', 'data', 'usemap'),
            'q' => 'cite',
            'blockquote' => 'cite',
            'ins' => 'cite',
            'del' => 'cite',
            'form' => 'action',
            'input' => array('src', 'usemap'),
            'head' => 'profile',
            'base' => 'href',
            'script' => array('src', 'for')
        );
        static $html_links_xpath;
        if (!$html_links_xpath) {
            foreach ($html_links as $e => &$attrs) {
              if (is_string($attrs))
                  $attrs = array($attrs);
              foreach ($attrs as $a) {
                  if ($html_links_xpath)
                      $html_links_xpath .= ' | ';
                  $html_links_xpath .= '//' . $e . '[@' . $a . ']';
              }
            }
        }
    
        $detectedEncoding = mb_detect_encoding($buffer, "UTF-8, ISO-8859-1");
        if ($detectedEncoding) {
            $buffer = mb_convert_encoding($buffer, "HTML-ENTITIES", $detectedEncoding);
        }
    
        $xpath = $this->loadHTML($buffer);
    
        // proxify html links
        foreach ($xpath->query($html_links_xpath) as $e) {
            if (!array_key_exists($e->nodeName, $html_links))
                continue;
    
            foreach ($html_links[$e->nodeName] as $a) {
                $value = $e->getAttribute($a);
                if (!$value)
                    continue;

                $new_value = $this->proxifyURL($this->rel2abs($value));
                if ($new_value != $value) {
                    $e->setAttribute($a, $new_value);
                }
                if ($e->nodeName == 'a' && $this->_anchorTarget) {
                    if (!$e->getAttribute('target')) {
                        $e->setAttribute('target', $this->_anchorTarget);
                    }
                }
            }
        }
    
        // proxify tags with a "style" attribute
        foreach ($xpath->query('//*[@style]') as $e) {
            $value = $e->getAttribute('style');
            $new_value = $this->proxifyCSS($value);
            if ($new_value != $value) {
                $e->setAttribute('style', $new_value);
            }
        }
    
        // proxify <style> tags
        foreach ($xpath->query('//style') as $e) {
            $value = $e->textContent;
            if (!$value)
                continue;
            $new_value = $this->proxifyCSS($value);
            if ($new_value != $value) {
                self::replaceTextContent($e, $new_value);
            }
        }
    
        // proxify <script> tags
        foreach ($xpath->query('//script') as $e) {
            if ($e->hasAttribute('type') && $e->getAttribute('type') != 'text/javascript')
                continue;
            $value = $e->textContent;
            if (!$value)
                continue;
            $new_value = $this->proxifyJS($value);
            if ($new_value != $value) {
                self::replaceTextContent($e, $new_value);
            }
        }

        // proxify XMLHttpRequest
        $head = $xpath->query('//head')->item(0);
        $body = $xpath->query('//body')->item(0);
        $root = $head != null ? $head : $body;
        if ($root != null) {
            $this->proxifyXMLHttpRequest($root);
        }

        return $this->saveHTML($xpath);
    }

    /**
     * Proxify JavaScript
     */
    protected function proxifyJS($buffer)
    {
        return $buffer;
    }

    /**
     * Load HTML document
     */
    protected function loadHTML($buffer)
    {
        $doc = new DOMDocument();
        @$doc->loadHTML($buffer);
        return new DOMXpath($doc);
    }

    /**
     * Save HTML document
     */
    protected function saveHTML($xpath)
    {
        return $xpath->document->saveHTML();
    }

    /**
     * Process buffered output
     */
    protected function processOutput()
    {
        if (!$this->isBuffered()) {
            // nothing to do
            return;
        }

        $buffer = $this->getBuffer();
        $content_type = $this->getContentType();
        if ($content_type == "text/html") {
            $buffer = $this->proxifyHTML($buffer);
        } elseif ($content_type == "text/css") {
            $buffer = $this->proxifyCSS($buffer);
        } elseif (in_array($content_type, array("text/javascript", "application/javascript"))) {
            $buffer = $this->proxifyJS($buffer);
        }
        $content_length = strlen($buffer);
        header("Content-Length: $content_length");
        echo($buffer);
    }
}

// vi: ts=4:sw=4:et:
?>
