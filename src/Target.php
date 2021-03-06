<?php
/**
 * Target.php file.
 *
 * Post log messages to [Loggly](http://loggly.com/) with this log target class.
 * Loggly is a cloud based log management service:
 *
 * - https://www.loggly.com/
 *
 * This is based on the yii-loggly extension by Alexey Ashurok:
 *
 * - http://github.com/aotd1/yii-loggly
 *
 * @author Dirk Adler <adler@spacedealer.de>
 * @copyright Copyright &copy; 2008-2014 spacedealer GmbH
 */

namespace achertovsky\loggly;

use Yii;
use yii\log\Logger;
use yii\base\InvalidConfigException;
use yii\helpers\Json;

/**
 * Class Target
 *
 * @package spacedealer\loggly
 */
class Target extends \yii\log\Target
{
    /**
     * @var string loggly customer token
     */
    public $customerToken;

    /**
     * @var bool
     */
    public $finishRequest = true;

    /**
     * @var string
     */
    public $baseUrl = 'https://logs-01.loggly.com';

    /**
     * @var bool whether ips are logged. disabled by default.
     */
    public $enableIp = false;

    /**
     * @var bool whether trail id is logged. disabled by default.
     */
    public $enableTrail = false;
    
    /**
     * @var boolean whether trace is logged. disabled by default.
     */
    public $enableTrace = false;

    /**
     * @var string md5 based random id. will be generated if not set.
     */
    public $trail;

    /**
     * @var int maximal time the curl connection phase is allowed to take in seconds.
     */
    public $connectTimeout = 5;

    /**
     * @var int maximal time the curl request is allowed to take in seconds.
     */
    public $timeout = 5;

    /**
     * @var array optional list of tags
     * @see https://www.loggly.com/docs/tags/
     */
    public $tags = [];

    /**
     * @var bool Whether to use bulk upload of messages.
     */
    public $bulk = false;

    /**
     * @var resource cURL-Handle
     */
    private $_curl;

    /**
     * @var string log url including customer token and optional tags
     */
    private $_url;

    /**
     * IP that gonna be used when $_SERVER['REMOTE_ADDR'] aint set
     *
     * @var string
     */
    public $cliIp = '0.0.0.0';

    /**
     * Validate config and init.
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        // validate customer token
        if (!is_string($this->customerToken) || strlen($this->customerToken) !== 36) {
            throw new InvalidConfigException("Loggly customer token must be a valid 36 character string");
        }

        // init trail id
        if (empty($this->trail)) {
            $this->trail = md5(rand() . rand() . rand() . rand());
        }

        // init endpoint url
        $endpoint = ($this->bulk === true) ? '/bulk/' : '/inputs/';
        $tags = empty($this->tags) ? '' : '/tag/' . implode(',', $this->tags) . '/';
        $this->_url = $this->baseUrl . $endpoint . $this->customerToken . $tags;
    }

    /**
     * The loggly post url.
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->_url;
    }

    /**
     * Push log [[messages]] to loggly.
     */
    public function export()
    {
        $ch = $this->initCurl();

        // process messages
        if ($this->bulk === true) {
            $messages = [];
            foreach ($this->messages as $message) {
                $messages[] = json_encode($this->formatMessage($message), JSON_FORCE_OBJECT);
            }
            $data = implode("\n", $messages);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_exec($ch);
        } else {
            foreach ($this->messages as $message) {
                $data = json_encode($this->formatMessage($message), JSON_FORCE_OBJECT);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_exec($ch);
            }
        }
    }

    /**
     * Compile log message. Adds remote ip address and trail id if enabled.
     *
     * @param array $message
     * @return array
     */
    public function formatMessage($message)
    {
        list($text, $level, $category, $timestamp, $traces) = $message;
        $code = null;
        if ($message[0] instanceof \Exception) {
            $text = $message[0]->getMessage();
            $traces = $message[0]->getTrace();
            $code = $message[0]->getCode();
        }
        if (is_array($message[0])) {
            $text = Json::encode($message[0]);
        }
        $level = Logger::getLevelName($level);
        $msg = [
            'timestamp' => date('Y/m/d H:i:s', $timestamp),
            'level' => $level,
            'category' => $category,
            'message' => $text,
        ];
        $debug = Yii::$app->getModule('debug');
        if (!is_null($debug)) {
            $msg['tag'] = $debug->logTarget->tag;
        }
        if (!is_null($code)) {
            $msg['code'] = $code;
        }
        if ($this->enableIp) {
            $msg['ip'] = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : $this->cliIp;
        }
        if ($this->enableTrail) {
            $msg['trail'] = $this->trail;
        }
        if ($this->enableTrace) {
            /*
             * since loggly has some issues with nested json:
             * format array with minimal nesting
             */
            $toLog = [];
            foreach ($traces as $trace) {
                if (empty($trace['file'])) {
                    continue;
                }
                $toLog[] = "{$trace['file']}({$trace['line']})";
            }
            $msg['trace'] = $toLog;
        }

        return $msg;
    }

    /**
     * Init curl.
     *
     * @return resource
     */
    private function initCurl()
    {
        if ($this->_curl !== null) {
            return $this->_curl;
        }

        $this->_curl = curl_init();
        curl_setopt($this->_curl, CURLOPT_URL, $this->getUrl());
        curl_setopt($this->_curl, CURLOPT_HTTPHEADER, ['Content-type: application/json']);
        curl_setopt($this->_curl, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($this->_curl, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($this->_curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->_curl, CURLOPT_POST, 1);
        curl_setopt($this->_curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($this->_curl, CURLOPT_SSL_VERIFYPEER, true);

        return $this->_curl;
    }

    /**
     * Closes open curl connection.
     */
    public function __destruct()
    {
        if ($this->_curl !== null) {
            curl_close($this->_curl);
        }
    }
}
