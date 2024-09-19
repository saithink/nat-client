<?php
namespace Saithink\NatClient;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use support\Log;

/**
 * Intranet penetration client
 */
class Client
{

    /**
     * debug
     * @var bool
     */
    protected $debug = false;

    /**
     * server host
     * @var string
     */
    protected $host = '';

    /**
     * client token
     * @var string
     */
    protected $token = '';

    /**
     * current app
     * @var array
     */
    protected $app = [];

    /**
     * number of connection failures with the server
     * @var array
     */
    protected $connectFailCount = 0;

    /**
     * number of pre-created connections
     */
    const PRE_CONNECTION_COUNT = 10;

    /**
     * the connection is disconnected and reconnected after 58 seconds
     */
    const IDLE_TIMEOUT = 58;

    /**
     * construct
     * @param bool $debug
     */
    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    /**
     * process start time
     * @return void
     */
    public function onWorkerStart()
    {
        $this->createSettingConnection();
    }

    /**
     * create a connection to receive a configuration push
     * @return void
     */
    protected function createSettingConnection()
    {
        $host = config('plugin.saithink.nat-client.app.host');
        $token = config('plugin.saithink.nat-client.app.token');
        $this->token = $token;
        $this->host = $host;
        if (empty($host) || empty($token)) {
            echo $logs = "Nat-Client：客户端未设置服务端地址或密钥\n";
            Log::error($logs);
            return;
        }
        $connection = new AsyncTcpConnection("frame://$host");
        $connection->onConnect = function ($connection) use ($token, $host) {
            $connection->send("OPTION / HTTP/1.1\r\nNat-host: $host\r\nNat-token: $token\r\nnat-setting-client: yes\r\nHost: $host\r\n\r\n", true);
        };
        $connection->onMessage = function ($connection, $buffer) {
            $data = json_decode($buffer, true);
            if (!$data) {
                echo $logs = "Nat-Client：获取配置错误 $buffer\n";
                Log::error($logs);
                return;
            }
            $type = $data['type'] ?? '';
            switch ($type) {
                // heartbeat
                case 'ping':
                    return;
                // delivery configuration
                case 'setting':
                    $app = $data['setting'];
                    echo $logs = "Nat-Client：成功获取配置 \n" . var_export($app, true) ."\n";
                    $this->debugLog($logs);
                    $this->createConnection($app);
                    return;
                // unknown
                default :
                    $type = var_export($type, true);
                    echo $logs = "Nat-Client：未知命令 $type \n";
                    Log::error($logs);
            }
        };
        $connection->onClose = function ($connection) {
            $connection->reConnect(1);
        };
        Timer::add(55, function () use ($connection) {
            if ($connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
                $connection->send(json_encode(['type' => 'ping']));
            }
        });
        $connection->connect();
    }

    /**
     * createConnection
     * @return void
     */
    protected function createConnection($app)
    {
        $domain = $app['domain'];
        if (isset($this->app['domain']) && $this->app['domain'] === $domain) {
            return;
        }
        for ($i = 0; $i < static::PRE_CONNECTION_COUNT; $i++) {
            Timer::add($i + 0.001, function () use ($domain) {
                $this->createConnectionToServer($domain);
            }, null, false);
        }
        $this->app = $app;
    }

    /**
     * createConnectionToServer
     * @return void
     */
    public function createConnectionToServer($domain)
    {
        if (!isset($this->app['domain']) || $this->app['domain'] !== $domain) {
            $this->debugLog("Nat-Client：因域名 $domain 在配置中不存在而忽略");
            return;
        }
        $this->debugLog("Nat-Client：Connect $this->host with $domain");
        $serverConnection = new AsyncTcpConnection("tcp://$this->host");
        $serverConnection->lastBytesReaded = 0;
        $serverConnection->onConnect = function ($serverConnection) use ($domain) {
            $this->connectFailCount = 0;
            $serverConnection->send("OPTION / HTTP/1.1\r\nHost: $this->host\r\nNat-host: $domain\r\nNat-token:$this->token\r\n\r\n");
        };
        $serverConnection->onMessage = function ($serverConnection, $data) use ($domain) {
            $this->debugLog("Nat-Client：Client request");
            $localIp = $this->app['local_ip'];
            $localPort = $this->app['local_port'];
            $localConnection = new AsyncTcpConnection("tcp://$localIp:$localPort");
            $localConnection->send($data);
            $localConnection->pipe($serverConnection);
            $serverConnection->pipe($localConnection);
            $localConnection->connect();
            $this->createConnectionToServer($domain);
        };
        $serverConnection->onClose = function ($serverConnection) use ($domain) {
            if (isset($this->app['domain']) && $this->app['domain'] === $domain) {
                $count = $this->connectFailCount;
                if($count === 0) {
                    $this->createConnectionToServer($domain);
                } else {
                    $time = min($count * 0.1, 10);
                    $this->debugLog("Nat-Client：定时 $time 秒重连服务端");
                    Timer::add($time, function () use ($domain) {
                        $this->createConnectionToServer($domain);
                    }, null, false);
                }
            }
            if ($serverConnection->timeoutTimer) {
                Timer::del($serverConnection->timeoutTimer);
                $serverConnection->timeoutTimer = null;
            }
        };
        $serverConnection->onError = function ($serverConnection, $code) {
            if ($code === 1) {
                $this->connectFailCount++;
                $this->debugLog("Nat-Client：连接服务端 $this->host 失败 $this->connectFailCount 次");
            }
        };
        $serverConnection->timeoutTimer = Timer::add(static::IDLE_TIMEOUT, function () use ($serverConnection, $domain) {
            if ($serverConnection->lastBytesReaded == $serverConnection->bytesRead || $serverConnection->getStatus() === TcpConnection::STATUS_CLOSED) {
                if($serverConnection->timeoutTimer) {
                    Timer::del($serverConnection->timeoutTimer);
                    $serverConnection->timeoutTimer = null;
                }
                $serverConnection->close();
                $this->debugLog("Nat-Client：连接 Host:$domain 空闲" . static::IDLE_TIMEOUT . "秒执行正常关闭");
            }
            $serverConnection->lastBytesReaded = $serverConnection->bytesRead;
        });
        $serverConnection->connect();
    }

    /**
     * debugLog
     * @param $msg
     * @return void
     */
    protected function debugLog($msg)
    {
        if ($this->debug) {
            echo date('Y-m-d H:i:s') . " $msg" . PHP_EOL;
        }
    }
}
