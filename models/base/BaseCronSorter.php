<?php

namespace oitmain\smartcron\models\base;

/**
 * Class BaseCronSorter
 * @package oitmain\smartcron\models\base
 */
abstract class BaseCronSorter
{

    abstract public function compareCron(&$a, &$b);

    public function sortCrons(&$crons)
    {
        return usort($crons, array($this, 'compareCron'));
    }

}
