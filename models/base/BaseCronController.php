<?php

namespace oitmain\smartcron\models\base;

use Cron\CronExpression;
use DateInterval;
use DateTime;
use Exception;
use oitmain\smartcron\models\CronManager;
use oitmain\smartcron\models\CronMutex;
use oitmain\smartcron\models\CronResult;
use oitmain\smartcron\models\db\Cron;
use Yii;
use yii\web\Controller;

abstract class BaseCronController extends Controller
{

    public $cronManager = null;

    public function beforeAction($action)
    {
        // Close session because it locks the process
        Yii::$app->session->close();

        return parent::beforeAction($action);
    }

}