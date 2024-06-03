<?php
return [
    'enable' => false,
    'task'   => [
        'listen'            => '127.0.0.1:22345',
        'crontab_table'     => 'system_crontab', //任务计划表
        'crontab_table_log' => 'system_crontab_log',//任务计划流水表
        'debug'             => true, //控制台输出日志
        'write_log'         => true, // 任务计划日志
    ],
];
