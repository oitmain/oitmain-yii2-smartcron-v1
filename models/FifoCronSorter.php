<?php

namespace oitmain\smartcron\models;

use DateTime;
use oitmain\smartcron\models\base\BaseCron;
use oitmain\smartcron\models\base\BaseCronSorter;
use oitmain\smartcron\models\db\Cron;

/**
 * Class FifoCronSorter
 * @package oitmain\smartcron\models
 */
class FifoCronSorter extends BaseCronSorter
{

    /**
     * @var Cron[]
     */
    protected $_pausedDbCrons;

    /**
     * @param Cron $dbCron
     * @return $this
     */
    public function addPausedDbCron(&$dbCron)
    {
        $this->_pausedDbCrons[$dbCron->name] = $dbCron;
        return $this;
    }


    /**
     * @param BaseCron $a
     * @param BaseCron $b
     * @return integer An integer less than, equal to, or greater than zero if the first argument is considered to be
     * respectively less than, equal to, or greater than the second.
     */
    public function compareCron(&$a, &$b)
    {
        /*
         * Cron that is most behind is top priority
         * 1. Paused and scheduled time
         */

        $aScheduledExpression = $a->getScheduleExpression();
        $bScheduledExpression = $b->getScheduleExpression();

        $aScheduledDT = $aScheduledExpression->getNextRunDate('now', 0, true);
        $bScheduledDt = $bScheduledExpression->getNextRunDate('now', 0, true);

        if (isset($this->_pausedDbCrons[$a->getName()])) {
            $aPausedDbCron = $this->_pausedDbCrons[$a->getName()];
            $aScheduledDT = DateTime::createFromFormat('U.u', $aPausedDbCron->paused_mt);
        }

        if (isset($this->_pausedDbCrons[$b->getName()])) {
            $bPausedDbCron = $this->_pausedDbCrons[$b->getName()];
            $bScheduledDt = DateTime::createFromFormat('U.u', $bPausedDbCron->paused_mt);
        }

        if ($aScheduledDT == $bScheduledDt) {
            return 0;
        }

        return $aScheduledDT < $bScheduledDt ? -1 : 1;
    }

}
