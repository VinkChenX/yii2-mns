<?php

namespace yii\mns;

require_once('sdk/mns-autoloader.php');

use Yii;

use AliyunMNS\Client;
use AliyunMNS\Model\SubscriptionAttributes;
use AliyunMNS\Requests\PublishMessageRequest;
use AliyunMNS\Requests\CreateTopicRequest;
use AliyunMNS\Exception\MnsException;
use AliyunMNS\Exception\MessageNotExistException;
use AliyunMNS\Exception\QueueNotExistException;
use AliyunMNS\Model\SendMessageRequestItem;

use AliyunMNS\Requests\SendMessageRequest;
use AliyunMNS\Requests\BatchSendMessageRequest;
use AliyunMNS\Requests\ReceiveMessageRequest;
use AliyunMNS\Requests\BatchReceiveMessageRequest;
use AliyunMNS\Requests\DeleteMessageRequest;
use AliyunMNS\Requests\BatchDeleteMessageRequest;
use AliyunMNS\Requests\CreateQueueRequest;

if (!function_exists('getallheaders'))
{
    function getallheaders()
    {
       $headers = array();
       foreach ($_SERVER as $name => $value)
       {
           if (substr($name, 0, 5) == 'HTTP_')
           {
               $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
           }
       }
       return $headers;
    }
}

/**
 * Yii2 Mns Component
 * 
 */
class Mns extends \yii\base\Component {
    
    /**
     * @var string 
     */
    private $accessKeyId;
    
    /**
     * @var string 
     */
    private $accessKeySecret;
    
    /**
     * @var string 
     */
    public $endpoint;
    
    private $client;
    
    /**
     * 日志
     * @var [] 
     */
    public $logs = [];
    
    public function setAccessKeyId($value) {
        $this->accessKeyId = $value;
    }
    
    public function setAccessKeySecret($value) {
        $this->accessKeySecret = $value;
    }
    
    /**
     * 获取client
     * @param bool $reset 是否重置
     * @return Client
     */
    protected function getClient($reset=false) {
        if(!$this->client || $reset) {
            $this->client = new Client($this->endpoint, $this->accessKeyId, $this->accessKeySecret);
        }
        return $this->client;
    }
    
    /**
     * 创建主题
     * @param CreateTopicRequest|string $name 主题类或名称
     * @throws MnsException
     * @return \AliyunMNS\Responses\CreateTopicResponse
     */
    public function topicCreate($name) {
        $request = $name instanceof CreateTopicRequest ? $name : new CreateTopicRequest($name);
        return $this->getClient()->createTopic($request);
    }
    
    /**
     * 获取主题
     * @param string $name 获取主题 
     * @throws MnsException
     * @return \AliyunMNS\Topic
     */
    public function topicGet($name) {
        return $this->getClient()->getTopicRef($name);
    }
    
    /**
     * 订阅主题
     * @param string|\AliyunMNS\Topic $topic 主题的名称或主题类
     * @param string $subscriptionName 订阅识别名称
     * @param string $endpoint 订阅事件的回调地址
     * @throws MnsException
     * @return \AliyunMNS\Responses\SubscribeResponse
     */
    public function topicSubscribe($topic, $subscriptionName, $endpoint) {
        if( !($topic instanceof \AliyunMNS\Topic)) {
            $topic = $this->topicGet($topic);
        }
        $attributes = new SubscriptionAttributes($subscriptionName, $endpoint);
        return $topic->subscribe($attributes);
    }
    
    /**
     * 取消订阅主题
     * @param string|\AliyunMNS\Topic $topic 主题的名称或主题类
     * @param string $subscriptionName 订阅识别名称
     * @throws MnsException
     * @return \AliyunMNS\Responses\UnsubscribeResponse
     */
    public function topicUnsubscribe($topic, $subscriptionName) {
        if( !($topic instanceof \AliyunMNS\Topic)) {
            $topic = $this->topicGet($topic);
        }
        return $topic->unsubscribe($subscriptionName);
    }
    
    /**
     * 删除主题
     * @param string $name 主题的名称
     * @throws MnsException
     * @return \AliyunMNS\Responses\DeleteTopicResponse
     */
    public function topicDelete($name) {
        return $this->getClient()->deleteTopic($name);
    }
    
