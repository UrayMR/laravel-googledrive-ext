# Laravel Google Drive Storage (for Laravel 12)

Flysystem v3 adapter for Google Drive, built for Laravel 12.

This package provides a Flysystem v3 adapter that integrates Google Drive with Laravel's `Storage` facade, plus a small `Gdrive` helper utility for common file operations.

## Quick Example

Once installed and configured, you can immediately use Google Drive storage just like any other Laravel disk:

```php
use Illuminate\Support\Facades\Storage;

// Upload a file
Storage::disk('google')->put('docs/example.txt', 'Hello from Laravel 12!');

return \Uraymr\GoogleDrive\Gdrive::previewFile('docs/example.txt');
```

## Highlights

- Implements a Flysystem v3 adapter using the official `google/apiclient` Drive API.
- Works as a Laravel filesystem disk: `Storage::disk('google')->...`.
- Offers a `Gdrive` helper class with convenience methods (put/get/delete/exists/list/makeDirectory/etc.).
- Supports Service Account (JSON key file) and OAuth (client_id/client_secret/refresh_token).

## Requirements

- PHP ^8.0
- Laravel ^12.0
- league/flysystem ^3.0
- google/apiclient ^2.0

These are defined in `composer.json` for this package.

These packages are built specifically for Laravel 12 and Flysystem v3.
Using versions earlier than Laravel 12 or Flysystem v3 will result in compatibility issues.
Laravel ^10 may work on this package, but it is not recommended to use it.

## Installation

Install the package via Composer:

```bash
composer require uraymr/laravel-googledrive-ext
```

Laravel package discovery will register the service provider automatically. If you don't use package discovery, register the provider in `config/app.php`:

```php
'providers' => [
	// ...
	Uraymr\GoogleDrive\Providers\GoogleDriveServiceProvider::class,
],
```

## Configuration

Add a `google` disk to your application's `config/filesystems.php` `disks` array. The service provider expects the disk config to contain the authentication and optional settings.

Example disk configuration (Service Account):

```php
'google' => [
	'driver' => 'google',
	'service_account' => env('GOOGLE_SERVICE_ACCOUNT_JSON', storage_path('app/google-service-account.json')),
	'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID', 'root'),
],
```

Example disk configuration (OAuth client + refresh token):

```php
'google' => [
	'driver' => 'google',
	'client_id' => env('GOOGLE_CLIENT_ID'),
	'client_secret' => env('GOOGLE_CLIENT_SECRET'),
	'refresh_token' => env('GOOGLE_REFRESH_TOKEN'),
	'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID', 'root'),
],
```

Important config keys used by the service provider:

- `service_account` — (string) path to a Google Service Account JSON credentials file.
- `client_id`, `client_secret`, `refresh_token` — OAuth 2.0 values (if not using a service account).
- `access_token`, `created`, `expires_in` — optional values for the access token (provider will refresh if expired and refresh_token is present).
- `folder_id` — Drive folder ID used as the adapter root. Default is the Drive `root`.

## How it works (brief)

- The `GoogleDriveServiceProvider` registers a custom `'google'` filesystem driver using `Storage::extend('google', ...)`.
- It creates a `Google\Client` (configured either with a service account JSON or OAuth credentials) and a `Google\Service\Drive` instance.
- The `GoogleDriveAdapter` implements the Flysystem v3 `FilesystemAdapter` interface and maps filesystem operations to Drive API calls.
- `Gdrive` is a small static wrapper around `Storage::disk('google')` adding convenience methods.

## Usage

Once configured, use the Laravel `Storage` facade as usual:

```php
use Illuminate\Support\Facades\Storage;

// Put a file
Storage::disk('google')->put('documents/report.pdf', $contents);

// Get contents
$contents = Storage::disk('google')->get('documents/report.pdf');

// Check exists
$exists = Storage::disk('google')->exists('documents/report.pdf');

// Delete
Storage::disk('google')->delete('documents/report.pdf');
```

Or use the package helper `Gdrive` (convenience static methods):

```php
use Uraymr\GoogleDrive\Gdrive;

// Upload
Gdrive::put('invoices/2025-10.pdf', $contents);

// Read
$contents = Gdrive::get('invoices/2025-10.pdf');

// Stream
$stream = Gdrive::readStream('invoices/2025-10.pdf');

// Metadata
$info = Gdrive::info('invoices/2025-10.pdf'); // size, mime_type, last_modified, path

// List directory
$items = Gdrive::list('/');

// Make directory
Gdrive::makeDirectory('backups');

// Download remote file to local path
Gdrive::download('invoices/2025-10.pdf', storage_path('app/invoices/2025-10.pdf'));
```

Available `Gdrive` methods (static):

- healthCheck
- put
- putStream
- get
- readStream
- delete
- rename (move)
- copy
- exists
- info
- list
- download
- makeDirectory
- deleteDirectory

Additional methods:

- previewFile

These call `Storage::disk($disk ?? 'google')` internally — pass a different disk name if you registered multiple google-like disks.

## Tips & Troubleshooting

- Service account access: If you use a service account, make sure the service account has access to the target Drive folder (either the folder is owned by the service account or it has been shared with the service account's email).
- OAuth flow: If you use OAuth client credentials, ensure you obtain a refresh token and store it in the disk config. The provider will refresh the access token automatically when expired.
- File not found: The adapter searches by basename under the configured folder ID. Verify that the file name (basename) exists directly under the `folder_id` folder (or under a child folder if you set `folder_id` to a deeper folder ID).

## Contributing

Contributions, bug reports and feature requests are welcome. Please open issues or pull requests on the repository.

## License

This package is open-source library licensed under the MIT license.
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

## Author

Uray Muhammad R. (<umrajsyafiq@gmail.com>)

## Acknowledgements

This package is inspired by and built upon the amazing work of:

- **[Flysystem](https://github.com/thephpleague/flysystem)** — by _The PHP League_

  > A powerful and flexible filesystem abstraction library for PHP.  
  > Uraymr\GoogleDrive integrates directly with Flysystem v3 to provide seamless Google Drive storage support in Laravel 12.

- **[Masbug/laravel-google-drive](https://github.com/masbug/laravel-google-drive)** — by _Masbug_
  > A great reference implementation for connecting Laravel to Google Drive.  
  > This package was heavily inspired by Masbug’s approach, refactored for Flysystem v3 and fully compatible with Laravel 12+.

Huge thanks to these developers and open-source communities for their contributions! 💚
