<?php
namespace app\pay\controller;

use think\Db;
use think\Cache;
use think\Controller;

/**
 * 支付控制器
 * 
 * ===== 性能优化说明 =====
 * 1. 已优化 land_1() 方法的随机选择逻辑，避免使用 ORDER BY RAND()
 * 2. 已优化批量查询，减少数据库查询次数
 * 3. 已优化批量更新，使用单条SQL替代循环更新
 * 4. 已添加缓存机制（商户信息、配置信息）
 * 
 * ===== 数据库索引建议（重要！） =====
 * 为了保证高性能，请确保以下数据库索引已创建：
 * 
 * -- orders 表索引
 * ALTER TABLE `orders` ADD INDEX `idx_land_userid_state` (`land_id`, `userid`, `state`);
 * ALTER TABLE `orders` ADD INDEX `idx_userid_state_paytime` (`userid`, `state`, `pay_time`);
 * ALTER TABLE `orders` ADD INDEX `idx_num` (`num`);
 * ALTER TABLE `orders` ADD INDEX `idx_syorder` (`syorder`);
 * ALTER TABLE `orders` ADD INDEX `idx_state_ordertime` (`state`, `order_time`);
 * 
 * -- land 表索引
 * ALTER TABLE `land` ADD INDEX `idx_userid_del_ban` (`userid`, `del`, `ban`, `land_lx`);
 * ALTER TABLE `land` ADD INDEX `idx_typec` (`typec`);
 * ALTER TABLE `land` ADD INDEX `idx_mid` (`mid`);
 * 
 * -- user 表索引
 * ALTER TABLE `user` ADD INDEX `idx_api_id` (`api_id`);
 * ALTER TABLE `user` ADD INDEX `idx_parent_id_cate` (`parent_id`, `cate_id`);
 * 
 * -- game_list 表索引
 * ALTER TABLE `game_list` ADD INDEX `idx_id_status` (`id`, `game_status`);
 */
class Pay extends Commone {
	// 类属性：缓存game_id配置（避免每次请求都调用config）
	private static $allowed_game_ids = null;
	
	/**
	 * 获取允许的游戏ID列表（动态获取所有可用通道）
	 */
	private function getAllowedGameIds() {
		if (self::$allowed_game_ids === null) {
			self::$allowed_game_ids = Db::name('game_list')->where('game_status', 1)->column('id');
		}
		return self::$allowed_game_ids;
	}
	
