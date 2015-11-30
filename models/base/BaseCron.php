<?php

namespace oitmain\yii2\smartcron\v1\models\base;

use Cron\CronExpression;
use DateInterval;
use DateTime;
use Exception;
use oitmain\yii2\smartcron\v1\models\CronMutex;
use oitmain\yii2\smartcron\v1\models\CronResult;
use oitmain\yii2\smartcron\v1\models\db\Cron;
use oitmain\yii2\smartcron\v1\models\db\CronDetail;
use Yii;
use yii\helpers\Inflector;

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
    protected $_pauseAfter = 45; // seconds

    protected $_scheduleExpression = null;

    protected $_loopUpdateThreshold = 1; // seconds

    abstract public function getSchedule();

    public function getName()
    {
        $className = get_called_class();
        $namespacePos = strrpos($className, '\\');
        if ($namespacePos) {
            $className = substr($className, $namespacePos + 1);
        }

        return Inflector::camel2id($className);
    }

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

    public function getPausedCron()
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
            Yii::trace('Cron is already running', __METHOD__);
            return false;
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

        if ($pausedDbCron) {
            Yii::trace('Found a paused cron', __METHOD__);
        }

        /*
        if ($pausedDbCron) {
            file_put_contents('cron2.log', '== Paused Cron ==', FILE_APPEND);
            file_put_contents('cron2.log', print_r($pausedDbCron, true), FILE_APPEND);
        } else {
            if ($nextScheduledDbCron) {
                file_put_contents('cron2.log', '== Next Scheduled Cron ==', FILE_APPEND);
                file_put_contents('cron2.log', print_r($nextScheduledDbCron, true), FILE_APPEND);

                if ($this->isDbCronDue($nextScheduledDbCron)) {
                    file_put_contents('cron2.log', 'Cron is due', FILE_APPEND);
                }
            }
        }
        */


        $dbCron = $pausedDbCron ? $pausedDbCron : null;

        if (!$pausedDbCron && $this->isDbCronDue($nextScheduledDbCron)) {
            $dbCron = $nextScheduledDbCron;
        }

        if (!$dbCron) {
            Yii::trace('No cron to run', __METHOD__);
        } else {
            if (!$this->acquireDbCron($dbCron)) {
                Yii::trace('Failed to acquire scheduled cron', __METHOD__);
            } else {

                Yii::trace($dbCron->toArray(), __METHOD__);

                // file_put_contents('cron2.log', 'Current timestamp ' . microtime(true) . "\n", FILE_APPEND);

                $timeoutT = $this->getScheduleExpression()
                    ->getNextRunDate(new DateTime($dbCron->scheduled_at . ' UTC'))
                    ->getTimestamp();

                // file_put_contents('cron2.log', 'Next cron due at ' . $timeoutT . "\n", FILE_APPEND);

                $timeoutT -= $this->_timeoutBuffer;

                // file_put_contents('cron2.log', 'Timeout expected at ' . $timeoutT . "\n", FILE_APPEND);

                $startMT = microtime(true);

                $dbCronDetail = new CronDetail();
                $dbCronDetail->cron_id = $dbCron->id;
                $dbCronDetail->doStart($startMT);

                $cronResult->cronId = $dbCron->id;
                $cronResult->cronDetailId = $dbCronDetail->id;

                if ($dbCron->doResume()) {
                    $this->eventResume($dbCron->id, $dbCronDetail->id);
                } else {
                    $dbCron->doStart($startMT);
                    $this->eventReset($dbCron->id, $dbCronDetail->id);
                }

                try {
                    $loopStartedMT = microtime(true);
                    // file_put_contents('cron2.log', 'Loop started at ' . $loopStartedMT . "\n", FILE_APPEND);
                    while ($this->eventLoop($dbCron->id, $dbCronDetail->id)) {

                        // file_put_contents('cron2.log', 'Looping' . "\n", FILE_APPEND);
                        $currentTimeMT = microtime(true);

                        if (($loopStartedMT + $this->_loopUpdateThreshold) < $currentTimeMT) {
                            // file_put_contents('cron2.log', 'Heartbeat at ' . $currentTimeMT . "\n", FILE_APPEND);

                            $dbCron->doHeartbeat();

                            if ($currentTimeMT > $timeoutT) {
                                // file_put_contents('cron2.log', "Timed out\n", FILE_APPEND);
                                $this->cleanupTimedOut($dbCron->id, $dbCronDetail->id);
                                $dbCronDetail->doTimeout();
                                $dbCron->doTimeout();
                                break;
                            }

                            if (($currentTimeMT - $startMT) > ($this->_pauseAfter)) {
                                // file_put_contents('cron2.log', "Paused\n", FILE_APPEND);
                                $this->eventPaused($dbCron->id, $dbCronDetail->id);
                                $dbCronDetail->doFinish();
                                $dbCron->doPause();
                                break;
                            }
                            $loopStartedMT = $currentTimeMT;
                        }
                    }

                    if ($dbCron->status == Cron::STATUS_RUNNING) {
                        // file_put_contents('cron2.log', "Finished\n", FILE_APPEND);
                        $this->eventFinished($dbCron->id, $dbCronDetail->id);
                        $dbCronDetail->doFinish();
                        $dbCron->doFinish();
                    }
                } catch (Exception $e) {
                    $this->cleanupFailed($dbCron->id, $dbCronDetail->id);
                    $dbCronDetail->doError();
                    $dbCron->doError();
                    $this->releaseDbCron($dbCron);
                    throw $e;
                }

                $this->releaseDbCron($dbCron);
            }
        }


        return $cronResult;
    }


    /*
     * Run initialize the job before loops
     */
    abstract public function eventReset($cronId, $cronDetailId);

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