<?php

namespace app\modules\gateway\controllers\v1\inner;

use app\common\models\logic\LogicUser;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Financial;
use app\common\models\model\User;
use app\components\Macro;
use app\lib\helpers\ControllerParameterValidator;
use app\lib\helpers\ResponseHelper;
use app\modules\gateway\controllers\v1\BaseInnerController;
use app\modules\gateway\models\logic\LogicChannelAccount;
use Yii;

/**
 * 账户操作内部接口
 */
class AccountController extends BaseInnerController
{
    /*
     * 调整账户余额
     */
    public function actionChangeBalance()
    {
        $userId = ControllerParameterValidator::getRequestParam($this->allParams, 'user_id', null, Macro::CONST_PARAM_TYPE_INT, '用户id错误');
        $amount = ControllerParameterValidator::getRequestParam($this->allParams, 'amount',null,Macro::CONST_PARAM_TYPE_DECIMAL,'金额错误');
        $opUserId = ControllerParameterValidator::getRequestParam($this->allParams, 'op_userid',null,Macro::CONST_PARAM_TYPE_INT,'操作者ID错误');
        $opUsername = ControllerParameterValidator::getRequestParam($this->allParams, 'op_username',null,Macro::CONST_PARAM_TYPE_USERNAME,'操作者用户名错误');
        $bak = ControllerParameterValidator::getRequestParam($this->allParams, 'bak',null,Macro::CONST_PARAM_TYPE_STRING,'调整原因错误',[1]);
        $balanceType = ControllerParameterValidator::getRequestParam($this->allParams, 'type',null,Macro::CONST_PARAM_TYPE_ENUM,'金额类型错误',[1,2]);

        if(empty($amount)){
            return ResponseHelper::formatOutput(Macro::FAIL,'调整金额不能为0');
        }

        $user = User::findOne(['id'=>$userId]);
        if(!$user){
            return ResponseHelper::formatOutput(Macro::ERR_USER_NOT_FOUND,'用户不存在');
        }
        if($amount<0 && $user->balance<$amount){
            return ResponseHelper::formatOutput(Macro::FAIL,"用户余额不足,当前余额:{$user->balance}");
        }

        //退回账户扣款
        $logicUser = new LogicUser($user);
        $ip = Yii::$app->request->userIP??'';
        if($balanceType==1){
            $type = $amount>0?Financial::EVENT_TYPE_SYSTEM_PLUS:Financial::EVENT_TYPE_SYSTEM_MINUS;
            $logicUser->changeUserBalance($amount, $type, date('YmdHis').mt_rand(10000,99999),
                $amount,$ip,$bak,$opUserId,$opUsername);
        }
        elseif($balanceType==2){
            $type = $amount>0?Financial::EVENT_TYPE_SYSTEM_FROZEN:Financial::EVENT_TYPE_SYSTEM_UNFROZEN;
            $logicUser->changeUserFrozenBalance($amount, $type, date('YmdHis').mt_rand(10000,99999),
                $amount,$ip,$bak,$opUserId,$opUsername);
        }

        return ResponseHelper::formatOutput(Macro::SUCCESS,'余额修改成功');
    }
}
