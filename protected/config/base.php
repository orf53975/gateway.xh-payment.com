<?php
include(__DIR__."/project.php");

!defined('WWW_DIR') && define('WWW_DIR', realpath(__DIR__ . '/../../'));
!defined('RUNTIME_DIR') && define('RUNTIME_DIR', WWW_DIR . '/runtime');
//!is_dir(RUNTIME_DIR) && mkdir(RUNTIME_DIR, 0777, true);

$config = [
    'id'        => SYSTEM_NAME,
    'basePath'  => __DIR__.DIRECTORY_SEPARATOR.'..',
    'name'      => SYSTEM_NAME,
    'bootstrap' => [
        'log',
        'paymentNotifyQueue',
        'remitBankCommitQueue',
        'remitQueryQueue',
        'orderQueryQueue',
        'remitNotifyQueue',
    ],
    'runtimePath' => constant('RUNTIME_DIR'),
    'modules' => [
        'gateway' => [
            'class' => 'app\modules\gateway\GatewayModule',
        ],
    ],
    'components' => [
        'response' => [
            'format'    => yii\web\Response::FORMAT_JSON,
            //强制处理无法捕获的框架级别错误,例如NotFoundHttpException
            'on beforeSend' => function ($event) {
                $response = $event->sender;
                if ($response->statusCode >= 400) {
                    $response->data = [
                        'code' => $response->data['code']==0?$response->statusCode:$response->data['code'],
                        'message' => $response->data['message']??($response->data['name']??'系统错误: '.$_SERVER['LOG_ID']),
                    ];
                }
            },
        ],
        'request'=>[
            'enableCookieValidation' => false,
            'enableCsrfValidation'   => false,
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ]
        ],
        'cache' => [
            'class' => 'yii\redis\Cache',
            'redis' => 'redis'
        ],
        'db' => [
            'class' => 'yii\db\Connection',
            'dsn' => 'mysql:host=127.0.0.1;dbname=payment',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8',
            'tablePrefix' => 'p_',
//            'enableLogging'=>true,
        ],
        'redis' => [
            'class' => 'yii\redis\Connection',
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ],
        'mongodb' => [
            'class' => '\yii\mongodb\Connection',
            'dsn'    => 'mongodb://sh:sh@127.0.0.1/sh'
        ],
        'formatter' => [
            'dateFormat' => 'yyyy-mm-dd',
            'datetimeFormat' => 'yyyy-mm-dd H:i:s',
            'decimalSeparator' => ',',
            'thousandSeparator' => ' ',
            'currencyCode' => 'RMB',
        ],
        'user' => [
            'identityClass' => '\app\common\models\model\User',
            'class' => 'yii\web\User',
            'enableAutoLogin' => true,
            'enableSession' => false,
            'loginUrl' => null,
        ],
        'urlManager' => [
            'class'     => '\yii\web\UrlManager',
            'enablePrettyUrl' => true,
            'showScriptName'  => false,

//            'enableStrictParsing' => true,
            'rules' => [
                /********商户接口URL重写开始*******/
                //收银台
                '/cashier.html' => '/gateway/v1/web/order/cashier',
                '/api/v1/cashier' => '/gateway/v1/web/order/cashier',
                //订单付款
                '/order/pay.html' => '/gateway/v1/web/order-pay/pay',
                //下单后随机跳转多次再到上游
                '/order/go.html' => '/gateway/v1/web/order-pay/rand-redirect',
                '/order/go/<sign:\S+>.html' => '/gateway/v1/web/order-pay/rand-redirect',
                //下单后二维码中间统计跳转页面
                '/order/r' => '/gateway/v1/web/order-pay/qr-redirect',
                //扫码界面循环检测订单状态
                '/order/check_status.html' => '/gateway/v1/web/order-pay/check-status',
                //v1支付接口
                '/pay.html' => '/gateway/v1/web/order/web-bank',
                //后台下单接口
                '/order.html' => '/gateway/v1/server/order/order',
                '/api/v1/order' => '/gateway/v1/server/order/order',
                //收款查询
                '/query.html' => '/gateway/v1/server/order/status',
                '/api/v1/query' => '/gateway/v1/server/order/status',
                //出款
                '/remit.html' => '/gateway/v1/server/remit/single',
                '/api/v1/remit' => '/gateway/v1/server/remit/single',
                //出款查询
                '/remit_query.html' => '/gateway/v1/server/remit/status',
                '/api/v1/remit_query' => '/gateway/v1/server/remit/status',
                //余额查询
                '/balance.html' => '/gateway/v1/server/account/balance',
                '/api/v1/balance' => '/gateway/v1/server/account/balance',
                //充值回调
                '/api/v1/callback/recharge-notify/<channelId:\d+>' => '/gateway/v1/web/callback/recharge-notify',
                '/api/v1/callback/recharge-return/<channelId:\d+>' => '/gateway/v1/web/callback/recharge-return',
                '/api/v1/callback/remit-notify/<channelId:\d+>' => '/gateway/v1/web/callback/remit-notify',
                /********商户接口URL重写结束*******/

                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => ['api/v1/user'],
                    'pluralize' => true,
                    'extraPatterns' => [
                        'POST login' => 'login',
                        'GET signup-test' => 'signup-test',
                        'GET profile' => 'profile',
                    ]
                ],
            ]
        ],
        'log' => [
            'targets' => [
                'file' => [
                    'class' => '\power\yii2\log\FileTarget',
                    'levels' => ['error', 'warning'],
                    'logFile' => '@runtime/log/err'.date('md').'.log',
                    'enableRotation' => true,
                    'maxFileSize' => 1024 * 300,
                    'logVars' => [],
//                    'fileMode' => 777,
                ],
                'notice' => [
                    'class' => '\power\yii2\log\FileTarget',
                    'levels' => ['notice', 'trace','info','warning','error'],//'profile',
//                    'logFile' => '@runtime/log/common'.date('md').'.log',
                    'logFile' => '@runtime/log/common'.date('md').(str_pad(ceil(date('H')/2)*2,2,'0',STR_PAD_LEFT)).'.log',
                    'categories' => ['application','yii\db\Command::query', 'yii\db\Command::execute','yii\queue\Queue'],//'yii\db\Command::query', 'yii\db\Command::execute'
                    'enableRotation' => false,
                    'maxLogFiles' => 100,
                    'maxFileSize' => 1024 * 200,
                    'fileMode' => 0777,
                    'logVars' => [],
                    'prefix' => function ($message) {
                        $request = Yii::$app->getRequest();
                        $ip = method_exists($request,'getUserIP')?$request->getUserIP() : '-';

                        $user = Yii::$app->has('user', true) ? Yii::$app->get('user') : null;
                        if ($user && ($identity = $user->getIdentity(false))) {
                            $userID = $identity->getId();
                        } else {
                            $userID = '-';
                        }

                        if (empty($_SERVER['LOG_ID']) || !is_string($_SERVER['LOG_ID'])) {
                            $_SERVER['LOG_ID'] = strval(uniqid());
                        }

                        return "[$ip] [$userID] [{$_SERVER['LOG_ID']}]";
                    }
                ],
                'db_log' => [
                    'levels' => ['warning','error'],
                    'class' => '\yii\log\DbTarget',
                    'exportInterval' => 1,
                    'logVars' => [],
                    'logTable' => '{{%system_log}}',
                ],

                'sys_notice'=>[
                    'class' => 'app\components\SystemNoticeLogger',
                    'levels' => ['error', 'warning'],
                    'logVars' => [],
                    //电报报警，会传入msg=xx&key=xx&chatId=xx到api_uri对应接口
                    //配置已移到系统配置表
                    'telegram'=>[],
                    //邮件报警
		            //配置已移到系统配置表
                    'email' => [],
                ],
            ],
        ],
        'mailer' => [
            'class' => 'yii\swiftmailer\Mailer',
//            'viewPath' => '@app/mail',
            'useFileTransport' =>false,//这句一定有，false发送邮件，true只是生成邮件在runtime文件夹下，不发邮件
            'transport' => [
                'class' => 'Swift_SmtpTransport',
                'encryption' => 'tls',
                'host' => 'smtp.gmail.com',
                'port' => '587',
                'username' => 'mail.booter.ui@gmail.com',
                'password' => 'htXb7wyFhDDEu74Y',
            ],
            'messageConfig'=>[
                'charset'=>'UTF-8',
                'from'=>['mail.booter.ui@gmail.com'=>'支付网关']
            ],
        ],
        'i18n' => [
            'translations' => [
                'app*' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    //'basePath' => '@app/messages',
                    //'sourceLanguage' => 'en-US',
                    'language' => 'zh-CN',
                    'fileMap' => [
                        'app' => 'app.php',
                        'app/error' => 'error.php',
                    ],
                ],
            ],
        ],
	
	//订单通知,出款提交银行等队列配置
	//充值订单通知
        'paymentNotifyQueue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis',
            'as log' => \yii\queue\LogBehavior::class,
            'channel' => REDIS_PREFIX.'tq_on',
