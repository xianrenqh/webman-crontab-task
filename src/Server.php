<?php
declare (strict_types=1);

namespace xianrenqh\Task;

use support\Container;
use think\facade\Db;
use Workerman\Connection\TcpConnection;
use Workerman\Crontab\Crontab;
use Workerman\Worker;

/**
 * 注意：定时器开始、暂停、重起 都是在下一分钟开始执行
 */
class Server
{
    const FORBIDDEN_STATUS = '0';

    const NORMAL_STATUS = '1';

    // 命令任务
    public const COMMAND_CRONTAB = '1';
    // 类任务
    public const CLASS_CRONTAB = '2';
    // URL任务
    public const URL_CRONTAB = '3';
    // EVAL 任务
    public const EVAL_CRONTAB = '4';
    //shell 任务
    public const SHELL_CRONTAB = '5';


    private Worker $worker;


    /**
     * 调试模式
     * @var bool
     */
    private bool $debug = false;

    /**
     * 任务进程池
     * @var Crontab[] array
     */
    private array $crontabPool = [];

    /**
     * 定时任务表
     * @var string
     */
    private string $crontabTable;

    /**
     * 定时任务日志表
     * @var string
     */
    private string $crontabLogTable;

    public function __construct()
    {
    }


    public function onWorkerStart($worker)
    {
        $config                = config('plugin.xianrenqh.task.app.task');
        $this->debug           = $config['debug'];
        $this->crontabTable    = $config['crontab_table'];
        $this->crontabLogTable = $config['crontab_table_log'];
        $this->worker          = $worker;

        $this->checkCrontabTables();
        $this->crontabInit();
    }

    /**
     * 当客户端与Workman建立连接时(TCP三次握手完成后)触发的回调函数
     * 每个连接只会触发一次onConnect回调
     * 此时客户端还没有发来任何数据
     * 由于udp是无连接的，所以当使用udp时不会触发onConnect回调，也不会触发onClose回调
     * @param TcpConnection $connection
     */
    public function onConnect(TcpConnection $connection)
    {
        $this->checkCrontabTables();
    }


    public function onMessage(TcpConnection $connection, $data)
    {
        $data   = json_decode($data, true);
        $method = $data['method'];
        $args   = $data['args'];
        $connection->send(call_user_func([$this, $method], $args));
    }


