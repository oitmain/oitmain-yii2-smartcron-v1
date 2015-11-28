<?php

namespace oitmain\yii2\smartcron\v1\models\db;

use Yii;

/**
 * @inherit
 */
class CronDetail extends BaseCronDetail
{

    const STATUS_DEAD = 'DEAD';
    const STATUS_TIMEOUT = 'TIMEOUT';
    const STATUS_ERROR = 'ERROR';
    const STATUS_SUCCESS = 'SUCCESS';
    const STATUS_RUNNING = 'RUNNING';

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

        if ($startMT && $endMT) {
            return $endMT - $startMT;
        }

        return null;
    }


    public function doStart($mt = false)
    {
        $this->start_mt = $mt ? $mt: microtime(true);
        $this->status = static::STATUS_RUNNING;
        $this->save(false);
    }

    public function doTimeout($mt = false)
    {
        $this->status = static::STATUS_TIMEOUT;
        $this->end_mt = $mt ? $mt: microtime(true);
        $this->save(false);
    }

    public function doFinish($mt = false)
    {
        $this->status = static::STATUS_SUCCESS;
        $this->end_mt = $mt ? $mt: microtime(true);
        $this->save(false);
    }

    public function doError($mt = false)
    {
        $this->status = static::STATUS_ERROR;
        $this->end_mt =$mt ? $mt:  microtime(true);
        $this->save(false);
    }

}
