<?php

namespace oitmain\yii2\smartcron\v1\models\base;

use Cron\CronExpression;
use DateInterval;
use DateTime;
use Exception;
use oitmain\yii2\smartcron\v1\models\CronManager;
use oitmain\yii2\smartcron\v1\models\CronMutex;
use oitmain\yii2\smartcron\v1\models\CronResult;
use oitmain\yii2\smartcron\v1\models\db\Cron;
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