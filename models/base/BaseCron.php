<?php

namespace oitmain\smartcron\models\base;

use Cron\CronExpression;
use DateInterval;
use DateTime;
use Exception;
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
     * schedule # day ahead
     */
    protected $_scheduleAheadDay = 1;

    protected $_maxScheduleCount = 1000;

    protected $_minScheduleCount = 1;

    /*
     * Timeout cron job before # of seconds from next cron job
     */
    protected $_timeoutBuffer = 15;

    /*
     * Do not time out
     */
    protected $_forceRun = false;

    /*
     * Pause the cron after running x time, resume on next run
     */
    protected $_pauseAfter = 5; // seconds

    protected $_scheduleExpression = null;

    abstract public function getSchedule();

    abstract public function getName();

    public function getHeartbeatExpires()
    {
        return $this->_heartbeatExpires;
    }


    public function getScheduleExpression()
    {
        if (!$this->_scheduleExpression) {
            $this->_scheduleExpression = CronExpression::factory($this->getSchedule());
        }
        return $this->_scheduleExpression;
    }

    /**
     * @param Cron $dbCron
     * @return bool
     */
    protected function isDbCronDue($dbCron)
    {
        $currentST = date('Y-m-d H:i');

        $scheduleDT = new DateTime($dbCron->scheduled_at . ' UTC');
        $scheduleDT->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $scheduleST = $scheduleDT->format('Y-m-d H:i');

        return $currentST == $scheduleST;
    }

    /**
     * @return Cron|boolean
     */
    protected function getNextScheduledDbCron()
    {
        return Cron::getNextScheduled($this)->one($this->_db);
    }

    /**
     * @param $dbCron Cron
     * @return boolean
     */
    protected function acquireDbCron($dbCron)
    {
        $scheduleDT = new DateTime($dbCron->scheduled_at . ' UTC');
        return CronMutex::acquireCronDate($this->getName(), $scheduleDT);
    }

    /**
     * @param $dbCron Cron
     */
    protected function releaseDbCron($dbCron)
    {
        $scheduleDT = new DateTime($dbCron->scheduled_at . ' UTC');
        CronMutex::releaseCronDate($this->getName(), $scheduleDT);
    }

    protected function getRunningCrons()
    {
        $runningCrons = Cron::getAllRunningCrons($this)->all($this->_db);
        return $runningCrons;
    }

    protected function getPausedCron()
    {
        $pausedCron = Cron::getAllPausedCrons($this)->orderBy(['scheduled_at' => SORT_ASC])->one($this->_db);
        return $pausedCron;
    }

    public function cleanupDirtyCrons()
    {
        /* @var Cron[] $dirtyCrons */
        $dirtyCrons = Cron::getAllDirtyCrons($this)->all($this->_db);
        foreach ($dirtyCrons as $dirtyCron) {
            switch ($dirtyCron->status) {
                case Cron::STATUS_TIMEOUT:
                    $this->cleanupTimedOut($dirtyCron->id, 0);
                    $dirtyCron->doCleanup();
                    break;
                case Cron::STATUS_ERROR:
                    $this->cleanupFailed($dirtyCron->id, 0);
                    $dirtyCron->doCleanup();
                    break;
                case Cron::STATUS_DEAD:
                    $this->cleanupDied($dirtyCron->id, 0);
                    $dirtyCron->doCleanup();
                    break;
            }
        }
        return $this;
    }


    public function run($createSchedule = true)
    {
        if ($createSchedule) $this->databaseCreateSchedule();

        $cronResult = new CronResult();
        $cronResult->cron = $this;

        /*
         * [Cron manager does this task]
         * Look for a existing RUNNING job
         * KILL it if heart beat is over heartbeatExpires
         */

        if ($this->getRunningCrons()) {
            return $cronResult;
        }


        /*
         * Look for paused cron, if found resume
         * else look for due cron, if found start
         */


        /*
         * Get current schedule and get next schedule
         * timeout and cleanup if job exceeds next schedule (15 seconds before next schedule)
         *
         */


        //$dbCron -> lock seperately because it can be retrieved by pause and new
        // $dbCron = $this->acquireCron();

        $pausedDbCron = $this->getPausedCron();
        $nextScheduledDbCron = $this->getNextScheduledDbCron();

        $dbCron = $pausedDbCron ? $pausedDbCron : null;
        if (!$pausedDbCron && $this->isDbCronDue($nextScheduledDbCron)) {
            $dbCron = $nextScheduledDbCron;
        }

        if ($dbCron && $this->acquireDbCron($dbCron)) {

            $timeoutT = $this->getScheduleExpression()
                ->getNextRunDate(new DateTime($dbCron->scheduled_at . ' UTC'))
                ->getTimestamp();
            $timeoutT -= $this->_timeoutBuffer;

            $startMT = microtime(true);

            if ($dbCron->doResume()) {
                $this->eventResume($dbCron->id, 0);
            } else {
                $dbCron->doStart();
                $this->eventReset();
            }

            try {
                while ($this->eventLoop($dbCron->id, 0)) {
                    $dbCron->doHeartbeat();

                    if (time() > $timeoutT) {
                        $this->cleanupTimedOut($dbCron->id, 0);
                        $dbCron->doTimeout();
                        break;
                    }

                    if (microtime(true) - $startMT > $this->_pauseAfter) {
                        $this->eventPaused($dbCron->id, 0);
                        $dbCron->doPause();
                        break;
                    }
                }

                if ($dbCron->status == Cron::STATUS_RUNNING) {
                    $this->eventFinished($dbCron->id, 0);
                    $dbCron->doFinish();
                }
            } catch (Exception $e) {
                $this->cleanupFailed($dbCron->id, 0);
                $dbCron->doError();
                $this->releaseDbCron($dbCron);
                throw $e;
            }

            $this->releaseDbCron($dbCron);
        }


        return $cronResult;
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

    abstract public function cleanupTimedOut($cronId, $cronDetailId);

    abstract public function cleanupDied($cronId, $cronDetailId);


    public function databaseMarkMissedSchedule()
    {
        Cron::updateAllMissedCrons($this);
        return $this;
    }

    public function databaseMarkDeadSchedule()
    {
        Cron::updateAllDeadCrons($this);
        return $this;
    }

    public function databaseCreateSchedule()
    {
        $scheduleExpression = $this->getScheduleExpression();

        $toDT = new DateTime();
        $toDT->add(DateInterval::createFromDateString($this->_scheduleAheadDay . ' day'));

        $fromDt = new DateTime();
        $scheduleDT = $scheduleExpression->getNextRunDate($fromDt, 0, true);;

        $scheduleDTs = array();

        for ($i = 0; $i < $this->_maxScheduleCount; $i++) {

            if ($scheduleDT > $toDT && $i > $this->_minScheduleCount) {
                break;
            }
            $scheduleDTs[$scheduleDT->getTimestamp()] = $scheduleDT;

            $scheduleDT = $scheduleExpression->getNextRunDate($scheduleDT, 0, false);
        }

        Cron::insertAllSchedule($this, $scheduleDTs);
    }

}