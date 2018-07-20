<?php

namespace app\lib\payment\channels\sl;

use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicApiRequestLog;
use app\common\models\model\BankCodes;
use app\common\models\model\Channel;
use app\common\models\model\LogApiRequest;
use app\common\models\model\Order;
use app\common\models\model\Remit;
use app\components\Macro;
use app\components\Util;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\payment\channels\BasePayment;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\LogicRemit;
use power\yii2\net\exceptions\SignatureNotMatchException;
use Yii;

/**
 * 随乐支付接口
 *
 * @package app\lib\payment\channels\sl
 */
class SlBasePayment extends BasePayment
{
    const  PAY_TYPE_MAPS = [
        Channel::METHOD_WECHAT_QR => 'WeiXinScanOrder',
        Channel::METHOD_WECHAT_H5 => 'WeiXinWapOrder',
        Channel::METHOD_ALIPAY_QR => 'ZFBScanOrder',
        //此处文档有误，WeiXinWapOrder实际上是支付宝H5
        Channel::METHOD_ALIPAY_H5 => 'WeiXinWapOrder',
        Channel::METHOD_QQ_QR => 'QQScanOrder',
        Channel::METHOD_UNIONPAY_QR => 'YLScanOrder',
    ];

    CONST BANK_NAMES = [
        'BJRCB'=>'北京农商银行',
        'BOC'=>'中国银行',
        'CEB'=>'中国光大银行',
        'CIB'=>'兴业银行',
        'CITIC'=>'中信银行',
        'CMBC'=>'中国民生银行',
        'ICBC'=>'中国工商银行',
        'SPABANK'=>'平安银行',
        'SPDB'=>'浦发银行',
        'PSBC'=>'中国邮政储蓄银行',
        'NJCB'=>'南京银行',
        'COMM'=>'交通银行',
        'CMB'=>'招商银行',
        'CCB'=>'中国建设银行',
        'GDB'=>'广发银行',
        'HKBEA'=>'东亚银行',
    ];

    const SL_SERVER_CER = '-----BEGIN CERTIFICATE-----
MIICWDCCAcGgAwIBAgIJAIjWByBnT3ieMA0GCSqGSIb3DQEBCwUAMEUxCzAJBgNV
BAYTAkFVMRMwEQYDVQQIDApTb21lLVN0YXRlMSEwHwYDVQQKDBhJbnRlcm5ldCBX
aWRnaXRzIFB0eSBMdGQwHhcNMTcwNzI3MDYxODE0WhcNMTkwODE2MDYxODE0WjBF
MQswCQYDVQQGEwJBVTETMBEGA1UECAwKU29tZS1TdGF0ZTEhMB8GA1UECgwYSW50
ZXJuZXQgV2lkZ2l0cyBQdHkgTHRkMIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKB
gQC+2IOQUtg0PBZkuxyLlQ38QKPAQlf6vsRQ4/ROgzOaF1ERW57OGNWJd91PW48+
X3+YMDpLeuPnYXT1cfOXNfBNwBPSk1tOcn4Np2cJRWZHK+289RApNqfH+IbJoezh
ieADofVYTplNLCyJuJGj5RmaCAPicwiSwly6xWymIlmG+QIDAQABo1AwTjAdBgNV
HQ4EFgQUr2BeR+H34dax2gzg4GnbPnpwlGMwHwYDVR0jBBgwFoAUr2BeR+H34dax
2gzg4GnbPnpwlGMwDAYDVR0TBAUwAwEB/zANBgkqhkiG9w0BAQsFAAOBgQAgbKui
lsQibz6qZh6QRWMfT2uK3/xqH2KV8ASv4Ds7nMUhh7mGoCdPzmDJ/Lf+G7VK9boE
EuPAgzYI8tQtmJ2PNL1XizWe2ptpYNbHUfPJamNGJjetGw+2ql7G82ErMbu/urHS
/G8jE2Ybgma3A0SrvSABgJlmYfMht6LOOb4UAA==
-----END CERTIFICATE-----';

    public function __construct(...$arguments)
    {
        parent::__construct(...$arguments);
    }

    /*
     * 解析异步通知请求，返回订单
     *
     * @return array self::RECHARGE_NOTIFY_RESULT
     */
    public function parseNotifyRequest(array $request){
        $ret = self::RECHARGE_NOTIFY_RESULT;

        $result=file_get_contents('php://input', 'r');
        $tmp = explode("|", $result);
        if(count($tmp)!==2){
            return $ret;
        }
        $xml = simplexml_load_string(base64_decode($tmp[0]));
        $json = json_encode($xml);
        $request = json_decode($json, TRUE)['@attributes'];

        //按照文档获取所有签名参数,某些渠道签名参数不固定,也可以直接获取所有request
        $callbackParamsName = ["application","version","merchantId","merchantOrderId","deductList","deductList.item.payOrderId","deductList.item.payAmt","deductList.item.payStatus","deductList.item.payDesc","deductList.item.payTime","refundList"];
        $data = [];
        foreach ($callbackParamsName as $p){
            if(!isset($request[$p])){
                throw new SignatureNotMatchException("参数{$p}必须存在!");
            }
            $data[$p] = $request[$p];
        }

        //验证必要参数
        $data['merchantOrderId'] = ControllerParameterValidator::getRequestParam($request, 'merchantOrderId', null, Macro::CONST_PARAM_TYPE_ORDER_NO, '订单号错误！');
        $data['amount'] = ControllerParameterValidator::getRequestParam($request, 'deductList.item.payAmt', null, Macro::CONST_PARAM_TYPE_DECIMAL, '订单金额错误！');
        $data['status'] = ControllerParameterValidator::getRequestParam($request, 'deductList.item.payStatus', null, Macro::CONST_PARAM_TYPE_INT, '状态错误！');

        $order = LogicOrder::getOrderByOrderNo($data['merchantOrderId']);
        $this->setPaymentConfig($order->channelAccount);
        $this->setOrder($order);

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$order->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_IN_RECHARGE_NOTIFY,
            'merchant_id'=>$order->merchant_id,
            'merchant_name'=>$order->merchant_account,
            'channel_account_id'=>$order->channelAccount->id,
            'channel_name'=>$order->channelAccount->channel_name,
        ];

        //将平台传来的sign更改符号再进行验签方法
        $localSign = self::rsaVerify($data, $tmp[1], self::SL_SERVER_CER);
        if(!$localSign){
            throw new SignatureNotMatchException("签名验证失败");
        }

        $ret['data']['order'] = $order;
        $ret['data']['order_no'] = $order->order_no;

        if ($data['status']=='01') {
            $ret['data']['trade_status'] = Order::STATUS_PAID;
            $ret['data']['amount'] = $data['amount'];
            $ret['status'] = Macro::SUCCESS;
        }