    /**
     * 主题发布消息
     * @param string|\AliyunMNS\Topic $topic 主题的名称或主题类
     * @param string $body 发布消息的内容
     * @param string $subscriptionUrl 订阅事件的回调地址
     * @throws MnsException
     * @return \AliyunMNS\Responses\PublishMessageResponse
     */
    public function topicPublishMessage($topic, $body) {
        $request = new PublishMessageRequest($body);
        if( !($topic instanceof \AliyunMNS\Topic)) {
            $topic = $this->topicGet($topic);
        }
        return $topic->publishMessage($request);
    }
    
    /**
     * 主题接收消息回调 判断http_code是否为200 如果不是200 则会调用N次后结束
     * @throws \Exception
     * @return Object 包括以下属性 TopicName SubscriptionName MessageId MessageMD5 Message
     */
    public function topicGetCallbackMsg() {
        $tmpHeaders = array();
        $headers = getallheaders();
        foreach ($headers as $key => $value)
        {
            $key = strtolower($key);
            if (0 === strpos($key, 'x-mns-'))
            {
                $tmpHeaders[$key] = $value;
            }
        }
        ksort($tmpHeaders);
        $canonicalizedMNSHeaders = implode("\n", array_map(function ($v, $k) { return $k . ":" . $v; }, $tmpHeaders, array_keys($tmpHeaders)));
        $this->logs[] = "canonicalizedMNSHeaders: $canonicalizedMNSHeaders";
        
        $method = $_SERVER['REQUEST_METHOD'];
        $canonicalizedResource = $_SERVER['REQUEST_URI'];

        $contentMd5 = '';
        if (array_key_exists('Content-MD5', $headers))
        {
            $contentMd5 = $headers['Content-MD5'];
        }
        else if (array_key_exists('Content-md5', $headers))
        {
            $contentMd5 = $headers['Content-md5'];
        }
        else if (array_key_exists('Content-Md5', $headers))
        {
            $contentMd5 = $headers['Content-Md5'];
        }

        $contentType = '';
        if (array_key_exists('Content-Type', $headers))
        {
            $contentType = $headers['Content-Type'];
        }
        if(!isset($headers['Date'],$headers['X-Mns-Signing-Cert-Url'], $headers['Authorization'])) {
            throw new \Exception('回调数据未定义 Date X-Mns-Signing-Cert-Url Authorization');
        }
        $date = isset($headers['Date']) ? $headers['Date'] : '';

        $stringToSign = strtoupper($method) . "\n" . $contentMd5 . "\n" . $contentType . "\n" . $date . "\n" . $canonicalizedMNSHeaders . "\n" . $canonicalizedResource;
        $this->logs[] = "stringToSign: $stringToSign";
        
        $publicKeyURL = base64_decode($headers['X-Mns-Signing-Cert-Url']);
        $publicKey = $this->curl_get($publicKeyURL);
        $signature = $headers['Authorization'];
        $this->logs[] = "publicKey:$publicKey , signature: $signature";
        
        $pass = $this->topicVerify($stringToSign, $signature, $publicKey);
        if (!$pass)
        {
            throw new \Exception('verify signature fail', 400);
        }

        // 2. now parse the content
        $content = file_get_contents("php://input");
        $this->logs[] = "content: $content";

        if (!empty($contentMd5) && $contentMd5 != base64_encode(md5($content)))
        {
            throw new \Exceptio('md5 mismatch', 401);
        }

        $msg = new \SimpleXMLElement($content);
        return $msg;
    }
    
    /**
     * 创建队列
     * @param string|CreateQueueRequest $name 名称
     * @return \AliyunMNS\Responses\CreateQueueResponse
     */
    public function queueCreate($name) {
        $request = $name instanceof CreateQueueRequest ? $name : new CreateQueueRequest($name);
        return $this->getClient()->createQueue($request);
    }
    
    /**
     * 获取队列
     * @param string $name 名称
     * @return \AliyunMNS\Queue
     */
    public function queueGet($name) {
        return $this->getClient()->getQueueRef($name);
    }
    
    /**
     * 队列删除
     * @param string $name 名称
     */
    public function queueDelete($name) {
        return $this->getClient()->deleteQueue($name);
    }
    
