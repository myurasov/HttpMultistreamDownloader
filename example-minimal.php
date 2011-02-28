<?php

/**
 * HttpMultistreamDownloader simplest example
 *
 * @author Mikhail Yurasov <me@yurasov.me>
 * @copyright Copyright (c) 2011 Mikhail Yurasov
 */

include 'Downloader.php';

$downloader = new ymF\Component\HttpMultistreamDownloader\Downloader(
  'http://fastdl.mongodb.org/osx/mongodb-osx-x86_64-1.6.5.tgz');
$downloader->download();

?>