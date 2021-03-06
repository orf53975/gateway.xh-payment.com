<?php
namespace app\modules\gateway\controllers\v1\inner;

use app\common\models\model\LogApiRequest;
use app\common\models\model\Remit;
use app\common\models\model\SiteConfig;
use app\common\models\model\User;
use app\components\Macro;
use app\components\Util;
use app\jobs\RemitCommitJob;
use app\jobs\RemitQueryJob;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\helpers\ResponseHelper;
use app\lib\payment\ChannelPayment;
use app\modules\gateway\controllers\v1\BaseInnerController;
use app\modules\gateway\models\logic\LogicRemit;
use Yii;

/**
 * 后台充值订单接口
 */
class RemitController extends BaseInnerController
{
    /**
     * 前置action
     *
     * @author booter.ui@gmail.com
     */
    public function beforeAction($action){
        return parent::beforeAction($action);
    }

    /**
     * 后台手工出款
     */
    public function actionAdd()
    {
        $merchantUsername = ControllerParameterValidator::getRequestParam($this->allParams, 'merchant_username', null,Macro::CONST_PARAM_TYPE_USERNAME,'充值账户错误');
        $rawRemits = ControllerParameterValidator::getRequestParam($this->allParams, 'remits', null,Macro::CONST_PARAM_TYPE_ARRAY,'提款列表错误');

        $totalAmount = 0;
        $remitCount = count($rawRemits);
        $isBatch = $remitCount>1;
        $batOrderNo = $isBatch?LogicRemit::generateBatRemitNo():'';
        $remits = $errRemits = $okRemits = [];


        //出款账户
        $merchant = User::findOne(['username'=>$merchantUsername]);
        if(empty($merchant)){
            return ResponseHelper::formatOutput(Macro::ERR_USER_NOT_FOUND,'用户不存在:'.$merchantUsername,['batOrderNo'=>$batOrderNo, 'errRemits'=>$rawRemits,
                'okRemits'=>$okRemits]);
        }

        $channelAccount = $merchant->paymentInfo->remitChannel;
        if(empty($channelAccount)){
            return ResponseHelper::formatOutput(Macro::ERR_REMIT_BANK_CONFIG,'用户出款通道未配置',['batOrderNo'=>$batOrderNo, 'errRemits'=>$rawRemits,
                'okRemits'=>$okRemits]);
        }

        //拆单后单笔最大金额,取渠道配置,用户配置,系统配置中的较小值
        $maxAmount = $channelAccount->remit_quota_pertime ? $channelAccount->remit_quota_pertime : Remit::MAX_REMIT_PER_TIME; //渠道单次限额,默认49999
        if ($merchant->paymentInfo->remit_quota_pertime && $maxAmount > $merchant->paymentInfo->remit_quota_pertime) $maxAmount = $merchant->paymentInfo->remit_quota_pertime; //用户单次限额
        $maxSplitConfigAmount = SiteConfig::cacheGetContent('remit_order_split_max_amount');//系统配置的拆单最高金额
        if($maxSplitConfigAmount || $maxSplitConfigAmount<$maxAmount) $maxAmount = $maxSplitConfigAmount;

        //系统配置的拆单后单笔最小金额
        $minAmount = SiteConfig::cacheGetContent('remit_order_split_min_amount');//35000;
        if(!$minAmount || $minAmount>=$maxAmount) $minAmount = intval($maxAmount * 0.8);

        Yii::info(json_encode(['remit add split to minAmount maxAmount',$merchantUsername,$minAmount, $maxAmount,$channelAccount->remit_quota_pertime,$merchant->paymentInfo->remit_quota_pertime]));
        foreach ($rawRemits as $i => $remitArr) {
            //拆单后最大最小金额都不为0的才拆单.($maxAmount>10000)防止配置的拆单金额过小,引起大批量小额订单
            if ($minAmount>0 && $maxAmount>10000 && $remitArr['amount'] >= $maxAmount) {
                $leftAmount   = $remitArr['amount'];
                $splitAmounts = [];
                while ($leftAmount > 0) {
                    if ($maxAmount >= $leftAmount) {
                        $splitAmounts[] = $leftAmount;
                        break;
                    }

                    $per            = mt_rand($minAmount, $maxAmount);//bcdiv(mt_rand($minAmount*10,$maxAmount*10),10,2);
                    $leftAmount     = bcsub($leftAmount, $per, 2);
                    $splitAmounts[] = $per;
                }

                Yii::info("{$merchantUsername}，{$remitArr['amount']},{$remitArr['bank_no']} remit add  split to: " . implode(',', $splitAmounts) . ' sum is:' . array_sum($splitAmounts),' rawAmount: '.$remitArr['amount']);
                unset($rawRemits[$i]);
                foreach ($splitAmounts as $sa) {
                    $rawRemits[] = array_merge($remitArr, ['amount' => $sa , 'split_raw_amount'=>$remitArr['amount']]);
                }

            }
        }

        foreach ($rawRemits as $i=>$remitArr){
            $totalAmount=bcadd($totalAmount,$remitArr['amount'],6);

            if(
                empty($remitArr['amount'])
                || empty($remitArr['bank_code'])
                || empty($remitArr['bank_no'])
                || empty($remitArr['bank_account'])
//                || empty($remitArr['bank_province'])
//                || empty($remitArr['bank_city'])
//                || empty($remitArr['bank_branch'])

            ){
                $remitArr['msg'] = '银行信息不能为空';
                $errRemits[] = $remitArr;
                continue;
            }

            if($isBatch){
                $remitArr['bat_order_no'] = $batOrderNo;
                $remitArr['bat_index'] = $i+1;
                $remitArr['bat_count'] = $remitCount;
            }else{
                $remitArr['bat_order_no'] = '';
                $remitArr['bat_index'] = 0;
                $remitArr['bat_count'] = 0;
            }

            $remits[] = $remitArr;

        }

        //初步余额检测
        if($merchant->balance<$totalAmount){
            return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,
                "账户余额($merchant->balance)小于总出款金额($totalAmount)",
                ['batOrderNo'=>$batOrderNo, 'errRemits'=>$rawRemits,'okRemits'=>$okRemits]
            );
        }

