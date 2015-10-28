<?php

namespace oitmain\smartcron\models;

use oitmain\smartcron\models\base\BaseCron;

/**
 * Class CronManager
 * @package oitmain\smartcron\models
 */
class CronManager
{

    /**
     * @var array
     */
    protected $_crons = array();

    /**
     * @param $cron
     */
    public function addCron($cron)
    {
        $this->_crons[] = $cron;
    }


    /**
     * @return BaseCron
     */
    protected function getNext()
    {

    }

    /**
     * @return bool
     */
    public function run()
    {
        $cron = $this->getNext();
        if ($cron) return $cron->run();
        return false;
    }

}
