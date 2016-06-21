<?php

namespace cjr\Daemonizer;

class Task
{

    public function start($start)
    {
        $this->start = $start;
        return $this;
    }

    public function main($main)
    {
        $this->main = $main;
        return $this;
    }

    public function stop($stop)
    {
        $this->stop = $stop;
        return $this;
    }

    public function interval($interval)
    {
        $this->interval = $interval;
        return $this;
    }

    public function runStart()
    {
        call_user_func($this->start);
    }

    public function runMain()
    {
        call_user_func($this->main);
    }

    public function runStop()
    {
        call_user_func($this->stop);
    }

    public function getInterval()
    {
        return $this->interval;
    }

}
