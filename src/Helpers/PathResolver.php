<?php

namespace Uraymr\GoogleDrive\Helpers;

use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class PathResolver
{
  public function __construct(
    protected Drive $service,
    protected string $rootFolderId = 'root'
  ) {}

  public function findFileByPath(string $path): ?DriveFile
  {
    $segments = array_filter(explode('/', trim($path, '/')));
    $parentId = $this->rootFolderId;

    foreach ($segments as $segment) {
      $response = $this->service->files->listFiles([
        'q' => sprintf(
          "name = '%s' and '%s' in parents and trashed = false",
          addslashes($segment),
          $parentId
        ),
        'fields' => 'files(id, name, mimeType, size, modifiedTime)',
        'pageSize' => 1,
      ]);

      $file = $response->getFiles()[0] ?? null;
      if (!$file) return null;

      $parentId = $file->id;
    }

    return $file;
  }

  public function resolveParentId(string $path, bool $createIfMissing = true): string
  {
    $directoryPath = trim(dirname($path), '/');
    if ($directoryPath === '' || $directoryPath === '.') {
      return $this->rootFolderId;
    }

    $segments = explode('/', $directoryPath);
    $parentId = $this->rootFolderId;

    foreach ($segments as $segment) {
      $response = $this->service->files->listFiles([
        'q' => sprintf(
          "name = '%s' and '%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
          addslashes($segment),
          $parentId
        ),
        'fields' => 'files(id)',
        'pageSize' => 1,
      ]);

      $folder = $response->getFiles()[0] ?? null;

      if (!$folder && $createIfMissing) {
        $folder = $this->service->files->create(new DriveFile([
          'name' => $segment,
          'mimeType' => 'application/vnd.google-apps.folder',
          'parents' => [$parentId],
        ]));
      }

      if (!$folder) {
        throw new \RuntimeException("Folder '$segment' not found and auto-create disabled.");
      }

      $parentId = $folder->id;
    }

    return $parentId;
  }

  public function normalizePath(string $path): string
  {
    // change backslash to slash
    $path = str_replace('\\', '/', $path);

    // delete multiple slashes
    $path = preg_replace('#/+#', '/', $path);

    // delete dot segments
    $parts = [];
    foreach (explode('/', $path) as $segment) {
      if ($segment === '' || $segment === '.') {
        continue;
      }
      if ($segment === '..') {
        array_pop($parts);
      } else {
        $parts[] = $segment;
      }
    }

    // join segments
    return implode('/', $parts);
  }
}
