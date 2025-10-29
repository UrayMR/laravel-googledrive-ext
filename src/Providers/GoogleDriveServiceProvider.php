<?php

namespace Uraymr\GoogleDrive\Providers;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDriveService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Uraymr\GoogleDrive\Adapters\GoogleDriveAdapter;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;

/**
 * Class GoogleDriveServiceProvider
 *
 * Service provider to register Google Drive filesystem integration
 * for Laravel's Storage facade, supporting both Service Account
 * and OAuth authentication methods.
 *
 * @package Uraymr\GoogleDrive\Providers
 */
class GoogleDriveServiceProvider extends ServiceProvider
{
  /**
   * Bootstrap the application services.
   *
   * @return void
   */
  public function boot(): void
  {
    // Register the custom 'google' disk driver
    Storage::extend('google', function ($app, $config) {
      $client = $this->createGoogleClient($config);
      $service = new GoogleDriveService($client);

      $folderId = $config['folder_id'] ?? 'root';
      $rootFolderId = ($folderId === null || $folderId === '' || $folderId === '/') ? 'root' : $folderId;

      $adapter = new GoogleDriveAdapter($service, $rootFolderId);

      // Create the League Filesystem operator
      $filesystemOperator = new Filesystem($adapter);

      // Build a config array for the Laravel FilesystemAdapter wrapper
      $laravelConfig = array_merge([
        'visibility' => $config['visibility'] ?? 'private',
        'root' => $config['root'] ?? '',
      ], $config);

      return new LaravelFilesystemAdapter($filesystemOperator, $adapter, $laravelConfig);
    });
  }

  /**
   * Create and configure a Google API Client instance.
   *
   * Supports two authentication methods:
   * - Service Account (JSON credentials file)
   * - OAuth Client (client_id, client_secret, refresh_token)
   *
   * @param array $config
   * @return \Google\Client
   *
   * @throws \Exception
   */
  protected function createGoogleClient(array $config): GoogleClient
  {
    $client = new GoogleClient();
    $client->setApplicationName($config['app_name'] ?? config('app.name', 'Laravel App'));
    $client->setScopes(GoogleDriveService::DRIVE);

    // Auth config handling
    if (!empty($config['service_account'])) {
      // Auth with Service Account JSON
      $this->useServiceAccountConfig($client, $config['service_account']);
    } elseif (!empty($config['client_id']) && !empty($config['client_secret']) && !empty($config['refresh_token'])) {
      // Auth with OAuth 2.0 credentials
      $this->useOAuthConfig($client, $config);
    } else {
      throw new \Exception('Google Drive credentials are not properly configured.');
    }

    return $client;
  }

  /**
   * Configure the client for Service Account authentication.
   *
   * @param \Google\Client $client
   * @param string $credentialsPath
   * @return void
   */
  protected function useServiceAccountConfig(GoogleClient $client, string $credentialsPath): void
  {
    if (!file_exists($credentialsPath)) {
      throw new \Exception("Service account file not found: {$credentialsPath}");
    }

    $client->setAuthConfig($credentialsPath);
    $client->useApplicationDefaultCredentials();
  }

  /**
   * Configure the client for OAuth authentication.
   *
   * @param \Google\Client $client
   * @param array $config
   * @return void
   */
  protected function useOAuthConfig(GoogleClient $client, array $config): void
  {
    $client->setClientId($config['client_id']);
    $client->setClientSecret($config['client_secret']);
    $client->setAccessType('offline');
    $client->setApprovalPrompt('force');

    $accessToken = $client->fetchAccessTokenWithRefreshToken($config['refresh_token']);

    if (isset($accessToken['error'])) {
      throw new \Exception('Failed to fetch access token: ' . $accessToken['error_description']);
    }

    $client->setAccessToken($accessToken);

    if ($client->isAccessTokenExpired()) {
      $client->fetchAccessTokenWithRefreshToken($config['refresh_token']);
    }
  }
}
