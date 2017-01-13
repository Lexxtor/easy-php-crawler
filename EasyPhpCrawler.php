<?php

namespace Lexxtor\EasyPhpCrawler;


/**
 * Class EasyPhpCrawler
 */
class EasyPhpCrawler
{
    /**
     * If set, then every URL must satisfy at last one of this rules.
     * @var string[]|callable[] regular expression or function($url, $this)
     */
    public $allowUrlRules = [];
    /**
     * If set, then every URL must not satisfy all of this rules.
     * @var string[]|callable[] regular expression or function($url, $this)
     */
    public $denyUrlRules = [];
    /**
     * Function that calls before loading URL. Return false to prevent URL loading.
     * @var callable|null regular expression or function($url, $this)
     */
    public $beforeLoadUrl;
    /**
     * Return any non null value to overwrite loaded content.
     * @var callable|null  function($url, $content, $this)
     */
    public $afterLoadUrlSuccess;
    /**
     * @var callable|null  function($url, $errorMessage, $this)
     */
    public $afterLoadUrlFail;
    /**
     * @var callable|null  function($allowedUrls, $content, $this)
     */
    public $afterParseUrls;
    /**
     * RegExp used to parse URLs from loaded HTML.
     * @var string
     */
    public $urlRegExp = '%(?<=["\'])((https?://)|(//)|(/))([^\s/?\.#"<>-][^\s/?\.#"<>]*\.?)+(/[^"\'\s<>]*)?%i';
    /**
     * HTTP referrer header
     * @var string
     */
    public $referrer = 'https://www.google.ru/';

    /**
     * Example: 'tcp://1.2.3.4:5555'
     * @var string
     */
    public $proxy;
    /**
     * @var string
     */
    public $proxyLogin;
    /**
     * @var string
     */
    public $proxyPass;

    /**
     * @var string[]
     */
    public $urlsQueue = [];
    /**
     * @var int
     */
    public $currentUrlIndex = 0;

    /**
     * @return string first URL host
     */
    public function getStartHost() {
        return parse_url($this->urlsQueue[0], PHP_URL_HOST);
    }

    /**
     * @return string first URL scheme
     */
    public function getStartScheme() {
        return parse_url($this->urlsQueue[0], PHP_URL_SCHEME) ?: 'http';
    }

    /**
     * Return URLs from content that allowed by one of $this->urlAllowRules (if any) and not denied by any of $this->urlDenyRules
     * @param string $content
     * @return string[] urls
     */
    public function parseUrls($content) {
        // get URLs
        preg_match_all($this->urlRegExp, $content, $matches);
        $urls = array_unique($matches[0]);

        // filter URLs
        $allowedUrls = [];
        foreach ($urls as $url) {
            $url = $this->normalizeURL($url);
            if ($this->checkUrl($url)) {
                $allowedUrls[] = $url;
            }
        }
        $allowedUrls = array_unique($allowedUrls);

        // event callback
        if (is_callable($this->afterParseUrls)) {
            call_user_func($this->afterParseUrls, $allowedUrls, $content, $this);
        }

        return $allowedUrls;
    }

    /**
     * Check that URL allowed by one of $this->urlAllowRules (if any) and not denied by any of $this->urlDenyRules
     * @param $url
     * @return boolean $url suits rules or not
     */
    public function checkUrl($url) {
        // must suit at last one allow rule
        if ($this->allowUrlRules) {
            $allow = false;
            foreach ($this->allowUrlRules as $urlRule) {
                if (is_string($urlRule))
                {
                    if (preg_match($urlRule, $url)) {
                        $allow = true;
                        break;
                    }
                }
                elseif (call_user_func($urlRule, $url, $this)) {
                    $allow = true;
                    break;
                }
            }
        }
        else
            $allow = true;

        if (!$allow)
            return false;

        // must not suit all deny rules
        foreach ($this->denyUrlRules as $urlRule) {
            if (is_string($urlRule))
            {
                if (preg_match($urlRule, $url)) {
                    return false;
                }
            }
            elseif (call_user_func($urlRule, $url, $this)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert any valid URL to absolute URL with protocol.
     * @param $url
     * @return string
     */
    public function normalizeURL($url) {
        if (substr($url,0,2) == '//')
            $url = $this->getStartScheme().':' . $url;
        elseif (substr($url,0,1) == '/')
            $url = $this->getStartScheme().'://' . $this->getStartHost() . $url;

        return $url;
    }

    /**
     * Adds URLs to queue, if they not exist in it.
     * @param array $urls
     */
    public function addUrls(array $urls) {
        foreach ($urls as $url) {
            if (!in_array($url, $this->urlsQueue))
                $this->urlsQueue[] = $url;
        }
    }

    /**
     * @return string|null URL or null if queue is ended.
     */
    public function getNextUrl() {
        if (isset($this->urlsQueue[$this->currentUrlIndex]))
            return $this->urlsQueue[$this->currentUrlIndex++];
        else
            return null;
    }

    /**
     * @param string $url
     * @return string|null content from URL or null
     */
    public function load($url) {
        $url = $this->normalizeURL($url);

        if (is_callable($this->beforeLoadUrl) && call_user_func($this->beforeLoadUrl, $url, $this) === false)
            return null;

        $content = $this->_load($url, $errorMessage);

        if ($content === null) {
            // on fail
            if (is_callable($this->afterLoadUrlFail)) {
                call_user_func($this->afterLoadUrlFail, $url, $errorMessage, $this);
            } else {
                echo "Error: $errorMessage\n";
            }
        }
        else {
            // on success
            if (is_callable($this->afterLoadUrlSuccess)) {
                $result = call_user_func($this->afterLoadUrlSuccess, $url, $content, $this);
                if ($result !== null) {
                    $content = $result;
                }
            }
        }

        return $content;
    }

    /**
     * @param string $url
     * @param string $errorMessage
     * @return string|null
     */
    public function _load($url, &$errorMessage = '') {
        $context = stream_context_create([
            'http' => [
                'proxy' => $this->proxy ? $this->proxy : null,
                'request_fulluri' => $this->proxy ? true : false,
                'method' => "GET",
                'header' => "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8\r\n" .
                    ($this->proxyLogin ? "Proxy-Authorization: Basic ".base64_encode("{$this->proxyLogin}:{$this->proxyPass}") : '') .
                    "Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.6,en;q=0.4\r\n" .
                    ($this->referrer ? "Referer: {$this->referrer}\r\n" : "") .
                    "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/51.0.2704.79 Chrome/51.0.2704.79 Safari/537.36"
            ]
        ]);

        try {
            $content = @file_get_contents($url, false, $context);
        }
        catch (\Exception $exception) {
            $errorMessage = $exception->getMessage();
            return null;
        }

        if ($content === false) {
            $errorMessage = 'Not found.';
            return null;
        }

        return $content;
    }

    public function getQueueSize() {
        return sizeof($this->urlsQueue);
    }

    /**
     * Crawl from given URLs.
     *
     * @param $urls string[]|string
     */
    public function crawl($urls) {
        if (is_string($urls))
            $urls = [$urls];

        $this->addUrls($urls);

        while ($url = $this->getNextUrl()) {
            $content = $this->load($url);
            $this->addUrls($this->parseUrls($content));
        }
    }

    /**
     * Start crawling from given URLs.
     *
     * @param string|string[] $urls address to start from
     * @param array $options
     */
    public static function go($urls, $options = []) {
        $instance = new static;

        foreach ($options as $option => $value) {
            $instance->$option = $value;
        }

        $instance->crawl($urls);
    }
}