    /**
     * 队列发送消息
     * @param string|\AliyunMNS\Queue $queue 队列的名称或队列类
     * @param string $body 发布消息的内容
     * @param integer $delaySeconds 延迟秒数 默认为0
     * @throws MnsException
     * @return \AliyunMNS\Responses\SendMessageResponse
     */
    public function queueSendMessage($queue, $body, $delaySeconds=0) {
        $request = new SendMessageRequest($body, $delaySeconds);
        if( !($queue instanceof \AliyunMNS\Queue)) {
            $queue = $this->queueGet($queue);
        }
        return $queue->sendMessage($request);
    }
    
     /**
     * 队列批量发送消息
     * @param string|\AliyunMNS\Queue $queue 队列的名称或队列类
     * @param [] $bodys 发布消息的内容数组 stirng或SendMessageRequestItem
     * @throws MnsException
     * @return \AliyunMNS\Responses\SendMessageResponse
     */
    public function queueBatchSendMessage($queue, $bodys) {
        $sendMessageRequestItems = [];
        foreach($bodys as $body) {
            if($body instanceof SendMessageRequestItem) {
                $sendMessageRequestItems = $body;
            } else {
                $sendMessageRequestItems[] = new SendMessageRequestItem($body);
            }
        }
        $request = new BatchSendMessageRequest($sendMessageRequestItems);
        if( !($queue instanceof \AliyunMNS\Queue)) {
            $queue = $this->queueGet($queue);
        }
        return $queue->batchSendMessage($request);
    }
    
    /**
     * 队列接收消息
     * @param string|\AliyunMNS\Queue $queue 队列的名称或队列类
     * @param integer $waitSeconds 等待秒数
     * @throws MnsException
     * @return \AliyunMNS\Responses\ReceiveMessageResponse
     */
    public function queueReceiveMessage($queue, $waitSeconds=30) {
        if( !($queue instanceof \AliyunMNS\Queue)) {
            $queue = $this->queueGet($queue);
        }
        try {
            $res = $queue->receiveMessage($waitSeconds);
        } catch (QueueNotExistException $ex) {
            $res = null;
        } catch (MessageNotExistException $ex) {
            $res = null;
        }
        
        return $res;
    }
    
    /**
     * 队列批量接收消息
     * @param string|\AliyunMNS\Queue $queue 队列的名称或队列类
     * @param integer $num 接收消息的数量
     * @param integer $waitSeconds 等待秒数
     * @throws MnsException
     * @throws MessageNotExistException
     * @return \AliyunMNS\Responses\ReceiveMessageResponse
     */
    public function queueBatchReceiveMessage($queue, $num, $waitSeconds=30) {
        if( !($queue instanceof \AliyunMNS\Queue)) {
            $queue = $this->queueGet($queue);
        }
        $request = new BatchReceiveMessageRequest($num, $waitSeconds);
        try {
            $res = $queue->batchReceiveMessage($request);
        } catch (QueueNotExistException $ex) {
            $res = null;
        } catch (MessageNotExistException $ex) {
            $res = null;
        }
        return $res;
    }
    
    /**
     * 删除消息
     * @param string|\AliyunMNS\Queue $queue 队列的名称或队列类
     * @param string $receiptHandle 消息回执
     */
    public function queueDeleteMessage($queue, $receiptHandle) {
        if( !($queue instanceof \AliyunMNS\Queue)) {
            $queue = $this->queueGet($queue);
        }
        return $queue->deleteMessage($receiptHandle);
    }
    
    /**
     * 删除消息
     * @param string|\AliyunMNS\Queue $queue 队列的名称或队列类
     * @param [] $receiptHandles 消息回执数组
     */
    public function queueBatchDeleteMessage($queue, $receiptHandles) {
        if( !($queue instanceof \AliyunMNS\Queue)) {
            $queue = $this->queueGet($queue);
        }
        return $queue->batchDeleteMessage($receiptHandles);
    }

    /**
     * curl get 调用
     * @param string $url 链接
     * @return string
     */
    protected function curl_get($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $output = curl_exec($ch);

        curl_close($ch);

        return $output;
    }

    /**
     * 验证主题签约
     * @param string $data 数据
     * @param string $signature 签约
     * @param string $pubKey 公钥
     * @return bool
     */
    protected function topicVerify($data, $signature, $pubKey)
    {
        $res = openssl_get_publickey($pubKey);
        $result = (bool) openssl_verify($data, base64_decode($signature), $res);
        openssl_free_key($res);
        return $result;
    }
    
}

