<?php

namespace oitmain\yii2\smartcron\v1\models\base;

use Yii;
use yii\web\Controller;

abstract class BaseCronController extends Controller
{

    public $cronManager = null;

    public function beforeAction($action)
    {
        // Close session because it locks the process
        Yii::$app->session->close();
        Yii::$app->set('session', null);

        return parent::beforeAction($action);
    }

}