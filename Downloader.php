<?php

/**
 * HTTP Multistream Downloader
 *
 * @author Mikhail Yurasov <me@yurasov.me>
 * @copyright Copyright (c) 2011 Mikhail Yurasov
 * @version 1.0.6
 */

namespace ymF\Component\HttpMultistreamDownloader;

use Exception;

class Downloader
{
  private $curlMultiHandle;
  private $outputFileHandle;
  private $writePositions = array();
  private $curlHandles = array();
  private $doneBytes = 0;
  private $totalBytes;
  private $break = false;
  private $lastProgressCallbackTime = 0;
  private $chunkIndex = 0;

  private $url;
  private $outputFile;
  private $maxParallelChunks = 10;
  private $chunkSize = 1048576;
  private $maxRedirs = 20;
  private $progressCallback;
  private $minCallbackPeriod = 1; // Minimum time between two callbacks [sec]
  private $cookie;
  private $networkTimeout = 60;   // [sec]
  private $debugMode = false;
  private $runningChunks = 0;
  private $userAgent = 'PHP';

  public function __construct($url)
  {
    if (!extension_loaded('curl'))
      throw new Exception('cURL extension is not loaded');

    $this->url = $url;
  }

  public function __destruct()
  {
    // Release resources
    $this->_cleanup();
  }

  /**
   * Download file
   *
   * @return int Bytes received
   */
  public function download()
  {
    try
    {
      $this->_download();
    }
    catch (Exception $e)
    {
      $this->_cleanup();
      throw $e;
    }

    $this->_cleanup();
    return $this->doneBytes;
  }

  /**
   * Download file
   */
  private function _download()
  {
    // Open output file for writing
    if (false === ($this->outputFileHandle = @fopen($this->getOutputFile(), 'w')))
      throw new Exception("Failed to open file \"{$this->getOutputFile()}\"");

    // Get file size
    $this->totalBytes = $this->getTotalBytes();

    // Calculate total number of chunks
    $totalChunks = (int) ceil($this->totalBytes / $this->chunkSize);

    // Process chunks

    $this->runningChunks = 0;
    $chunksLeft = $totalChunks;
    $this->maxParallelChunks = min($this->maxParallelChunks, $totalChunks);
    $this->curlMultiHandle = curl_multi_init();
    $curlSelectTimeout = min(1, $this->networkTimeout);
    
    while (($this->runningChunks || $chunksLeft) && !$this->break)
    {
      // Add chunks to request
      
      $chunksToAdd = min($this->maxParallelChunks - $this->runningChunks, $chunksLeft);
      $chunksLeft -= $chunksToAdd;

      for ($i = 0; $i < $chunksToAdd; $i++)
        curl_multi_add_handle($this->curlMultiHandle,
          $this->_allocateChunk());

      // Release funished curl handles
      
      do
      {
        $curlMessages = 0;
        $curlInfo = curl_multi_info_read($this->curlMultiHandle, $curlMessages);

        if ($curlInfo !== false)
        {
          if ($curlInfo['result'] == \CURLE_OK)
          {
            curl_multi_remove_handle($this->curlMultiHandle, $curlInfo['handle']);
            unset($this->curlHandles[(string) $curlInfo['handle']]);
            unset($this->writePositions[(string) $curlInfo['handle']]);
            curl_close($curlInfo['handle']);
          }
          else throw new Exception("Transfer error: " . curl_error($curlInfo['handle']));
        }
      }
      while ($curlMessages);

      // Excecute curl multi handle
      
      curl_multi_exec($this->curlMultiHandle, $this->runningChunks);
      curl_multi_select($this->curlMultiHandle, $curlSelectTimeout);
    }
  }

