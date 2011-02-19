<?php

/**
 * HTTP Multistream Downloader
 *
 * @author Mikhail Yurasov <me@yurasov.me>
 * @copyright Copyright (c) 2011 Mikhail Yurasov
 * @version 1.0b
 */

namespace ymF\Components;

use Exception;

class httpMultistreamDownloader
{
  private $curlMultiHandle;
  private $outputFileHandle;
  private $writePositions = array();
  private $curlHandles = array();
  private $doneBytes = 0;
  private $totalBytes;
  private $break = false;
  private $progressCallbackTime = 0;

  private $url;
  private $outputFile;
  private $maxParallelChunks = 10;
  private $maxChunkSize = 1048576;
  private $minChunkSize = 61440;
  private $maxRedirs = 20;
  private $progressCallback;
  private $minCallbackPeriod = 1; // Minimum time between two callbacks
  private $cookie;
  private $effectiveUrl = '';

  public function __construct()
  {
    if (!extension_loaded('curl'))
      throw new Exception('cURL extension is not loaded');
  }

  public function __destruct()
  {
    // Release resources
    $this->_cleanup();
  }

  /**
   * Download file
   */
  public function download()
  {
    // Open output file for writing
    if (false === ($this->outputFileHandle = fopen($this->outputFile, 'w')))
      throw new Exception("Failed to open file \"{$this->outputFile}\"");

    // Get file size over HHTP
    $this->totalBytes = $this->_httpFileSize(
      $this->url, $this->effectiveUrl, $this->maxRedirs, $this->cookie);
    
    if (false === $this->totalBytes)
      throw new Exception("Unable to get file size of \"$this->url\"");

    // Calculate desired chunk size

    $desiredChunkSize = min(ceil($this->totalBytes / $this->maxParallelChunks), $this->maxChunkSize);
    $desiredChunkSize = max($desiredChunkSize, $this->minChunkSize);
    $totalChunks = ceil($this->totalBytes / $desiredChunkSize);

    // Allocate chunks

    for ($i = 0; $i < $totalChunks; $i++)
    {
      $curChunkOffset = $i * $desiredChunkSize;
      $curChunkLength = min($desiredChunkSize, $this->totalBytes - $desiredChunkSize * $i);
      $range = $curChunkOffset . '-' . ($curChunkOffset + $curChunkLength - 1);
      $ch = curl_init($this->effectiveUrl);

      curl_setopt($ch, \CURLOPT_WRITEFUNCTION, array($this, '_writeData'));
      curl_setopt($ch, \CURLOPT_HEADER, false);
      curl_setopt($ch, \CURLOPT_RANGE, $range);
      curl_setopt ($ch, \CURLOPT_COOKIE, $this->cookie);

      $this->curlHandles[$i] = $ch;
      $this->writePositions[(string) $ch] = $curChunkOffset;
    }

    // Execute chunks

    $runningChunks = 0;
    $curChunkIndex = 0;
    $maxParallelChunks = min($this->maxParallelChunks, $totalChunks);
    $this->curlMultiHandle = curl_multi_init();

    do
    {
      $chunksToAdd = min(
          $maxParallelChunks - $runningChunks,
          $totalChunks - $curChunkIndex);

      // Add chunks to request
      for ($i = 0; $i < $chunksToAdd; $i++)
      {
        $ch = $this->curlHandles[$curChunkIndex];
        curl_multi_add_handle($this->curlMultiHandle, $ch);
        $curChunkIndex++;
      }

      curl_multi_exec($this->curlMultiHandle, $runningChunks);
    }
    while (
        (($curChunkIndex < $totalChunks)
        || ($runningChunks > 0)
        || ($chunksToAdd > 0))
        && !$this->break
        && (usleep(10000) || true) // Sleep 10ms
      );

    // Release resources
    $this->_cleanup();
  }