//            'strictJobType' => false,
//            'serializer' => \yii\queue\serializers\JsonSerializer::class,
        ],
	//出款提交银行
        'remitBankCommitQueue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis',
            'as log' => \yii\queue\LogBehavior::class,
            'channel' => REDIS_PREFIX.'tq_rbc',
        ],
	//出款查询
        'remitQueryQueue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis',
            'as log' => \yii\queue\LogBehavior::class,
            'channel' => REDIS_PREFIX.'tq_rq',
        ],
        //出款通知
        'remitNotifyQueue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis',
            'as log' => \yii\queue\LogBehavior::class,
            'channel' => REDIS_PREFIX.'tq_rnq',
        ],
	//充值查询
        'orderQueryQueue' => [
            'class' => \yii\queue\redis\Queue::class,
            'redis' => 'redis',
            'as log' => \yii\queue\LogBehavior::class,
            'channel' => REDIS_PREFIX.'tq_oq',
        ],

        'on beforeRequest' => ['\power\yii2\log\LogHelper', 'onBeforeRequest'],
        'on afterRequest' => ['\power\yii2\log\LogHelper', 'onAfterRequest'],
    ],

    'params' => [
        'secret'   => [        // 参数签名私钥, 由客户端、服务端共同持有
        ],

        'paymentGateWayApiDefaultSignType' => 'md5',//rsa

        'user.apiTokenExpire' => 3600,
        'user.passwordResetTokenExpire' => 600,
        'user.rateLimit' => [60, 60],
    ],
];

return $config;
