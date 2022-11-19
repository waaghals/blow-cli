<?php
declare(strict_types=1);

namespace App\Command;

use App\AWS\Files;
use App\AWS\Profiles;
use Aws\Api\DateTimeResult;
use Aws\Exception\AwsException;
use Aws\SSOOIDC\Exception\SSOOIDCException;
use Aws\SSOOIDC\SSOOIDCClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('sso:login', 'Login into AWS')]
class SsoLogin extends Command
{
    private const DEFAULT_INTERVAL_SEC = 5;
    private const UPDATE_DELAY_SEC = 1;

    protected function configure(): void
    {
        $this
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Which profile to use', null, function () {
                return Profiles::list();
            });
    }

    private function isValidAccessToken(?array $accessToken): bool
    {
        if ($accessToken === null) {
            return false;
        }
        if (empty($accessToken['accessToken']) || empty($accessToken['expiresAt'])) {
            return false;
        }

        try {
            $expiration = (new DateTimeResult($accessToken['expiresAt']))->getTimestamp();
        } catch (\Exception) {
            return false;
        }
        $now = time();
        return $expiration > $now;
    }

    private function isValidClientCredentials(?array $clientCredentials): bool
    {
        if ($clientCredentials === null) {
            return false;
        }

        if (empty($clientCredentials['clientId']) || empty($clientCredentials['clientSecret']) || empty($clientCredentials['clientSecretExpiresAt'])) {
            return false;
        }

        return $clientCredentials['clientSecretExpiresAt'] > time();
    }

    private static function readClientCredentials(string $region): ?array
    {
        $fileName = Files::clientCredentials($region);
        if (!@is_readable($fileName)) {
            return null;
        }

        $content = file_get_contents($fileName);
        return json_decode($content, true);
    }

    private static function writeClientCredentials(string $region, array $clientCredentials): void
    {
        $filePath = Files::clientCredentials($region);
        $directory = dirname($filePath);
        if (!file_exists($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($filePath, json_encode($clientCredentials, JSON_PRETTY_PRINT));
    }

    private static function readAccessToken(string $startUrl): ?array
    {
        $fileName = Files::accessToken($startUrl);
        if (!file_exists($fileName)) {
            return null;
        }

        $content = file_get_contents($fileName);
        return json_decode($content, true);
    }

    private static function writeAccessToken(array $accessToken): void
    {
        $json = json_encode($accessToken, JSON_PRETTY_PRINT);
        file_put_contents(Files::accessToken($accessToken['startUrl']), $json);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profile = $input->getOption('profile');
        if ($profile === null) {
            $profile = $io->choice('Select your profile', Profiles::list()); // TODO add default option
        }

        $profileData = Profiles::get($profile);

        $requiredFields = ['sso_start_url', 'sso_region', 'sso_role_name', 'sso_account_id'];
        $validProfile = true;
        $errors = [];
        foreach ($requiredFields as $field) {
            if (!isset($profileData[$field])) {
                $errors [] = sprintf('Missing required field "%s" in profile.', $field);
                $validProfile = false;
            }
        }
        if (!$validProfile) {
            $io->text($errors);
            $io->error(sprintf('Profile "%s" with invalid sso configuration selected.', $profile));
            return Command::FAILURE;
        }

        $accessToken = self::readAccessToken($profileData['sso_start_url']);
        if (self::isValidAccessToken($accessToken)) {
            $io->text('A valid access token already exists.');
            return Command::SUCCESS;
        }

        // TODO use same client
        $client = new SSOOIDCClient([
            'region' => $profileData['sso_region'],
            'version' => 'latest',
            'credentials' => false,
        ]);

        $clientCredentials = self::readClientCredentials($profileData['sso_region']);
        if (!self::isValidClientCredentials($clientCredentials)) {
            $io->text('No valid client credentials found.');

            $clientCredentials = $client->registerClient([
                'clientName' => APP_NAME,
                'clientType' => 'public',
            ])->toArray();
            if (!self::isValidClientCredentials($clientCredentials)) {
                $io->error('Did not receive valid client credentials.');
                return Command::FAILURE;
            }

            self::writeClientCredentials($profileData['sso_region'], $clientCredentials);
            $io->text('Stored new client credentials.');
        }

        $io->text('Starting device authorization.');
        $deviceAuthorization = $client->startDeviceAuthorization([
            'clientId' => $clientCredentials['clientId'],
            'clientSecret' => $clientCredentials['clientSecret'],
            'startUrl' => $profileData['sso_start_url'],
        ]);

        $io->text(['Click the following link to start authenticating.', '', $deviceAuthorization['verificationUriComplete']]);

        $interval = $deviceAuthorization['interval'] ?? self::DEFAULT_INTERVAL_SEC;
        while (true) {
            sleep($interval);
            try {
                $accessToken = $client->createToken([
                    'grantType' => 'urn:ietf:params:oauth:grant-type:device_code',
                    'clientId' => $clientCredentials['clientId'],
                    'clientSecret' => $clientCredentials['clientSecret'],
                    'deviceCode' => $deviceAuthorization['deviceCode'],
                ]);

                break;
            } catch (AwsException $exception) {
                if (!$exception instanceof SSOOIDCException) {
                    $io->error(['Received unexpected exception.', $exception->getAwsErrorType()]);
                    return Command::FAILURE;
                }

                $errorCode = $exception->getAwsErrorCode();
                if ($errorCode === 'AuthorizationPendingException') {
                    continue;
                } else if ($errorCode === 'SlowDownException') {
                    $interval += self::UPDATE_DELAY_SEC;
                } else if ($errorCode === 'ExpiredTokenException') {
                    $io->error('Login attempt expired. Restart login.');
                    return Command::FAILURE;
                }
            }
        }

        $expiresAt = new DateTimeResult();
        $expiresAt->add(new \DateInterval('PT' . $accessToken['expiresIn'] . 'M'));

        $accessToken = [
            'accessToken' => $accessToken['accessToken'],
            'expiresAt' => $expiresAt->__toString(),
            'region' => $profileData['sso_region'],
            'startUrl' => $profileData['sso_start_url'],
        ];

        self::writeAccessToken($accessToken);
        $io->success('Successfully logged in.');

        return Command::SUCCESS;
    }
}
