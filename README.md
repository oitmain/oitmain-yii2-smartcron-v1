# yii2-smartcron
Framework to create and manage cron tasks


Cron Heartbeat
Cron Schedule Tracking


        $cronManager = new CronManager();
        $cronManager->addCron(new SimpleCron());
        $cronResult = $cronManager->run();