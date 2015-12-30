<?php

namespace oitmain\yii2\smartcron\v1\models\base;

use Cron\CronExpression;
use DateInterval;
use DateTime;
use Exception;
use oitmain\yii2\core\v1\models\base\OitHelper;
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

    protected $_schedule = '0 1 * * *';

    protected $_noTimeout = false;

    public function getSchedule()
    {
        return $this->_schedule;
    }

    public function setSchedule($schedule)
    {
        $this->_schedule = $schedule;
    }

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
                    $this->cleanupTimedOut($dirtyCron, null);
                    $dirtyCron->doCleanup();
                    break;
                case Cron::STATUS_ERROR:
                    $this->cleanupFailed($dirtyCron, null);
                    $dirtyCron->doCleanup();
                    break;
                case Cron::STATUS_DEAD:
                    $this->cleanupDied($dirtyCron, null);
                    $dirtyCron->doCleanup();
                    break;
            }
        }
        return $this;
    }


    public function run($createSchedule = true, $dbCron = null)
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


        if ($dbCron == null) {
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

                if ($dbCronDetail->isAttributeSafe('debug_tag')) {
                    $debugTag = OitHelper::getDebugTag();
                    $dbCronDetail->debug_tag = $debugTag ? $debugTag : null;
                }
                $dbCronDetail->cron_id = $dbCron->id;

                $dbCronDetail->doStart($startMT);

                $cronResult->cronId = $dbCron->id;
                $cronResult->cronDetailId = $dbCronDetail->id;

                if ($dbCron->doResume()) {
                    $this->eventResume($dbCron, $dbCronDetail);
                } else {
                    $dbCron->doStart($startMT);
                    $this->eventReset($dbCron, $dbCronDetail);
                }

                try {
                    $loopStartedMT = microtime(true);
                    // file_put_contents('cron2.log', 'Loop started at ' . $loopStartedMT . "\n", FILE_APPEND);
                    while ($this->eventLoop($dbCron, $dbCronDetail)) {

                        // file_put_contents('cron2.log', 'Looping' . "\n", FILE_APPEND);
                        $currentTimeMT = microtime(true);

                        if (($loopStartedMT + $this->_loopUpdateThreshold) < $currentTimeMT) {
                            // file_put_contents('cron2.log', 'Heartbeat at ' . $currentTimeMT . "\n", FILE_APPEND);

                            $memoryUsed = OitHelper::memoryUsedPercentage();
                            Yii::trace("Cron memory used " . round($memoryUsed, 2));

                            // Prevent memory overflow by force pausing
                            if ($memoryUsed > 0.15 && Yii::$app->hasModule('debug')) {
                                Yii::trace("Debug mode and memory used over 15%, force pausing");
                                $this->_pauseAfter = 0;
                            }

                            if ($memoryUsed > 0.75) {
                                Yii::trace("Memory used over 75%, force pausing");
                                $this->_pauseAfter = 0;
                            }

                            $dbCron->doHeartbeat();

                            if (!$this->_noTimeout && $currentTimeMT > $timeoutT) {
                                // file_put_contents('cron2.log', "Timed out\n", FILE_APPEND);
                                $this->cleanupTimedOut($dbCron, $dbCronDetail);
                                $dbCronDetail->doTimeout();
                                $dbCron->doTimeout();
                                break;
                            }

                            if (($currentTimeMT - $startMT) > ($this->_pauseAfter)) {
                                // file_put_contents('cron2.log', "Paused\n", FILE_APPEND);
                                $this->eventPaused($dbCron, $dbCronDetail);
                                $dbCronDetail->doFinish();
                                $dbCron->doPause();
                                break;
                            }
                            $loopStartedMT = $currentTimeMT;
                        }
                    }

                    if ($dbCron->status == Cron::STATUS_RUNNING) {
                        // file_put_contents('cron2.log', "Finished\n", FILE_APPEND);
                        $this->eventFinished($dbCron, $dbCronDetail);
                        $dbCronDetail->doFinish();
                        $dbCron->doFinish();
                    }
                } catch (Exception $e) {
                    $this->cleanupFailed($dbCron, $dbCronDetail);
                    $dbCronDetail->doError();
                    $dbCron->doError();
                    $this->releaseDbCron($dbCron);
                    throw $e;
                }

                $this->releaseDbCron($dbCron);
            }
        }

        Yii::trace("Memory after cron " . round(OitHelper::memoryUsedPercentage(), 2));

        return $cronResult;
    }


    /**
     * Run initialize the job before loops
     * @param Cron $cron
     * @param CronDetail $cronDetail
     */
    abstract public function eventReset($cron, $cronDetail);

    /**
     * Repeat until timeout or complete
     * @param Cron $cron
     * @param CronDetail $cronDetail
     * @return boolean Return true to continue loop and false to finish
     */
    abstract public function eventLoop($cron, $cronDetail);

    /**
     * @param Cron $cron
     * @param CronDetail $cronDetail
     * @return mixed
     */
    abstract public function eventFinished($cron, $cronDetail);

    /**
     * @param Cron $cron
     * @param CronDetail $cronDetail
     * @return mixed
     */
    abstract public function eventPaused($cron, $cronDetail);

    /**
     * @param Cron $cron
     * @param CronDetail $cronDetail
     * @return mixed
     */
    abstract public function eventResume($cron, $cronDetail);

    /**
     * @param Cron $cron
     * @param CronDetail $cronDetail
     * @return mixed
     */
    abstract public function cleanupFailed($cron, $cronDetail);

    /**
     * @param Cron $cron
     * @param CronDetail $cronDetail
     * @return mixed
     */
    abstract public function cleanupTimedOut($cron, $cronDetail);

    /**
     * @param Cron $cron
     * @param CronDetail $cronDetail
     * @return mixed
     */
    abstract public function cleanupDied($cron, $cronDetail);

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

        return $this;
    }

}