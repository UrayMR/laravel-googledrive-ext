<?php

namespace Uraymr\GoogleDrive;

use Illuminate\Filesystem\FilesystemAdapter as Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Class Gdrive
 *
 * A utility class for interacting with Google Drive storage,
 * built on top of Laravel's Storage facade.
 *
 * Provides methods for file and directory management,
 * metadata retrieval, and others.
 *
 * @package Uraymr\GoogleDrive
 */
class Gdrive
{
  /**
   * Get the configured Google Drive filesystem disk.
   *
   * @param string|null $disk
   * @return Filesystem
   */
  protected static function disk(?string $disk = null): Filesystem
  {
    $diskInstance = Storage::disk($disk ?? 'google');
    /** @var Filesystem $diskInstance */
    return $diskInstance;
  }

  public static function healthCheck(?string $disk = null): bool
  {
    $diskInstance = self::disk($disk);
    try {
      $diskInstance->listContents('/', false)->toArray();
      return true;
    } catch (\Exception $e) {
      return false;
    }
  }

  /**
   * Upload a file to Google Drive.
   * Commonly used for small files (e.g., text files, JSON, CSV).
   *
   * @param string $path
   * @param string $contents
   * @param string|null $disk
   * @return bool
   * @throws RuntimeException
   */
  public static function put(string $path, string $contents, ?string $disk = null): bool
  {
    return self::disk($disk)->put($path, $contents);
  }

  /**
   * Upload a stream to Google Drive.
   * Commonly used for large files (e.g., images, videos, PDFs).
   *
   * @param string $path
   * @param resource $stream
   * @param string|null $disk
   * @return bool
   * @throws RuntimeException
   */
  public static function putStream(string $path, $stream, ?string $disk = null): bool
  {
    if (!is_resource($stream)) {
      throw new RuntimeException('The $stream must be a valid resource.');
    }

    return self::disk($disk)->put($path, $stream);
  }

  /**
   * Read the contents of a file.
   *
   * @param string $path
   * @param string|null $disk
   * @return string|null
   */
  public static function get(string $path, ?string $disk = null): ?string
  {
    $diskInstance = self::disk($disk);
    return $diskInstance->exists($path)
      ? $diskInstance->get($path)
      : null;
  }

  /**
   * Get a readable stream for a file.
   *
   * @param string $path
   * @param string|null $disk
   * @return resource|null
   */
  public static function readStream(string $path, ?string $disk = null)
  {
    $diskInstance = self::disk($disk);
    return $diskInstance->exists($path)
      ? $diskInstance->readStream($path)
      : null;
  }

  /**
   * Delete a file from Google Drive.
   *
   * @param string $path
   * @param string|null $disk
   * @return bool
   */
  public static function delete(string $path, ?string $disk = null): bool
  {
    return self::disk($disk)->delete($path);
  }

  /**
   * Rename (move) a file.
   *
   * @param string $from
   * @param string $to
   * @param string|null $disk
   * @return bool
   */
  public static function rename(string $from, string $to, ?string $disk = null): bool
  {
    $diskInstance = self::disk($disk);
    if (!$diskInstance->exists($from)) {
      return false;
    }

    $diskInstance->move($from, $to);
    return true;
  }

  /**
   * Copy a file to another location.
   *
   * @param string $source
   * @param string $destination
   * @param string|null $disk
   * @return bool
   */
  public static function copy(string $source, string $destination, ?string $disk = null): bool
  {
    return self::disk($disk)->copy($source, $destination);
  }

  /**
   * Check whether a file or directory exists.
   *
   * @param string $path
   * @param string|null $disk
   * @return bool
   */
  public static function exists(string $path, ?string $disk = null): bool
  {
    return self::disk($disk)->exists($path);
  }

  /**
   * Get file metadata (size, MIME type, last modified time).
   *
   * @param string $path
   * @param string|null $disk
   * @return array<string, mixed>|null
   */
  public static function info(string $path, ?string $disk = null): ?array
  {
    $diskInstance = self::disk($disk);
    if (!$diskInstance->exists($path)) {
      return null;
    }

    $info = [];

    if (method_exists($diskInstance, 'size')) {
      $info['size'] = $diskInstance->size($path);
    }

    if (method_exists($diskInstance, 'mimeType')) {
      $info['mime_type'] = $diskInstance->mimeType($path);
    }

    if (method_exists($diskInstance, 'lastModified')) {
      $info['last_modified'] = $diskInstance->lastModified($path);
    }

    $info['path'] = $path;

    return $info;
  }

  /**
   * List the contents of a directory.
   *
   * @param string $directory
   * @param bool $recursive
   * @param string|null $disk
   * @return array<int, StorageAttributes>
   */
  public static function list(string $directory = '/', bool $recursive = false, ?string $disk = null): array
  {
    return self::disk($disk)
      ->listContents($directory, $recursive)
      ->toArray();
  }

  /**
   * Download a file from Google Drive to a local path.
   *
   * @param string $remotePath
   * @param string $localPath
   * @param string|null $disk
   * @return bool
   */
  public static function download(string $remotePath, string $localPath, ?string $disk = null): bool
  {
    $contents = self::get($remotePath, $disk);
    return $contents ? (bool) file_put_contents($localPath, $contents) : false;
  }

  /**
   * Create a directory.
   *
   * @param string $directory
   * @param string|null $disk
   * @return bool
   */
  public static function makeDirectory(string $directory, ?string $disk = null): bool
  {
    $diskInstance = self::disk($disk);

    if ($diskInstance->exists($directory)) {
      return true;
    }

    $diskInstance->makeDirectory($directory);
    return true;
  }

  /**
   * Delete a directory and all its contents.
   *
   * @param string $directory
   * @param string|null $disk
   * @return bool
   */
  public static function deleteDirectory(string $directory, ?string $disk = null): bool
  {
    return self::disk($disk)->deleteDirectory($directory);
  }

  /**
   * Get a preview response for a file.
   *
   * @param string $path
   * @param string|null $disk
   * @return StreamedResponse|null
   */
  public static function previewFile(string $path, ?string $disk = null)
  {
    $diskInstance = self::disk($disk);
    if (!$diskInstance->exists($path)) {
      return abort(404, 'File not found.');
    }

    $file = self::readStream($path, $disk);
    if (!$file) {
      return abort(500, 'Could not read file.');
    }

    $mimeType = $diskInstance->mimeType($path);
    $filename = basename($path);

    return new StreamedResponse(function () use ($file) {
      fpassthru($file);
      fclose($file);
    }, 200, [
      'Content-Type' => $mimeType,
      'Content-Disposition' => "inline; filename=\"{$filename}\"",
    ]);
  }
}
