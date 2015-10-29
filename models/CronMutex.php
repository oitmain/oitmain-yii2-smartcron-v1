<?php

namespace oitmain\yii2\smartcron\v1\models;

use DateTime;
use oitmain\yii2\smartcron\v1\models\base\BaseCron;
use Yii;
use yii\base\ErrorException;
use yii\mutex\Mutex;

/**
 * Class CronMutex
 * @package oitmain\yii2\smartcron\v1
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
     * @param DateTime $DT
     * @return boolean
     */
    static function acquireCronDate($name, $DT)
    {
        return static::getMutex()->acquire(static::getCronDateMutexKey($name, $DT));
    }

    /**
     * @param $name
     * @param DateTime $DT
     * @return boolean
     */
    static function releaseCronDate($name, $DT)
    {
        return static::getMutex()->release(static::getCronDateMutexKey($name, $DT));
    }


    /**
     * @param BaseCron $cron
     * @return bool
     * @throws ErrorException
     */
    static function acquireCron(&$cron)
    {
        return static::getMutex()->acquire(static::getCronMutexKey($cron->getName()));
    }

    /**
     * @param BaseCron $cron
     * @return bool
     * @throws ErrorException
     */
    static function releaseCron(&$cron)
    {
        return static::getMutex()->release(static::getCronMutexKey($cron->getName()));
    }


    /**
     * @param string $name
     * @param DateTime $DT
     * @return string
     */
    static function getCronDateMutexKey($name, $DT)
    {
        return static::getCronMutexKey($name) . '_' . $DT->getTimestamp();
    }

    /**
     * @param string $name
     * @return string
     */
    static function getCronMutexKey($name)
    {
        return 'cron_mutex_' . $name;
    }
}
