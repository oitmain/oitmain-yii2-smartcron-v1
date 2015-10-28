<?php

namespace oitmain\smartcron\models\base;

use Cron\CronExpression;
use DateTime;
use DateTimeZone;
use oitmain\smartcron\models\CronMutex;
use oitmain\smartcron\models\CronResult;
use oitmain\smartcron\models\db\Cron;

abstract class BaseCron
{

    /*
     * STATUS
     * SCHEDULED : scheduled tasks
     * MISSED : missed task
     * DEAD : did not finish and heartbeat stopped
     * TIMEOUT : did not finish on time, and next cron schedule is ready
     * ERROR : did not finish due to an exception
     * SUCCESS : finished without any problem
     * PAUSED : paused
     * RUNNING : running
     */

    /*
     * Consider cron dead in $_heartbeatExpires seconds if there are no
     * heartbeat
     */
    protected $_heartbeatExpires = 60; // seconds

    protected $_db = null;

    /*
     * schedule # of crons ahead
     */
    protected $_scheduleAhead = 10;

    protected $_dbCron = null;

    abstract public function getSchedule();

    abstract public function getName();

    protected function databaseCreateSchedule()
    {
        $dbCron = new Cron();
        $scheduleExpression = $this->getScheduleExpression();
        $scheduleDTs = $scheduleExpression->getMultipleRunDates($this->_scheduleAhead, 'now', false, true);

        $dbCron->createBatchSchedule($this, $scheduleDTs);
    }

    protected function databaseMarkMissedSchedule()
    {
        $cron = new Cron();
        $scheduleExpression = $this->getScheduleExpression();
        $scheduleDTs = $scheduleExpression->getMultipleRunDates($this->_scheduleAhead, 'now', false, true);

        $cron->createBatchSchedule($this, $scheduleDTs);
    }


    public function getScheduleExpression()
    {
        return CronExpression::factory($this->getSchedule());
    }

    protected function cleanupTimeout()
    {

    }


    /**
     * @param bool|true $lock
     * @return Cron
     */
    protected function acquireCron($lock = true)
    {
        $currentDT = new DateTime('now', new DateTimeZone('UTC'));

        $scheduleExpression = $this->getScheduleExpression();
        if ($scheduleExpression->isDue($currentDT)) {
            $scheduleDT = $scheduleExpression->getNextRunDate($currentDT, 0, true);

            $dbCron = new Cron();
            $scheduledDbCron = $dbCron->getBySchedule($this, $scheduleDT)->one();

            if ($scheduledDbCron && CronMutex::acquireCronDate($this->getName(), $scheduleDT)) {
                return $scheduledDbCron;
            }
        }

        return false;
    }

    /**
     * @param $dbCron Cron
     */
    protected function releaseCron($dbCron)
    {
        $scheduleDT = new DateTime($dbCron->scheduled_at . ' UTC');
        CronMutex::releaseCronDate($this->getName(), $scheduleDT);
    }

    public function run()
    {
        $this->databaseCreateSchedule();

        $dbCron = $this->acquireCron();
        if ($dbCron) {

            $dbCron->start_mt = microtime(true);
            $dbCron->status = Cron::STATUS_RUNNING;
            $dbCron->save();

            $this->internalRun();

            $dbCron->status = Cron::STATUS_SUCCESS;
            $dbCron->end_mt = microtime(true);
            $dbCron->save();

            $this->releaseCron($dbCron);
        }

        // $this->databaseMarkMissedSchedule();

        $cronResult = new CronResult();
        return $cronResult;
    }


    protected function heartbeat()
    {

    }

    protected function isTimeout()
    {

    }

    protected function pause()
    {

    }


    /*
     * Run initialize the job before loops
     */
    abstract public function eventReset();

    /*
     * Repeat until timeout or complete
     * @return boolean Return true to continue loop and false to finish
     */
    abstract public function eventLoop($cronId, $cronDetailId);

    abstract public function eventFinished($cronId, $cronDetailId);

    abstract public function eventPaused($cronId, $cronDetailId);

    abstract public function eventResume($cronId, $cronDetailId);

    abstract public function cleanupFailed($cronId, $cronDetailId);

    abstract public function cleanupTimedout($cronId, $cronDetailId);

    abstract public function cleanupDied($cronId, $cronDetailId);

}