        return $ret;
    }

    /*
     * 解析同步通知请求，返回订单
     * 返回订单对象表示请求验证成功且已经支付成功，可进行下一步业务
     * 返回int表示请求验证成功，订单未支付完成,int为订单在三方的状态
     * 其它表示错误
     *
     * @return array self::RECHARGE_NOTIFY_RESULT
     */
    public function parseReturnRequest(array $request){
        //同步仅返回订单号，直接忽略
        $ret = self::RECHARGE_NOTIFY_RESULT;
        return $ret;
    }

    /**
     * 微信扫码支付
     */
    public function wechatQr()
    {
        //网银支付获取银行代码
        if($this->order['pay_method_code']==Channel::METHOD_WEBBANK){
            $bankCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);

            if(empty($bankCode)){
                throw new OperationFailureException("银行代码配置错误:".$this->order['channel_id'].':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
            }
        }
        elseif(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }
        //其他支付获取支付通道代码
        else{
            $bankCode = self::PAY_TYPE_MAPS[$this->order['pay_method_code']];
        }

        $params = [
            'application' => self::PAY_TYPE_MAPS[$this->order['pay_method_code']],
            'version' => '1.0.1',
            'timestamp' => date('YmdHis'),
            'merchantId' => $this->order['channel_merchant_id'],
            'merchantOrderId' => $this->order['order_no'],
            'merchantOrderAmt' => bcadd(0, $this->order['amount'], 2)*100,
            'merchantOrderDesc' => 'recharge',
            'userName' => 'user_'.mt_rand(100000,999999),
            'payerId' => '',
            'salerId' => '',
            'guaranteeAmt' => '0',
            'merchantPayNotifyUrl' => str_replace('http','http',$this->getRechargeNotifyUrl()),
            'merchantFrontEndUrl' => '',
            'userMobileNo' => '',
            'credentialType' => '',
            'credentialNo' => '',
            'payerId' => '',
            'salerId' => '',
            'guaranteeAmt' => '',
            'userType' => '1',
            'accountType' => '0',
            'bankId' => '',//$bankCode,
            'msgExt' => '',
            'bizType' => '',
            'rptType' => '1',
            'payMode' => '0',
        ];

        $xml = self::arrayToXml($params);
        $base64Xml = base64_encode($xml);
        $sign = self::rsaSign($xml,trim($this->paymentConfig['key']));
        $requestData['msg'] = "{$base64Xml}|{$sign}";
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/pay/pay.htm";

        $retTxt = self::post($requestUrl,$requestData);
        //接口日志记录
        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $retTxt, [$xml]);

        $ret = self::RECHARGE_CASHIER_RESULT;
        if (!empty($retTxt)) {
            try {
                $retTxtArr = explode('|',$retTxt);

                if(count($retTxtArr)!==2){
                    $ret['message'] = '付款提交失败,支付服务器返回消息格式错误';
                    return $ret;
                }
                $xmlStr = base64_decode($retTxtArr[0]);
                Yii::info("{$this->order['order_no']} sl recharge xml response: ".$xmlStr);
                $xml = simplexml_load_string($xmlStr);
                $json = json_encode($xml);
                $res = json_decode($json, TRUE)['@attributes'];
            } catch (\Exception $e) {
                $res = [];
            }

            if(!empty($res['respCode'])&&$res['respCode']=='000'
            && !empty($res['codeUrl'])
            ){
                if(strpos($res['codeUrl'],'url=')!==false){
                    $urlArr = explode('url=', $res['codeUrl']);
                    $res['codeUrl'] = urldecode($urlArr[1]);
                }

                $ret['status'] = Macro::SUCCESS;
                if(Util::isMobileDevice() &&substr($res['codeUrl'],0,4)=='http'){
                    $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
                    $ret['data']['url'] = $res['codeUrl'];
                }else{
                    $ret['data']['type'] = self::RENDER_TYPE_QR;
                    $ret['data']['qr'] = $res['codeUrl'];
                }
            } else {
                $ret['message'] = $res['respDesc']??'返回数据错误';
            }
        }

        return $ret;
    }


    /**
     * 网银支付
     */
    public function webBank()
    {
        //网银支付获取银行代码
        if($this->order['pay_method_code']==Channel::METHOD_WEBBANK){
            $bankCode = BankCodes::getChannelBankCode($this->order['channel_id'],$this->order['bank_code']);

            if(empty($bankCode)){
                throw new OperationFailureException("银行代码配置错误:".$this->order['channel_id'].':'.$this->order['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
            }
        }
        elseif(empty(self::PAY_TYPE_MAPS[$this->order['pay_method_code']])){
            throw new OperationFailureException("程序支付方式未指定:".$this->order['channel_id'].':'.$this->order['bank_code'].':PAY_TYPE_MAPS',
                Macro::ERR_PAYMENT_BANK_CODE);
        }
        //其他支付获取支付通道代码
        else{
            $bankCode = self::PAY_TYPE_MAPS[$this->order['pay_method_code']];
        }

        $params = [
            'application' => 'SubmitOrder',
            'version' => '1.0.1',
            'merchantId' => $this->order['channel_merchant_id'],
            'merchantName' => '',
            'merchantOrderId' => $this->order['order_no'],
            'merchantOrderAmt' => bcadd(0, $this->order['amount'], 2)*100,
            'merchantOrderDesc' => '',
            'merchantPayNotifyUrl' => str_replace('http','http',$this->getRechargeNotifyUrl()),
            'merchantFrontEndUrl' => '',
            'userMobileNo' => '',
            'credentialType' => '',
            'credentialNo' => '',
            'payerId' => '',
            'userName' => '',
            'salerId' => '',
            'guaranteeAmt' => '',
            'userType' => '1',
            'accountType' => '0',
            'bankId' => '',//$bankCode,
            'msgExt' => '',
            'orderTime' => date('YmdHis'),
            'bizType' => '',
            'rptType' => '1',
            'payMode' => '0',
        ];

        $xml = self::arrayToXml($params);
        $base64Xml = base64_encode($xml);
        $sign = self::rsaSign($base64Xml,trim($this->paymentConfig['key']));

        $requestData['msg'] = "{$base64Xml}|{$sign}";
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/GateWay/ReceiveBank.aspx";

        $retTxt = self::post($requestUrl,$requestData);

        //接口日志记录
        LogicApiRequestLog::rechargeAddLog($this->order, $requestUrl, $retTxt, $requestData);

        $ret = self::RECHARGE_CASHIER_RESULT;
        $ret['status'] = Macro::SUCCESS;
        $ret['data']['type'] = self::RENDER_TYPE_REDIRECT;
        $ret['data']['url'] = $requestUrl;

        return $ret;
    }


    /**
     * 支付宝H5
     */
    public function alipayH5()
    {
        return $this->wechatQr();
    }
    /**
     * 微信H5
     */
    public function wechatH5()
    {
        return $this->wechatQr();
    }

    /**
     * 支付宝扫码支付
     */
    public function alipayQr()
    {
        return $this->wechatQr();
    }

    /**
     * QQ扫码支付
     */
    public function qqQr()
    {
        return $this->wechatQr();
    }

    /**
     * 银联扫码支付
     */
    public function unoinPayQr()
    {
        return $this->wechatQr();
    }

    /**
     * 收款订单状态查询
     *
     * @return array
     */
    public function orderStatus(){

        $params = [
            'merchant_no'=>$this->order['channel_merchant_id'],
            'merchant_billno'=>$this->order['order_no'],
            'sign_way'=>'md5',
        ];
        $params['sign'] = self::md5Sign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri']."/merchant/deposit/query?".http_build_query($params);
        $resTxt = self::httpGet($requestUrl);

        Yii::info('order query result: '.$this->order['order_no'].' '.$resTxt);
        $ret = self::RECHARGE_QUERY_RESULT;
        if (!empty($resTxt)) {
            $res = json_decode($resTxt, true);

            if (
                isset($res['code']) && $res['code'] == '0'
                && isset($res['data']['status'])
            ) {
                if(isset($res['data']['status'])=='200'
                    && !empty($res['data']['paid_amount'])){
                    $localSign = self::md5Sign($res,trim($this->paymentConfig['key']));
                    if($localSign == $res['data']['sign']){
                        $ret['status'] = Macro::SUCCESS;
                        $ret['data']['amount'] = $res['data']['paid_amount'];
                        $ret['data']['channel_order_no'] = $res['data']['billno'];
                        $ret['data']['trade_status'] = Order::STATUS_PAID;
                    }
                }
            } else {
                $ret['message'] = '订单查询失败:'.$resTxt;
            }
        }

        return  $ret;
    }

    /**
     * 余额查询,此通道没有余额查询接口.但是需要做伪方法,防止批量实时查询失败.
     *
     * return  array BasePayment::BALANCE_QUERY_RESULT
     */
    public function balance()
    {
    }

    /**
     * 提交出款请求
     *
     * @return array ['code'=>'Macro::FAIL|Macro::SUCCESS','data'=>['channel_order_no'=>'三方订单号',bank_status=>'三方银行状态,需转换为Remit表状态']]
     */
    public function remit(){
        $ret = self::REMIT_RESULT;

        if(empty($this->remit)){
            throw new OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }
        $bankCode = BankCodes::getChannelBankCode($this->remit['channel_id'],$this->remit['bank_code'],'remit');

        if(empty($bankCode)){
            throw new OperationFailureException("通道讯通宝银行代码配置错误:".$this->remit['channel_id'].':'.$this->remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        if(empty($this->remit['bank_province'])){
            $this->remit['bank_province'] = '北京市';
        }
        if(empty($this->remit['bank_city'])){
            $this->remit['bank_city'] = '北京市';
        }
        if(empty($this->remit['bank_branch'])){
            $this->remit['bank_branch'] = $this->remit['bank_name'].'北京市中关村分行';
        }

        $params = [
            'application' => 'ReceivePay',
            'version' => '1.0.1',
            'merchantId' => $this->remit['channel_merchant_id'],
            'tranId' => $this->remit['order_no'],
            'timestamp' => date('YmdHis'),
            'receivePayNotifyUrl' => str_replace('http','http',$this->getRemitNotifyUrl()),
            'receivePayType' => '1',
            'accountProp' => '0',
            'bankGeneralName' => self::BANK_NAMES[$this->remit['bank_code']],
            'accNo' =>  $this->remit['bank_no'],
            'accName' => $this->remit['bank_account'],
            'amount' => bcadd(0, $this->remit['amount'], 2)*100,
            'credentialType' => '01',
            'credentialNo' => '341801197312078423',//self::getRandIdCard(),
            'tel' => '15129281787',//self::getRandMobile(),
            'summary' => '',
        ];

        $xml = self::arrayToXml($params);
        Yii::info("sl remit request xml:".$xml);
        $base64Xml = base64_encode($xml);
        Yii::info("sl remit request base64Xml:".$base64Xml);
        $sign = self::rsaSign($xml,trim($this->paymentConfig['key']));
        $requestData['msg'] = "{$base64Xml}|{$sign}";
        $requestUrl = $this->paymentConfig['gateway_base_uri']."/pay/pay.htm";

        $resTxt = self::post($requestUrl, $requestData);
        LogicApiRequestLog::outLog($requestUrl, 'POST', $resTxt, 200,0, [$xml]);

//        Yii::info('remit to bank raw result: '.$this->remit['order_no'].' '.$resTxt);
        if (!empty($resTxt)) {
            try {
                $retTxtArr = explode('|',$resTxt);

                if(count($retTxtArr)!==2){
                    $ret['message'] = '出款提交失败,服务器返回参数格式错误';
                    return $ret;
                }
                $xmlStr = base64_decode($retTxtArr[0]);
                Yii::info("{$this->order['order_no']} sl remit xml response: ".$xmlStr);

                $xml = simplexml_load_string($xmlStr);
                $json = json_encode($xml);
                $res = json_decode($json, TRUE)['@attributes'];
            } catch (\Exception $e) {
                $res = [];
            }

            if(is_array($res) && !empty($res['sign'])){
                $localSign = self::rsaVerify($res,$res['sign'],trim(self::XTB_PUBLIC_KEY));
                Yii::info('remit query ret sign: '.$this->remit['order_no'].' local:'.$localSign.' back:'.$res['sign']);
                if (
                    isset($res['respCode']) && $res['respCode'] == '000'
                ) {
                    $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
                    $ret['message'] = "{$res['respDesc']}";
                    $ret['data']['amount'] = $res['r3_Amt'];
                    $ret['status'] = Macro::SUCCESS;
                } else {
                    if(strpos($res['respDesc'],'余额不足') !== false){
                        $ret['status'] = Macro::ERR_THIRD_CHANNEL_BALANCE_NOT_ENOUGH;
                    }

                    $ret['message'] = "{$res['respDesc']}";
                }
            }else{
                $ret['message'] = $res['respDesc']??'返回数据格式错误';
            }
        }

        return  $ret;
    }

    /**
     * 提交出款状态查询
     *
     * @return array ['code'=>'Macro::FAIL|Macro::SUCCESS','data'=>['channel_order_no'=>'三方订单号',bank_status=>'三方银行状态,需转换为Remit表状态']]
     */
    public function remitStatus(){
        if(empty($this->remit)){
            throw new OperationFailureException('未传入出款订单对象',Macro::ERR_UNKNOWN);
        }

        $params = [
            'p0_Cmd'=>'Money',
            'p1_MerId'=>$this->remit['channel_merchant_id'],
            'p2_Order'=>$this->remit['order_no'],
        ];
        $params['sign'] = self::rsaSign($params,trim($this->paymentConfig['key']));

        $requestUrl = $this->paymentConfig['gateway_base_uri'].'/GateWay/ReceiveWithdrawCheck.aspx';
        $resTxt = self::post($requestUrl, $params);
        LogicApiRequestLog::outLog($requestUrl, 'GET', $resTxt, 200,0, $params);

//        Yii::info('remit query result: '.$this->remit['order_no'].' '.$resTxt);
        $ret = self::REMIT_QUERY_RESULT;
        $ret['data']['remit'] = $this->remit;
        $ret['data']['order_no'] = $this->remit->order_no;

        if (!empty($resTxt)) {
            try{
                $xml = simplexml_load_string($resTxt);
                $json = json_encode($xml);
                $res = json_decode($json,TRUE);
            }catch (\Exception $e){
                $res = [];
            }

            if(is_array($res) && !empty($res['sign'])){
                $res['sign']= str_replace("*", "+",$res['sign']);
                $res['sign']= str_replace("-", "/",$res['sign']);
                $localSign = self::rsaVerify($res,$res['sign'],trim(self::XTB_PUBLIC_KEY));
                Yii::info('remit query ret sign: '.$this->remit['order_no'].' local:'.$localSign.' back:'.$res['sign']);
                if (
                    isset($res['retCode']) && $res['retCode'] == '0000'
                    && isset($res['state'])
                ) {

                    if($res['state'] == '00'){
                        $ret['data']['bank_status'] = Remit::BANK_STATUS_PROCESSING;
                    }elseif($res['r5_state'] == '04'){
                        $ret['state']['bank_status'] = Remit::BANK_STATUS_SUCCESS;
                        $ret['message'] = "出款提交失败({$resTxt})";
                    }elseif($res['state'] == '05'){
                        $ret['data']['bank_status'] = Remit::BANK_STATUS_FAIL;
                        $ret['message'] = "出款提交失败({$resTxt})";
                    }

                    $ret['data']['amount'] = $res['r3_Amt'];
                    $ret['status'] = Macro::SUCCESS;
                } else {
                    $ret['message'] = "出款查询失败({$resTxt})";
                }
            }else{
                $ret['message'] = "出款查询失败({$resTxt})";
            }
        }

        return  $ret;
    }

    /**
     * 生成通知响应内容
     *
     * @param boolean $isSuccess
     * @return string
     */
    public static function createdResponse($isSuccess)
    {
        $str = 'fail';
        if($isSuccess){
            $str = 'success';
        }
        return $str;
    }

    /**
     *
     * 发送post请求
     *
     * @param string $url 请求地址
     * @param array|string $postData 请求数据
     *
     * @return bool|string
     */
    public static function post(string $url, $postData, $header = [], $timeout = 20)
    {
        $headers = [];
        try {
            $ch = curl_init(); //初始化curl
            curl_setopt($ch,CURLOPT_URL, $url);//抓取指定网页
            curl_setopt($ch, CURLOPT_HEADER, 0);//设置header
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//要求结果为字符串且输出到屏幕上
            curl_setopt($ch, CURLOPT_POST, 1);//post提交方式
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            $body = curl_exec($ch);//运行curl
            curl_close($ch);
        } catch (\Exception $e) {
            $body     = $e->getMessage();
        }

        Yii::info('request to channel: ' . $url . ' ' . json_encode($postData,JSON_UNESCAPED_UNICODE). ' resp: ' . $body);

        return $body;
    }

    /**
     *
     * 获取参数rsa签名
     *
     * @param string $strToSign 要签名的参数数组
     * @param string $signKey 签名密钥
     *
     * @return bool|string
     */
    public static function rsaSign(string $strToSign, string $signKey){

//        if(substr($signKey,0,31)!='-----BEGIN RSA PRIVATE KEY-----'){
//            $signKey = "-----BEGIN RSA PRIVATE KEY-----\n" .
//                wordwrap($signKey, 64, "\n", true) .
//                "\n-----END RSA PRIVATE KEY-----";
//        }
//
//        $res = openssl_get_privatekey($signKey);
//        //调用openssl内置签名方法，生成签名$sign
//        openssl_sign($strToSign, $sign, $res);
//        openssl_free_key($res);
//        $signStr = base64_encode($sign);

        $strToSignBin = md5($strToSign,true);
        $certs = array();
        $keyFile = dirname(__FILE__).'/merchant_cert.pfx';
        openssl_pkcs12_read(file_get_contents(($keyFile)),
            $certs,"11111111"); //其中password为你的证书密码
        if(!$certs) return ;
        $signature = '';
        openssl_sign($strToSignBin, $signature, $certs['pkey']);
        $signStr = base64_encode($signature);

        Yii::info('rsaSign string: '.$signStr.' raw: '.$strToSign);
        return $signStr;
    }

    /**
     * RSA验签
     *
     * array $data 待签名数据
     * string $sign 需要验签的签名
     * string string $pubKey 公钥字符串,可以是原始密钥文本或去掉头尾两行及换行的密钥
     *
     * return bool 验签是否通过
     */
    function rsaVerify($data, $sign, $pubKey)
    {
        unset($data['sign']);
        unset($data['rp_transTime']);
        if(substr($pubKey,0,26)!='-----BEGIN PUBLIC KEY-----'){
            $wrapStr = wordwrap($pubKey, 64, "\n", true);
            $pubKey = "-----BEGIN PUBLIC KEY-----\n"
                .$wrapStr
                .= "\n-----END PUBLIC KEY-----";
        }
//var_dump($data);
        $strToSign = implode('', $data);
//        echo "回调参数: p1_MerId=2800&r0_Cmd=Buy&r1_Code=1&r2_TrxId=GM2018071815235554678318&r3_Amt=10.00&r4_Cur=RMB&r5_Pid=&r6_Order=118071815065664306&r7_Uid=&r8_MP=&r9_BType=2&rp_PayDate=2018/7/18%2015:24:58&sign=ITJMKhT4HMRmaesZMzF5yzUKwkz6Hz7u0Z6zX*MSF6Ec9EwFa*vvMABJcswi5Sh3AqoVB3aYJdNYimZOLQpjHuBo7yqemH-7JZ4epKaHl0r7ek78yhQ076mqFhsb9BGBGrBPYhugtuxqW6eRnzf3lg5l2RK*xWdUkX9DrfhWAZM=\n";
        echo "\n\n验签字符串: \n";
        echo ($strToSign);
        echo "\n\n回传签名：\n";
        echo ($sign);
        echo "\n\npubkey: \n";
        echo ($pubKey );

        $res = openssl_get_publickey($pubKey);
        // 调用openssl内置方法验签，返回bool值
        $result = (boolean)openssl_verify($strToSign, base64_decode($sign), $res);
        // 释放资源
        openssl_free_key($res);

        // 返回资源是否成功
        return $result;
    }

    /**
     * 转换数组为请求xml
     *
     * @param array $arr
     * @return string
     */
    public static function arrayToXml(array $arr)
    {
        $xml = '<'.'?xml version="1.0" encoding="utf-8" standalone="no"?>';
        $xml .= '<message';
        foreach ($arr as $k=>$v){
            $xml .= " {$k}=\"{$v}\"";
        }
        $xml .= '/>';

        return $xml;
    }

    /**
     * 随机手机号
     * @return string
     */
    public static function getRandMobile()
    {
        $data = array(
            130,131,132,133,134,135,136,137,138,139,144,147,150,151,152,153,155,156,157,158,159,176,177,178,180,181,182,183,184,185,186,187,188,189,
        );
        $prefix = $data[mt_rand(0, count($data) - 1)];
        return $prefix . mt_rand(10000000,99999999);
    }

    /**
     * 获取随机身份证号
     * @return string
     */
    public static function getRandIdCard()
    {
        $identity_card = '';
        //身份证起止年月 eg：1990年12月31日 mktime(0,0,0,12,31,1990)
        $year_start = mktime(0,0,0,1,1,1950);
        $year_end = mktime(0,0,0,12,31,1992);
        //全国区域代码 共3131
        $Region= array(
            110101,110102,110105,110106,110107,110108,110109,110111,110112,110113,110114,110115,
            110116,110117,110228,110229,120101,120102,120103,120104,120105,120106,120110,120111,
            120112,120113,120114,120115,120116,120221,120223,120225,130101,130102,130103,130104,
            130105,130107,130108,130121,130123,130124,130125,130126,130127,130128,130129,130130,
            130131,130132,130133,130181,130182,130183,130184,130185,130201,130202,130203,130204,
            130205,130207,130208,130209,130223,130224,130225,130227,130229,130281,130283,130301,
            130302,130303,130304,130321,130322,130323,130324,130401,130402,130403,130404,130406,
            130421,130423,130424,130425,130426,130427,130428,130429,130430,130431,130432,130433,
            130434,130435,130481,130501,130502,130503,130521,130522,130523,130524,130525,130526,
            130527,130528,130529,130530,130531,130532,130533,130534,130535,130581,130582,130601,
            130602,130603,130604,130621,130622,130623,130624,130625,130626,130627,130628,130629,
            130630,130631,130632,130633,130634,130635,130636,130637,130638,130681,130682,130683,
            130684,130701,130702,130703,130705,130706,130721,130722,130723,130724,130725,130726,
            130727,130728,130729,130730,130731,130732,130733,130801,130802,130803,130804,130821,
            130822,130823,130824,130825,130826,130827,130828,130901,130902,130903,130921,130922,
            130923,130924,130925,130926,130927,130928,130929,130930,130981,130982,130983,130984,
            131001,131002,131003,131022,131023,131024,131025,131026,131028,131081,131082,131101,
            131102,131121,131122,131123,131124,131125,131126,131127,131128,131181,131182,140101,
            140105,140106,140107,140108,140109,140110,140121,140122,140123,140181,140201,140202,
            140203,140211,140212,140221,140222,140223,140224,140225,140226,140227,140301,140302,
            140303,140311,140321,140322,140401,140402,140411,140421,140423,140424,140425,140426,
            140427,140428,140429,140430,140431,140481,140501,140502,140521,140522,140524,140525,
            140581,140601,140602,140603,140621,140622,140623,140624,140701,140702,140721,140722,
            140723,140724,140725,140726,140727,140728,140729,140781,140801,140802,140821,140822,
            140823,140824,140825,140826,140827,140828,140829,140830,140881,140882,140901,140902,
            140921,140922,140923,140924,140925,140926,140927,140928,140929,140930,140931,140932,
            140981,141001,141002,141021,141022,141023,141024,141025,141026,141027,141028,141029,
            141030,141031,141032,141033,141034,141081,141082,141101,141102,141121,141122,141123,
            141124,141125,141126,141127,141128,141129,141130,141181,141182,150101,150102,150103,
            150104,150105,150121,150122,150123,150124,150125,150201,150202,150203,150204,150205,
            150206,150207,150221,150222,150223,150301,150302,150303,150304,150401,150402,150403,
            150404,150421,150422,150423,150424,150425,150426,150428,150429,150430,150501,150502,
            150521,150522,150523,150524,150525,150526,150581,150601,150602,150621,150622,150623,
            150624,150625,150626,150627,150701,150702,150721,150722,150723,150724,150725,150726,
            150727,150781,150782,150783,150784,150785,150801,150802,150821,150822,150823,150824,
            150825,150826,150901,150902,150921,150922,150923,150924,150925,150926,150927,150928,
            150929,150981,152201,152202,152221,152222,152223,152224,152501,152502,152522,152523,
            152524,152525,152526,152527,152528,152529,152530,152531,152921,152922,152923,210101,
            210102,210103,210104,210105,210106,210111,210112,210113,210114,210122,210123,210124,
            210181,210201,210202,210203,210204,210211,210212,210213,210224,210281,210282,210283,
            210301,210302,210303,210304,210311,210321,210323,210381,210401,210402,210403,210404,
            210411,210421,210422,210423,210501,210502,210503,210504,210505,210521,210522,210601,
            210602,210603,210604,210624,210681,210682,210701,210702,210703,210711,210726,210727,
            210781,210782,210801,210802,210803,210804,210811,210881,210882,210901,210902,210903,
            210904,210905,210911,210921,210922,211001,211002,211003,211004,211005,211011,211021,
            211081,211101,211102,211103,211121,211122,211201,211202,211204,211221,211223,211224,
            211281,211282,211301,211302,211303,211321,211322,211324,211381,211382,211401,211402,
            211403,211404,211421,211422,211481,220101,220102,220103,220104,220105,220106,220112,
            220122,220181,220182,220183,220201,220202,220203,220204,220211,220221,220281,220282,
            220283,220284,220301,220302,220303,220322,220323,220381,220382,220401,220402,220403,
            220421,220422,220501,220502,220503,220521,220523,220524,220581,220582,220601,220602,
            220605,220621,220622,220623,220681,220701,220702,220721,220722,220723,220724,220801,
            220802,220821,220822,220881,220882,222401,222402,222403,222404,222405,222406,222424,
            222426,230101,230102,230103,230104,230108,230109,230110,230111,230112,230123,230124,
            230125,230126,230127,230128,230129,230182,230183,230184,230201,230202,230203,230204,
            230205,230206,230207,230208,230221,230223,230224,230225,230227,230229,230230,230231,
            230281,230301,230302,230303,230304,230305,230306,230307,230321,230381,230382,230401,
            230402,230403,230404,230405,230406,230407,230421,230422,230501,230502,230503,230505,
            230506,230521,230522,230523,230524,230601,230602,230603,230604,230605,230606,230621,
            230622,230623,230624,230701,230702,230703,230704,230705,230706,230707,230708,230709,
            230710,230711,230712,230713,230714,230715,230716,230722,230781,230801,230803,230804,
            230805,230811,230822,230826,230828,230833,230881,230882,230901,230902,230903,230904,
            230921,231001,231002,231003,231004,231005,231024,231025,231081,231083,231084,231085,
            231101,231102,231121,231123,231124,231181,231182,231201,231202,231221,231222,231223,
            231224,231225,231226,231281,231282,231283,232721,232722,232723,310101,310104,310105,
            310106,310107,310108,310109,310110,310112,310113,310114,310115,310116,310117,310118,
            310120,310230,320101,320102,320103,320104,320105,320106,320107,320111,320113,320114,
            320115,320116,320124,320125,320201,320202,320203,320204,320205,320206,320211,320281,
            320282,320301,320302,320303,320305,320311,320312,320321,320322,320324,320381,320382,
            320401,320402,320404,320405,320411,320412,320481,320482,320501,320505,320506,320507,
            320508,320509,320581,320582,320583,320585,320601,320602,320611,320612,320621,320623,
            320681,320682,320684,320701,320703,320705,320706,320721,320722,320723,320724,320801,
            320802,320803,320804,320811,320826,320829,320830,320831,320901,320902,320903,320921,
            320922,320923,320924,320925,320981,320982,321001,321002,321003,321012,321023,321081,
            321084,321101,321102,321111,321112,321181,321182,321183,321201,321202,321203,321281,
            321282,321283,321284,321301,321302,321311,321322,321323,321324,330101,330102,330103,
            330104,330105,330106,330108,330109,330110,330122,330127,330182,330183,330185,330201,
            330203,330204,330205,330206,330211,330212,330225,330226,330281,330282,330283,330301,
            330302,330303,330304,330322,330324,330326,330327,330328,330329,330381,330382,330401,
            330402,330411,330421,330424,330481,330482,330483,330501,330502,330503,330521,330522,
            330523,330601,330602,330621,330624,330681,330682,330683,330701,330702,330703,330723,
            330726,330727,330781,330782,330783,330784,330801,330802,330803,330822,330824,330825,
            330881,330901,330902,330903,330921,330922,331001,331002,331003,331004,331021,331022,
            331023,331024,331081,331082,331101,331102,331121,331122,331123,331124,331125,331126,
            331127,331181,340101,340102,340103,340104,340111,340121,340122,340123,340124,340181,
            340201,340202,340203,340207,340208,340221,340222,340223,340225,340301,340302,340303,
            340304,340311,340321,340322,340323,340401,340402,340403,340404,340405,340406,340421,
            340501,340503,340504,340506,340521,340522,340523,340601,340602,340603,340604,340621,
            340701,340702,340703,340711,340721,340801,340802,340803,340811,340822,340823,340824,
            340825,340826,340827,340828,340881,341001,341002,341003,341004,341021,341022,341023,
            341024,341101,341102,341103,341122,341124,341125,341126,341181,341182,341201,341202,
            341203,341204,341221,341222,341225,341226,341282,341301,341302,341321,341322,341323,
            341324,341501,341502,341503,341521,341522,341523,341524,341525,341601,341602,341621,
            341622,341623,341701,341702,341721,341722,341723,341801,341802,341821,341822,341823,
            341824,341825,341881,350101,350102,350103,350104,350105,350111,350121,350122,350123,
            350124,350125,350128,350181,350182,350201,350203,350205,350206,350211,350212,350213,
            350301,350302,350303,350304,350305,350322,350401,350402,350403,350421,350423,350424,
            350425,350426,350427,350428,350429,350430,350481,350501,350502,350503,350504,350505,
            350521,350524,350525,350526,350527,350581,350582,350583,350601,350602,350603,350622,
            350623,350624,350625,350626,350627,350628,350629,350681,350701,350702,350721,350722,
            350723,350724,350725,350781,350782,350783,350784,350801,350802,350821,350822,350823,
            350824,350825,350881,350901,350902,350921,350922,350923,350924,350925,350926,350981,
            350982,360101,360102,360103,360104,360105,360111,360121,360122,360123,360124,360201,
            360202,360203,360222,360281,360301,360302,360313,360321,360322,360323,360401,360402,
            360403,360421,360423,360424,360425,360426,360427,360428,360429,360430,360481,360482,
            360501,360502,360521,360601,360602,360622,360681,360701,360702,360721,360722,360723,
            360724,360725,360726,360727,360728,360729,360730,360731,360732,360733,360734,360735,
            360781,360782,360801,360802,360803,360821,360822,360823,360824,360825,360826,360827,
            360828,360829,360830,360881,360901,360902,360921,360922,360923,360924,360925,360926,
            360981,360982,360983,361001,361002,361021,361022,361023,361024,361025,361026,361027,
            361028,361029,361030,361101,361102,361121,361122,361123,361124,361125,361126,361127,
            361128,361129,361130,361181,370101,370102,370103,370104,370105,370112,370113,370124,
            370125,370126,370181,370201,370202,370203,370205,370211,370212,370213,370214,370281,
            370282,370283,370284,370285,370301,370302,370303,370304,370305,370306,370321,370322,
            370323,370401,370402,370403,370404,370405,370406,370481,370501,370502,370503,370521,
            370522,370523,370601,370602,370611,370612,370613,370634,370681,370682,370683,370684,
            370685,370686,370687,370701,370702,370703,370704,370705,370724,370725,370781,370782,
            370783,370784,370785,370786,370801,370802,370811,370826,370827,370828,370829,370830,
            370831,370832,370881,370882,370883,370901,370902,370911,370921,370923,370982,370983,
            371001,371002,371081,371082,371083,371101,371102,371103,371121,371122,371201,371202,
            371203,371301,371302,371311,371312,371321,371322,371323,371324,371325,371326,371327,
            371328,371329,371401,371402,371421,371422,371423,371424,371425,371426,371427,371428,
            371481,371482,371501,371502,371521,371522,371523,371524,371525,371526,371581,371601,
            371602,371621,371622,371623,371624,371625,371626,371701,371702,371721,371722,371723,
            371724,371725,371726,371727,371728,410101,410102,410103,410104,410105,410106,410108,
            410122,410181,410182,410183,410184,410185,410201,410202,410203,410204,410205,410211,
            410221,410222,410223,410224,410225,410301,410302,410303,410304,410305,410306,410311,
            410322,410323,410324,410325,410326,410327,410328,410329,410381,410401,410402,410403,
            410404,410411,410421,410422,410423,410425,410481,410482,410501,410502,410503,410505,
            410506,410522,410523,410526,410527,410581,410601,410602,410603,410611,410621,410622,
            410701,410702,410703,410704,410711,410721,410724,410725,410726,410727,410728,410781,
            410782,410801,410802,410803,410804,410811,410821,410822,410823,410825,410882,410883,
            410901,410902,410922,410923,410926,410927,410928,411001,411002,411023,411024,411025,
            411081,411082,411101,411102,411103,411104,411121,411122,411201,411202,411221,411222,
            411224,411281,411282,411301,411302,411303,411321,411322,411323,411324,411325,411326,
            411327,411328,411329,411330,411381,411401,411402,411403,411421,411422,411423,411424,
            411425,411426,411481,411501,411502,411503,411521,411522,411523,411524,411525,411526,
            411527,411528,411601,411602,411621,411622,411623,411624,411625,411626,411627,411628,
            411681,411701,411702,411721,411722,411723,411724,411725,411726,411727,411728,411729,
            419001,420101,420102,420103,420104,420105,420106,420107,420111,420112,420113,420114,
            420115,420116,420117,420201,420202,420203,420204,420205,420222,420281,420301,420302,
            420303,420321,420322,420323,420324,420325,420381,420501,420502,420503,420504,420505,
            420506,420525,420526,420527,420528,420529,420581,420582,420583,420601,420602,420606,
            420607,420624,420625,420626,420682,420683,420684,420701,420702,420703,420704,420801,
            420802,420804,420821,420822,420881,420901,420902,420921,420922,420923,420981,420982,
            420984,421001,421002,421003,421022,421023,421024,421081,421083,421087,421101,421102,
            421121,421122,421123,421124,421125,421126,421127,421181,421182,421201,421202,421221,
            421222,421223,421224,421281,421301,421303,421321,421381,422801,422802,422822,422823,
            422825,422826,422827,422828,429004,429005,429006,429021,430101,430102,430103,430104,
            430105,430111,430112,430121,430124,430181,430201,430202,430203,430204,430211,430221,
            430223,430224,430225,430281,430301,430302,430304,430321,430381,430382,430401,430405,
            430406,430407,430408,430412,430421,430422,430423,430424,430426,430481,430482,430501,
            430502,430503,430511,430521,430522,430523,430524,430525,430527,430528,430529,430581,
            430601,430602,430603,430611,430621,430623,430624,430626,430681,430682,430701,430702,
            430703,430721,430722,430723,430724,430725,430726,430781,430801,430802,430811,430821,
            430822,430901,430902,430903,430921,430922,430923,430981,431001,431002,431003,431021,
            431022,431023,431024,431025,431026,431027,431028,431081,431101,431102,431103,431121,
            431122,431123,431124,431125,431126,431127,431128,431129,431201,431202,431221,431222,
            431223,431224,431225,431226,431227,431228,431229,431230,431281,431301,431302,431321,
            431322,431381,431382,433101,433122,433123,433124,433125,433126,433127,433130,440101,
            440103,440104,440105,440106,440111,440112,440113,440114,440115,440116,440183,440184,
            440201,440203,440204,440205,440222,440224,440229,440232,440233,440281,440282,440301,
            440303,440304,440305,440306,440307,440308,440401,440402,440403,440404,440501,440507,
            440511,440512,440513,440514,440515,440523,440601,440604,440605,440606,440607,440608,
            440701,440703,440704,440705,440781,440783,440784,440785,440801,440802,440803,440804,
            440811,440823,440825,440881,440882,440883,440901,440902,440903,440923,440981,440982,
            440983,441201,441202,441203,441223,441224,441225,441226,441283,441284,441301,441302,
            441303,441322,441323,441324,441401,441402,441421,441422,441423,441424,441426,441427,
            441481,441501,441502,441521,441523,441581,441601,441602,441621,441622,441623,441624,
            441625,441701,441702,441721,441723,441781,441801,441802,441821,441823,441825,441826,
            441827,441881,441882,445101,445102,445121,445122,445201,445202,445221,445222,445224,
            445281,445301,445302,445321,445322,445323,445381,450101,450102,450103,450105,450107,
            450108,450109,450122,450123,450124,450125,450126,450127,450201,450202,450203,450204,
            450205,450221,450222,450223,450224,450225,450226,450301,450302,450303,450304,450305,
            450311,450321,450322,450323,450324,450325,450326,450327,450328,450329,450330,450331,
            450332,450401,450403,450404,450405,450421,450422,450423,450481,450501,450502,450503,
            450512,450521,450601,450602,450603,450621,450681,450701,450702,450703,450721,450722,
            450801,450802,450803,450804,450821,450881,450901,450902,450921,450922,450923,450924,
            450981,451001,451002,451021,451022,451023,451024,451025,451026,451027,451028,451029,
            451030,451031,451101,451102,451121,451122,451123,451201,451202,451221,451222,451223,
            451224,451225,451226,451227,451228,451229,451281,451301,451302,451321,451322,451323,
            451324,451381,451401,451402,451421,451422,451423,451424,451425,451481,460101,460105,
            460106,460107,460108,460201,460321,460322,460323,469001,469002,469003,469005,469006,
            469007,469021,469022,469023,469024,469025,469026,469027,469028,469029,469030,500101,
            500102,500103,500104,500105,500106,500107,500108,500109,500110,500111,500112,500113,
            500114,500115,500116,500117,500118,500119,500223,500224,500226,500227,500228,500229,
            500230,500231,500232,500233,500234,500235,500236,500237,500238,500240,500241,500242,
            500243,510101,510104,510105,510106,510107,510108,510112,510113,510114,510115,510121,
            510122,510124,510129,510131,510132,510181,510182,510183,510184,510301,510302,510303,
            510304,510311,510321,510322,510401,510402,510403,510411,510421,510422,510501,510502,
            510503,510504,510521,510522,510524,510525,510601,510603,510623,510626,510681,510682,
            510683,510701,510703,510704,510722,510723,510724,510725,510726,510727,510781,510801,
            510802,510811,510812,510821,510822,510823,510824,510901,510903,510904,510921,510922,
            510923,511001,511002,511011,511024,511025,511028,511101,511102,511111,511112,511113,
            511123,511124,511126,511129,511132,511133,511181,511301,511302,511303,511304,511321,
            511322,511323,511324,511325,511381,511401,511402,511421,511422,511423,511424,511425,
            511501,511502,511503,511521,511523,511524,511525,511526,511527,511528,511529,511601,
            511602,511621,511622,511623,511681,511701,511702,511721,511722,511723,511724,511725,
            511781,511801,511802,511803,511822,511823,511824,511825,511826,511827,511901,511902,
            511921,511922,511923,512001,512002,512021,512022,512081,513221,513222,513223,513224,
            513225,513226,513227,513228,513229,513230,513231,513232,513233,513321,513322,513323,
            513324,513325,513326,513327,513328,513329,513330,513331,513332,513333,513334,513335,
            513336,513337,513338,513401,513422,513423,513424,513425,513426,513427,513428,513429,
            513430,513431,513432,513433,513434,513435,513436,513437,520101,520102,520103,520111,
            520112,520113,520114,520121,520122,520123,520181,520201,520203,520221,520222,520301,
            520302,520303,520321,520322,520323,520324,520325,520326,520327,520328,520329,520330,
            520381,520382,520401,520402,520421,520422,520423,520424,520425,520502,520521,520522,
            520523,520524,520525,520526,520527,520602,520603,520621,520622,520623,520624,520625,
            520626,520627,520628,522301,522322,522323,522324,522325,522326,522327,522328,522601,
            522622,522623,522624,522625,522626,522627,522628,522629,522630,522631,522632,522633,
            522634,522635,522636,522701,522702,522722,522723,522725,522726,522727,522728,522729,
            522730,522731,522732,530101,530102,530103,530111,530112,530113,530114,530122,530124,
            530125,530126,530127,530128,530129,530181,530301,530302,530321,530322,530323,530324,
            530325,530326,530328,530381,530402,530421,530422,530423,530424,530425,530426,530427,
            530428,530501,530502,530521,530522,530523,530524,530601,530602,530621,530622,530623,
            530624,530625,530626,530627,530628,530629,530630,530701,530702,530721,530722,530723,
            530724,530801,530802,530821,530822,530823,530824,530825,530826,530827,530828,530829,
            530901,530902,530921,530922,530923,530924,530925,530926,530927,532301,532322,532323,
            532324,532325,532326,532327,532328,532329,532331,532501,532502,532503,532523,532524,
            532525,532526,532527,532528,532529,532530,532531,532532,532601,532622,532623,532624,
            532625,532626,532627,532628,532801,532822,532823,532901,532922,532923,532924,532925,
            532926,532927,532928,532929,532930,532931,532932,533102,533103,533122,533123,533124,
            533321,533323,533324,533325,533421,533422,533423,540101,540102,540121,540122,540123,
            540124,540125,540126,540127,542121,542122,542123,542124,542125,542126,542127,542128,
            542129,542132,542133,542221,542222,542223,542224,542225,542226,542227,542228,542229,
            542231,542232,542233,542301,542322,542323,542324,542325,542326,542327,542328,542329,
            542330,542331,542332,542333,542334,542335,542336,542337,542338,542421,542422,542423,
            542424,542425,542426,542427,542428,542429,542430,542521,542522,542523,542524,542525,
            542526,542527,542621,542622,542623,542624,542625,542626,542627,610101,610102,610103,
            610104,610111,610112,610113,610114,610115,610116,610122,610124,610125,610126,610201,
            610202,610203,610204,610222,610301,610302,610303,610304,610322,610323,610324,610326,
            610327,610328,610329,610330,610331,610401,610402,610403,610404,610422,610423,610424,
            610425,610426,610427,610428,610429,610430,610431,610481,610501,610502,610521,610522,
            610523,610524,610525,610526,610527,610528,610581,610582,610601,610602,610621,610622,
            610623,610624,610625,610626,610627,610628,610629,610630,610631,610632,610701,610702,
            610721,610722,610723,610724,610725,610726,610727,610728,610729,610730,610801,610802,
            610821,610822,610823,610824,610825,610826,610827,610828,610829,610830,610831,610901,
            610902,610921,610922,610923,610924,610925,610926,610927,610928,610929,611001,611002,
            611021,611022,611023,611024,611025,611026,620101,620102,620103,620104,620105,620111,
            620121,620122,620123,620201,620301,620302,620321,620401,620402,620403,620421,620422,
            620423,620501,620502,620503,620521,620522,620523,620524,620525,620601,620602,620621,
            620622,620623,620701,620702,620721,620722,620723,620724,620725,620801,620802,620821,
            620822,620823,620824,620825,620826,620901,620902,620921,620922,620923,620924,620981,
            620982,621001,621002,621021,621022,621023,621024,621025,621026,621027,621101,621102,
            621121,621122,621123,621124,621125,621126,621201,621202,621221,621222,621223,621224,
            621225,621226,621227,621228,622901,622921,622922,622923,622924,622925,622926,622927,
            623001,623021,623022,623023,623024,623025,623026,623027,630101,630102,630103,630104,
            630105,630121,630122,630123,632121,632122,632123,632126,632127,632128,632221,632222,
            632223,632224,632321,632322,632323,632324,632521,632522,632523,632524,632525,632621,
            632622,632623,632624,632625,632626,632721,632722,632723,632724,632725,632726,632801,
            632802,632821,632822,632823,640101,640104,640105,640106,640121,640122,640181,640201,
            640202,640205,640221,640301,640302,640303,640323,640324,640381,640401,640402,640422,
            640423,640424,640425,640501,640502,640521,640522,650101,650102,650103,650104,650105,
            650106,650107,650109,650121,650201,650202,650203,650204,650205,652101,652122,652123,
            652201,652222,652223,652301,652302,652323,652324,652325,652327,652328,652701,652722,
            652723,652801,652822,652823,652824,652825,652826,652827,652828,652829,652901,652922,
            652923,652924,652925,652926,652927,652928,652929,653001,653022,653023,653024,653101,
            653121,653122,653123,653124,653125,653126,653127,653128,653129,653130,653131,653201,
            653221,653222,653223,653224,653225,653226,653227,654002,654003,654021,654022,654023,
            654024,654025,654026,654027,654028,654201,654202,654221,654223,654224,654225,654226,
            654301,654321,654322,654323,654324,654325,654326,659001,659002,659003,659004);
        function calc_suffix_d ($base){
            if (strlen($base) <> 17){
                die('Invalid Length');
            }
            $factor = array(7,9,10,5,8,4,2,1,6,3,7,9,10,5,8,4,2);
            $sums = 0;
            for ($i=0;$i< 17;$i++){
                $sums += substr($base,$i,1) * $factor[$i];
            }
            $mods = $sums % 11;//10X98765432
            switch ($mods){
                case 0: return '1';break;
                case 1: return '0';break;
                case 2: return 'x';break;
                case 3: return '9';break;
                case 4: return '8';break;
                case 5: return '7';break;
                case 6: return '6';break;
                case 7: return '5';break;
                case 8: return '4';break;
                case 9: return '3';break;
                case 10: return '2';break;
            }
        }
        $seed  = mt_rand(0,3130);//total of region code
        $birth = mt_rand($year_start,$year_end);
        $birth_format = date('Ymd',$birth);
        $suffix_a = mt_rand(0,9);
        $suffix_b = mt_rand(0,9);
        $suffix_c = mt_rand(0,9);//male or female
        $base = $Region[$seed].$birth_format.$suffix_a.$suffix_b.$suffix_c;
        $identity_card .= $base.calc_suffix_d($base);
        return $identity_card;
    }

}