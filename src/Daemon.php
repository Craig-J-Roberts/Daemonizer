<?php

namespace cjr\Daemonizer;

use \Exception;

class Daemon
{

    private $pid = -1; 
    private $interval = 1000;
    
    private $incomingSignals = array();  
    private $signals = array();
    private $run = false; 
    
    private $stdin = '/dev/null';
    private $stdout = '/dev/null';
    private $stderr = '/dev/null';
    
    private $lockFile = 'daemon.lock';
    private $runningDirectory = '.';
    
    private $tasks = array();
    private $taskRuns = array();
    
    private $backgroundTasks = array();
    private $backgroundTaskPID = array();
    private $backgroundTaskRuns = array();
    
    private $loopStart = 0;
    

    public function __construct()
    {
        $this->signals = array(SIGHUP => array(&$this, 'sigterm'), SIGINT => array(&$this, 'sigint'), SIGTERM => array(&$this, 'sigterm'));
    }

    public function start()
    {
        $this->pid = $this->executeInNewProcess(array($this, 'startDaemon'));

        return $this->pid;
    }

    public function stop()
    {
        postix_kill($this->pid, SIGTERM);
    }

    public function stdin($stdin)
    {
        $this->stdin = $stdin;

        return $this;
    }

    public function stdout($stdout)
    {
        $this->stdout = $stdout;

        return $this;
    }

    public function stderr($stderr)
    {
        $this->stderr = $stderr;

        return $this;
    }

    public function lockFile($lockfile)
    {
        $this->lockFile = $lockfile;

        return $this;
    }

    public function runningDirectory($runningDirectory)
    {
        $this->runningDirectory = $runningDirectory;

        return $this;
    }

    public function interval($interval)
    {
        $this->interval = $interval;

        return $this;
    }

    public function task()
    {
        $this->taskRuns[] = 0;
        return $this->tasks[] = new Task();
    }

    public function backgroundTask()
    {
        $this->backgroundTaskRuns[] = 0;
        $this->backgroundTaskPID[] = 0;
        return $this->backgroundTasks[] = new Task();
    }

    private function main()
    {
        while ($this->run) {
            $this->loopStart();

            $this->processSignals();

            $this->processBackgroundTasks();

            $this->processSignals();

            $this->processTasks();

            $this->processSignals();

            $this->loopEnd();

            $this->processSignals();
        }
    }

    private function init()
    {
        $this->pid = $this->setSID();
        $this->chdir($this->runningDirectory);
        $this->closeIO($this->stdin, $this->stdout, $this->stderr);
        $this->fdlock = $this->lock($this->lockFile, $this->pid);
        $this->installSignalHandlers();
        $this->run = true;
    }

    private function last()
    {
        $this->signalBackgroundTasks(SIGTERM);
        $this->unlock($this->fdlock);
        exit(0);
    }

    private function sigterm()
    {
        $this->run = false;
    }

    private function sigint()
    {
        $this->run = false;
        $this->last();
    }

    private function startDaemon()
    {
        $this->init();
        $this->main();
        $this->last();
    }

    private function executeInNewProcess($function, $arguments = array())
    {
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new Exception("Failed to Fork");
        } elseif ($pid > 0) {
            return $pid;
        } else {
            try {
                call_user_func($function, $arguments);
                exit(0);
            } catch (Exception $e) {
                exit(1);
            }
        }
    }

    private function installSignalHandlers()
    {
        foreach ($this->signals as $signal => $handler) {
            if (!pcntl_signal($signal, array(&$this, 'getSignals'))) {
                throw new Exception('Unable to install signal handlers');
            }
        }
    }

    private function getSignals($sig)
    {
        $this->incomingSignals[] = $sig;
    }

    private function loopStart()
    {
        $this->loopStart = microtime(true);
    }

    private function loopEnd()
    {   
        $loopDuration = microtime(true) - $this->loopStart();
        $sleepTime = ($this->interval * 1000) - $loopDuration;
        if($sleepTime < 0) {
            $sleepTime = 0;
        }
        usleep($sleepTime);
    }

    private function processSignals()
    {
        pcntl_signal_dispatch();

        foreach ($this->incomingSignals as $key => $signal) {
            unset($this->incomingSignals[$key]);
            $this->signalBackgroundTasks($signal);
            call_user_func($this->signals[$signal], array($signal));
        }
    }

    private function processBackgroundTasks()
    {
        foreach ($this->backgroundTasks as $taskID => $task) {
            if ((microtime(true) - $this->backgroundTaskRuns[$taskID]) * 1000 >= $task->getInterval()) {
                $this->backgroundTaskPID[$taskID] = $this->exclusiveExecuteInNewProcess(array(&$task, 'runMain'), $this->backgroundTaskPID[$taskID]);
                $this->backgroundTaskRuns[$taskID] = microtime(true);
            }
        }
    }

    private function signalBackgroundTasks($signal)
    {
        foreach ($this->backgroundTaskPID as $pid) {
            if ($pid > 0) {
                postix_kill($pid, $signal);
            }
        }
    }

    private function exclusiveExecuteInNewProcess($function, $pid)
    {
        if ($pid > 0) {
            $res = pcntl_waitpid($pid, $status, WNOHANG);
            if ($res <= 0) {
                return $pid;
            }
        }

        return $this->executeInNewProcess($function);
    }

    private function processTasks()
    {
        foreach ($this->tasks as $taskID => $task) {
            if ((microtime(true) - $this->taskRuns[$taskID]) * 1000 >= $task->getInterval()) {
                $task->runMain();
                $this->taskRuns[$taskID] = microtime(true);
            }
        }
    }

    private function chdir($dir)
    {
        if (!chdir($dir)) {
            throw new Exception("Failed to change working directory");
        }
    }

    private function closeIO($stdin, $stdout, $stderr)
    {
        $fdin = fopen($stdin, 'r');
        $fdout = fopen($stdout, 'wb');
        $fderr = fopen($stderr, 'wb');

        eio_dup2($fdin, STDIN);
        eio_dup2($fdout, STDOUT);
        eio_dup2($fderr, STDERR);
        eio_event_loop();

        fclose($fdin);
        fclose($fdout);
        fclose($fderr);
    }

    private function lock($lockfile, $pid)
    {
        if (!$fdlock = fopen($lockfile, 'c')) {
            throw new Exception('Failed to open lock file');
        }
        if (!flock($fdlock, LOCK_EX | LOCK_NB)) {
            throw new Exception('Failed to aquire lock');
        }
        ftruncate($fdlock, 0);
        fwrite($fdlock, $pid);

        return $fdlock;
    }

    private function unlock($fdlock)
    {
        fflush($fdlock);
        flock($fdlock, LOCK_UN | LOCK_NB);
        fclose($fdlock);
    }

    private function setSID()
    {
        $pid = posix_setsid();
        if ($pid < 0) {
            throw new Exception('Failed to set SID');
        }

        return $pid;
    }

}
