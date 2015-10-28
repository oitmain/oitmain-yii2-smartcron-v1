<?php

namespace oitmain\smartcron\models;

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
