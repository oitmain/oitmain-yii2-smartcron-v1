<?php

namespace oitmain\yii2\smartcron\v1\models\db;

use Yii;

/**
 * This is the model class for table "cron_detail".
 *
 * @property integer $id
 * @property integer $cron_id
 * @property string $start_mt
 * @property string $end_mt
 * @property string $status
 * @property string $elapsed
 *
 * @property Cron $cron
 */
class BaseCronDetail extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'cron_detail';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['cron_id', 'start_mt'], 'required'],
            [['cron_id'], 'integer'],
            [['start_mt', 'end_mt', 'elapsed'], 'number'],
            [['status'], 'string']
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'cron_id' => 'Cron ID',
            'start_mt' => 'Start Mt',
            'end_mt' => 'End Mt',
            'status' => 'Status',
            'elapsed' => 'Elapsed',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCron()
    {
        return $this->hasOne(Cron::className(), ['id' => 'cron_id']);
    }
}
