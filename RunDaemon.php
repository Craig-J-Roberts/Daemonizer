<?php
require('vendor/autoload.php');

use cjr\Daemonizer\Daemon;

$daemon = new Daemon();

$daemon->runningDirectory('.')
        ->stdin('/dev/null')
        ->stdout('application.log')
        ->stderr('error.log')
        ->lockFile('daemon.pid')
        ->interval(1000);

$daemon->task()
        ->main(function() {
            echo('Task 1 Running...'.PHP_EOL);
            sleep(1);
            echo('...Task 1 Complete'.PHP_EOL);
        })
        ->interval(2000);
        
$daemon->backgroundTask()
        ->main(function() {
            echo('Background Task 1 Running...'.PHP_EOL);
            sleep(10);
            echo('...Background Task 1 Complete.'.PHP_EOL);
        })
        ->interval(5000);

$pid = $daemon->start();

echo('Daemon started with pid ' . $pid . PHP_EOL);