  /**
   * Get remote file size by http protocol
   *
   * @param string $url
   * @param string &$effectiveUrl
   * @param int $maxRedirs
   * @param string $cookie
   * @return int On error returns FALSE
   */
  private function _httpFileSize(
    $url,
    &$effectiveUrl = null,
    $maxRedirs = 20,
    $cookie = null)
  {
    $ch = curl_init($url);

    curl_setopt($ch, \CURLOPT_NOBODY, true);
    curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, \CURLOPT_HEADER, true);
    curl_setopt($ch, \CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, \CURLOPT_MAXREDIRS, $maxRedirs);
    curl_setopt($ch, \CURLOPT_COOKIE, $cookie);

    $data = curl_exec($ch);
    $effectiveUrl = curl_getinfo($ch, \CURLINFO_EFFECTIVE_URL);
    $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
    $redirects = curl_getinfo($ch, \CURLINFO_REDIRECT_COUNT);
    curl_close($ch);

    // Check HTTP response code
    if (substr((string)$httpCode, 0, 1) != 2)
      return false; // Bad HTTP response code

    // Get last response
    $matches = preg_split('/^HTTP/m', $data);
    $data = array_pop($matches);

    // Get content length
    $matches = array();
    if (!preg_match('/Content-Length: (\d+)/', $data, $matches))
      return false; // Can't get conntent length

    return (int) $matches[1];
  }

  /**
   * Data writing callback funtion for curl
   *
   * @param resource $curlHandle
   * @param string $data
   * @return int Length of data written or FALSE on user request to break
   */
  public function _writeData($curlHandle, $data)
  {
    $dataLength = strlen($data);
    fseek($this->outputFileHandle, $this->writePositions[(string) $curlHandle]);
    fwrite($this->outputFileHandle, $data);
    $this->writePositions[(string) $curlHandle] += $dataLength;
    $this->doneBytes += $dataLength;

    if (!is_null($this->progressCallback))
    {
      // Check time elapsed from last callback
      if ((microtime(true) - $this->progressCallbackTime)
        >= $this->minCallbackPeriod)
      {
        if (false === call_user_func(
          $this->progressCallback,
          $this->doneBytes,
          $this->totalBytes))
        {
          $this->break = true;
          return false;
        }

        // Save last callback time
        $this->progressCallbackTime = microtime(true);
      }
    }

    return $dataLength;
  }

  /**
   * Release resources
   */
  private function _cleanup()
  {
    if (!is_null($this->curlMultiHandle))
      curl_multi_close($this->curlMultiHandle);

    if (!is_null($this->outputFileHandle))
      fclose($this->outputFileHandle);

    $this->curlMultiHandle = $this->outputFileHandle = null;
  }

  // <editor-fold desc="Getters and setters">

  public function setProgressCallback($progressCallback)
  {
    if (!is_callable($progressCallback))
      throw new Exception("Callback must be callable");

    $this->progressCallback = $progressCallback;
  }

  public function setProgressCallbackTime($progressCallbackTime)
  {
    $this->progressCallbackTime = $progressCallbackTime;
  }

  public function setUrl($url)
  {
    $this->url = $url;
  }

  public function setOutputFile($outputFile)
  {
    $this->outputFile = $outputFile;
  }

  public function setMaxParallelChunks($maxParallelChunks)
  {
    $this->maxParallelChunks = $maxParallelChunks;
  }

  public function setMaxChunkSize($maxChunkSize)
  {
    $this->maxChunkSize = $maxChunkSize;
  }

  public function setMinCallbackPeriod($minCallbackPeriod)
  {
    $this->minCallbackPeriod = $minCallbackPeriod;
  }

  public function setCookie($cookie)
  {
    $this->cookie = $cookie;
  }

  public function setMinChunkSize($minChunkSize)
  {
    $this->minChunkSize = $minChunkSize;
  }

  public function setMaxRedirs($maxRedirs)
  {
    $this->maxRedirs = $maxRedirs;
  }

  public function getEffectiveUrl()
  {
    return $this->effectiveUrl;
  }

  // </editor-fold>
}