  /**
   * Get remote file size by http protocol
   *
   * @param string $url
   * @param int $maxRedirs
   * @param string $cookie
   * @return int On error returns FALSE
   */
  private function _httpFileSize(
    $url,
    $maxRedirs = 20,
    $cookie = null)
  {
    $ch = curl_init($url);

    curl_setopt_array($ch, array(
      \CURLOPT_NOBODY => true,
      \CURLOPT_RETURNTRANSFER => true,
      \CURLOPT_HEADER => true,
      \CURLOPT_FOLLOWLOCATION => true,
      \CURLOPT_MAXREDIRS => $maxRedirs,
      \CURLOPT_COOKIE => $cookie,
      \CURLOPT_CONNECTTIMEOUT => $this->networkTimeout,
      \CURLOPT_LOW_SPEED_TIME => $this->networkTimeout,
      \CURLOPT_LOW_SPEED_LIMIT => 1,
      \CURLOPT_USERAGENT => $this->userAgent
    ));

    $data = curl_exec($ch);
    $contentLenght = curl_getinfo($ch, \CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Check HTTP response code
    if (substr((string)$httpCode, 0, 1) != 2)
      return false; // Bad HTTP response code

    if ($contentLenght < 0)
      return false; // Can't get content length

    return $contentLenght;
  }

  /**
   * Allocate chunk
   * 
   * @return resource
   */
  private function _allocateChunk()
  {
    $curChunkOffset = $this->chunkIndex * $this->chunkSize;
    $curChunkLength = min($this->chunkSize,
      $this->totalBytes - $this->chunkSize * $this->chunkIndex);
    $range = $curChunkOffset . '-' . ($curChunkOffset + $curChunkLength - 1);

    $ch = curl_init($this->url);
    
    curl_setopt_array($ch, array(
      \CURLOPT_WRITEFUNCTION => array($this, '_writeData'),
      \CURLOPT_HEADER => false,
      \CURLOPT_RANGE => $range,
      \CURLOPT_CONNECTTIMEOUT => $this->networkTimeout,
      \CURLOPT_LOW_SPEED_TIME => $this->networkTimeout,
      \CURLOPT_LOW_SPEED_LIMIT => 1,
      \CURLOPT_COOKIE => $this->cookie,
      \CURLOPT_FOLLOWLOCATION => true,
      \CURLOPT_VERBOSE => $this->debugMode,
      \CURLOPT_USERAGENT => $this->userAgent
    ));

    $this->curlHandles[(string) $ch] = $ch;
    $this->writePositions[(string) $ch] = $curChunkOffset;
    $this->chunkIndex++;

    return $ch;
  }

  /**
   * Data writing callback funtion for curl
   *
   * @param resource $curlHandle
   * @param string $data
   * @return int Length of data written or FALSE on user request to break
   */
  private function _writeData($curlHandle, $data)
  {
    fseek($this->outputFileHandle, $this->writePositions[(string) $curlHandle]);
    $dataLength = fwrite($this->outputFileHandle, $data);
    $this->writePositions[(string) $curlHandle] += $dataLength;
    $this->doneBytes += $dataLength;

    if (!is_null($this->progressCallback))
    {
      // Check time elapsed from last callback
      if ((microtime(true) - $this->lastProgressCallbackTime)
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
        $this->lastProgressCallbackTime = microtime(true);
      }
    }

    return $dataLength;
  }

  /**
   * Release resources
   */
  private function _cleanup()
  {
    // Release left curl handles
    foreach ($this->curlHandles as $key => $ch)
    {
      curl_multi_remove_handle($this->curlMultiHandle, $ch);
      curl_close($ch);
      unset($this->curlHandles[$key]);
    }

    // Release curl multi handle
    if (!is_null($this->curlMultiHandle))
      curl_multi_close($this->curlMultiHandle);

    // Close output file
    if (!is_null($this->outputFileHandle))
      @fclose($this->outputFileHandle);

    // Set handles to null
    $this->curlMultiHandle = $this->outputFileHandle = null;
  }

  // <editor-fold desc="Getters and setters">

  public function setProgressCallback($progressCallback)
  {
    if (!is_callable($progressCallback) && !is_null($progressCallback))
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

  public function setMinCallbackPeriod($minCallbackPeriod)
  {
    $this->minCallbackPeriod = $minCallbackPeriod;
  }

  public function setCookie($cookie)
  {
    $this->cookie = $cookie;
  }

  public function setMaxRedirs($maxRedirs)
  {
    $this->maxRedirs = $maxRedirs;
  }

  public function setChunkSize($chunkSize)
  {
    $this->chunkSize = (int) $chunkSize;
  }

  public function setNetworkTimeout($networkTimeout)
  {
    $this->networkTimeout = $networkTimeout;
  }

  public function getTotalBytes()
  {
    if (is_null($this->totalBytes))
    {
      // Get file size over HHTP
      if (false === ($this->totalBytes = $this->_httpFileSize(
          $this->url, $this->maxRedirs, $this->cookie)))
        throw new Exception("Unable to get file size of \"$this->url\"");
    }
    
    return $this->totalBytes;
  }

  public function setDebugMode($debugMode)
  {
    $this->debugMode = $debugMode;
  }

  public function getRunningChunks()
  {
    return $this->runningChunks;
  }

  public function setMaxParallelChunks($maxParallelChunks)
  {
    $this->maxParallelChunks = $maxParallelChunks;
  }

  public function getOutputFile()
  {
    if (is_null($this->outputFile) && !is_null($this->url))
      $this->outputFile = basename($this->url);

    return $this->outputFile;
  }

  public function getUserAgent()
  {
    return $this->userAgent;
  }

  public function setUserAgent($userAgent)
  {
    $this->userAgent = $userAgent;
  }

  // </editor-fold>
}
