<?php

/**
 * HTTP Multistream Downloader
 *
 * @author Mikhail Yurasov <me@yurasov.me>
 * @copyright Copyright (c) 2011 Mikhail Yurasov
 * @version 1.0c
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

  private $url;
  private $outputFile;
  private $parallelChunks = 10;
  private $chunkSize = 1048576;
  private $maxRedirs = 20;
  private $progressCallback;
  private $minCallbackPeriod = 1; // Minimum time between two callbacks [sec]
  private $cookie;
  private $effectiveUrl = '';
  private $networkTimeout = 10; // [sec]

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
   *
   * @return int
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
  public function _download()
  {
    // Open output file for writing
    if (false === ($this->outputFileHandle = fopen($this->outputFile, 'w')))
      throw new Exception("Failed to open file \"{$this->outputFile}\"");

    // Get file size
    $this->totalBytes = $this->getTotalBytes();

    // Calculate total number of chunks
    $totalChunks = (int) ceil($this->totalBytes / $this->chunkSize);

    // Process chunks

    $runningChunks = 0;
    $chunksLeft = $totalChunks;
    $this->parallelChunks = min($this->parallelChunks, $totalChunks);
    $this->curlMultiHandle = curl_multi_init();

    while (($runningChunks || $chunksLeft) && !$this->break)
    {
      // Add chunks to request
      
      $chunksToAdd = min($this->parallelChunks - $runningChunks, $chunksLeft);
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
          if ($curlInfo['result'] != \CURLE_OK)
          {
            throw new Exception("Transfer error");
          }
          else
          {
            curl_multi_remove_handle($this->curlMultiHandle, $curlInfo['handle']);
            unset($this->curlHandles[(string) $curlInfo['handle']]);
            unset($this->writePositions[(string) $curlInfo['handle']]);
            curl_close($curlInfo['handle']);
          }
        }
      }
      while ($curlMessages);

      // Excecute curl multi handle
      
      curl_multi_exec($this->curlMultiHandle, $runningChunks);
      $curlActivity = curl_multi_select($this->curlMultiHandle);

      // Check for network timeout
      
      if ($curlActivity == 0 && ($runningChunks || $chunksLeft))
      {
        $timeStalled = microtime(true);

        while (curl_multi_select($this->curlMultiHandle) == 0)
        {
          if (microtime(true) - $timeStalled > $this->networkTimeout)
            throw new Exception("Network timeout");
        }
      }
    }
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
    $contentLenght = curl_getinfo($ch, \CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $effectiveUrl = curl_getinfo($ch, \CURLINFO_EFFECTIVE_URL);
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
   * @staticvar int $chunkIndex
   * @return resource
   */
  private function _allocateChunk()
  {
    static $chunkIndex = 0;
    
    $curChunkOffset = $chunkIndex * $this->chunkSize;
    $curChunkLength = min($this->chunkSize,
      $this->totalBytes - $this->chunkSize * $chunkIndex);
    $range = $curChunkOffset . '-' . ($curChunkOffset + $curChunkLength - 1);

    $ch = curl_init($this->effectiveUrl);
    curl_setopt($ch, \CURLOPT_WRITEFUNCTION, array($this, '_writeData'));
    curl_setopt($ch, \CURLOPT_HEADER, false);
    curl_setopt($ch, \CURLOPT_RANGE, $range);
    curl_setopt($ch, \CURLOPT_COOKIE, $this->cookie);

    $this->curlHandles[(string) $ch] = $ch;
    $this->writePositions[(string) $ch] = $curChunkOffset;
    $chunkIndex++;

    return $ch;
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
    static $lastCallbackTime = 0;

    fseek($this->outputFileHandle, $this->writePositions[(string) $curlHandle]);
    $dataLength = fwrite($this->outputFileHandle, $data);
    $this->writePositions[(string) $curlHandle] += $dataLength;
    $this->doneBytes += $dataLength;

    if (!is_null($this->progressCallback))
    {
      // Check time elapsed from last callback
      if ((microtime(true) - $lastCallbackTime)
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
        $lastCallbackTime = microtime(true);
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
      fclose($this->outputFileHandle);

    // Set handles to null
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

  public function setParallelChunks($parallelChunks)
  {
    $this->parallelChunks = $parallelChunks;
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

  public function getEffectiveUrl()
  {
    return $this->effectiveUrl;
  }

  public function setChunkSize($chunkSize)
  {
    $this->chunkSize = $chunkSize;
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
          $this->url, $this->effectiveUrl, $this->maxRedirs, $this->cookie)))
        throw new Exception("Unable to get file size of \"$this->url\"");
    }
    
    return $this->totalBytes;
  }

  // </editor-fold>
}