        try{
            foreach ($remits as $remit){
                $request['trade_no'] = LogicRemit::generateMerchantRemitNo();
                $request['op_uid']              = $this->allParams['op_uid'] ?? 0;
                $request['op_username']         = $this->allParams['op_username'] ?? '';
                $request['client_ip']         = $this->allParams['op_ip'] ?? '';

                $request['bat_order_no'] = $remit['bat_order_no']??'';
                $request['bat_index'] = $remit['bat_index']??0;
                $request['bat_count'] = $remit['bat_count']??0;
                $request['bank_code'] = $remit['bank_code'];
                $request['account_name'] = $remit['bank_account'];
                $request['account_number'] = $remit['bank_no'];
                $request['order_amount'] = $remit['amount'];
                $request['split_raw_amount'] = $remit['split_raw_amount']??$request['order_amount'];
                $request['type'] = Remit::TYPE_BACKEND;

                //生成订单
                $remit = LogicRemit::addRemit($request, $merchant, $channelAccount);
                $okRemits[] = [$remit->order_no];
            }
        }catch (\Exception $e){
            return ResponseHelper::formatOutput(Macro::ERR_UNKNOWN,$e->getMessage(),['batOrderNo'=>$batOrderNo, 'errRemits'=>$errRemits, 'okRemits'=>$okRemits]);
        }


