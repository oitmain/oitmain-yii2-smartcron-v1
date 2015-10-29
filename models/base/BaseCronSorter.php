<?php

namespace oitmain\yii2\smartcron\v1\models\base;

/**
 * Class BaseCronSorter
 * @package oitmain\yii2\smartcron\v1\models\base
 */
abstract class BaseCronSorter
{

    abstract public function compareCron(&$a, &$b);

    public function sortCrons(&$crons)
    {
        return usort($crons, array($this, 'compareCron'));
    }

}
