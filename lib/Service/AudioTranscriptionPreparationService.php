<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use OCA\OpenAi\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AudioTranscriptionPreparationService {
 public const OPENAI_MAX_UPLOAD_BYTES = 25 * 1024 * 1024;
 public const SAFE_UPLOAD_BYTES = 24 * 1024 * 1024;

 private const CHUNK_SECONDS = 900;
 private const RETRY_CHUNK_SECONDS = 600;
 private const AUDIO_BITRATE = '32k';
 private const FFMPEG_TIMEOUT_SECONDS = 600;

 public function __construct(
  private LoggerInterface $logger,
  private IL10N $l10n,
  private IAppConfig $appConfig,
 ) {
 }

 /**
  * @return array<int, array{path: string, filename: string}>
  */
 public function prepare(File $file): array {
  $workDir = $this->createWorkDir();
  try {
   $inputPath = $workDir . '/input.' . $this->getExtension($file->getName(), 'webm');
   $this->copyFileToPath($file, $inputPath);

   if ($this->isUnderUploadLimit($inputPath)) {
    return [[
     'path' => $inputPath,
     'filename' => $file->getName() ?: basename($inputPath),
    ]];
   }

   $this->assertFfmpegAvailable();

   $extractedPath = $workDir . '/audio.webm';
   $this->runFfmpeg([
    $this->getFfmpegBinary(), '-y', '-i', $inputPath, '-vn', '-c:a', 'copy', $extractedPath,
   ], 'extract audio from recording');

   if ($this->isUnderUploadLimit($extractedPath)) {
    return [[
     'path' => $extractedPath,
     'filename' => 'audio.webm',
    ]];
   }

   return $this->splitAndCompress($inputPath, $workDir, self::CHUNK_SECONDS);
  } catch (RuntimeException $e) {
   $this->removeDirectory($workDir);
   throw $e;
  }
 }

 /**
  * @param array<int, array{path: string, filename: string}> $preparedFiles
  */
 public function cleanup(array $preparedFiles): void {
  foreach ($preparedFiles as $preparedFile) {
   $path = $preparedFile['path'] ?? '';
   if ($path === '') {
    continue;
   }

   $workDir = dirname($path);
   if (is_dir($workDir) && str_starts_with(basename($workDir), 'integration_openai_stt_')) {
    $this->removeDirectory($workDir);
   }
  }
 }

 /**
  * @return array<int, array{path: string, filename: string}>
  */
 private function splitAndCompress(string $inputPath, string $workDir, int $segmentSeconds): array {
  $pattern = $workDir . '/chunk-%03d.mp3';
  $this->runFfmpeg([
   $this->getFfmpegBinary(), '-y', '-i', $inputPath,
   '-vn', '-ac', '1', '-ar', '16000', '-b:a', self::AUDIO_BITRATE,
   '-f', 'segment', '-segment_time', (string)$segmentSeconds,
   $pattern,
  ], 'split recording audio into transcription chunks');

  $chunks = glob($workDir . '/chunk-*.mp3') ?: [];
  sort($chunks, SORT_STRING);
  if ($chunks === []) {
   throw new RuntimeException($this->l10n->t('Could not split the recording audio for transcription.'));
  }

  foreach ($chunks as $chunk) {
   if (!$this->isUnderUploadLimit($chunk)) {
    if ($segmentSeconds !== self::RETRY_CHUNK_SECONDS) {
     foreach ($chunks as $oldChunk) {
      @unlink($oldChunk);
     }
     return $this->splitAndCompress($inputPath, $workDir, self::RETRY_CHUNK_SECONDS);
    }
    throw new RuntimeException($this->l10n->t('A recording audio chunk is too large for transcription.'));
   }
  }

  return array_map(static fn (string $chunk): array => [
   'path' => $chunk,
   'filename' => basename($chunk),
  ], $chunks);
 }

 private function copyFileToPath(File $file, string $path): void {
  try {
   $source = $file->fopen('r');
   $target = fopen($path, 'wb');
   if ($source === false || $target === false) {
    throw new RuntimeException($this->l10n->t('Could not prepare audio file for transcription.'));
   }
   stream_copy_to_stream($source, $target);
  } catch (NotPermittedException|LockedException|GenericFileException $e) {
   $this->logger->warning('Could not read audio file for transcription preprocessing: ' . $e->getMessage(), ['exception' => $e, 'app' => Application::APP_ID]);
   throw new RuntimeException($this->l10n->t('Could not read audio file.'));
  } finally {
   if (isset($source) && is_resource($source)) {
    fclose($source);
   }
   if (isset($target) && is_resource($target)) {
    fclose($target);
   }
  }
 }

 private function runFfmpeg(array $command, string $description): void {
  $descriptorSpec = [
   0 => ['pipe', 'r'],
   1 => ['pipe', 'w'],
   2 => ['pipe', 'w'],
  ];
  $process = proc_open($command, $descriptorSpec, $pipes);
  if (!is_resource($process)) {
   throw new RuntimeException($this->l10n->t('Could not start ffmpeg for audio transcription.'));
  }

  fclose($pipes[0]);
  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);
  $startedAt = time();
  $stderr = '';

  $exitCode = 1;
  do {
   $status = proc_get_status($process);
   $stderr .= stream_get_contents($pipes[2]);
   if ((time() - $startedAt) > self::FFMPEG_TIMEOUT_SECONDS) {
    proc_terminate($process);
    throw new RuntimeException($this->l10n->t('Audio preprocessing timed out.'));
   }
   if (!$status['running']) {
    $exitCode = $status['exitcode'];
    break;
   }
   usleep(100000);
  } while (true);

  $stderr .= stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  proc_close($process);

  if ($exitCode !== 0) {
   $this->logger->warning('ffmpeg failed to ' . $description . ': ' . $stderr, ['app' => Application::APP_ID]);
   throw new RuntimeException($this->l10n->t('Could not process recording audio for transcription.'));
  }
 }

 private function assertFfmpegAvailable(): void {
  $process = proc_open([$this->getFfmpegBinary(), '-version'], [
   0 => ['pipe', 'r'],
   1 => ['pipe', 'w'],
   2 => ['pipe', 'w'],
  ], $pipes);
  if (!is_resource($process)) {
   throw new RuntimeException($this->l10n->t('Recording is too large for transcription and ffmpeg is not available.'));
  }
  foreach ($pipes as $pipe) {
   fclose($pipe);
  }
  if (proc_close($process) !== 0) {
   throw new RuntimeException($this->l10n->t('Recording is too large for transcription and ffmpeg is not available.'));
  }
 }

 private function createWorkDir(): string {
  $workDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'integration_openai_stt_' . bin2hex(random_bytes(8));
  if (!mkdir($workDir, 0700, true) && !is_dir($workDir)) {
   throw new RuntimeException($this->l10n->t('Could not create temporary folder for audio transcription.'));
  }
  return $workDir;
 }

 private function removeDirectory(string $directory): void {
  foreach (glob($directory . '/*') ?: [] as $path) {
   if (is_dir($path)) {
    $this->removeDirectory($path);
   } else {
    @unlink($path);
   }
  }
  @rmdir($directory);
 }

 private function isUnderUploadLimit(string $path): bool {
  $size = filesize($path);
  return $size !== false && $size <= self::SAFE_UPLOAD_BYTES;
 }

 private function getFfmpegBinary(): string {
  return $this->appConfig->getValueString(Application::APP_ID, 'ffmpeg_binary', 'ffmpeg', lazy: true) ?: 'ffmpeg';
 }

 private function getExtension(string $fileName, string $fallback): string {
  $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
  return preg_match('/^[a-z0-9]+$/', $extension) === 1 ? $extension : $fallback;
 }
}