        return ResponseHelper::formatOutput(Macro::SUCCESS,'',['batOrderNo'=>$batOrderNo, 'errRemits'=>$errRemits, 'okRemits'=>$okRemits]);
    }

    /**
     * 后台同步出款状态并处理业务
     */
    public function actionSyncStatus()
    {
        $inSeconds = ControllerParameterValidator::getRequestParam($this->allParams, 'inSeconds', '',Macro::CONST_PARAM_TYPE_INT_GT_ZERO,'时间秒数错误');
        $orderNoList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        if(empty($inSeconds) && empty($orderNoList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $filter['status'] = [Remit::STATUS_CHECKED,Remit::STATUS_BANK_PROCESSING,Remit::STATUS_BANK_PROCESS_FAIL,Remit::STATUS_BANK_NET_FAIL,Remit::STATUS_SUCCESS,Remit::STATUS_NOT_REFUND];
        //最长一天
        if($inSeconds>14400) $inSeconds = 14400;
        if($inSeconds){
            $filter[] = ['>=','created_at',time()-$inSeconds];
        }
        if($orderNoList){
            foreach ($orderNoList as $k=>$on){
                if(!Util::validate($on,Macro::CONST_PARAM_TYPE_ORDER_NO)){
                    unset($orderNoList[$k]);
                }
            }

            $filter['order_no'] = $orderNoList;
        }
        $remits = Remit::findAll($filter);
        $orderAmount = count($remits);
        foreach ($remits as $remit){
            //低于10个,实时提交
            if($orderAmount<=10){
                Yii::info('remit status check realtime: '.$remit->order_no);
                LogicRemit::queryChannelRemitStatus($remit);
            }
            //多于10,加入队列
            else{
                Yii::info('remit status check by queue: '.$remit->order_no);
                $job = new RemitQueryJob([
                    'orderNo'=>$remit->order_no,
                ]);
                Yii::$app->remitQueryQueue->push($job);
            }
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS,'');
    }

    /**
     * 实时查看订单上游状态
     * 仅仅查询结果返回显示,不进行业务处理
     */
    public function actionSyncStatusRealtime()
    {
        $orderNo = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNo', null,Macro::CONST_PARAM_TYPE_ORDER_NO,'订单号列表错误');

        $remit =  Remit::findOne(['order_no'=>$orderNo]);
        if(!$remit){
            return ResponseHelper::formatOutput(Macro::FAIL,'订单不存在');
        }

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$remit->order_no,
            'merchant_order_no'=>$remit->merchant_order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_REMIT_QUERY,
            'merchant_id'=>$remit->channel_merchant_id,
            'merchant_name'=>$remit->channelAccount->merchant_account,
            'channel_account_id'=>$remit->channel_account_id,
            'channel_name'=>$remit->channelAccount->channel_name,
        ];

        $paymentChannelAccount = $remit->channelAccount;
        $payment = new ChannelPayment($remit, $paymentChannelAccount);
        $remitRet = $payment->remitStatus();

        $msg = '';
        if(
            isset($remitRet['data']['bank_status'])
            && !empty($remitRet['data']['remit'])
        ){

            if($remitRet['status'] === Macro::SUCCESS){
                switch ($remitRet['data']['bank_status']){
                    case Remit::BANK_STATUS_PROCESSING:
                        $msg =date('Ymd H:i:s')." 银行处理中"."\n";
                        break;
                    case Remit::BANK_STATUS_SUCCESS:
                        if(!empty($remitRet['data']['amount'])
                            && bccomp($remitRet['data']['amount'],$remitRet['data']['remit']->amount,2)!==0
                        ){
                            $msg = date('Y-m-d H:i:s')." 实际出款金额({$remitRet['data']['amount']})与订单金额({$remitRet['data']['remit']->amount})不符合，请手工确认。\n";
                            Yii::error($remitRet['data']['remit']->order_no.' '.$msg);
                        }else{
                            $msg = "出款成功";
                        }
                        break;
                    case  Remit::BANK_STATUS_FAIL:
                        $msg = date('Y-m-d H:i:s').' 出款失败:'.$remitRet['message']."\n";
                        break;
                    default:
                        $msg = date('Y-m-d H:i:s').' :'.$remitRet['message'].($remitRet['data']['rawMessage']??'')."\n";
                        break;

                }
            }
        }else{
            $msg = '订单查询结果数据结构错误'.$remitRet['message'];
        }

        $msg = '本地状态:'.Remit::ARR_STATUS[$remit->status]."\n上游状态:".$msg;
        if(!empty($remitRet['data']['rawMessage'])){
            $msg.=" \n原始消息".$remitRet['data']['rawMessage'];
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS, $msg);
    }

    /**
     * 设置订单为成功
     */
    public function actionSetSuccess()
    {
        $rawOrderList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        Yii::info($rawOrderList);
        if(empty($rawOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $opOrderList = [];
        foreach ($rawOrderList as $k=>$on){
            if(Util::validate($on['order_no'],Macro::CONST_PARAM_TYPE_ORDER_NO)){
                $opOrderList[$on['order_no']] = $on;
            }
        }
        if(empty($opOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED,json_encode($rawOrderList));
        }

        $filter['order_no'] = array_keys($opOrderList);

        $orders = Remit::findAll($filter);
        foreach ($orders as $order){
            $bak = $opOrderList[$order->order_no]['bak']??'';
            try{
                LogicRemit::setSuccess($order,$this->allParams['op_uid'],$this->allParams['op_username'],$bak);
            }catch (\Exception $ex){

            }
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }


    /**
     * 设置订单为失败
     */
    public function actionSetFail()
    {
        $rawOrderList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        Yii::info($rawOrderList);
        if(empty($rawOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $opOrderList = $filter = [];
        foreach ($rawOrderList as $k=>$on){
            if(Util::validate($on['order_no'],Macro::CONST_PARAM_TYPE_ORDER_NO)){
                $opOrderList[$on['order_no']] = $on;
                $filter['order_no'][] = "{$on['order_no']}";
            }
        }
        if(empty($opOrderList) || empty($filter)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED,json_encode($rawOrderList));
        }

        //NOTICE: !!!array_keys出来的订单号会被识别为int类型,卡SQL
//        $filter['order_no'] = array_keys($opOrderList);

        $orders = Remit::findAll($filter);
        foreach ($orders as $order){
            $bak = $opOrderList[$order->order_no]['bak']??'';
            LogicRemit::setFailAndRefund($order,$bak,$this->allParams['op_uid'],$this->allParams['op_username']);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }

    /**
     * 设置订单为已审核
     */
    public function actionSetChecked()
    {
        $rawOrderList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        if(empty($rawOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $opOrderList = [];
        foreach ($rawOrderList as $k=>$on){
            if(Util::validate($on['order_no'],Macro::CONST_PARAM_TYPE_ORDER_NO)){
                $opOrderList[$on['order_no']] = $on;
            }
        }
        if(empty($opOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED,json_encode($rawOrderList));
        }

        //未扣款的也可以审核,用于处理扣款失败重新扣款的订单
        $filter['status'] = [Remit::STATUS_DEDUCT,Remit::STATUS_NONE];
        $filter['order_no'] = array_keys($opOrderList);

        $orders = Remit::findAll($filter);
        foreach ($orders as $order){
            $bak = $opOrderList[$order->order_no]['bak']??'';
            LogicRemit::setChecked($order,$this->allParams['op_uid'],$this->allParams['op_username']);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }


    /**
     * 强制特定的提交到上游
     * 需要先重置订单为已审核状态
     */
    public function actionReSubmitToBank()
    {
        $rawOrderList = ControllerParameterValidator::getRequestParam($this->allParams, 'orderNoList', '',Macro::CONST_PARAM_TYPE_ARRAY,'订单号列表错误');

        if(empty($rawOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        $opOrderList = [];
        foreach ($rawOrderList as $k=>$on){
            if(Util::validate($on['order_no'],Macro::CONST_PARAM_TYPE_ORDER_NO)){
                $opOrderList[$on['order_no']] = $on;
            }
        }
        if(empty($opOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED,json_encode($rawOrderList));
        }

        $filter['status'] = Remit::STATUS_LIST_CAN_COMMIT_TO_BANK;
        $filter['order_no'] = array_keys($opOrderList);
        $orders = Remit::findAll($filter);
        $orderAmount = count($orders);
        foreach ($orders as $order){
            LogicRemit::setChecked($order,$this->allParams['op_uid'],$this->allParams['op_username'],"重置为已审核并提交.",true);
            //低于10个,实时提交
            if($orderAmount<=10){
                Yii::info("actionReSubmitToBank realtime {$order->order_no}");
                LogicRemit::commitToBank($order,true);
            }
            //高于10个,队列提交
            else{
                Yii::info("actionReSubmitToBank queue {$order->order_no}");
                $job = new RemitCommitJob([
                    'orderNo'=>$order->order_no,
                    'force'=>true,
                ]);
                Yii::$app->remitBankCommitQueue->push($job);//->delay(10)
            }

        }

        return ResponseHelper::formatOutput(Macro::SUCCESS);
    }

    /**
     * 商户审核订单
     */
    public function actionMerchantCheck()
    {
        $rawOrderList = ControllerParameterValidator::getRequestParam($this->allParams, 'remitIdList', null,Macro::CONST_PARAM_TYPE_ARRAY,'订单ID错误');
        $status = ControllerParameterValidator::getRequestParam($this->allParams, 'status', null,Macro::CONST_PARAM_TYPE_ENUM,'状态错误',[1,2]);

        if(empty($rawOrderList)){
            Util::throwException(Macro::PARAMETER_VALIDATION_FAILED);
        }

        foreach ($rawOrderList as $k=>$v){
            $rawOrderList[$k] = intval($v);
        }

        $remits = Remit::findAll(['id'=>$rawOrderList,'status'=>Remit::STATUS_DEDUCT,'need_merchant_check'=>1,'merchant_check_status'=>0]);
        $msgs = [];
        foreach ($remits as $remit){
            try{
                LogicRemit::merchantCheck($remit,$status,$this->allParams['op_uid'],$this->allParams['op_username']);
            }catch (\Exception $e){
                $msgs[] = $e->getMessage();
            }

        }

        $msgs = implode(';',$msgs);

        return ResponseHelper::formatOutput(Macro::SUCCESS,$msgs);
    }
}
