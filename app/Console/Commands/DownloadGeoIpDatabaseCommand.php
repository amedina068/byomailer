<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class DownloadGeoIpDatabaseCommand extends Command
{
    protected $signature = 'geoip:download';

    protected $description = 'Download the MaxMind GeoLite2-City database';

    public function handle(): int
    {
        $licenseKey = config('services.maxmind.license_key');

        if (! $licenseKey) {
            $this->error('MAXMIND_LICENSE_KEY is not set. Get a free license key at https://www.maxmind.com/en/geolite2/signup');

            return self::FAILURE;
        }

        $dbPath = config('services.maxmind.database_path');
        $directory = dirname($dbPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->info('Downloading GeoLite2-City database...');

        $url = sprintf(
            'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=%s&suffix=tar.gz',
            $licenseKey
        );

        $tempFile = $directory.'/geolite2-city.tar.gz';

        $response = Http::withOptions(['sink' => $tempFile, 'timeout' => 120])->get($url);

        if (! $response->successful()) {
            $this->error('Download failed: '.$response->status());

            return self::FAILURE;
        }

        $this->info('Extracting database...');

        $tempDir = $directory.'/geolite2-extract';
        @mkdir($tempDir, 0755, true);

        $result = Process::run("tar -xzf {$tempFile} -C {$tempDir}");

        @unlink($tempFile);

        if ($result->failed()) {
            $this->error('Extraction failed: '.$result->errorOutput());
            $this->cleanupDirectory($tempDir);

            return self::FAILURE;
        }

        $mmdbFiles = glob($tempDir.'/*/GeoLite2-City.mmdb');

        if (empty($mmdbFiles)) {
            $this->error('GeoLite2-City.mmdb not found in archive.');
            $this->cleanupDirectory($tempDir);

            return self::FAILURE;
        }

        rename($mmdbFiles[0], $dbPath);
        $this->cleanupDirectory($tempDir);

        if (! file_exists($dbPath)) {
            $this->error('Failed to move GeoLite2-City.mmdb to destination.');

            return self::FAILURE;
        }

        $this->info('GeoLite2-City database installed at: '.$dbPath);

        return self::SUCCESS;
    }

    private function cleanupDirectory(string $path): void
    {
        Process::run('rm -rf '.escapeshellarg($path));
    }
}
