<?php

namespace oitmain\smartcron\models\db;

use Yii;

/**
 * This is the model class for table "cron".
 *
 * @property integer $id
 * @property string $created_at
 * @property string $updated_at
 * @property string $name
 * @property string $scheduled_at
 * @property string $expression
 * @property string $start_mt
 * @property string $end_mt
 * @property string $heartbeat_mt
 * @property string $status
 * @property string $paused_mt
 * @property string $elapsed
 * @property integer $cleanup
 *
 * @property CronDetail[] $cronDetails
 */
class BaseCron extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'cron';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['created_at', 'updated_at', 'name', 'scheduled_at', 'expression'], 'required'],
            [['created_at', 'updated_at', 'scheduled_at'], 'safe'],
            [['start_mt', 'end_mt', 'heartbeat_mt', 'paused_mt', 'elapsed'], 'number'],
            [['status'], 'string'],
            [['cleanup'], 'integer'],
            [['name'], 'string', 'max' => 100],
            [['expression'], 'string', 'max' => 10],
            [['name', 'scheduled_at'], 'unique', 'targetAttribute' => ['name', 'scheduled_at'], 'message' => 'The combination of Name and Scheduled At has already been taken.']
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'name' => 'Name',
            'scheduled_at' => 'Scheduled At',
            'expression' => 'Expression',
            'start_mt' => 'Start Mt',
            'end_mt' => 'End Mt',
            'heartbeat_mt' => 'Heartbeat Mt',
            'status' => 'Status',
            'paused_mt' => 'Paused Mt',
            'elapsed' => 'Elapsed',
            'cleanup' => 'Cleanup',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCronDetails()
    {
        return $this->hasMany(CronDetail::className(), ['cron_id' => 'id']);
    }
}
