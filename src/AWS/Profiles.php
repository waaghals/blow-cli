<?php

namespace App\AWS;

class Profiles
{
    public static function get(string $profile): ?array
    {
        $profiles = self::all();
        return $profiles[$profile] ?? null;
    }

    private static function all(): array
    {
        $profileData = [];
        $configProfileData = \Aws\parse_ini_file(Files::config(), true, INI_SCANNER_RAW);
        foreach ($configProfileData as $name => $profile) {
            // standardize config profile names
            $name = str_replace('profile ', '', $name);
            if (!isset($profileData[$name])) {
                $profileData[$name] = $profile;
            }
        }

        return $profileData;
    }

    public static function list(): array
    {
        return array_keys(Profiles::all());
    }
}