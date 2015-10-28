<?php

namespace oitmain\smartcron\test;

use oitmain\smartcron\models\base\BaseCron;

class SimpleCron extends BaseCron
{

    protected $_i = 3;

    public function getSchedule()
    {
        return '* * * * *';
    }

    public function getName()
    {
        return 'simple_cron';
    }

    public function reset()
    {
        $this->_i = 3;
    }

    public function loop($cronId, $cronDetailId)
    {
        $this->_i --;

        sleep(1);

        return $this->_i > 0;
    }

    public function died($cronId, $cronDetailId)
    {

    }

    public function paused($cronId, $cronDetailId)
    {

    }

    public function resume($cronId, $cronDetailId)
    {

    }

    public function failed($cronId, $cronDetailId)
    {

    }

    public function timedout($cronId, $cronDetailId)
    {

    }

}
