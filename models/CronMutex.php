<?php

namespace oitmain\smartcron\models;

use DateTime;
use Yii;
use yii\base\ErrorException;
use yii\mutex\Mutex;

/**
 * Class CronMutex
 * @package oitmain\smartcron
 */
class CronMutex
{

    /**
     * @var Mutex
     */
    static protected $_mutex;


    /**
     * @return Mutex
     * @throws ErrorException
     */
    static function getMutex()
    {
        if (!static::$_mutex) {
            if (!Yii::$app->has('mutex'))
                throw new ErrorException('Yii mutex component is not defined');
            static::$_mutex = Yii::$app->mutex;
        }
        return static::$_mutex;
    }

    /**
     * @param $name
     * @param $DT DateTime
     * @return boolean
     */
    static function acquireCronDate($name, $DT)
    {
        return static::getMutex()->acquire(static::getCronDateKey($name, $DT));
    }

    /*
     * @param $name
     * @param $DT DateTime
     * @return string
     */
    static function getCronDateKey($name, $DT)
    {
        return 'cron_mutex_' . $name . '_' . $DT->getTimestamp();
    }

    /**
     * @param $name
     * @param $DT DateTime
     * @return boolean
     */
    static function releaseCronDate($name, $DT)
    {
        return static::getMutex()->release(static::getCronDateKey($name, $DT));
    }


}
