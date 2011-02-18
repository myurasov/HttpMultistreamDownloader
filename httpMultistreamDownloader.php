<?php

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
  private $maxParallelChunks = 10;
  private $maxChunkSize = 1048576;
  private $minChunkSize = 61440;
  private $progressCallback;

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
    if (false === ($this->totalBytes = $this->_httpFileSize($this->url)))
      throw new Exception("Failed to get file size of \"{$this->url}\"");

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
      $ch = curl_init($this->url);
      curl_setopt($ch, \CURLOPT_WRITEFUNCTION, array($this, '_writeData'));
      curl_setopt($ch, \CURLOPT_HEADER, false);
      curl_setopt($ch, \CURLOPT_RANGE, $range);
      $this->curlHandles[$i] = $ch;
      $this->writePositions[(string) $ch] = $curChunkOffset;
    }

    // Execute chunks

    $runningChunks = 0;
    $curChunkIndex = 0;
    $this->maxParallelChunks = min($this->maxParallelChunks, $totalChunks);
    $this->curlMultiHandle = curl_multi_init();

    do
    {
      $chunksToAdd = min(
          $this->maxParallelChunks - $runningChunks,
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
        && (usleep(10000) || true) // Sleep 10ms
      );

    // Release resources
    $this->_cleanup();
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
      if (false === call_user_func(
        $this->progressCallback,
        $this->doneBytes,
        $this->totalBytes))
      {
        $this->break = true;
        return false;
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

  /**
   * Get remote file size by http protocol
   *
   * @param string $url
   * @return int On error returns FALSE
   */
  private function _httpFileSize($url)
  {
    $ch = curl_init($url);
    curl_setopt($ch, \CURLOPT_NOBODY, true);
    curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, \CURLOPT_HEADER, true);
    curl_setopt($ch, \CURLOPT_FOLLOWLOCATION, true);

    $data = curl_exec($ch);
    curl_close($ch);

    if ($data === false)
      return false;

    $contentLength = '';
    $status = '';

    if (!preg_match('/^HTTP\/1\.[01] (\d)\d\d/', $data, $matches))
      return false; // Can't get HTTP code

    if ($matches[1] != '2')
      return false; // Bad HTTP code

    if (!preg_match('/Content-Length: (\d+)/', $data, $matches))
      return false; // Cant't get content length

    return (int)$matches[1];
  }

  // <editor-fold desc="Getters and setters">

  public function getUrl()
  {
    return $this->url;
  }

  public function setUrl($url)
  {
    $this->url = $url;
  }

  public function getMaxParallelChunks()
  {
    return $this->maxParallelChunks;
  }

  public function setMaxParallelChunks($maxParallelChunks)
  {
    $this->maxParallelChunks = $maxParallelChunks;
  }

  public function getMaxChunbkSize()
  {
    return $this->maxChunbkSize;
  }

  public function setMaxChunbkSize($maxChunbkSize)
  {
    $this->maxChunbkSize = $maxChunbkSize;
  }

  public function getMinChunkSize()
  {
    return $this->minChunkSize;
  }

  public function setMinChunkSize($minChunkSize)
  {
    $this->minChunkSize = $minChunkSize;
  }

  public function getOutputFile()
  {
    return $this->outputFile;
  }

  public function setOutputFile($outputFile)
  {
    $this->outputFile = $outputFile;
  }

  public function setProgressCallback($progressCallback)
  {
    if (!is_callable($progressCallback))
      throw new Exception("Callback must be callable");

    $this->progressCallback = $progressCallback;
  }

  // </editor-fold>
}