    /**
     * 定时器列表
     * @param array $data
     * @return false|string
     */
    private function crontabIndex(array $data)
    {
        $limit = $data['limit'] ?? 15;
        $page  = $data['page'] ?? 1;
        $where = $data['where'] ?? [];
        $data  = Db::table($this->crontabTable)
            ->where($where)
            ->order('id', 'desc')
            ->paginate(($page - 1) * $limit);

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => $data]);
    }

    /**
     * 初始化定时任务
     * @return void
     */
    private function crontabInit(): void
    {
        $ids = Db::table($this->crontabTable)
            ->where('status', self::NORMAL_STATUS)
            ->order('sort', 'desc')
            ->column('id');
        if (!empty($ids)) {
            foreach ($ids as $id) {
                $this->crontabRun($id);
            }
        }
    }

    /**
     * 创建定时器
     * @param $id
     */
    private function crontabRun($id)
    {
        $data = Db::table($this->crontabTable)
            ->where('id', $id)
            ->where('status', self::NORMAL_STATUS)
            ->find();

        if (!empty($data)) {
            switch ($data['type']) {
                case self::COMMAND_CRONTAB:
                    $this->crontabPool[$data['id']] = [
                        'id'          => $data['id'],
                        'target'      => $data['target'],
                        'rule'        => $data['rule'],
                        'parameter'   => $data['parameter'],
                        'singleton'   => $data['singleton'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'crontab'     => new Crontab($data['rule'], function () use ($data) {
                            $time      = time();
                            $parameter = $data['parameter'] ?: '{}';
                            $startTime = microtime(true);
                            $code      = 0;
                            $result    = true;
                            try {
                                if (strpos($data['target'], 'php webman') !== false) {
                                    $command = $data['target'];
                                } else {
                                    $command = "php webman " . $data['target'];
                                }
                                $exception = shell_exec($command);
                            } catch (\Throwable $e) {
                                $result    = false;
                                $code      = 1;
                                $exception = $e->getMessage();
                            }

                            $this->runInSingleton($data);

                            $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);
                            $endTime = microtime(true);
                            Db::query("UPDATE {$this->crontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                            $this->crontabRunLog([
                                'crontab_id'   => $data['id'],
                                'target'       => $data['target'],
                                'parameter'    => $parameter,
                                'exception'    => $exception,
                                'return_code'  => $code,
                                'running_time' => round($endTime - $startTime, 6),
                                'create_time'  => $time,
                                'update_time'  => $time,
                            ]);

                        })
                    ];
                    break;
                case self::CLASS_CRONTAB:
                    $this->crontabPool[$data['id']] = [
                        'id'          => $data['id'],
                        'target'      => $data['target'],
                        'rule'        => $data['rule'],
                        'parameter'   => $data['parameter'],
                        'singleton'   => $data['singleton'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'crontab'     => new Crontab($data['rule'], function () use ($data) {
                            $time  = time();
                            $class = trim($data['target']);
                            //$method     = 'execute';
                            $parameters = $data['parameter'] ?: null;
                            $startTime  = microtime(true);
                            if ($class) {
                                $class  = explode('\\', $class);
                                $method = end($class);
                                array_pop($class);
                                $class = implode('\\', $class);
                                if (class_exists($class) && method_exists($class, $method)) {
                                    try {
                                        $result   = true;
                                        $code     = 0;
                                        $instance = Container::get($class);
                                        if ($parameters && is_array($parameters)) {
                                            $res = $instance->{$method}(...$parameters);
                                        } else {
                                            $res = $instance->{$method}();
                                        }
                                    } catch (\Throwable $throwable) {
                                        $result = false;
                                        $code   = 1;
                                    }
                                    $exception = isset($throwable) ? $throwable->getMessage() : $res;
                                }
                            }

                            $this->runInSingleton($data);
                            $this->debug && $this->writeln('执行定时器任务#'.$data['id'].' '.$data['rule'].' '.$data['target'],
                                $result);
                            $endTime = microtime(true);
                            Db::query("UPDATE {$this->crontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                            $this->crontabRunLog([
                                'crontab_id'   => $data['id'],
                                'target'       => $data['target'],
                                'parameter'    => $parameters ?? '',
                                'exception'    => $exception ?? '',
                                'return_code'  => $code,
                                'running_time' => round($endTime - $startTime, 6),
                                'create_time'  => $time,
                                'update_time'  => $time,
                            ]);

                        })
                    ];
                    break;
                case self::URL_CRONTAB:
                    $this->crontabPool[$data['id']] = [
                        'id'          => $data['id'],
                        'target'      => $data['target'],
                        'rule'        => $data['rule'],
                        'parameter'   => $data['parameter'],
                        'singleton'   => $data['singleton'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'crontab'     => new Crontab($data['rule'], function () use ($data) {
                            $time      = time();
                            $url       = trim($data['target']);
                            $startTime = microtime(true);
                            $client    = new \GuzzleHttp\Client();
                            try {
                                $response = $client->get($url);
                                $result   = $response->getStatusCode() === 200;
                                $code     = 0;
                            } catch (\Throwable $throwable) {
                                $result    = false;
                                $code      = 1;
                                $exception = $throwable->getMessage();
                            }

                            $this->runInSingleton($data);

                            $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);
                            $endTime = microtime(true);
                            Db::query("UPDATE {$this->crontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                            $this->crontabRunLog([
                                'crontab_id'   => $data['id'],
                                'target'       => $data['target'],
                                'parameter'    => $data['parameter'],
                                'exception'    => $exception ?? '',
                                'return_code'  => $code,
                                'running_time' => round($endTime - $startTime, 6),
                                'create_time'  => $time,
                                'update_time'  => $time,
                            ]);

                        })
                    ];
                    break;
                case self::SHELL_CRONTAB:
                    $this->crontabPool[$data['id']] = [
                        'id'          => $data['id'],
                        'target'      => $data['target'],
                        'rule'        => $data['rule'],
                        'parameter'   => $data['parameter'],
                        'singleton'   => $data['singleton'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'crontab'     => new Crontab($data['rule'], function () use ($data) {
                            $time      = time();
                            $parameter = $data['parameter'] ?: '';
                            $startTime = microtime(true);
                            $code      = 0;
                            $result    = true;
                            try {
                                $exception = shell_exec($data['target']);
                            } catch (\Throwable $e) {
                                $result    = false;
                                $code      = 1;
                                $exception = $e->getMessage();
                            }

                            $this->runInSingleton($data);

                            $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);
                            $endTime = microtime(true);
                            Db::query("UPDATE {$this->crontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                            $this->crontabRunLog([
                                'crontab_id'   => $data['id'],
                                'target'       => $data['target'],
                                'parameter'    => $parameter,
                                'exception'    => $exception,
                                'return_code'  => $code,
                                'running_time' => round($endTime - $startTime, 6),
                                'create_time'  => $time,
                                'update_time'  => $time,
                            ]);

                        })
                    ];
                    break;
                case self::EVAL_CRONTAB:
                    $this->crontabPool[$data['id']] = [
                        'id'          => $data['id'],
                        'target'      => $data['target'],
                        'rule'        => $data['rule'],
                        'parameter'   => $data['parameter'],
                        'singleton'   => $data['singleton'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'crontab'     => new Crontab($data['rule'], function () use ($data) {
                            $time      = time();
                            $startTime = microtime(true);
                            $result    = true;
                            $code      = 0;
                            try {
                                eval($data['target']);
                            } catch (\Throwable $throwable) {
                                $result    = false;
                                $code      = 1;
                                $exception = $throwable->getMessage();
                            }

                            $this->runInSingleton($data);

                            $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);
                            $endTime = microtime(true);
                            Db::query("UPDATE {$this->crontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                            $this->crontabRunLog([
                                'crontab_id'   => $data['id'],
                                'target'       => $data['target'],
                                'parameter'    => $data['parameter'],
                                'exception'    => $exception ?? '',
                                'return_code'  => $code,
                                'running_time' => round($endTime - $startTime, 6),
                                'create_time'  => $time,
                                'update_time'  => $time,
                            ]);

                        })
                    ];
                    break;
            }
        }
    }


    /**
     * 是否单次
     * @param $crontab
     * @return void
     */
    private function runInSingleton($crontab)
    {
        if ($crontab['singleton'] == 0) {
            $this->crontabPool[$crontab['id']]['crontab']->destroy();
        }
    }

    /**
     * 记录执行日志
     * @param array $param
     * @return void
     */
    private function crontabRunLog(array $param): void
    {
        Db::table($this->crontabLogTable)->insert($param);
    }

    /**
     * 创建定时任务
     * @param array $param
     * @return string
     */
    private function crontabCreate(array $param): string
    {
        $param['create_time'] = $param['update_time'] = time();
        $id                   = Db::table($this->crontabTable)
            ->insertGetId($param);
        $id && $this->crontabRun($id);

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$id]]);
    }

    /**
     * 修改定时器
     * @param array $param
     * @return string
     */
    private function crontabUpdate(array $param): string
    {
        $row = Db::table($this->crontabTable)
            ->where('id', $param['id'])
            ->update($param);

        if (isset($this->crontabPool[$param['id']])) {
            $this->crontabPool[$param['id']]['crontab']->destroy();
            unset($this->crontabPool[$param['id']]);
        }
        if ($param['status'] == self::NORMAL_STATUS) {
            $this->crontabRun($param['id']);
        }

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$row]]);

    }


    /**
     * 清除定时任务
     * @param array $param
     * @return string
     */
    private function crontabDelete(array $param): string
    {
        if ($id = $param['id']) {
            $ids = explode(',', (string)$id);

            foreach ($ids as $item) {
                if (isset($this->crontabPool[$item])) {
                    $this->crontabPool[$item]['crontab']->destroy();
                    unset($this->crontabPool[$item]);
                }
            }

            $rows = Db::table($this->crontabTable)
                ->where('id in (' . $id . ')')
                ->delete();

            return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$rows]]);
        }

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => true]]);
    }

    /**
     * 重启定时任务
     * @param array $param
     * @return string
     */
    private function crontabReload(array $param): string
    {
        $ids = explode(',', (string)$param['id']);

        foreach ($ids as $id) {
            if (isset($this->crontabPool[$id])) {
                $this->crontabPool[$id]['crontab']->destroy();
                unset($this->crontabPool[$id]);
            }
            Db::table($this->crontabTable)
                ->where('id', $id)
                ->update(['status' => self::NORMAL_STATUS]);
            $this->crontabRun($id);
        }

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => true]]);
    }


    /**
     * 执行日志列表
     * @param array $param
     * @return string
     */
    private function crontabLog(array $param): string
    {
        $where = $param['where'] ?? [];
        $limit = $param['limit'] ?? 15;
        $page  = $param['page'] ?? 1;
        $param['crontab_id'] && $where[] = ['crontab_id', '=', $param['crontab_id']];

        $data = Db::table($this->crontabLogTable)
            ->where($where)
            ->order('id', 'desc')
            ->paginate(($page - 1) * $limit);

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => $data]);
    }

    /**
     * 输出日志
     * @param $msg
     * @param bool $isSuccess
     */
    private function writeln($msg, bool $isSuccess)
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . ($isSuccess ? " [Ok] " : " [Fail] ") . PHP_EOL;
    }

    /**
     * 检测表是否存在
     */
    private function checkCrontabTables()
    {
        $allTables = $this->getDbTables();
        !in_array($this->crontabTable, $allTables) && $this->createCrontabTable();
        !in_array($this->crontabLogTable, $allTables) && $this->createCrontabLogTable();
    }

    /**
     * 获取数据库表名
     * @return array
     */
    private function getDbTables(): array
    {
        return Db::getTables();
    }

    /**
     * 创建定时器任务表
     */
    private function createCrontabTable()
    {
        $sql = <<<SQL
 CREATE TABLE `system_crontab` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '任务标题',
  `type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '任务类型 (1 command, 2 class, 3 url, 4 eval)',
  `rule` varchar(100) NOT NULL DEFAULT '' COMMENT '任务执行表达式',
  `target` varchar(150) NOT NULL DEFAULT '' COMMENT '调用任务字符串',
  `parameter` varchar(500) NOT NULL DEFAULT '' COMMENT '任务调用参数',
  `running_times` int(11) NOT NULL DEFAULT '0' COMMENT '已运行次数',
  `last_running_time` int(11) NOT NULL DEFAULT '0' COMMENT '上次运行时间',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序，越大越前',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '任务状态状态[0:禁用;1启用]',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  `singleton` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否单次执行 (0 是 1 不是)',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `title` (`title`) USING BTREE,
  KEY `create_time` (`create_time`) USING BTREE,
  KEY `status` (`status`) USING BTREE,
  KEY `type` (`type`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC COMMENT='定时器任务表';
SQL;

        return Db::query($sql);
    }

    /**
     * 定时器任务流水表
     */
    private function createCrontabLogTable()
    {
        $sql = <<<SQL
CREATE TABLE `system_crontab_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `crontab_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '任务id',
  `target` varchar(255) NOT NULL DEFAULT '' COMMENT '任务调用目标字符串',
  `parameter` varchar(500) NOT NULL DEFAULT '' COMMENT '任务调用参数',
  `exception` text NOT NULL COMMENT '任务执行或者异常信息输出',
  `return_code` tinyint(1) NOT NULL DEFAULT '0' COMMENT '执行返回状态[0成功; 1失败]',
  `running_time` varchar(10) NOT NULL DEFAULT '' COMMENT '执行所用时间',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `create_time` (`create_time`) USING BTREE,
  KEY `crontab_id` (`crontab_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC COMMENT='定时器任务执行日志表';
SQL;

        return Db::query($sql);
    }

}
