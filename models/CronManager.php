<?php

namespace oitmain\yii2\smartcron\v1\models;

use oitmain\yii2\smartcron\v1\models\base\BaseCron;
use oitmain\yii2\smartcron\v1\models\db\Cron;
use Yii;

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
     * @return bool|CronResult
     */
    public function run($maintenance = true)
    {
        // Clean up database
        if ($maintenance) {
            foreach ($this->_crons as &$cron) {
                $cron
                    ->databaseMarkMissedSchedule()
                    ->databaseMarkDeadSchedule()
                    ->cleanupDirtyCrons();
            }
        }

        // Sort and prioritize the cron
        $this->sortCrons($this->_crons);

        $runningCronNames = $this->getRunningCronNames();

        // Run paused cron if there are no crons on schedule
        $pausedCrons = [];

        foreach ($this->_crons as &$cron) {

            Yii::trace('Trying cron ' . $cron->getName(), __METHOD__);

            // Run cron if it's not running
            if (!isset($runningCronNames[$cron->getName()])) {
                if ($maintenance) {
                    $cron->databaseCreateSchedule();
                }
                if ($cron->getScheduleExpression()->isDue()) {
                    Yii::trace('Cron is due', __METHOD__);
                    if (CronMutex::acquireCron($cron)) {
                        $cronResult = $cron->run(false);
                        CronMutex::releaseCron($cron);
                        return $cronResult;
                    }
                    else
                    {
                        Yii::trace('Failed acquiring cron', __METHOD__);
                    }
                }
                else
                {
                    Yii::trace('Cron is NOT due', __METHOD__);
                }
                // Paused crons are second priority
                if ($cron->getPausedCron()) {
                    Yii::trace('Cron is paused, priority lowered', __METHOD__);
                    $pausedCrons[] = &$cron;
                }
            }
            else
            {
                Yii::trace('Cron is running', __METHOD__);
            }
        }

        foreach ($pausedCrons as &$cron) {
            if (CronMutex::acquireCron($cron)) {
                $cronResult = $cron->run(false);
                CronMutex::releaseCron($cron);
                return $cronResult;
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