	public function pay() {
		// 系统配置缓存（缓存60秒）
		$config_set_status = $this->getCachedConfig('pay_no_status');
		$config_set_msg = $this->getCachedConfig('pay_no_msg');
		if ($config_set_status['value'] == '2') {
			msgJson('', 1000, $config_set_msg['value']);
		}
		
		$orderid = $this->request->param('orderid');
		if(!$orderid) {
			$info = trim(xss($this->request->param('record')));
			if (empty($info)) {
				msgJson('', 1001, 'record（附加信息：如商户网站的订单号或用户名）参数错误');
			}
			//充值金额
			$money_index = floatval($this->request->param('money'));
			if ($money_index <= 0) {
				msgJson('', 1002, 'money（金额）参数错误');
			}
			//api_id
			$api_id = xss($this->request->param('api_id'));
			if (empty($api_id)) {
				msgJson('', 1003, '商户ID参数不可为空');
			}
			//refer来源
			$refer = urlencode($this->request->param('refer'));
			if (empty($refer)) {
				msgJson('', 1004, '订单错误,来源不明');
			}
		//notify_url 异步通知地址
		$notify_url = $this->request->param('notify_url');
		if (empty($notify_url)) {
			msgJson('', 1005, '回调通知地址不可为空');
		}
		// 接收客户端IP参数（可选）
		$client_ip = xss($this->request->param('ip'));
		// 商户信息缓存（缓存5分钟）
		$user = $this->getCachedUser($api_id);
		if (!$user) {
			msgJson('', 1006, '商户ID不存在');
		}
		$mid = $this->request->param('mid');
		$paytype = $this->request->param('paytype');
		if(empty($paytype)) {
			msgJson('', 1009, 'paytype（支付方式）参数不可为空，alipay=支付宝，wxpay=微信');
		}
		if(!in_array($paytype, ['alipay', 'wxpay'])) {
			msgJson('', 1010, 'paytype（支付方式）参数值无效，仅支持 alipay 或 wxpay');
		}
		// 接收指定通道代码参数（可选）
		$channel = xss($this->request->param('channel'));
		// 接收通道分类ID参数（可选，channel 优先级更高）
		$type_id = intval($this->request->param('type_id'));
		// 接收常用通道参数（可选，game_my=1 时仅使用商户收藏通道）
		$game_my = intval($this->request->param('game_my'));
			$sign = $this->request->param('sign');
			//验证签名
			$sign_data = ['api_id' => $api_id, 'record' => $info, 'money' => sprintf("%.2f", $money_index)];
			$sign_index = md5_sign($sign_data, $user['api_key']);
			$lx_land = '';
			if ($sign != $sign_index){
			    msgJson('', 1007, '签名错误');
			}
			
			if ($user['balance'] < $money_index * ($user['pay_sxf']/100)) {
				msgJson('', 1008, '商户余额不足');
		}
		$land = $this->land_1($user['id'],$money_index,$mid,$channel,$user,$type_id,$game_my);
		if ($land == '-1') {
			msgJson('', 1016, '暂无账户可用,请稍后再试');
		}
		// 通道信息实时查询（优化：去除冗余的IN条件，只用精确查询）
		$game_data = Db::name('game_list')->where('id',$land['typec'])->find();
		if (!$game_data) {
			msgJson('', 1014, '通道不存在');
		}
		// 验证通道ID是否在允许的范围内（移到查询后验证，更高效）
		if (!in_array($game_data['id'], $this->getAllowedGameIds(), true)) {
			msgJson('', 1014, '通道不存在');
		}
			if ($game_data['game_status'] == 0) {
				msgJson('', 1015, '通道已关闭');
			}
		$order_num = date("YmdHis") . time() . mt_rand(10000, 99999);
		//订单号 29 位
		$game_dm = $game_data['game_dm'] . '_pay';
		if($game_dm=='alipay_dmf_pay') {
			$json = json_decode($land['json'], true);
			$json['order_num'] = $order_num;
			$land['json'] = json_encode($json);
		}
		$pdata = json_decode($land['json'], true);
		$pdata['orderid'] = $order_num;

		// 确定核销信息：商户下单选中了核销名下的账号 → 记录核销ID和名称；商户自己的账号 → mash_id=0
		$mash_id_ins = 0;
		$mash_name_ins = '';
		if (intval($land['userid']) !== intval($user['id'])) {
			$mash_user = Db::name('user')->where('id', $land['userid'])->field('id,name')->find();
			if ($mash_user) {
				$mash_id_ins   = intval($mash_user['id']);
				$mash_name_ins = strval($mash_user['name']);
			}
		}
		// 异步下单：先写占位订单，推 Redis 队列，立即跳转等待页
		$order_time = time();
		$placeholder_order = [
			'order_id'      => '',
			'order_url'     => '0',
			'order_message' => 'ok',
			'alipay_url'    => '',
			'order_mb'      => 'waiting',
			'hy_sign'       => '',
			'hy_time'       => '',
			'game_dm'       => $game_dm,
			'refer'         => urldecode($refer),
		];
		$hy_json = json_encode(['hy_sign' => '', 'hy_time' => '']);
	$insert_order = Db::name('orders')->insert([
		'land_id'          => $land['id'],
		'land_name'        => $land['username'],
		'mash_id'          => $mash_id_ins,
		'mash_name'        => $mash_name_ins,
		'userid'           => $user['id'],
		'num'              => $order_num,
		'money'            => $money_index,
		'remark'           => $info,
		'payc'             => $game_data['id'],
		'type'             => $paytype,
		'client_info'      => getClientInfo($client_ip),
		'client_ip'        => getClientIp(),
		'pay_time'         => 0,
		'state'            => 1,
		'check_state'      => 1,
		'check_state_mash' => 1,
		'order_time'       => $order_time,
		'notify_url'       => $notify_url,
		'syorder'          => '',
		'hy_json'          => $hy_json,
		'order_url'        => json_encode($placeholder_order),
	]);
	if (!$insert_order) {
		limitRollback($land['id'], $money_index, time());
		msgJson('', 1017, '平台订单创建失败');
		}
		// 推入 Redis 队列，由 Workerman Worker 异步调用上游（携带 client_ip 供 CLI 下 pay() 使用）
		$task = json_encode([
			'order_num'    => $order_num,
			'game_dm'      => $game_data['game_dm'],
			'pdata'        => $pdata,
			'money'        => $money_index,
			'paytype'      => $paytype,
			'land_id'      => $land['id'],
			'userid'       => $user['id'],
			'game_dm_full' => $game_dm,
			'client_ip'    => getClientIp(),
		]);
		try {
			$redis = new \Redis();
			$redis->connect('127.0.0.1', 6379);
			$pushed = $redis->lPush('pay_async_queue', $task);
			if (!$pushed) {
				// lPush 返回 false，记录日志但不阻断流程（等待页会轮询，Worker 兜底）
				\app\sever\controller\Words::log_daily('pay_task', "[pay][{$order_num}] lPush 返回 false，队列可能异常");
			}
		} catch (\Exception $e) {
			\app\sever\controller\Words::log_daily('pay_task', "[pay][{$order_num}] Redis推队列异常: " . $e->getMessage());
		}
		$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
		header('location:' . $protocol . $_SERVER['HTTP_HOST'] . '/?orderid=' . $order_num);
		exit;
	} else {
		$where_order['num'] = $orderid;
		$where_order['state'] = 1;
		$order_find = Db::name('orders')->where($where_order)->find();
		if(!$order_find) {
			msgJson('', 1019, '订单异常,请联系客服');
		} else {
			$sy_order = json_decode($order_find['order_url'],true);
			$game_dm = $sy_order['game_dm'];
			$order_num = $order_find['num'];
			$sy = $order_find['syorder'];
			$refer = $sy_order['refer'];
			$order_time = $order_find['order_time'];
			$money_index = $order_find['money'];
		}

			$muban = $sy_order['order_mb'];
			$data = array('money' => $money_index, 'order_num' => $order_num, 'order_time' => date('Y-m-d H:i:s',$order_time), 'refer' => urldecode($refer), 'order_sy' => $sy_order['order_id'], 'order_url' => $sy_order['order_url'], 'alipay_url' => $sy_order['alipay_url'], 'order_mb' => $sy_order['order_mb']);
			$pay_msg_set = config_set('pay_msg_set');
			$order_time_set = config_set('order_time_set');
			$this->assign('pay_smg_set',$pay_msg_set['value']);
			$this->assign('order_time_set',$order_time_set['value']);
			$this->assign('data', $data);

			// 订单仍在等待异步拉单（order_mb='waiting' 且 order_url='0'）
			if ($sy_order['order_mb'] === 'waiting' && $sy_order['order_url'] === '0') {
				return view('waiting');
			}
			if ($sy_order['order_mb'] === 'js_alipay') {
				return view('waiting');
			}
			// order_url='-1' 表示异步拉单失败
			if ($sy_order['order_url'] === '-1') {
				msgJson('', 1018, '上游订单创建失败，请重试');
			}

		if($sy_order['order_mb'] != 'wangyi'){
			return view($muban);
        }else{
	            
	            if($sy_order['order_url']!="0" && $sy_order['order_url']!="-1"){
	                if(strpos($sy_order['order_url'], 'form action')>0 || strpos($sy_order['order_url'], '<html') !== false){
	                    echo $sy_order['order_url'];
	                }else if(strpos($sy_order['order_url'], 'qr.alipay.com')>0){
						header('location: '.$sy_order['order_url']);
	                }else{
                        // $url = $sy_order['order_url'];
                        // if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
                        //     if(is_weixin()==2){
                	       //     header("Location: {$url}");
                	       // }else{
                	       //     return view('weixin_h5');
                	       // }
                        // }
                        
                        
	                    $url = $sy_order['order_url'];
                        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
                            
                            // 判断是否是网易支付宝支付回调URL
                            $is_netease_callback = false;
                            if (strpos($url, 'matrix.netease.com') !== false) {
                                $is_netease_callback = true;
                            }
                            if ($is_netease_callback) {
								if($order_find['type'] == 'alipay'){
									$redirect_url = $this->getRedirectUrl($url);
                                    header("Location: {$redirect_url}");
								}else{
                                    if (is_weixin() == 2) {
										$redirect_url = $this->getRedirectUrl($url);
                                        header("Location: {$redirect_url}");
                                    } else {
                                            return view('weixin_h5');
                                    }
								}
                            }else{
                                header('location: '.$sy_order['order_url']);
                            }
                        }else if (strpos($url, 'weixin://') === 0) {
                            return view('weixin_h5'); // 或者你自己的模板渲染方法
                        }else{
                            header('location: '.$sy_order['order_url']);
                        }
	                }
	            }else{
	                return view('wangyi');
	            }
	        }
		}
	}
	
	/**
	 * 下单接口（返回JSON格式）
	 * 地址：/pay/create
	 * 返回订单号和支付链接，不直接跳转
	 */
	public function create() {
		// 设置返回JSON格式
		header('Content-Type: application/json; charset=utf-8');
		
		// 系统配置缓存（缓存60秒）
		$config_set_status = $this->getCachedConfig('pay_no_status');
		$config_set_msg = $this->getCachedConfig('pay_no_msg');
		if ($config_set_status['value'] == '2') {
			return json(['code' => 0, 'msg' => $config_set_msg['value']]);
		}
		
		$info = trim(xss($this->request->param('record')));
		if (empty($info)) {
			return json(['code' => 0, 'msg' => 'record（附加信息：如商户网站的订单号或用户名）参数错误']);
		}
		//充值金额
		$money_index = floatval($this->request->param('money'));
		if ($money_index <= 0) {
			return json(['code' => 0, 'msg' => 'money（金额）参数错误']);
		}
		//api_id
		$api_id = xss($this->request->param('api_id'));
		if (empty($api_id)) {
			return json(['code' => 0, 'msg' => '商户ID参数不可为空']);
		}
		//refer来源
		$refer = urlencode($this->request->param('refer'));
		if (empty($refer)) {
			return json(['code' => 0, 'msg' => '订单错误,来源不明']);
		}
		//notify_url 异步通知地址
		$notify_url = $this->request->param('notify_url');
		if (empty($notify_url)) {
			return json(['code' => 0, 'msg' => '回调通知地址不可为空']);
		}
		// 接收客户端IP参数（可选）
		$client_ip = xss($this->request->param('ip'));
		// 商户信息缓存（缓存5分钟）
		$user = $this->getCachedUser($api_id);
		if (!$user) {
			return json(['code' => 0, 'msg' => '商户ID不存在']);
		}
	$mid = $this->request->param('mid');
	$paytype = $this->request->param('paytype');
	if(empty($paytype)) {
		return json(['code' => 0, 'msg' => 'paytype（支付方式）参数不可为空，alipay=支付宝，wxpay=微信']);
	}
	if(!in_array($paytype, ['alipay', 'wxpay'])) {
		return json(['code' => 0, 'msg' => 'paytype（支付方式）参数值无效，仅支持 alipay 或 wxpay']);
	}
	// 接收指定通道代码参数（可选）
	$channel = xss($this->request->param('channel'));
	// 接收通道分类ID参数（可选，channel 优先级更高）
	$type_id = intval($this->request->param('type_id'));
	// 接收常用通道参数（可选，game_my=1 时仅使用商户收藏通道）
	$game_my = intval($this->request->param('game_my'));
	$sign = $this->request->param('sign');
	//验证签名
	$sign_data = ['api_id' => $api_id, 'record' => $info, 'money' => sprintf("%.2f", $money_index)];
	$sign_index = md5_sign($sign_data, $user['api_key']);
	if ($sign != $sign_index){
		return json(['code' => 0, 'msg' => '签名错误']);
	}
	
	if ($user['balance'] < $money_index * ($user['pay_sxf']/100)) {
		return json(['code' => 0, 'msg' => '商户余额不足']);
	}
	$land = $this->land_1($user['id'],$money_index,$mid,$channel,$user,$type_id,$game_my);
	if ($land == '-1') {
		return json(['code' => 0, 'msg' => '暂无账户可用,请稍后再试']);
	}
	// 通道信息实时查询（优化：去除冗余的IN条件，只用精确查询）
	$game_data = Db::name('game_list')->where('id',$land['typec'])->find();
	if (!$game_data) {
		return json(['code' => 0, 'msg' => '通道不存在']);
	}
	// 验证通道ID是否在允许的范围内（移到查询后验证，更高效）
	if (!in_array($game_data['id'], $this->getAllowedGameIds(), true)) {
		return json(['code' => 0, 'msg' => '通道不存在']);
	}
		if ($game_data['game_status'] == 0) {
			return json(['code' => 0, 'msg' => '通道已关闭']);
		}
	$order_num = date("YmdHis") . time() . mt_rand(10000, 99999);
	//订单号 29 位
	$game_dm = $game_data['game_dm'] . '_pay';
	if($game_dm=='alipay_dmf_pay') {
		$json = json_decode($land['json'], true);
		$json['order_num'] = $order_num;
		$land['json'] = json_encode($json);
	}
	$pdata = json_decode($land['json'], true);
	$pdata['orderid'] = $order_num;

	// 确定核销信息：商户下单选中了核销名下的账号 → 记录核销ID和名称；商户自己的账号 → mash_id=0
	$mash_id_ins = 0;
	$mash_name_ins = '';
	if (intval($land['userid']) !== intval($user['id'])) {
		$mash_user = Db::name('user')->where('id', $land['userid'])->field('id,name')->find();
		if ($mash_user) {
			$mash_id_ins   = intval($mash_user['id']);
			$mash_name_ins = strval($mash_user['name']);
		}
	}
	// 异步下单：先写占位订单，推 Redis 队列，立即返回
	$order_time = time();
	$placeholder_order = [
		'order_id'      => '',
		'order_url'     => '0',
		'order_message' => 'ok',
		'alipay_url'    => '',
		'order_mb'      => 'waiting',
		'hy_sign'       => '',
		'hy_time'       => '',
		'game_dm'       => $game_dm,
		'refer'         => urldecode($refer),
	];
	$hy_json = json_encode(['hy_sign' => '', 'hy_time' => '']);
	$insert_order = Db::name('orders')->insert([
		'land_id'          => $land['id'],
		'land_name'        => $land['username'],
		'mash_id'          => $mash_id_ins,
		'mash_name'        => $mash_name_ins,
		'userid'           => $user['id'],
		'num'              => $order_num,
		'money'            => $money_index,
		'remark'           => $info,
		'payc'             => $game_data['id'],
		'type'             => $paytype,
		'client_info'      => getClientInfo($client_ip),
		'client_ip'        => getClientIp(),
		'pay_time'         => 0,
		'state'            => 1,
		'check_state'      => 1,
		'check_state_mash' => 1,
		'order_time'       => $order_time,
		'notify_url'       => $notify_url,
		'syorder'          => '',
		'hy_json'          => $hy_json,
		'order_url'        => json_encode($placeholder_order),
	]);
	if (!$insert_order) {
		limitRollback($land['id'], $money_index, time());
		return json(['code' => 0, 'msg' => '平台订单创建失败']);
	}
	// 推入 Redis 队列，由 Workerman Worker 异步调用上游（携带 client_ip 供 CLI 下 pay() 使用）
	$task = json_encode([
		'order_num'    => $order_num,
		'game_dm'      => $game_data['game_dm'],
		'pdata'        => $pdata,
		'money'        => $money_index,
		'paytype'      => $paytype,
		'land_id'      => $land['id'],
		'userid'       => $user['id'],
		'game_dm_full' => $game_dm,
		'client_ip'    => getClientIp(),
	]);
	try {
		$redis = new \Redis();
		$redis->connect('127.0.0.1', 6379);
		$pushed = $redis->lPush('pay_async_queue', $task);
		if (!$pushed) {
			\app\sever\controller\Words::log_daily('pay_task', "[create][{$order_num}] lPush 返回 false，队列可能异常");
		}
	} catch (\Exception $e) {
		\app\sever\controller\Words::log_daily('pay_task', "[create][{$order_num}] Redis推队列异常: " . $e->getMessage());
	}
	$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
	$host = $_SERVER['HTTP_HOST'];
	$pay_url = $protocol . $host . '/?orderid=' . $order_num;
	return json([
		'code' => 1,
		'msg'  => '下单成功',
		'data' => [
			'order_num'  => $order_num,
			'pay_url'    => $pay_url,
			'order_time' => date('Y-m-d H:i:s', $order_time),
		],
	]);
}

