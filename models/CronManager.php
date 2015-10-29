<?php

namespace oitmain\yii2\smartcron\v1\models;

use oitmain\yii2\smartcron\v1\models\base\BaseCron;
use oitmain\yii2\smartcron\v1\models\db\Cron;

/**
 * Class CronManager
 * @package oitmain\yii2\smartcron\v1\models
 */
class CronManager
{

    /**
     * @var BaseCron[]
     */
    protected $_crons = array();

    /**
     * @param BaseCron $cron
     * @return $this
     */
    public function addCron($cron)
    {
        $this->_crons[$cron->getName()] = $cron;
        return $this;
    }

    /**
     * @return bool
     */
    public function run()
    {
        // Clean up database
        foreach ($this->_crons as &$cron) {
            $cron
                ->databaseMarkMissedSchedule()
                ->databaseMarkDeadSchedule()
                ->cleanupDirtyCrons();
        }

        // Sort and prioritize the cron
        $this->sortCrons($this->_crons);

        $runningCronNames = $this->getRunningCronNames();

        foreach ($this->_crons as &$cron) {

            // Run cron if it's not running
            if (!isset($runningCronNames[$cron->getName()])) {
                $cron->databaseCreateSchedule();
                if ($cron->getScheduleExpression()->isDue()) {
                    if (CronMutex::acquireCron($cron)) {
                        $cronResult = $cron->run(false);
                        CronMutex::releaseCron($cron);
                        return $cronResult;
                    }
                }
            }
        }

        return false;
    }


    protected function getRunningCronNames()
    {
        $runningDbCronNames = array();
        $runningDbCronNameRows = Cron::getAllRunningCrons()
            ->select(['name'])
            ->distinct(true)
            ->asArray()
            ->all();

        foreach ($runningDbCronNameRows as &$runningDbCronNameRow) {
            $runningDbCronNames[$runningDbCronNameRow['name']] = $runningDbCronNameRow['name'];
        }

        return $runningDbCronNames;
    }

    protected function sortCrons(&$crons)
    {

        /* @var Cron[] $pausedDbCrons */
        $pausedDbCrons = Cron::getAllPausedCrons()->all();

        $cronSorter = new FifoCronSorter();

        foreach ($pausedDbCrons as &$pausedDbCron) {
            $cronSorter->addPausedDbCron($pausedDbCron);
        }

        $cronSorter->sortCrons($this->_crons);

    }

}
