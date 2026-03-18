<?php

//配置文件
return [
	'view_replace_str' => [
		'__HOME__'   => '/static/public/index',
		'__PAY__'   => '/static/public/pay',
		'__QDIMG__'   => '/uploads/'
	],
	
    // 默认模块名
    'default_module'         => 'pay',
    // 禁止访问模块
    'deny_module_list'       => ['common'],
    // 默认控制器名
    'default_controller'     => 'pay',
    // 默认操作名
    'default_action'         => 'pay',
    // 默认验证器
    'default_validate'       => '',
    // 默认的空控制器名
    'empty_controller'       => 'Error',
    // 操作方法后缀
    'action_suffix'          => '',
    // 自动搜索控制器
    'controller_auto_search' => false,
];