public function orderc() {
	    $num = $this->request->param('num');
	    if (empty($num)){
		    json_msg(-1, '平台订单号错误');
		}
	    $order = Db::name('orders')->where(['num'=>$num])->find();

	    if (!$order){
		    json_msg(1001, '订单已被销毁');
		}
		$sy_order = json_decode($order['order_url'],true);
		$order_mb = isset($sy_order['order_mb']) ? $sy_order['order_mb'] : '';
	    if($sy_order['order_url']=="0"){
	        echo json_encode(['code' => 0, 'msg' => 'wait pay', 'order_mb' => $order_mb]);exit;
	    }else if($sy_order['order_url']=="-1"){
	        $fail_msg = !empty($sy_order['order_message']) ? $sy_order['order_message'] : '系统繁忙，请重新提交';
	        echo json_encode(['code' => 1001, 'msg' => $fail_msg, 'order_mb' => $order_mb]);exit;
	    }else{
	        echo json_encode(['code' => 200, 'msg' => $sy_order['order_url'], 'order_mb' => $order_mb]);exit;
	    }
	}
	
	public function orders() {
		$num = $this->request->param('num');
		//订单号
		$syorder = $this->request->param('syorder');
		//上游订单号
		if (empty($num)){
		    json_msg(-1, '平台订单号错误');
		}
		if (empty($syorder)){ 
		     json_msg(-1, '上游订单号错误');
		}
		
		$order_where['num'] = $num;
		$order_where['syorder'] = $syorder;
		$order = Db::name('orders')->where($order_where)->find();
		if (!$order || !is_array($order)){
		    json_msg(1001, '订单已被销毁');
		}
		$land_id = $order['land_id'];
		$land = Db::name('land')->where('id',$land_id)->find();
		if (!$land || !is_array($land)){
		    json_msg(1001, '账号信息不存在');
		}
		// 优化：去除冗余的IN条件
		$game_data = Db::name('game_list')->where('id',$land['typec'])->find();
		if (!$game_data || !is_array($game_data)){
		    json_msg(1001, '通道信息不存在');
		}
		// 验证通道ID是否在允许的范围内
		if (!in_array($game_data['id'], $this->getAllowedGameIds(), true)) {
			json_msg(1001, '通道信息不存在');
		}
		
		//结果为未支付才进行支付检查处理
		if($game_data['game_dm']!='alipay_dmf'){
		if ($order['state'] == 1) {
			if($land['typec']!='') {
			    
		    $cls = model("\\app\common\\game\\".ucfirst($game_data['game_dm']));
		    	$status_result = $cls->status(json_decode($land['json'], true),$order['syorder'],intval($order['money']));
		    	// 兼容新返回格式 ['paid'=>bool,'raw'=>string] 和旧返回格式 string
		    	if (is_array($status_result)) {
		    	    $syorder = $status_result['paid'] ? $order['syorder'] : '';
		    	} else {
		    	    $syorder = (string)$status_result;
		    	}
		    	
		//开始置订单
		if($syorder!='' && $syorder!=$num && $syorder!='-2') {
			//上游订单不可为空和上游订单不可和本平台订单相同，和本平台相同订单是支付宝当面付
			$paytime = time();
			$update = Db::name('orders')->where(['id'=>$order['id'],'state'=>1])->update(array('pay_time'=>$paytime,'state'=>2,'http'=>'还未请求'));
			if($update){
			    // 使用订单的userid（商户ID）来获取商户信息，因为手续费应该从商户余额中扣除
			    $user = Db::name('user')->where('id',$order['userid'])->find();
		        api_request($order['id'],$user);
			}
			json_msg(200, '支付成功');
		}else if($syorder=='-2'){
		    Db::name('orders')->where('id', $order['id'])->update(array("state"=>3));
		    limitRollback($order['land_id'], $order['money'], $order['order_time']);
		    json_msg(1002, '订单已经超时');
		}
			}
		}
		}

		if (!is_array($order)){
		    json_msg(1001, '订单已被销毁');
		}
		if ($order['state'] == 3){
		    json_msg(1002, '订单已经超时');
		}
		if ($order['state'] == 2){
		    json_msg(200, '支付成功');
	    }
		if ($order['state'] == 1){
		    json_msg(1003, '订单未支付');
		}
	}
	
	public function getpayback(){
	    $data = file_get_contents("php://input");
	    $data=json_decode($data,true);
	    if(isset($data['orderid'])){
	        $order=Db::name('orders')->where(['num'=>$data['orderid'],'state'=>1])->find();
	        if($order){
	            $sy_order = json_decode($order['order_url'],true);
	            if($sy_order['order_url']=='0' || $sy_order['order_url']=='-1'){
	                if($data['code']==1){
	                    if(isset($data['paytype']) && $data['paytype']==2){
	                        $data['payurl']=base64_decode($data['payurl']);
	                        $data['payurl']=json_decode($data['payurl'],true);
	                        $sy_order['order_url']=$data['payurl']['urldata'];
	                    }else{
	                        $sy_order['order_url']=$data['payurl'];
	                    }
        	        }else{
        	            $sy_order['order_url']='-1';
        	        }
        	        Db::name('orders')->where(['id'=>$order['id'],'state'=>1])->update(array('order_url'=>json_encode($sy_order)));
	            }
	            
	        }
	    }
	    echo '0';
	}
	
	public function getbillidback(){
	    $data = file_get_contents("php://input");
	    $data=json_decode($data,true);
	    if(isset($data['orderid'])){
	        $order=Db::name('orders')->where(['num'=>$data['orderid'],'state'=>1])->find();
	        if($order){
	            $billiddata=base64_decode($data['billiddata']);
	            $billiddata=json_decode($billiddata,true);
	            $sy_order = json_decode($order['order_url'],true);
	            if($sy_order['order_id']==''){
    	            $sy_order['order_id']=$billiddata['bill_id'];
        	        Db::name('orders')->where(['id'=>$order['id'],'state'=>1])->update(array('syorder'=>$billiddata['bill_id'],'order_url'=>json_encode($sy_order)));
	            }
	            
	        }
	    }
	    echo '0';
	}
	
	public function getbilllistback(){
	    $data = file_get_contents("php://input");
	    $data=json_decode($data,true);
	    if(isset($data['orderid'])){
	        $billlist=base64_decode($data['billlistdata']);
	        $billlist=json_decode($billlist,true);
	        if($billlist['bill_list']){
	            // 批量查询优化：收集所有syorder
	            $syorders = [];
	            foreach($billlist['bill_list'] as $key=>$val){
	                if(isset($val['id'])){
	                    $syorders[] = $val['id'];
	                }
	            }
	            
	            // 批量查询所有订单（从N次查询优化为1次）
	            $orders = [];
	            if(!empty($syorders)){
	                $orders_list = Db::name('orders')
	                    ->where('payc', 32)
	                    ->where('syorder', 'in', $syorders)
	                    ->where('state', 'neq', 2)
	                    ->select();
	                $orders = array_column($orders_list, null, 'syorder');
	            }
	            
	            // 批量查询所有用户信息（从N次查询优化为1次）
	            $user_ids = [];
	            $order_updates = [];
	            foreach($billlist['bill_list'] as $key=>$val){
	                if(isset($orders[$val['id']])){
	                    $order = $orders[$val['id']];
	                    $user_ids[] = $order['userid'];
	                    $order_updates[] = [
	                        'order' => $order,
	                        'syorder' => $val['id']
	                    ];
	                }
	            }
	            
	            $users = [];
	            if(!empty($user_ids)){
	                $user_ids = array_unique($user_ids);
	                $users_list = Db::name('user')->where('id', 'in', $user_ids)->select();
	                $users = array_column($users_list, null, 'id');
	            }
	            
            // 批量更新订单并处理回调
            foreach($order_updates as $item){
                $order = $item['order'];
                $paytime = time();
                $update = Db::name('orders')
                    ->where('id', $order['id'])
                    ->where('state', 'neq', 2)
                    ->update(array('pay_time'=>$paytime,'state'=>2,'http'=>'还未请求'));
                
                if($update && isset($users[$order['userid']])){
                    $user = $users[$order['userid']];
                    api_request($order['id'],$user);
                }
            }
	        }
	        
	        
	    }
	    echo '0';
	}
	
	public function paysuccess() {
	    echo 'success';
	}
	
	public function charges() {
		if($this->request->isPost()) {
		    $post = $this->request->post();
		    $user = Db::name('user')->where('api_id', $post['mchid'])->find();
		    if (!$user) {
    			msgJson('', 1006, '商户ID不存在');
    		}
    		// 核销用户不能使用收银台
    		if ($user['cate_id'] == 3) {
    			msgJson('', 1007, '核销账号无权使用收银台');
    		}
    		$money=$post['amount'];
    		if(intval($money)!=$money){
    		    msgJson('', 1001, '金额只能是整数');
    		}
    // 		if(($money % 5)!=0){
    // 		    msgJson('', 1002, '支付金额须为5的倍数');
    // 		}
    		$remarks = '';
    		if(isset($post['remarks']) && trim($post['remarks'])!=''){
    		    $remarks = '-'.trim($post['remarks']);
    		}
    		$api_id = $user['api_id'];
            $key = $user['api_key'];
		    $web_config = Db::name('config')->where('id',1)->find();
            $data = [
                    'api_id' => $api_id,
                    'record' => '收银台'.$remarks,
                    'money' => sprintf("%.2f",$money),
            ];
            ksort($data);
                $str1 = '';
                foreach ($data as $k => $v) {
                    $str1 .= '&' . $k . "=" . $v;
            }
		    $sign = md5(trim($str1) . $key);
            // 支付完成后跳转回来源页；HTTP_REFERER 为空时回退到商户平台会员中心
            $refer = isset($_SERVER['HTTP_REFERER']) ? trim($_SERVER['HTTP_REFERER']) : '';
            if (empty($refer) || !filter_var($refer, FILTER_VALIDATE_URL)) {
                $merchant_url = rtrim($web_config['url'] ?? '', '/');
                $refer = $merchant_url ? ($merchant_url . '/index/index/member.html') : ($this->request->domain() . url('index/index/member'));
            }
            $data['refer'] = $refer;
            // 支付网关回调必须使用完整绝对URL（domain=true），否则无法正确通知
            $data['notify_url'] = url('pay/pay/paysuccess', '', true, true);
            $data['sign'] = $sign;
            if(isset($post['mid']) && $post['mid'])$data['mid'] = $post['mid'];
            if(isset($post['bankcode']) && $post['bankcode'])$data['paytype'] = $post['bankcode'];
            if(isset($post['channel']) && $post['channel'])$data['channel'] = $post['channel'];
            if(isset($post['type_id']) && intval($post['type_id']) > 0)$data['type_id'] = intval($post['type_id']);
            if(isset($post['game_my']) && intval($post['game_my']) > 0)$data['game_my'] = 1;
            // 关键：收银台在哪个网关域名打开，就在该域名提交并展示收银台页面
            // 使用相对路径，避免 pay_url 是 JSON 数组时被拼进 URL
            // pay() 会返回/渲染 HTML（create() 只返回 JSON）
            $url = '/pay/pay';
            $htmls = "<form id='ckpay' name='ckpay' action='" . $url . "' method='post'>";
            foreach ($data as $key => $val) {
                 $htmls .= "<input type='hidden' name='" . $key . "' value='" . $val . "'/>";
            }
            $htmls .= "</form>";
            $htmls .= "<script>document.forms['ckpay'].submit();</script>";
            echo $htmls;
            //return $this->success($htmls);
		}else{
	    $pid = $this->request->get('pid');
    	    $channel = $this->request->get('channel');
    	    $type_id = intval($this->request->get('type_id'));
    	    $game_my = intval($this->request->get('game_my'));
    	    $user = Db::name('user')->where('api_id', $pid)->find();
    		if (!$user) {
    			msgJson('', 1006, '商户ID不存在');
    		}
    		// 核销用户不能使用收银台
    		if ($user['cate_id'] == 3) {
    			msgJson('', 1007, '核销账号无权使用收银台');
    		}
	        if(is_weixin()==2){
	            $products='[{"id":"1","name":"微信","code":"wxpay"}]';
	        }else if(isAlipayOnAppleDevice()){
	            $products='[{"id":"1","name":"支付宝","code":"alipay"}]';
	        }else{
	            $products='[{"id":"1","name":"支付宝","code":"alipay"},{"id":"2","name":"微信","code":"wxpay"}]';
	        }
	        
    		$products=json_decode($products,true);
    		
    		// 如果指定了通道，查询通道的 game_type_id 及金额配置
    		$game_type_id  = 0;
    		$amount_type   = 'custom';   // 默认：任意金额
    		$fixed_amounts = '[]';
    		$amount_min    = '';
    		$amount_max    = '';
    		if($channel != ''){
    			$game_info = Db::name('game_list')->where('game_dm', $channel)->where('game_status', 1)->find();
    			if($game_info){
    				if(isset($game_info['game_type_id'])) $game_type_id = intval($game_info['game_type_id']);
    				$raw_amounts = isset($game_info['fixed_amounts']) ? $game_info['fixed_amounts'] : '';
    				$decoded     = @json_decode($raw_amounts, true);
    				if(isset($game_info['amount_type']) && $game_info['amount_type'] === 'fixed' && is_array($decoded) && count($decoded) > 0){
    					$amount_type   = 'fixed';
    					$fixed_amounts = $raw_amounts;
    				} else {
    					$amount_type = 'custom';
    					// 仅当设置了有效范围（>0）时才限制，0 或未设置表示不限制
    					if(!empty($game_info['amount_min']) && floatval($game_info['amount_min']) > 0) $amount_min = $game_info['amount_min'];
    					if(!empty($game_info['amount_max']) && floatval($game_info['amount_max']) > 0) $amount_max = $game_info['amount_max'];
    				}
    			}
    		}

    		// 分类收银台：通过 type_id 指定通道分类（channel 优先级更高）
    		$type_cashier_name = '';
    		if($type_id > 0 && $channel == ''){
    			$type_info = Db::name('game_type')->where('id', $type_id)->find();
    			if($type_info){
    				$type_cashier_name = $type_info['title'];
    				// 读取分类的金额配置（仅在通道未指定时生效）
    				$type_amount_type = isset($type_info['amount_type']) ? $type_info['amount_type'] : '';
    				if($type_amount_type === 'fixed'){
    					$raw = isset($type_info['fixed_amounts']) ? $type_info['fixed_amounts'] : '[]';
    					$decoded = @json_decode($raw, true);
    					if(is_array($decoded) && count($decoded) > 0){
    						$amount_type   = 'fixed';
    						$fixed_amounts = $raw;
    					}
    				} else if($type_amount_type === 'custom'){
    					$amount_type = 'custom';
    					// 仅当设置了有效范围（>0）时才限制，0 或未设置表示不限制
    					if(!empty($type_info['amount_min']) && floatval($type_info['amount_min']) > 0) $amount_min = $type_info['amount_min'];
    					if(!empty($type_info['amount_max']) && floatval($type_info['amount_max']) > 0) $amount_max = $type_info['amount_max'];
    				}
    				// 空值：不设置，保持默认 custom 无限制
    			}
    		}
    		
    		$this->assign('name',$user['name']);
    		$this->assign('pid', $pid);
    		$this->assign('channel', $channel ? $channel : '');
    		$this->assign('type_id', $type_id > 0 ? $type_id : 0);
    		$this->assign('type_cashier_name', $type_cashier_name);
    		$this->assign('game_my', $game_my > 0 ? 1 : 0);
    		$this->assign('products', $products);
    		$this->assign('game_type_id',  $game_type_id);
    		$this->assign('amount_type',   $amount_type);
    		$this->assign('fixed_amounts', $fixed_amounts);
    		$this->assign('amount_min',    $amount_min);
    		$this->assign('amount_max',    $amount_max);
    		
    		// 使用玻璃拟态风格收银台（charges_glass）
    		return view('charges_glass');
		}
	}
	/**
	 * 取账号模式 - 支持三级用户体系（从商户自己的账号和所有核销的账号中轮询）
	 * 
	 * 性能优化说明：
	 * 1. 批量查询优化：将N次查询合并为1次查询（订单统计、游戏信息等）
	 * 2. 随机选择优化：使用PHP array_rand()替代 ORDER BY RAND()，大幅提升性能
	 * 3. 批量更新优化：使用单条SQL替代循环更新
	 * 4. 只查询一次land表，在内存中过滤，避免二次数据库查询
	 * 
	 * @param int $userid 商户ID
	 * @param float $money 订单金额
	 * @param string $mid 指定账号ID（可选）
	 * @param string $channel 指定通道代码（可选，优先级高于 type_id）
	 * @param array $user 商户信息（已缓存，用于读取分配策略）
	 * @param int $type_id 指定通道分类ID（可选，channel 不为空时忽略）
	 * @param int $game_my 为1时仅使用商户收藏的常用通道（优先级低于 channel 和 type_id）
	 * @return array|int 返回账号信息数组，失败返回-1
	 */
	private function land_1($userid, $money ,$mid = '', $channel = '', $user = [], $type_id = 0, $game_my = 0) {
		// 从已缓存的商户信息中取分配策略，避免额外数据库查询（0=随机均衡 1=额度优先 2=空闲优先）
		$land_sort_mode = isset($user['land_sort_mode']) ? intval($user['land_sort_mode']) : 0;

		// 获取商户下所有核销的ID列表（使用缓存优化，减少重复查询）
		$cache_key = 'mash_ids_' . $userid;
		$mash_ids = cache($cache_key);
		if($mash_ids === false){
		$mash_ids = Db::name('user')->where('parent_id', $userid)->where('cate_id', 3)->column('id');
			cache($cache_key, $mash_ids, 300); // 缓存5分钟
		}
		
		// 将商户自己的ID也加入到查询列表中（商户自己的账号 + 核销的账号）
		$all_user_ids = array_merge([$userid], $mash_ids);
		
		// 重置已过了限量时间窗口的账号：对 ds_status=1 的账号重新统计订单数，若均未超限则恢复为 0
		$now_reset = time();
		$locked_lands = Db::name('land')
			->where('userid', 'in', $all_user_ids)
			->where('ds_status', 1)
			->where('del', 0)
			->field('id, ds_time_pay, ds_num_pay, ds_time_create, ds_num_create')
			->select();
		if (!empty($locked_lands)) {
			$reset_ids = [];
			foreach ($locked_lands as $ll) {
				$still_limited = false;
				// 检查已支付限量
				if ($ll['ds_time_pay'] > 0 && $ll['ds_num_pay'] > 0) {
					$cnt = Db::name('orders')
						->where('land_id', $ll['id'])
						->where('state', 2)
						->where('pay_time', 'egt', $now_reset - $ll['ds_time_pay'])
						->count();
					if ($cnt >= $ll['ds_num_pay']) $still_limited = true;
				}
				// 检查新创建限量
				if (!$still_limited && $ll['ds_time_create'] > 0 && $ll['ds_num_create'] > 0) {
					$cnt = Db::name('orders')
						->where('land_id', $ll['id'])
						->where('order_time', 'egt', $now_reset - $ll['ds_time_create'])
						->count();
					if ($cnt >= $ll['ds_num_create']) $still_limited = true;
				}
				if (!$still_limited) {
					$reset_ids[] = $ll['id'];
				}
			}
			if (!empty($reset_ids)) {
				Db::name('land')->where('id', 'in', $reset_ids)->update(['ds_status' => 0]);
			}
		}

		$land_where['del'] = 0;
		$land_where['ban'] = 1;
		$land_where['land_lx'] = 1;
		$land_where['ds_status'] = 0;
		
		if($mid != ''){
		$land_where['mid'] = $mid;
	    }
		
		// 如果指定了通道代码，查询对应的通道ID并筛选（channel 优先级高于 type_id）
		$type_game_ids = [];
		if($channel != ''){
			$game_info = Db::name('game_list')->where('game_dm', $channel)->where('game_status', 1)->find();
			if($game_info){
				$land_where['typec'] = $game_info['id'];
			}
	    } elseif($type_id > 0) {
			// 如果指定了通道分类ID，筛选该分类下所有启用的通道
			$type_game_ids = Db::name('game_list')
				->where('game_type_id', $type_id)
				->where('game_status', 1)
				->column('id');
		} elseif($game_my > 0) {
			// 如果指定了常用通道模式，筛选该商户收藏的通道
			$type_game_ids = Db::name('game_my')
				->where('userid', $userid)
				->column('game_id');
		}
		
		// 从商户自己的账号 + 所有核销的账号中查询
		// type_id 筛选使用链式 where in，避免数组条件语法兼容问题
		$land_query = Db::name('land')->where('userid', 'in', $all_user_ids)->where($land_where);
		if(!empty($type_game_ids)){
			$land_query = $land_query->where('typec', 'in', $type_game_ids);
		}
	$land = $land_query->select();
	
	if(empty($land)){
		return -1;
	}
		
		// 批量查询优化：收集所有需要的ID
		$land_ids = [];
		$game_type_ids = [];
		foreach($land as $value){
			$land_ids[] = $value['id'];
			if($value['typec']) $game_type_ids[] = $value['typec'];
		}
		$land_ids = array_unique($land_ids);
		$game_type_ids = array_unique($game_type_ids);
		
	// 计算时间范围（今日）
	$today_start = strtotime(date('Y-m-d 00:00:00'));
	$today_end = strtotime(date('Y-m-d 23:59:59'));
	
	// 批量统计所有账号的今日订单金额和总订单金额（仅统计已支付，额度并发控制由Redis负责）
	$order_stats = [];
	if(!empty($land_ids)){
		$stats = Db::name('orders')
			->where('land_id', 'in', $land_ids)
			->where('userid', $userid)
			->where('state', 2)
			->field("
				land_id,
				SUM(CASE WHEN pay_time BETWEEN {$today_start} AND {$today_end} THEN money ELSE 0 END) as order_r,
				SUM(money) as order_z
			")
			->group('land_id')
			->select();
		
		foreach($stats as $stat){
			$order_stats[$stat['land_id']] = [
				'r' => $stat['order_r'] ?: 0,
				'z' => $stat['order_z'] ?: 0
			];
		}
	}
		
		// 批量查询游戏信息（从N次查询优化为1次）
		$games = [];
		if(!empty($game_type_ids)){
			$games_list = Db::name('game_list')->where('id', 'in', $game_type_ids)->select();
			$games = array_column($games_list, null, 'id');
		}
		
		// 批量统计时间段内的订单数量（收集所有需要查询的账号）
		// 滚动时间窗口逻辑：从当前时间往前推 N 分钟统计订单数
		$now = time();
		$ds_conditions_pay = [];    // 已支付限量条件
		$ds_conditions_create = []; // 新创建限量条件
		foreach($land as $value){
			$ds_time_pay = isset($value['ds_time_pay']) ? intval($value['ds_time_pay']) : 0;
			$ds_num_pay = isset($value['ds_num_pay']) ? intval($value['ds_num_pay']) : 0;
			$ds_time_create = isset($value['ds_time_create']) ? intval($value['ds_time_create']) : 0;
			$ds_num_create = isset($value['ds_num_create']) ? intval($value['ds_num_create']) : 0;
			
			// 已支付限量：时间和限量都大于0才生效
			if($ds_time_pay != 0 && $ds_num_pay != 0){
				$ds_conditions_pay[$value['id']] = [
					'ds_time' => $ds_time_pay,
					'ds_num' => $ds_num_pay
				];
			}
			// 新创建限量：时间和限量都大于0才生效
			if($ds_time_create != 0 && $ds_num_create != 0){
				$ds_conditions_create[$value['id']] = [
					'ds_time' => $ds_time_create,
					'ds_num' => $ds_num_create
				];
			}
		}
		
		// 批量查询时间段内的订单数量（分别统计已支付和新创建）
		// 使用滚动时间窗口：从当前时间往前推 N 分钟
		// 性能优化：按时间周期分组批量查询，减少数据库查询次数
		$ds_stats_pay = [];     // 已支付订单统计
		$ds_stats_create = [];  // 新创建订单统计
		
		// 统计已支付订单（按时间周期分组批量查询）
		if(!empty($ds_conditions_pay)){
			// 按时间周期分组
			$time_groups_pay = [];
			foreach($ds_conditions_pay as $land_id => $condition){
				$ds_time = $condition['ds_time'];
				if(!isset($time_groups_pay[$ds_time])){
					$time_groups_pay[$ds_time] = [];
				}
				$time_groups_pay[$ds_time][] = $land_id;
			}
			
			// 每组使用同一个时间条件批量查询
			foreach($time_groups_pay as $ds_time => $group_land_ids){
				$time_start = $now - $ds_time;
				$stats = Db::name('orders')
					->where('land_id', 'in', $group_land_ids)
					->where('state', 2)
					->where('pay_time', 'egt', $time_start)
					->field('land_id, count(*) as cnt')
					->group('land_id')
					->select();
				foreach($stats as $stat){
					$ds_stats_pay[$stat['land_id']] = intval($stat['cnt']);
				}
				// 未查到的账号设为0
				foreach($group_land_ids as $lid){
					if(!isset($ds_stats_pay[$lid])){
						$ds_stats_pay[$lid] = 0;
					}
				}
			}
				}
				
		// 统计新创建订单（按时间周期分组批量查询）
		if(!empty($ds_conditions_create)){
			// 按时间周期分组
			$time_groups_create = [];
			foreach($ds_conditions_create as $land_id => $condition){
				$ds_time = $condition['ds_time'];
				if(!isset($time_groups_create[$ds_time])){
					$time_groups_create[$ds_time] = [];
				}
				$time_groups_create[$ds_time][] = $land_id;
			}
			
			// 每组使用同一个时间条件批量查询
			foreach($time_groups_create as $ds_time => $group_land_ids){
				$time_start = $now - $ds_time;
				$stats = Db::name('orders')
					->where('land_id', 'in', $group_land_ids)
					->where('order_time', 'egt', $time_start)
					->field('land_id, count(*) as cnt')
					->group('land_id')
					->select();
				foreach($stats as $stat){
					$ds_stats_create[$stat['land_id']] = intval($stat['cnt']);
				}
				// 未查到的账号设为0
				foreach($group_land_ids as $lid){
					if(!isset($ds_stats_create[$lid])){
						$ds_stats_create[$lid] = 0;
					}
				}
			}
		}
		
		// 处理账号过滤
		$arr_id = [];
		$land_updates = [];  // 批量更新账号状态
		
		foreach ($land as $value) {
			// 从批量统计结果中获取今日和总金额
			$order_r = isset($order_stats[$value['id']]['r']) ? $order_stats[$value['id']]['r'] : 0;
			$order_z = isset($order_stats[$value['id']]['z']) ? $order_stats[$value['id']]['z'] : 0;
			
			//先计算总限额是否已限额
			if ($value['z_money'] != 0) {
				// 判断1：累计金额已达到总限额
				if ($order_z >= $value['z_money']) {
					$land_updates[] = [
						'id' => $value['id'],
						'land_lx' => 0
					];
				}
				// 判断2：订单金额超过总限额本身（新增）
				if ($money > $value['z_money']) {
					$arr_id[] = $value['id'];
				}
				// 判断3：订单金额超过总限额剩余额度（新增）
				$z_remaining = $value['z_money'] - $order_z;
				if ($money > $z_remaining) {
					$arr_id[] = $value['id'];
				}
			}
			if ($value['r_money'] != 0) {
				// 判断1：累计金额已达到日限额
				if ($order_r >= $value['r_money']) {
					$arr_id[] = $value['id'];
				}
				// 判断2：订单金额超过日限额本身（新增）
				if ($money > $value['r_money']) {
					$arr_id[] = $value['id'];
				}
				// 判断3：订单金额超过日限额剩余额度（新增）
				$r_remaining = $value['r_money'] - $order_r;
				if ($money > $r_remaining) {
					$arr_id[] = $value['id'];
				}
			}
			
		//判断是否在限定时间达到限量（独立检查已支付限量和新创建限量）
			$should_limit = false;
			
			// 检查已支付订单限量
		if(isset($ds_conditions_pay[$value['id']])){
			$order_ds_pay = isset($ds_stats_pay[$value['id']]) ? $ds_stats_pay[$value['id']] : 0;
			if($order_ds_pay >= $ds_conditions_pay[$value['id']]['ds_num']){
				$should_limit = true;
			}
			}
			// 检查新创建订单限量
		if(isset($ds_conditions_create[$value['id']])){
			$order_ds_create = isset($ds_stats_create[$value['id']]) ? $ds_stats_create[$value['id']] : 0;
			if($order_ds_create >= $ds_conditions_create[$value['id']]['ds_num']){
				$should_limit = true;
			}
			}
			
			if($should_limit){
				$arr_id[] = $value['id'];
				//收集需要更新ds_status的账号（优化：批量更新，不在循环中执行单独UPDATE）
				$land_updates[] = [
					'id' => $value['id'],
					'ds_status' => 1,
					'ds_status_time' => time()
				];
		}
			
			//判断是否存在需要的金额参数,不存在过滤掉(支付宝等无需过滤)
			$game = isset($games[$value['typec']]) ? $games[$value['typec']] : null;
			if ($game && $game['game_type']=='1') {
				$ckmoney = 'ck' . $money;
				$ck_json = json_decode($value['json'], true);
				if (!isset($ck_json[$ckmoney])) {
					$arr_id[] = $value['id'];
				}
			}
		}
		
	// 批量更新账号状态
	$current_time = time();
	if(!empty($land_updates)){
		$land_lx_ids  = [];
		$ds_status_ids = [];
		foreach($land_updates as $update_item){
			if(isset($update_item['land_lx'])){
				$land_lx_ids[] = $update_item['id'];
			}
			if(isset($update_item['ds_status'])){
				$ds_status_ids[] = $update_item['id'];
			}
		}
		// 总限额已支付金额达到上限 → 永久关闭账号
		if(!empty($land_lx_ids)){
			Db::name('land')->where('id', 'in', $land_lx_ids)->update(['land_lx' => 0]);
		}
		// 限量锁定
		if(!empty($ds_status_ids)){
			Db::name('land')->where('id', 'in', $ds_status_ids)->update([
				'ds_status' => 1,
				'ds_status_time' => $current_time
			]);
		}
	}
		
		// 从商户自己的账号 + 所有核销的账号中随机选择一个（性能优化：避免使用ORDER BY RAND()）
		// 先在已有的 $land 数组中过滤掉不可用的账号
		$available_lands = [];
		foreach($land as $item){
			if(!in_array($item['id'], $arr_id)){
				$available_lands[] = $item;
			}
		}
		
	if (empty($available_lands)) {
		return -1;
	}

	// 根据商户分配策略对候选账号排序，Redis原子预扣作为并发安全的最终防线
	// 0=随机均衡  1=额度优先（剩余最少的先用完）  2=空闲优先（剩余最多的优先）
	if ($land_sort_mode == 1 || $land_sort_mode == 2) {
		$get_remaining = function($item) use ($order_stats) {
			$r = floatval($item['r_money']);
			$z = floatval($item['z_money']);
			if ($r <= 0 && $z <= 0) return PHP_INT_MAX;
			$order_r = isset($order_stats[$item['id']]['r']) ? floatval($order_stats[$item['id']]['r']) : 0;
			$order_z = isset($order_stats[$item['id']]['z']) ? floatval($order_stats[$item['id']]['z']) : 0;
			$remaining = PHP_INT_MAX;
			if ($r > 0) $remaining = min($remaining, $r - $order_r);
			if ($z > 0) $remaining = min($remaining, $z - $order_z);
			return $remaining;
		};
		$sort_mode = $land_sort_mode;
		usort($available_lands, function($a, $b) use ($get_remaining, $sort_mode) {
			$ra = $get_remaining($a);
			$rb = $get_remaining($b);
			if ($ra == $rb) return rand(-1, 1);
			if ($sort_mode == 1) {
				// 额度优先：剩余越少越优先（升序），无限额排最后
				return $ra < $rb ? -1 : 1;
			} else {
				// 空闲优先：剩余越多越优先（降序），无限额排最前
				return $ra > $rb ? -1 : 1;
			}
		});
	} else {
		// 随机均衡（默认）：随机打乱
		shuffle($available_lands);
	}
	foreach ($available_lands as $candidate) {
		$r = floatval($candidate['r_money']);
		$z = floatval($candidate['z_money']);
		if ($r > 0 || $z > 0) {
			limitEnsureInit($candidate['id']);
			if (!limitPreDeduct($candidate['id'], $money, $r, $z)) {
				continue;
			}
		}
		return $candidate;
	}
	return -1;
}
	
	private function bug_set($user_id, $land_id, $reason = '') {
	    bug_set_handle($user_id, $land_id, $reason);
	}
	public function getapiback(){
	    // 简单记录接收数据
	    $logFile = __DIR__ . '/../../callback_api_log.txt';
	    
	    // 优先使用POST数据，如果为空则尝试解析JSON
	    $data = $_POST;
	    if (empty($data)) {
	        $rawData = file_get_contents("php://input");
	        $data = json_decode($rawData, true);
	    }
	    
	    // 保存接收的数据
	    $logContent = date('Y-m-d H:i:s') . " | " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
	    file_put_contents($logFile, $logContent, FILE_APPEND | LOCK_EX);
	    
	    // 处理订单更新
	    if(isset($data['orderid'])){
	        $order = Db::name('orders')->where(['num'=>$data['orderid'],'state'=>1])->find();
	        if($order){
	            $sy_order = json_decode($order['order_url'],true);
	            if($sy_order['order_id']==''){
	                $pay_id = isset($data['data']['pay_id']) ? $data['data']['pay_id'] : '';
	                $mid_url = isset($data['data']['mid_url']) ? $data['data']['mid_url'] : '';
	                
	                $sy_order['order_id'] = $pay_id;
	                $sy_order['order_url'] = $mid_url;
	                
	                Db::name('orders')->where(['id'=>$order['id'],'state'=>1])->update(array('syorder'=>$pay_id,'order_url'=>json_encode($sy_order)));
	            }
	        }
	    }
	    
	    echo '0';
	}
	public function getwyback(){
	    // 简单记录接收数据
	    $logFile = __DIR__ . '/../../callback_api_log.txt';
	    
	    // 优先使用POST数据，如果为空则尝试解析JSON
	    $data = $_POST;
	    if (empty($data)) {
	        $rawData = file_get_contents("php://input");
	        $data = json_decode($rawData, true);
	    }

	    // 保存接收的数据
	    $logContent = date('Y-m-d H:i:s') . " | " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
	   file_put_contents($logFile, $logContent, FILE_APPEND | LOCK_EX);
	    
	    // 处理订单更新
	    if(isset($data['orderId'])){
	        $order = Db::name('orders')->where(['num'=>$data['orderId'],'state'=>1])->find();
	        if($order){
	            $sy_order = json_decode($order['order_url'],true);
	            if($sy_order['order_id']==''){
                    $pay_id = isset($data['payment_response']['sn']) ? $data['payment_response']['sn'] : '';
                    $mid_url = isset($data['payment_response']['order_info']) ? $data['payment_response']['order_info'] : '';
	                $sy_order['order_id'] = $pay_id;
	                $sy_order['order_url'] = $mid_url;
	                $sy_order['order_mb']  = 'wangyi';
	                
	                Db::name('orders')->where(['id'=>$order['id'],'state'=>1])->update(array('syorder'=>$pay_id,'order_url'=>json_encode($sy_order)));
	            }

	            echo '1';exit;
	        }
	    }
	    echo '0';
	}
    function getRedirectUrl($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPGET => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        // 使用更灵活的匹配模式
        if (preg_match('/^Location:\s*(.*?)$/im', $response, $matches)) {
            return trim($matches[1]);
        }
        
        return $url;
    }

    /**
     * 查单接口 - 商户查询订单状态
     * 请求方式：GET/POST
     * 请求参数：
     *   api_id   - 商户ID（必填）
     *   order    - 平台订单号（必填）
     *   sign     - 签名（必填）
     * 签名规则：md5(api_id={api_id}&order={order}{api_key})
     * 返回格式：JSON
     */
    public function query() {
        // 获取请求参数
        $api_id = xss($this->request->param('api_id'));
        $order = xss($this->request->param('order'));
        $sign = $this->request->param('sign');
        
        // 参数验证（快速失败，减少无效请求）
        if (empty($api_id)) {
            $this->queryResponse(1001, '商户ID不能为空');
        }
        if (empty($order)) {
            $this->queryResponse(1002, '订单号不能为空');
        }
        if (empty($sign)) {
            $this->queryResponse(1003, '签名不能为空');
        }
        
        // 商户信息缓存（减少数据库查询，缓存5分钟）
        $cache_key = 'user_api_' . $api_id;
        $user = Cache::get($cache_key);
        if (!$user) {
            $user = Db::name('user')->where('api_id', $api_id)->find();
            if ($user) {
                Cache::set($cache_key, $user, 300);  // 缓存5分钟
            }
        }
        
        if (!$user) {
            $this->queryResponse(1004, '商户不存在');
        }
        
        // 验证签名
        $sign_str = "api_id={$api_id}&order={$order}{$user['api_key']}";
        $sign_check = md5($sign_str);
        if ($sign !== $sign_check) {
            $this->queryResponse(1005, '签名错误');
        }
        
        // 查询订单（订单号有唯一索引，查询快）
        $order_info = Db::name('orders')->where('num', $order)->where('userid', $user['id'])->find();
        if (!$order_info) {
            $this->queryResponse(1006, '订单不存在');
        }
        
        // 订单状态：1=未支付，2=已支付，3=已超时/关闭
        $state_map = [
            1 => ['status' => 'pending', 'status_text' => '待支付'],
            2 => ['status' => 'success', 'status_text' => '支付成功'],
            3 => ['status' => 'closed', 'status_text' => '已关闭']
        ];
        
        $state = isset($state_map[$order_info['state']]) ? $state_map[$order_info['state']] : ['status' => 'unknown', 'status_text' => '未知状态'];
        
        // 组装返回数据
        $data = [
            'order' => $order_info['num'],           // 平台订单号
            'money' => sprintf("%.2f", $order_info['money']),  // 订单金额
            'record' => $order_info['remark'],       // 附加信息
            'status' => $state['status'],            // 订单状态
            'status_text' => $state['status_text'],  // 状态说明
            'order_time' => date('Y-m-d H:i:s', $order_info['order_time']),  // 下单时间
            'pay_time' => $order_info['pay_time'] > 0 ? date('Y-m-d H:i:s', $order_info['pay_time']) : ''  // 支付时间
        ];
        
        $this->queryResponse(0, '查询成功', $data);
    }
    
    /**
     * 查单接口响应输出
     */
    private function queryResponse($code, $msg, $data = null) {
        header('Content-type: application/json; charset=utf-8');
        $response = [
            'code' => $code,
            'msg' => $msg
        ];
        if ($data !== null) {
            $response['data'] = $data;
        }
        exit(json_encode($response, JSON_UNESCAPED_UNICODE));
    }

    // ==================== 高并发缓存方法 ====================
    
    /**
     * 获取缓存的系统配置
     * @param string $key 配置键名
     * @return array
     */
    private function getCachedConfig($key) {
        $cache_key = 'config_set_' . $key;
        $config = Cache::get($cache_key);
        if (!$config) {
            $config = Db::name('set')->where('key', $key)->find();
            if ($config) {
                Cache::set($cache_key, $config, 60);  // 缓存60秒
            }
        }
        return $config;
    }
    
    /**
     * 获取缓存的商户信息
     * @param string $api_id 商户ID
     * @return array|null
     */
    private function getCachedUser($api_id) {
        $cache_key = 'user_api_' . $api_id;
        $user = Cache::get($cache_key);
        if (!$user) {
            $user = Db::name('user')->where('api_id', $api_id)->find();
            if ($user) {
                Cache::set($cache_key, $user, 300);  // 缓存5分钟
            }
        }
        return $user;
    }
    
    /**
     * 获取缓存的通道信息
     * @param int $typec 通道ID
     * @return array|null
     * @deprecated 已废弃 - 通道信息改为实时查询，不再使用缓存
     */
    // private function getCachedGameData($typec) {
    //     $cache_key = 'game_data_' . $typec;
    //     $game_data = Cache::get($cache_key);
    //     if (!$game_data) {
    //         $game_data = Db::name('game_list')->where('id', 'in', config('game_id'))->where('id', $typec)->find();
    //         if ($game_data) {
    //             Cache::set($cache_key, $game_data, 300);  // 缓存5分钟
    //         }
    //     }
    //     return $game_data;
    // }
    
    /**
     * 清除商户缓存（商户信息变更时调用）
     * @param string $api_id 商户ID
     */
    public static function clearUserCache($api_id) {
        Cache::rm('user_api_' . $api_id);
    }
    
    /**
     * 清除通道缓存（通道信息变更时调用）
     * @param int $typec 通道ID
     * @deprecated 已废弃 - 通道信息改为实时查询，不再使用缓存
     */
    // public static function clearGameCache($typec) {
    //     Cache::rm('game_data_' . $typec);
    // }
    
    /**
     * 清除配置缓存（配置变更时调用）
     * @param string $key 配置键名
     */
    public static function clearConfigCache($key) {
        Cache::rm('config_set_' . $key);
    }

    /**
     * 初始化所有账号的Redis限额数据（部署后访问一次）
     * URL: /pay/pay/limitInit?key=tadhsajhddsa324gusandjsakughdk5
     */
    public function limitInit() {
        if ('tadhsajhddsa324gusandjsakughdk5' != $this->request->get('key')) exit('密钥错误');
        ini_set('max_execution_time', 0);
        $lands = Db::name('land')->where('del', 0)->field('id')->select();
        $count = 0;
        foreach ($lands as $l) {
            limitRebuildOne($l['id']);
            $count++;
        }
        exit("已初始化 {$count} 个账号的Redis限额数据");
    }

}