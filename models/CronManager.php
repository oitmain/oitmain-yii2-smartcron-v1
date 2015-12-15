<?php

namespace oitmain\yii2\smartcron\v1\models;

use oitmain\yii2\smartcron\v1\models\base\BaseCron;
use oitmain\yii2\smartcron\v1\models\db\Cron;
use Yii;
use yii\base\Exception;

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
     * @var Cron[]
     */
    protected $_dueDbCrons = null;

    /**
     * @param BaseCron $cron
     * @return $this
     */
    public function addCron($cron)
    {
        $this->_crons[$cron->getName()] = $cron;
        return $this;
    }

    public function databaseMarkMissedSchedule()
    {
        Cron::updateAllMissedCrons();
    }

    /**
     * @return bool|CronResult
     */
    public function run($maintenance = true, $reloadDueCron = false)
    {

        $this->getAllDue($reloadDueCron);

        // Sort and prioritize the cron
        $this->sortDbCrons($this->_dueDbCrons);

        $runningCronNames = $this->getRunningCronNames();

        // Clean up database
        if ($maintenance) {
            foreach ($this->_crons as &$cron) {
                $cron
                    ->databaseCreateSchedule()
                    ->databaseMarkDeadSchedule()
                    ->cleanupDirtyCrons();
            }
        }

        foreach ($this->_dueDbCrons as $key => $dueDbCron) {
            Yii::trace('Found due cron ' . $dueDbCron->name, __METHOD__);
            $found = false;
            foreach ($this->_crons as &$cron) {
                if ($cron->getName() == $dueDbCron->name) {
                    $found = true;
                    if (CronMutex::acquireCron($cron)) {
                        $cronResult = $cron->run(false, $dueDbCron);
                        CronMutex::releaseCron($cron);
                        unset($this->_dueDbCrons[$key]);
                        return $cronResult;
                    } else {
                        Yii::trace('Failed acquiring cron', __METHOD__);
                    }
                }
            }
            if (!$found) {
                Yii::trace('Cron is not configured', __METHOD__);
                Cron::deleteAll(
                    ['and',
                        ['status' => Cron::STATUS_SCHEDULED],
                        ['>=', 'scheduled_at', $dueDbCron->scheduled_at],
                        ['name' => $dueDbCron->name],
                    ]
                );
            }
        }


        return false;
    }


    /**
     * @return bool|CronResult
     */
    public function runOne($cronId, $reschedule = true)
    {
        /* @var Cron $dbCron */
        $dbCron = Cron::findOne(['id' => $cronId]);

        foreach ($this->_crons as &$cron) {
            if ($cron->getName() == $dbCron->name) {
                if (CronMutex::acquireCron($cron)) {

                    if ($cron->getPausedCron()) {
                        throw new Exception('This cron is paused');
                    }

                    if ($dbCron->status != 'SCHEDULED') {
                        throw new Exception('Cron status is no scheduled ' . $dbCron->status);
                    }

                    if ($reschedule) {
                        $dbCron->scheduled_at = gmdate('Y-m-d H:i:00');
                        $dbCron->save();
                    }

                    $cronResult = $cron->run(true, $dbCron);

                    CronMutex::releaseCron($cron);
                    return $cronResult;
                } else {
                    Yii::trace('Failed acquiring cron', __METHOD__);
                    throw new Exception('Failed acquiring cron');
                }
            }
        }

        throw new Exception('Could not find cron');
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

    protected function sortDbCrons(&$dbCrons)
    {
        $cronSorter = new FifoCronSorter();
        $cronSorter->sortCrons($dbCrons);
    }

    protected function getAllDue($reload = false)
    {
        if (!$this->_dueDbCrons || $reload) {
            $this->_dueDbCrons = Cron::getAllDue(true)->all();
        }
        return $this->_dueDbCrons;
    }

}
