<?php

namespace oitmain\yii2\smartcron\v1\test;

use oitmain\yii2\smartcron\v1\models\base\BaseCron;
use Yii;

class SimpleCron extends BaseCron
{

    protected $_i = 10;

    protected $_schedule = '0/5 * * * *';

    protected function getCacheKey($cronId, $cronDetailId)
    {
        return 'simple_cron_' . $cronId;
    }

    public function eventReset($cron, $cronDetail)
    {
        $this->_i = 20;
    }

    public function eventLoop($cron, $cronDetail)
    {
        $this->_i--;

        var_dump($this->_i);

        sleep(1);

        return false;

        return $this->_i > 0;
    }

    public function eventFinished($cron, $cronDetail)
    {
        Yii::$app->cache->delete($this->getCacheKey($cron->id, $cronDetail->id));
    }

    public function eventPaused($cron, $cronDetail)
    {
        Yii::$app->cache->set($this->getCacheKey($cron->id, $cronDetail->id), $this->_i);
    }

    public function eventResume($cron, $cronDetail)
    {
        $this->_i = Yii::$app->cache->get($this->getCacheKey($cron->id, $cronDetail->id));
        if (!$this->_i) $this->_i = 0;
    }

    public function cleanupFailed($cron, $cronDetail)
    {
        Yii::$app->cache->delete($this->getCacheKey($cron->id, $cronDetail->id));
    }

    public function cleanupTimedOut($cron, $cronDetail)
    {
        Yii::$app->cache->delete($this->getCacheKey($cron->id, $cronDetail->id));
    }

    public function cleanupDied($cron, $cronDetail)
    {
        Yii::$app->cache->delete($this->getCacheKey($cron->id, $cronDetail->id));
    }

}
