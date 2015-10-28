<?php

namespace oitmain\smartcron\models\db;

use DateTime;
use DateTimeZone;
use oitmain\smartcron\models\CronMutex;
use Yii;
use yii\base\ErrorException;

/**
 * @inherit
 */
class Cron extends BaseCron
{

    const STATUS_SCHEDULED = 'SCHEDULED';
    const STATUS_MISSED = 'MISSED';
    const STATUS_DEAD = 'DEAD';
    const STATUS_TIMEOUT = 'TIMEOUT';
    const STATUS_ERROR = 'ERROR';
    const STATUS_SUCCESS = 'SUCCESS';
    const STATUS_PAUSED = 'PAUSED';
    const STATUS_RUNNING = 'RUNNING';

    public function beforeValidate()
    {
        if ($this->isNewRecord) {
            $this->created_at = gmdate('Y-m-d H:i:s');
        }

        $this->updated_at = gmdate('Y-m-d H:i:s');

        return parent::beforeValidate();
    }

    public function beforeSave($insert)
    {
        if (!$insert) {
            $this->elapsed = $this->calculateElapsed();
        }
        return parent::beforeSave($insert);
    }

    protected function calculateElapsed()
    {
        $startMT = $this->start_mt;
        $endMT = $this->end_mt;
        if (!$endMT) $endMT = $this->heartbeat_mt;

        if ($startMT && $endMT) {
            return $endMT - $startMT;
        }

        return null;
    }

    /**
     * @param bool|\oitmain\smartcron\models\base\BaseCron $cron
     * @return \yii\db\ActiveQuery
     */
    static function getAllScheduledCrons(&$cron = false)
    {
        $query = static::find()
            ->andWhere(['>=', 'scheduled_at', gmdate('Y-m-d H:i:s')])
            ->andWhere(['status' => static::STATUS_SCHEDULED]);

        if ($cron) {
            $query->andWhere(['name' => $cron->getName()]);
        }

        return $query;
    }

    /**
     * @param bool|\oitmain\smartcron\models\base\BaseCron $cron
     * @return \yii\db\ActiveQuery
     */
    static function getAllDirtyCrons(&$cron = false)
    {
        $query = static::find()
            ->andWhere(['cleanup' => 1])
            ->andWhere(['in', 'status', [static::STATUS_DEAD, static::STATUS_ERROR, static::STATUS_TIMEOUT]]);

        if ($cron) {
            $query->andWhere(['name' => $cron->getName()]);
        }

        return $query;
    }


    /**
     * @param bool|\oitmain\smartcron\models\base\BaseCron $cron
     * @return integer the number of rows updated
     */
    static function updateAllMissedCrons(&$cron)
    {
        return static::updateAll(
            [
                'status' => static::STATUS_MISSED,
                'cleanup' => 0,
            ],
            ['and',
                ['<', 'scheduled_at', gmdate('Y-m-d H:i')],
                ['status' => static::STATUS_SCHEDULED],
                ['name' => $cron->getName()],
            ]
        );
    }

    /**
     * @param bool|\oitmain\smartcron\models\base\BaseCron $cron
     * @return integer the number of rows updated
     */
    static function updateAllDeadCrons(&$cron)
    {
        $updateCount = 0;

        $updateCount += static::updateAll(
            [
                'status' => static::STATUS_DEAD,
                'cleanup' => 1,
            ],
            ['and',
                ['<', 'start_mt', microtime(true) - $cron->getHeartbeatExpires()],
                ['heartbeat_mt' => null],
                ['name' => $cron->getName()],
                ['status' => static::STATUS_RUNNING],
            ]
        );

        $updateCount += static::updateAll(
            [
                'status' => static::STATUS_DEAD,
                'cleanup' => 1,
            ],
            ['and',
                ['<', 'heartbeat_mt', microtime(true) - $cron->getHeartbeatExpires()],
                ['name' => $cron->getName()],
                ['status' => static::STATUS_RUNNING],
            ]
        );

        return $updateCount;
    }

    /**
     * @param bool|\oitmain\smartcron\models\base\BaseCron $cron
     * @return \yii\db\ActiveQuery
     */
    static function getAllMissedCrons(&$cron = false)
    {
        $query = static::find()
            ->andWhere(['<', 'scheduled_at', gmdate('Y-m-d H:i:s')])
            ->andWhere(['status' => static::STATUS_SCHEDULED]);

        if ($cron) {
            $query->andWhere(['name' => $cron->getName()]);
        }

        return $query;
    }


    /**
     * @param bool|\oitmain\smartcron\models\base\BaseCron $cron
     * @return \yii\db\ActiveQuery
     */
    static function getAllRunningCrons(&$cron = false)
    {
        $query = static::find()
            ->andWhere(['status' => static::STATUS_RUNNING]);

        if ($cron) {
            $query->andWhere(['name' => $cron->getName()]);
        }

        return $query;
    }

    /**
     * @param bool|\oitmain\smartcron\models\base\BaseCron $cron
     * @return \yii\db\ActiveQuery
     */
    static function getAllPausedCrons(&$cron = false)
    {
        $query = static::find()
            ->andWhere(['status' => static::STATUS_PAUSED]);

        if ($cron) {
            $query->andWhere(['name' => $cron->getName()]);
        }

        return $query;
    }

    /**
     * @param DateTime $DT
     * @param bool|\oitmain\smartcron\models\base\BaseCron $cron
     * @return \yii\db\ActiveQuery
     */
    static function getOneBySchedule($DT, &$cron = false)
    {
        $scheduleDT = (clone $DT);
        $scheduleDT->setTimezone(new DateTimeZone('UTC'));
        $query = static::find()
            ->andWhere(['=', 'scheduled_at', $scheduleDT->format('Y-m-d H:i:s')])
            ->limit(1);

        if ($cron) {
            $query->andWhere(['name' => $cron->getName()]);
        }

        return $query;
    }


