#!/usr/bin/env php
<?php
require __DIR__.'/../vendor/autoload.php';

use App\Command\DatabaseProxy;
use App\Command\SsoLogin;
use Symfony\Component\Console\Application;

const APP_NAME = '@app_name@';

$application = new Application(APP_NAME, '@app_version@');

$application->add(new DatabaseProxy());
$application->add(new SsoLogin());

$application->run();