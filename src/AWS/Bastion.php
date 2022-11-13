<?php

namespace App\AWS;

use Aws\Ec2\Ec2Client;

final class Bastion
{
    public function __construct(private readonly Ec2Client $client, private readonly string $instanceId)
    {
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function start(): void
    {
        $this->client->startInstances(['InstanceIds' => [$this->instanceId]]);
    }

    public function stop(): void
    {
        $this->client->stopInstances(['InstanceIds' => [$this->instanceId]]);
    }

    private function getState(): string
    {
        $result = $this->client->describeInstanceStatus(['InstanceIds' => [$this->instanceId]])->toArray();

        return $result['InstanceStatuses'][0]['InstanceState']['Name'];
    }

    public function isRunning(): string
    {
        return $this->getState() === 'running';
    }

    public function waitRunning(): void
    {
        $this->client->waitUntil('InstanceRunning', ['InstanceIds' => [$this->instanceId]]);
    }
}