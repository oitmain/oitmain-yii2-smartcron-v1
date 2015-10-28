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

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getScheduledCrons()
    {
        return $this->find()
            ->andWhere(['>=', 'scheduled_at', gmdate('Y-m-d H:i:s')])
            ->andWhere(['status' => static::STATUS_SCHEDULED]);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getMissedCrons()
    {
        return $this->find()
            ->andWhere(['<', 'scheduled_at', gmdate('Y-m-d H:i:s')])
            ->andWhere(['status' => static::STATUS_SCHEDULED]);
    }


    /**
     * @param $cron \oitmain\smartcron\models\base\BaseCron
     * @param $DT DateTime
     * @return \yii\db\ActiveQuery
     */
    public function getBySchedule($cron, $DT)
    {
        $scheduleDT = (clone $DT);
        $scheduleDT->setTimezone(new DateTimeZone('UTC'));
        return $this->find()
            ->andWhere(['=', 'scheduled_at', $scheduleDT->format('Y-m-d H:i:s')]);
    }

    /**
     * @param $cron \oitmain\smartcron\models\base\BaseCron
     * @param $DTs DateTime[]
     * @return DateTime[]
     */
    public function createBatchSchedule($cron, $DTs)
    {
        /* @var $lockedDateTimes DateTime[] */
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

        $scheduledCronMysqlDateRows = $this->find()
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
            $this->createSchedule($cron, $lockedDT);
            CronMutex::releaseCronDate($cron->getName(), $lockedDT);
        }

        return $lockedDTs;
    }

    /**
     * @param $cron \oitmain\smartcron\models\base\BaseCron
     * @param $DT DateTime
     * @return Cron
     * @throws ErrorException
     */
    public function createSchedule($cron, $DT)
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

        if (!$dbCron->save()) {
            throw new ErrorException('Could not save cron schedule');
        }
        return $dbCron;
    }


}
