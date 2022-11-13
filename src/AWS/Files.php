<?php

namespace App\AWS;

class Files
{
    public static function config(): string
    {
        return self::homeDir() . '/.aws/config';
    }

    public static function accessToken(string $startUrl): string
    {
        // Reverse engineered from AWS\Credentials\CredentialProvider::sso(...)
        return sprintf('%s/%s.json', self::ssoCacheDir(), utf8_encode(sha1($startUrl)));
    }

    public static function clientCredentials(): string
    {
        return sprintf('%s/client_credentials_%s.json', self::ssoCacheDir(), APP_NAME);
    }

    private static function ssoCacheDir(): string
    {
        return self::homeDir() . '/.aws/sso/cache';
    }

    private static function homeDir(): string
    {
        // On Linux/Unix-like systems, use the HOME environment variable
        if ($homeDir = getenv('HOME')) {
            return $homeDir;
        }

        // Get the HOMEDRIVE and HOMEPATH values for Windows hosts
        $homeDrive = getenv('HOMEDRIVE');
        $homePath = getenv('HOMEPATH');

        return ($homeDrive && $homePath) ? $homeDrive . $homePath : '';
    }
}