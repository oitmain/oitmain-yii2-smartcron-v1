<?php

namespace oitmain\yii2\smartcron\v1\models;

use DateTime;
use oitmain\yii2\smartcron\v1\models\base\BaseCronSorter;
use oitmain\yii2\smartcron\v1\models\db\Cron;

/**
 * Class FifoCronSorter
 * @package oitmain\yii2\smartcron\v1\models
 */
class FifoCronSorter extends BaseCronSorter
{

    /**
     * @param Cron $a
     * @param Cron $b
     * @return integer An integer less than, equal to, or greater than zero if the first argument is considered to be
     * respectively less than, equal to, or greater than the second.
     */
    public function compareCron(&$a, &$b)
    {
        /*
         * Cron that is most behind is top priority
         * 1. Paused and scheduled time
         */

        $aScheduledDT = $a->scheduled_at;
        $bScheduledDt = $b->scheduled_at;

        $isAPaused = $a->status == Cron::STATUS_PAUSED;
        $isBPaused = $b->status == Cron::STATUS_PAUSED;

        // Paused cron has secondary priority
        if ($isAPaused && !$isBPaused) return 1;
        if (!$isAPaused && $isBPaused) return -1;

        // Paused cron that wasn't executed for a while has higher priority
        if ($isAPaused) {
            $aScheduledDT = DateTime::createFromFormat('U.u', $a->paused_mt);
        }

        if ($isBPaused) {
            $bScheduledDt = DateTime::createFromFormat('U.u', $b->paused_mt);
        }

        if ($aScheduledDT == $bScheduledDt) {
            return 0;
        }

        return $aScheduledDT < $bScheduledDt ? -1 : 1;
    }

}
