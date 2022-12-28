<?php
declare(strict_types=1);

namespace App\Command;

use App\AWS\Bastion;
use App\AWS\Profiles;
use Aws\Ec2\Ec2Client;
use Aws\Rds\RdsClient;
use Aws\Sdk;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand('database:proxy', 'Port forward to an aws database instance')]
class DatabaseProxy extends Command
{
    private static function findAvailablePort(): int
    {
        $sock = socket_create_listen(0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);

        return $port;
    }

    protected function configure(): void
    {
        $this
            ->addOption('region', 'r', InputOption::VALUE_REQUIRED, 'Region database instance is in', 'eu-west-1')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Which profile to use', null, function () {
                return Profiles::list();
            });
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profile = $input->getOption('profile');
        if ($profile === null) {
            $profile = $io->choice('Select your profile', Profiles::list());
        }

        $region = $input->getOption('region');

        putenv(sprintf('AWS_PROFILE=%s', $profile));
        $sharedConfig = [
            'region' => $region,
            'version' => 'latest',
        ];

        $sdk = new Sdk($sharedConfig);
        $rdsClient = $sdk->createRds();

        $instances = $this->getDatabaseInstances($rdsClient);

        $dbInstance = $io->choice('Select an instance', array_keys($instances));

        $ec2Client = $sdk->createEc2();
        $bastion = $this->getBastionInstance($ec2Client);
        $bastion->start();
        $io->text('Waiting for bastion to be running.');
        $bastion->waitRunning();

        $dbEndpoint = $instances[$dbInstance]['Endpoint']['Address'];
        $dbPort = $instances[$dbInstance]['Endpoint']['Port'];

        $io->text('Proxying database instance.');
        try {
            // Do the following with the native cli
            // The ssm has a complicated websocket binary protocol.

            $availablePort = self::findAvailablePort();
            $process = new Process([
                'aws',
                'ssm',
                'start-session',
                '--profile',
                $profile,
                '--target',
                $bastion->getInstanceId(),
                '--document-name',
                'AWS-StartPortForwardingSessionToRemoteHost',
                '--parameters',
                json_encode([
                    'host' => [$dbEndpoint],
                    'portNumber' => [(string)$dbPort],
                    'localPortNumber' => [(string)$availablePort]
                ]),
            ]);
            $process->setTimeout(null);
            $process->start();

            foreach ($process as $type => $data) {
                if ($type === Process::OUT) {
                    $io->text(trim($data));
                } else {
                    $io->text(sprintf('<error>%s</error>', trim($data)));
                }
            }
        } finally {
            $io->text('Stopping bastion instance');
            $bastion->stop();
        }

        return Command::SUCCESS;
    }

    private function getDatabaseInstances(RdsClient $rdsClient): array
    {
        $instances = [];
        foreach ($rdsClient->describeDBInstances()->get('DBInstances') as $instance) {
            $instances[$instance['DBInstanceIdentifier']] = $instance;
        };

        return $instances;
    }

    private function getBastionInstance(Ec2Client $client): Bastion
    {
        $filters = [
            [
                'Name' => 'tag:Name',
                'Values' => ['bastion-host'],
            ],
        ];
        $instances = $client->describeInstances(['Filters' => $filters])->toArray();

        return new Bastion($client, $instances['Reservations'][0]['Instances'][0]['InstanceId']);
    }
}
