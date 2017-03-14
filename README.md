# yii2-mns
Mns component for yii2

##安装

使用composer安装
```
composer require vinkchen/yii2-mns
```
##配置
添加以下配置到配置的组件里：
```
'mns'=>[
    'class'=>'yii\mns\Mns',
    'accessKeyId'=>'从阿里云获取的accessKeyId',
    'AccessKeySecret'=>'从阿里云获取的accessKeySecret',
    'endpoint'=>'http://*****.mns.cn-hangzhou.aliyuncs.com/'
],
```

##使用方法

发送队列消息
```
Yii::$app->mns->queueSendMessage($queueName, $messageBody, $delaySeconds);
```

收取队列消息
```
$res = Yii::$app->mns->queueReceiveMessage($queueName);
```

发布主题消息
```
$res = Yii::$app->mns->topicPublishMessage($$topicName, $messageBody);
```

接收主题消息
```
$res = Yii::$app->mns->topicGetCallbackMsg($$topicName, $messageBody);
```