    /**
     * @param bool|\oitmain\smartcron\models\base\BaseCron $cron
     * @return \yii\db\ActiveQuery
     */
    static function getNextScheduled(&$cron = false)
    {
        $query = static::find()->orderBy(['scheduled_at' => SORT_ASC])
            ->andWhere(['status' => static::STATUS_SCHEDULED])
            ->limit(1);

        if ($cron) {
            $query->andWhere(['name' => $cron->getName()]);
        }

        return $query;
    }


    /**
     * @param $cron \oitmain\smartcron\models\base\BaseCron
     * @param $DTs DateTime[]
     * @return DateTime[]
     */
    static function insertAllSchedule(&$cron, $DTs)
    {

        // Remove schedule that doesn't match expression (reschedule)
        Cron::deleteAll(
            ['and',
                ['status' => static::STATUS_SCHEDULED],
                ['>', 'scheduled_at', gmdate('Y-m-d H:i:s')],
                ['name' => $cron->getName()],
                ['!=', 'expression', $cron->getSchedule()],
            ]
        );

        /* @var $lockedDTs DateTime[] */
        $lockedDTs = array();

        foreach ($DTs as $DT) {
            if (CronMutex::acquireCronDate($cron->getName(), $DT)) {
                $lockedDT = (clone $DT);
                $lockedDT->setTimezone(new DateTimeZone('UTC'));
                $lockedDTs[$lockedDT->format('Y-m-d H:i:s')] = $lockedDT;
            }
        }

        // Look for existing schedule and release mutex
        $searchMysqlDates = array();
        foreach ($lockedDTs as $lockedDT) {
            $searchMysqlDates[] = $lockedDT->format('Y-m-d H:i:s');
        }

        $scheduledCronMysqlDateRows = static::find()
            ->select('scheduled_at')
            ->andWhere(['name' => $cron->getName()])
            ->andWhere(['in', 'scheduled_at', $searchMysqlDates])
            ->asArray()
            ->all();

        foreach ($scheduledCronMysqlDateRows as $scheduledCronMysqlDateRow) {
            $scheduledCronMysqlDate = $scheduledCronMysqlDateRow['scheduled_at'];
            CronMutex::releaseCronDate($cron->getName(), $lockedDTs[$scheduledCronMysqlDate]);
            unset($lockedDTs[$scheduledCronMysqlDate]);
        }

        // Create new schedule and release mutex
        foreach ($lockedDTs as $lockedDT) {
            static::insertOneSchedule($cron, $lockedDT);
            CronMutex::releaseCronDate($cron->getName(), $lockedDT);
        }

        /*

        $transaction = Yii::$app->db->beginTransaction();

        try {

            // Create new schedule and release mutex
            foreach ($lockedDTs as $lockedDT) {
                static::insertOneSchedule($cron, $lockedDT);
            }

            $transaction->commit();

        } catch (Exception $e) {
            $transaction->rollBack();
        }


        // Create new schedule and release mutex
        foreach ($lockedDTs as $lockedDT) {
            CronMutex::releaseCronDate($cron->getName(), $lockedDT);
        }

         */

        return $lockedDTs;
    }

    /**
     * @param $cron \oitmain\smartcron\models\base\BaseCron
     * @param $DT DateTime
     * @return Cron
     * @throws ErrorException
     */
    static function insertOneSchedule(&$cron, $DT)
    {
        $now = gmdate('Y-m-d H:i:s');
        $scheduleDT = clone $DT;
        $scheduleDT->setTimezone(new DateTimeZone('UTC'));

        $dbCron = new Cron();
        $dbCron->created_at = $now;
        $dbCron->updated_at = $now;
        $dbCron->name = $cron->getName();
        $dbCron->scheduled_at = $scheduleDT->format('Y-m-d H:i:s');
        $dbCron->expression = $cron->getSchedule();
        $dbCron->status = static::STATUS_SCHEDULED;
        $dbCron->cleanup = 1;

        // Speed up by skiping validation
        if (!$dbCron->save(false)) {
            throw new ErrorException('Could not save cron schedule');
        }
        return $dbCron;
    }


    public function doResume()
    {
        if ($this->status == Cron::STATUS_PAUSED) {
            $this->paused_mt = null;
            $this->status = Cron::STATUS_RUNNING;
            $this->save(false);
            return true;
        }

        return false;
    }

    public function doStart()
    {
        $this->start_mt = microtime(true);
        $this->status = Cron::STATUS_RUNNING;
        $this->save(false);
    }

    public function doHeartbeat()
    {
        $this->heartbeat_mt = microtime(true);
        $this->save(false);
    }

    public function doTimeout()
    {
        $this->status = Cron::STATUS_TIMEOUT;
        $this->cleanup = 0;
        $this->end_mt = microtime(true);
        $this->save(false);
    }

    public function doPause()
    {
        $this->status = Cron::STATUS_PAUSED;
        $this->paused_mt = microtime(true);
        $this->save(false);
    }

    public function doFinish()
    {
        $this->status = Cron::STATUS_SUCCESS;
        $this->end_mt = microtime(true);
        $this->cleanup = 0;
        $this->save(false);
    }

    public function doError()
    {
        $this->status = Cron::STATUS_ERROR;
        $this->end_mt = microtime(true);
        $this->cleanup = 0;
        $this->save(false);
    }

    public function doCleanup()
    {
        $this->cleanup = 0;
        $this->save(false);
    }

}
