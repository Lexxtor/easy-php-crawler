easyPhpCrawler
=================================
It's a simple yet flexible crawler for parsing URLs and loading content.

Usage Example
-------------

``` php
<?php

use Lexxtor\EasyPhpCrawler\EasyPhpCrawler;

require 'EasyPhpCrawler.php';

EasyPhpCrawler::go('http://news.yandex.ru', [
    'beforeLoadUrl' => function($url, $crawler) {
        echo $crawler->currentUrlIndex . '/' . $crawler->getQueueSize() . "  $url  ";
    },
    'afterLoadUrlSuccess' => function($url, $content, $crawler) {
        echo 'loaded: ' . strlen($content) . "\n";
    },
    'afterLoadUrlFail' => static function($url, $errorMessage, $crawler) {
        echo 'Error: ' . $errorMessage . "\n";
    },
    'allowUrlRules' => [
        '/\/\/news.yandex.ru\//',
    ],
    'denyUrlRules' => [
        '/search/',
        '/\/$/',
        '/maps/',
        '/themes/',
        '/\?redircnt=/',
    ],
]);
```
