<?php

/**
 * httpMultistreamDownloader example
 *
 * @author Mikhail Yurasov <me@yurasov.me>
 * @copyright Copyright (c) 2011 Mikhail Yurasov
 */

include 'Downloader.php';

use ymF\Component\HttpMultistreamDownloader\Downloader;

$url = 'http://mirror.anl.gov/pub/ubuntu-iso/CDs-Edubuntu/6.06.1/edubuntu-6.06.1-install-amd64.iso';
$outputFile = pathinfo($url, PATHINFO_BASENAME);

$downloader = new Downloader($url);
$downloader->setOutputFile($outputFile);
$downloader->setMinCallbackPeriod(3);
$downloader->setMaxParallelChunks(30);
$downloader->setChunkSize(1024 * 1024);
$downloader->setDebugMode(true);;

$downloader->setProgressCallback(function($position, $totalBytes) use ($downloader)
  {
    static $prevPosition = 0;
    static $prevTime = 0;

    $now = microtime(true);
    $speed = ($position - $prevPosition) / ($now - $prevTime);
    $speed = round($speed / 1024 / 1024, 2) . 'MB/s';
    $positionFormatted = round($position / 1024 / 1024, 2) . 'MB';
    $totalBytesFormatted = round($totalBytes / 1024 / 1024, 2) . 'MB';
    $streams = $downloader->getRunningChunks();

    if ($now - $prevTime < 1e3)
      echo "speed: $speed; done: $positionFormatted / $totalBytesFormatted; streams: $streams\n";

    $prevPosition = $position;
    $prevTime = $now;

    return true;
  });

echo "Downloading $url ...\n";
echo "Content length: " . $downloader->getTotalBytes() . " bytes\n";

$downloader->download();

echo "Done!\n";
echo "Output file size: " . filesize($outputFile) . " bytes\n";
echo "Output file MD5: " . md5_file($outputFile) . "\n";