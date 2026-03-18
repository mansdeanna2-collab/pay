# 支付收款系统关键信息方式分析

> 本文档对代码库中的支付收款核心逻辑进行全面分析，梳理关键支付信息流转方式、收款通道路由机制、回调处理方法及安全校验流程。

---

## 目录

1. [系统架构总览](#1-系统架构总览)
2. [支付入口与接口](#2-支付入口与接口)
3. [支持的收款方式](#3-支持的收款方式)
4. [通道路由与账号选择（land_1）](#4-通道路由与账号选择land_1)
5. [订单生命周期](#5-订单生命周期)
6. [回调通知处理](#6-回调通知处理)
7. [安全机制](#7-安全机制)
8. [关键数据结构](#8-关键数据结构)

---

## 1. 系统架构总览

系统基于 ThinkPHP 框架，采用 **异步下单 + Redis 队列** 的架构：

```
商户请求 → Pay控制器 → 签名验证 → 账号选择(land_1)
    → 创建占位订单 → 推入Redis队列(pay_async_queue)
    → 返回等待页/JSON → Workerman Worker异步拉单
    → 前端轮询订单状态 → 跳转支付页面
```

**核心文件：**

| 文件 | 作用 |
|------|------|
| `pay/controller/Pay.php` | 支付核心控制器，包含所有支付逻辑 |
| `pay/config.php` | 模块配置（默认控制器、静态资源路径） |
| `pay/common.php` | 模块公共基类 `Commone`（Controller 基类） |
| `pay/view/pay/*.html` | 20个支付展示模板（二维码、H5、收银台等） |

---

## 2. 支付入口与接口

### 2.1 `pay()` — 主支付入口（HTML跳转模式）

- **路径：** `/pay/pay`（默认路由）
- **方式：** GET/POST
- **功能：** 接收商户下单参数，创建订单后直接跳转到支付页面

**关键参数：**

| 参数 | 必填 | 说明 |
|------|------|------|
| `api_id` | 是 | 商户ID |
| `money` | 是 | 支付金额 |
| `record` | 是 | 附加信息（如商户订单号） |
| `refer` | 是 | 来源URL |
| `notify_url` | 是 | 异步回调通知地址 |
| `paytype` | 是 | 支付方式：`alipay`（支付宝）或 `wxpay`（微信） |
| `sign` | 是 | MD5签名 |
| `mid` | 否 | 指定账号ID |
| `channel` | 否 | 指定通道代码 |
| `type_id` | 否 | 通道分类ID |
| `game_my` | 否 | 为1时仅使用商户收藏通道 |
| `ip` | 否 | 客户端IP |

**流程：**
1. 参数校验 → 签名验证 → 余额检查
2. 调用 `land_1()` 选择可用收款账号
3. 查询通道信息（`game_list` 表）
4. 生成29位订单号（`YmdHis` + 时间戳 + 随机数）
5. 写入占位订单（`order_url` 含 `order_mb=waiting`）
6. 推入 Redis 队列 `pay_async_queue`
7. 302 跳转到 `/?orderid={order_num}`（等待页轮询）

### 2.2 `create()` — JSON 下单接口

- **路径：** `/pay/create`
- **方式：** GET/POST
- **功能：** 与 `pay()` 逻辑一致，但返回 JSON 格式响应

**返回示例：**
```json
{
  "code": 1,
  "msg": "下单成功",
  "data": {
    "order_num": "20260318...",
    "pay_url": "https://domain/?orderid=...",
    "order_time": "2026-03-18 12:00:00"
  }
}
```

### 2.3 `charges()` — 收银台

- **路径：** `/pay/charges`（GET 显示 / POST 提交）
- **功能：** 面向终端用户的收银台页面，支持自定义金额或固定金额模式

**GET 参数：**
- `pid` — 商户 api_id
- `channel` — 指定通道代码
- `type_id` — 通道分类ID
- `game_my` — 常用通道模式

**收银台特性：**
- 自动检测微信/支付宝环境，显示对应支付方式
- 支持固定金额模式（`amount_type=fixed`）和自定义金额模式
- 支持金额范围限制（`amount_min` / `amount_max`）
- 提交后构造隐藏表单 POST 到 `/pay/pay` 完成下单

### 2.4 `query()` — 订单查询接口

- **路径：** `/pay/query`
- **方式：** GET/POST
- **签名规则：** `md5("api_id={api_id}&order={order}{api_key}")`

**返回：** 订单号、金额、状态（pending/success/closed）、下单时间、支付时间

### 2.5 `orders()` — 订单状态检查（含主动查单）

- **路径：** `/pay/orders`
- **功能：** 查询订单状态，对未支付订单主动调用上游 `status()` 方法检查

### 2.6 `orderc()` — 前端轮询接口

- **路径：** `/pay/orderc`
- **功能：** 供等待页（waiting.html）每 1.5 秒轮询订单状态
- **返回：** `code=0` 等待中 / `code=200` 支付链接已获取 / `code=1001` 失败

---

## 3. 支持的收款方式

系统通过 `game_list` 表中的 `game_dm`（通道代码）区分不同收款方式，每个通道对应一个展示模板（`order_mb`）。

### 3.1 支付宝系列

| 模板文件 | 通道场景 | 收款信息展示方式 |
|----------|----------|------------------|
| `alipay.html` | 支付宝扫码 | 二维码 + 倒计时 + 订单信息 |
| `alipay_ewm.html` | 支付宝扫码（增强版） | 自动检测支付宝客户端，应用内自动跳转 |
| `alipay_h5.html` | 支付宝H5 | 1秒后自动跳转到支付URL |
| `js_alipay.html` | 支付宝JSAPI | 应用内调用 `ap.tradePay()` 原生支付 |
| `yy_alipay_ewm.html` | YY平台-支付宝扫码 | 二维码 + 倒计时 |

### 3.2 微信支付系列

| 模板文件 | 通道场景 | 收款信息展示方式 |
|----------|----------|------------------|
| `weixin.html` | 微信扫码 | 二维码 + 倒计时 + "请使用微信扫一扫" |
| `weixin_h5.html` | 微信H5 | 二维码/Base64图片 + 环境检测 |
| `changyou_weixin_ewm.html` | 长游平台-微信扫码 | 二维码（外部API生成） |
| `shengqu_wx.html` | 升趣平台-微信扫码 | 二维码（外部API生成） |
| `yy_weixin_ewm.html` | YY平台-微信扫码 | 二维码 + 长按识别提示 |

### 3.3 通用/特殊模板

| 模板文件 | 场景 | 说明 |
|----------|------|------|
| `waiting.html` | 异步等待页 | 轮询订单状态，获取支付链接后跳转 |
| `wangyi.html` | 网易平台等待页 | 网易支付专用等待轮询页 |
| `h5.html` | 通用H5跳转 | 1秒后自动跳转 + 手动按钮 |
| `hy_h5.html` | 游戏H5跳转 | 自动跳转到支付URL |
| `direct_html.html` | 直接输出 | 直接输出上游返回的HTML |
| `tiaozhuan.html` | 浏览器引导 | 引导用户切换默认浏览器打开支付 |

### 3.4 收银台模板

| 模板文件 | 风格 | 特性 |
|----------|------|------|
| `charges.html` | 简约POS | 自定义键盘，金额取整 |
| `charges_glass.html` | 玻璃拟态风格 | Alpine.js响应式，固定/自定义金额 |
| `charges_pc.html` | PC桌面端 | 下拉选择支付方式 |
| `charges_style2.html` | 备用样式 | 另一种收银台风格 |

---

## 4. 通道路由与账号选择（land_1）

`land_1()` 是核心的收款账号选择方法，实现了 **三级用户体系** 下的智能路由。

### 4.1 用户体系

```
商户(user) ─┬─ 商户自有账号(land)
            └─ 核销用户(cate_id=3) ─── 核销用户的账号(land)
```

### 4.2 筛选流程

```
所有账号池（商户 + 核销用户的账号）
  ↓ 过滤条件: del=0, ban=1, land_lx=1, ds_status=0
  ↓ 通道筛选: channel > type_id > game_my（优先级递减）
  ↓ 限额过滤: 日限额、总限额剩余额度检查
  ↓ 限量过滤: 已支付订单数/新创建订单数时间窗口检查
  ↓ 金额匹配: 特定通道的固定面额匹配
  ↓ 分配策略排序
  ↓ Redis原子预扣并发防护
  → 返回选中账号
```

### 4.3 三种分配策略（`land_sort_mode`）

| 值 | 策略 | 说明 |
|----|------|------|
| 0 | 随机均衡 | `shuffle()` 随机打乱（默认） |
| 1 | 额度优先 | 剩余额度最少的优先使用（先用完） |
| 2 | 空闲优先 | 剩余额度最多的优先使用 |

### 4.4 限量机制

- **已支付限量**（`ds_time_pay` + `ds_num_pay`）：滚动窗口内已支付订单数达到上限则锁定
- **新创建限量**（`ds_time_create` + `ds_num_create`）：滚动窗口内新建订单数达到上限则锁定
- **锁定恢复**：每次调用 `land_1()` 时检查已锁定账号（`ds_status=1`），窗口内订单数回落后自动恢复

### 4.5 Redis并发控制

通过 `limitEnsureInit()` / `limitPreDeduct()` / `limitRollback()` 函数实现：
- 原子预扣：在 Redis 中预扣账号额度，防止高并发下超额分配
- 回滚机制：订单创建失败时回滚 Redis 预扣额度

---

## 5. 订单生命周期

### 5.1 状态流转

```
state=1（待支付）
  ├── → state=2（支付成功）  ← 上游回调/主动查单确认
  └── → state=3（已超时/关闭） ← 超时回收或上游返回-2
```

### 5.2 异步下单流程

```
1. pay()/create() 创建占位订单
   order_url = {"order_mb":"waiting", "order_url":"0", ...}

2. Redis队列 pay_async_queue 推入任务
   {order_num, game_dm, pdata, money, paytype, land_id, ...}

3. Workerman Worker 消费队列，调用上游接口

4. 上游返回支付链接 → 更新 order_url
   order_url.order_url = "https://支付链接"

5. 前端 waiting.html 轮询 orderc() 接口
   code=0 → 继续等待
   code=200 → 获取支付链接，跳转支付
   code=1001 → 拉单失败
```

### 5.3 支付确认方式

| 方式 | 方法 | 触发场景 |
|------|------|----------|
| 上游主动通知 | `getpayback()` | 上游通过回调URL通知支付结果 |
| 上游账单ID回调 | `getbillidback()` | 上游返回交易流水号 |
| 上游账单列表回调 | `getbilllistback()` | 上游批量推送已支付账单 |
| API回调 | `getapiback()` | 通用API回调 |
| 网易专用回调 | `getwyback()` | 网易平台支付回调 |
| 主动查单 | `orders()` | 调用上游 `status()` 方法主动查询 |

---

## 6. 回调通知处理

### 6.1 `getpayback()` — 通用支付回调

接收 JSON Body（`paytype` 为可选字段，值为 2 时表示 `payurl` 经过 Base64 编码）：
```json
{
  "orderid": "平台订单号",
  "code": 1,
  "payurl": "支付链接",
  "paytype": 2
}
```
更新订单的 `order_url` 字段中的支付链接。

### 6.2 `getwyback()` — 网易平台回调

接收 JSON Body：
```json
{
  "orderId": "平台订单号",
  "payment_response": {
    "sn": "上游流水号",
    "order_info": "支付链接/信息"
  }
}
```
设置 `order_mb=wangyi`，使用网易专用支付流程。

### 6.3 `getapiback()` — API回调

接收 JSON Body：
```json
{
  "orderid": "平台订单号",
  "data": {
    "pay_id": "上游订单号",
    "mid_url": "支付链接"
  }
}
```

### 6.4 `getbilllistback()` — 批量账单回调

接收 Base64 编码的账单列表，批量匹配并更新订单状态：
- 批量查询优化：收集所有 `syorder` 一次查询
- 批量状态更新：state=1 → state=2
- 触发商户异步通知：`api_request()`

### 6.5 商户通知（`api_request()`）

订单支付成功后，系统调用 `api_request()` 向商户的 `notify_url` 发送异步通知。

---

## 7. 安全机制

### 7.1 签名验证

**下单签名（pay/create）：**
```php
$sign_data = ['api_id' => $api_id, 'record' => $info, 'money' => sprintf("%.2f", $money)];
$sign = md5_sign($sign_data, $user['api_key']);
```

**查单签名（query）：**
```php
$sign_str = "api_id={$api_id}&order={$order}{$user['api_key']}";
$sign = md5($sign_str);
```

### 7.2 输入过滤

- `xss()` 函数过滤所有用户输入
- `floatval()` / `intval()` 数值强制转换
- `urlencode()` / `urldecode()` URL参数处理
- `filter_var(FILTER_VALIDATE_URL)` URL格式验证

### 7.3 并发控制

- Redis 原子操作预扣额度（`limitPreDeduct`）
- 数据库乐观锁更新（`where state=1 update state=2`）
- 批量操作使用事务性条件更新

### 7.4 系统开关

- `pay_no_status` 配置控制系统是否暂停接单
- 通道 `game_status` 控制单通道开关
- 账号 `ban`/`del`/`land_lx`/`ds_status` 多维度控制

---

## 8. 关键数据结构

### 8.1 核心数据表

| 表名 | 作用 | 关键字段 |
|------|------|----------|
| `orders` | 订单表 | num(订单号), money, state, order_url(JSON), notify_url, syorder(上游订单号) |
| `land` | 收款账号表 | userid, typec(通道ID), json(通道配置), r_money(日限额), z_money(总限额) |
| `user` | 用户/商户表 | api_id, api_key, balance, cate_id(用户类型), parent_id(上级ID) |
| `game_list` | 通道配置表 | game_dm(通道代码), game_status, game_type_id, fixed_amounts |
| `game_type` | 通道分类表 | title, amount_type, fixed_amounts |
| `game_my` | 商户收藏通道 | userid, game_id |
| `config/set` | 系统配置表 | key, value |

### 8.2 `order_url` JSON 结构

```json
{
  "order_id": "上游订单号",
  "order_url": "支付链接 | 0(等待中) | -1(失败)",
  "order_message": "状态消息",
  "alipay_url": "支付宝链接",
  "order_mb": "模板名称(waiting/alipay/weixin/wangyi/...)",
  "hy_sign": "",
  "hy_time": "",
  "game_dm": "通道代码_pay",
  "refer": "来源URL"
}
```

### 8.3 Redis 队列任务结构

```json
{
  "order_num": "平台订单号",
  "game_dm": "通道代码",
  "pdata": { "通道配置参数": "..." },
  "money": 100.00,
  "paytype": "alipay|wxpay",
  "land_id": "账号ID",
  "userid": "商户ID",
  "game_dm_full": "通道代码_pay",
  "client_ip": "客户端IP"
}
```

---

## 总结

本支付收款系统的关键信息方式可归纳为：

1. **双入口下单**：`pay()`（HTML跳转）和 `create()`（JSON API），均经过签名验证和账号智能路由
2. **异步拉单架构**：Redis 队列 + Workerman Worker，解耦下单与上游调用
3. **多通道收款**：通过 `game_list` 配置 20+ 种支付通道（支付宝/微信的扫码、H5、JSAPI等），不同通道对应不同展示模板
4. **智能账号分配**：三级用户体系 + 三种分配策略 + Redis 并发预扣 + 多维度限额限量
5. **多种回调处理**：5 个回调入口适配不同上游平台（通用、网易、API、账单）
6. **前端轮询闭环**：等待页 1.5 秒轮询 + 支付链接获取后自动跳转/渲染
