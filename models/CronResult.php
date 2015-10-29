<?php

namespace oitmain\yii2\smartcron\v1\models;

class CronResult
{

    public $cron;

    /*
     * Elapsed time in seconds with microsecond fraction
     */
    public $elapsed;

    public $timeout;

    public $finished;

    public $startedAtDT;

    public $finishedAtDT;

    public $cronId;

    public $cronDetailId;

}
