<?php

namespace Uraymr\GoogleDrive\Adapters;

use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\Visibility;
use Throwable;
use Uraymr\GoogleDrive\Helpers\PathResolver;

/**
 * Class GoogleDriveAdapter
 *
 * Flysystem v3 adapter for Google Drive API.
 * Provides a filesystem-like interface to interact with Google Drive storage.
 *
 * @package Uraymr\GoogleDrive\Adapters
 */
class GoogleDriveAdapter implements FilesystemAdapter
{
  protected PathResolver $pathResolver;
  protected Drive $service;
  protected string $rootFolderId;
  protected array $cache = [];

  /**
   * GoogleDriveAdapter constructor.
   *
   * @param  Drive  $service
   * @param  string  $rootFolderId
   */
  public function __construct(Drive $service, string $rootFolderId = 'root')
  {
    $this->service = $service;
    $this->rootFolderId = $rootFolderId;
    $this->pathResolver = new PathResolver($service, $rootFolderId);
  }

  /**
   * Write contents to a file.
   *
   * @param  string  $path
   * @param  string  $contents
   * @param  Config  $config
   * @throws UnableToWriteFile
   */
  public function write(string $path, string $contents, Config $config): void
  {
    try {
      $path = $this->pathResolver->normalizePath($path);

      $parentId = $this->pathResolver->resolveParentId($path);

      $fileMetadata = new DriveFile([
        'name' => basename($path),
        'parents' => [$parentId],
      ]);

      $this->service->files->create($fileMetadata, [
        'data' => $contents,
        'mimeType' => 'application/octet-stream',
        'uploadType' => 'media',
      ]);
    } catch (Throwable $e) {
      throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Write a stream to a file.
   *
   * @param  string  $path
   * @param  resource  $contents
   * @param  Config  $config
   * @throws UnableToWriteFile
   */
  public function writeStream(string $path, $contents, Config $config): void
  {
    try {
      if (!is_resource($contents)) {
        throw new \InvalidArgumentException("Expected a valid resource stream");
      }

      $streamContents = stream_get_contents($contents);
      $this->write($path, $streamContents ?: '', $config);
    } catch (Throwable $e) {
      throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Read a file's contents.
   *
   * @param  string  $path
   * @return string
   * @throws UnableToReadFile
   */
  public function read(string $path): string
  {
    try {
      $file = $this->findFile($path);
      if (!$file) {
        throw UnableToReadFile::fromLocation($path);
      }

      $response = $this->service->files->get($file->id, ['alt' => 'media']);

      return $response->getBody()->getContents();
    } catch (Throwable $e) {
      throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Read a file as a stream.
   *
   * @param  string  $path
   * @return resource
   * @throws UnableToReadFile
   */
  public function readStream(string $path)
  {
    $contents = $this->read($path);
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $contents);
    rewind($stream);
    return $stream;
  }

  /**
   * Delete a file.
   *
   * @param  string  $path
   * @throws UnableToDeleteFile
   */
  public function delete(string $path): void
  {
    try {
      $file = $this->findFile($path);
      if (!$file) {
        throw UnableToDeleteFile::atLocation($path);
      }
      $this->service->files->delete($file->id);
    } catch (Throwable $e) {
      throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Delete a directory by removing all its contents.
   *
   * @param  string  $path
   * @throws UnableToDeleteDirectory
   */
  public function deleteDirectory(string $path): void
  {
    try {
      foreach ($this->listContents($path, true) as $item) {
        if ($item instanceof FileAttributes) {
          $this->delete($item->path());
        } elseif ($item instanceof DirectoryAttributes) {
          $this->deleteDirectory($item->path());
        }
      }

      // Delete the directory itself
      $folder = $this->findFile($path);
      if ($folder && $folder->mimeType === 'application/vnd.google-apps.folder') {
        $this->service->files->delete($folder->id);
      }
    } catch (Throwable $e) {
      throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Create a directory.
   *
   * @param  string  $path
   * @param  Config  $config
   * @throws UnableToCreateDirectory
   */
  public function createDirectory(string $path, Config $config): void
  {
    try {
      $path = $this->pathResolver->normalizePath($path);
      $parentId = $this->pathResolver->resolveParentId($path);
      $metadata = new DriveFile([
        'name' => basename($path),
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentId],
      ]);
      $this->service->files->create($metadata);
    } catch (Throwable $e) {
      throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * Copy a file.
   *
   * @param  string  $source
   * @param  string  $destination
   * @param  Config  $config
   * @throws UnableToCopyFile
   */
  public function copy(string $source, string $destination, Config $config): void
  {
    try {
      $file = $this->findFile($source);
      if (!$file) {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
      }

      $copied = new DriveFile(['name' => basename($destination)]);
      $this->service->files->copy($file->id, $copied);
    } catch (Throwable $e) {
      throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
    }
  }

  /**
   * Move a file (copy then delete original).
   *
   * @param  string  $source
   * @param  string  $destination
   * @param  Config  $config
   * @throws UnableToMoveFile
   */
  public function move(string $source, string $destination, Config $config): void
  {
    try {
      $file = $this->findFile($source);
      if (!$file) {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
      }

      $newParentId = $this->pathResolver->resolveParentId($destination);
      $previousParents = implode(',', $file->parents ?? []);

      $this->service->files->update($file->id, new DriveFile([
        'name' => basename($destination),
      ]), [
        'addParents' => $newParentId,
        'removeParents' => $previousParents,
      ]);
    } catch (Throwable $e) {
      throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
    }
  }


  /**
   * List directory contents.
   *
   * @param  string  $path
   * @param  bool  $deep
   * @return iterable<DirectoryAttributes|FileAttributes>
   */
  public function listContents(string $path, bool $deep): iterable
  {
    $parentId = $path === '/' ? $this->rootFolderId : $this->findFile($path)?->id ?? $this->rootFolderId;
    $pageToken = null;

    do {
      $params = [
        'q' => sprintf("'%s' in parents and trashed = false", $parentId),
        'fields' => 'nextPageToken, files(id, name, mimeType, size, modifiedTime)',
      ];

      if ($pageToken) {
        $params['pageToken'] = $pageToken;
      }

      $files = $this->service->files->listFiles($params);

      foreach ($files->getFiles() as $file) {
        $isDir = $file->mimeType === 'application/vnd.google-apps.folder';
        $currentPath = trim($path, '/');
        $fullPath = $currentPath === '' ? $file->name : $currentPath . '/' . $file->name;

        if ($isDir) {
          yield new DirectoryAttributes($fullPath);
          if ($deep) {
            yield from $this->listContents($fullPath, true);
          }
        } else {
          yield new FileAttributes(
            $fullPath,
            (int)($file->size ?? 0),
            Visibility::PUBLIC,
            strtotime($file->modifiedTime)
          );
        }
      }

      $pageToken = $files->getNextPageToken();
    } while ($pageToken !== null);
  }


  /**
   * Get the MIME type of a file.
   *
   * @param  string  $path
   * @return FileAttributes
   * @throws UnableToRetrieveMetadata
   */
  public function mimeType(string $path): FileAttributes
  {
    $file = $this->findFile($path);
    if (!$file) {
      throw UnableToRetrieveMetadata::mimeType($path);
    }
    return new FileAttributes($path, null, null, null, $file->mimeType);
  }

  /**
   * Get the last modified timestamp of a file.
   *
   * @param  string  $path
   * @return FileAttributes
   * @throws UnableToRetrieveMetadata
   */
  public function lastModified(string $path): FileAttributes
  {
    $file = $this->findFile($path);
    if (!$file) {
      throw UnableToRetrieveMetadata::lastModified($path);
    }
    return new FileAttributes($path, null, null, strtotime($file->modifiedTime));
  }

  /**
   * Get the file size of a file.
   *
   * @param  string  $path
   * @return FileAttributes
   * @throws UnableToRetrieveMetadata
   */
  public function fileSize(string $path): FileAttributes
  {
    $file = $this->findFile($path);
    if (!$file) {
      throw UnableToRetrieveMetadata::fileSize($path);
    }
    return new FileAttributes($path, (int)($file->size ?? 0));
  }

  /**
   * Get the visibility of a file.
   *
   * @param  string  $path
   * @return FileAttributes
   * @throws UnableToRetrieveMetadata
   */
  public function visibility(string $path): FileAttributes
  {
    return new FileAttributes($path, null, Visibility::PUBLIC);
  }

  /**
   * Set the visibility of a file.
   *
   * @param  string  $path
   * @param  string  $visibility
   * @throws UnableToSetVisibility
   */
  public function setVisibility(string $path, string $visibility): void
  {
    // No-op: Google Drive visibility should be managed via permissions
    // throw UnableToSetVisibility::atLocation($path, 'Google Drive does not support visibility changes.');
  }

  /**
   * Main exists check.
   * 
   * @param string $path
   * @return bool
   */
  public function exists(string $path): bool
  {
    return $this->fileExists($path) || $this->directoryExists($path);
  }

  /**
   * Check if a file exists.
   *
   * @param  string  $path
   * @return bool
   */
  public function fileExists(string $path): bool
  {
    return (bool) $this->findFile($path);
  }

  /**
   * Check if a directory exists.
   *
   * @param  string  $path
   * @return bool
   */
  public function directoryExists(string $path): bool
  {
    $file = $this->findFile($path);
    return $file && $file->mimeType === 'application/vnd.google-apps.folder';
  }

  /**
   * Find a file by its name in the root folder.
   *
   * @param  string  $path
   * @return DriveFile|null
   */
  protected function findFile(string $path): ?DriveFile
  {
    $path = $this->pathResolver->normalizePath($path);

    if (isset($this->cache[$path])) {
      return $this->cache[$path];
    }

    $parentId = $this->pathResolver->resolveParentId($path, false);
    $fileName = basename($path);

    $response = $this->service->files->listFiles([
      'q' => sprintf("name = '%s' and '%s' in parents and trashed = false", $fileName, $parentId),
      'fields' => 'files(id, name, mimeType, size, modifiedTime)',
    ]);

    return $this->cache[$path] = $response->getFiles()[0] ?? null;
  }
}
