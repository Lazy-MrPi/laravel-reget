<?php

namespace Overlu\Reget;


use Exception;
use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Overlu\Reget\Utils\Command;
use Overlu\Reget\Utils\ConfigCache;
use Psr\SimpleCache\InvalidArgumentException;

class Reget
{
    private static $instance;
    private $drivers = [
        'nacos', 'consul', 'eureka'
    ];

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    /**
     * @return Reget
     * @throws Exception
     */
    public static function getInstance(): Reget
    {
        if (!(self::$instance instanceof self)) {
            self::$instance = new self();
        }
        return self::$instance;
    }


    /**
     * @return mixed
     * @throws Exception
     */
    public function init()
    {
        try {
            $driver = $this->getDriver();
            $driver_name = Str::studly($driver);
            $driverClass = "\\Overlu\\Reget\\Drivers\\" . $driver_name;
            $config = $this->getConfig();
            return new $driverClass($config);
        } catch (Exception $exception) {
            throw new Exception($exception->getMessage());
        }
    }

    /**
     * @return mixed|string
     */
    private function getServerPort()
    {
        return $_SERVER['SERVER_PORT'] ?? '80';
    }

    /**
     * @return Repository|mixed|null
     * @throws Exception
     */
    private function getConfig()
    {
        return  config('reget.' . $this->getDriver());
    }

    /**
     * 获取驱动
     * @return string
     * @throws Exception
     */
    private function getDriver(): string
    {
        $driver = config('reget.driver');
        if (!in_array($driver, $this->drivers)) {
            throw new Exception('illegal driver');
        }
        return $driver;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function lists()
    {
        $lists = json_decode($this->init()->servers(), true);
        if (!empty($lists)) {
            return $lists['doms'];
        }
        return [];
    }

    /**
     * 心跳
     * @return mixed
     * @throws Exception
     */
    public function heartbeat()
    {
        $res = $this->init()->heartbeat();
        Log::info("Heartbeat Response: " . $res);
        return json_decode($res, true);
    }

    /**
     * @param $name
     * @param bool $random
     * @return array|mixed|string|null
     * @throws Exception
     */
    public function service($name, $random = true)
    {
        $lists = $this->lists();
        if (in_array($name, $lists)) {
            $hosts = json_decode($this->init()->server($name), true)['hosts'];
            if ($hosts) {
                if ($random) {
                    $host = Arr::random($hosts);
                    return $host['port'] == '80' ? $host['ip'] : $host['ip'] . ':' . $host['port'];
                }
                foreach ($hosts as $host) {
                    $temp[] = $host['port'] == '80' ? $host['ip'] : $host['ip'] . ':' . $host['port'];
                }
                return $temp;
            }
        }
        return null;
    }

    /****************************** config ************************************/

    /**
     * 获取配置数据
     * @param $dataId
     * @param string $group
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function config($dataId, $group = 'DEFAULT_GROUP')
    {
        $config = ConfigCache::get($dataId, $group);
        if (!$config) {
            $config = $this->init()->config($dataId, $group);
            ConfigCache::set($dataId, $group, $config);
        }
        return $config;
    }

    /**
     * 发布配置数据
     * @param $dataId : 配置ID
     * @param $content : 配置内容
     * @param string $group : 配置分组
     * @return mixed
     * @throws Exception
     */
    public function publish($dataId, $content, $group = 'DEFAULT_GROUP')
    {
        return $this->init()->publish($dataId, $content, $group);
    }

    /**
     * 移除配置数据
     * @param $dataId : 配置ID
     * @param string $group : 配置分组
     * @return mixed
     * @throws Exception
     */
    public function remove($dataId, $group = 'DEFAULT_GROUP')
    {
        return $this->init()->remove($dataId, $group);
    }

    /**
     * 监听配置
     * @param string $dataId
     * @param string $group
     * @throws InvalidArgumentException
     */
    public function listen(string $dataId, string $group = 'DEFAULT_GROUP')
    {
        $num = 0;
        while (true) {
            try {
                $num++;
                $content = $this->config($dataId, $group);
                if ($this->init()->listen($dataId, $content, $group)) { // 配置发生了变化
                    $config = $this->init()->config($dataId, $group);
                    ConfigCache::set($dataId, $group, $config);
                    $message = "【Reget】发现变更配置：" . $dataId . " :" . $content . " => " . $config;
                    Log::info($message);
                    if (App::runningInConsole()) {
                        Command::info($message);
                    }
                }
            } catch (Exception $exception) {
                $message = "【Reget】请求异常：" . trim($exception->getMessage());
                Log::error($message);
                if (App::runningInConsole()) {
                    Command::error($message);
                }
                break;
            }
            Log::info("【Reget】监听次数：" . $num);
            if (App::runningInConsole()) {
                Command::info("【Reget】监听次数：" . $num);
            }
        }
    }
}
