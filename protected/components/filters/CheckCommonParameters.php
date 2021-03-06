<?php
namespace app\components\filters;

use app\components\Macro;
use Yii;
use power\yii2\exceptions\ParameterValidationExpandException;
use app\lib\helpers\ControllerParameterValidator;

class CheckCommonParameters extends \yii\base\ActionFilter
{
    /**
     * This method is invoked right before an action is to be executed (after all possible filters.)
     * You may override this method to do last-minute preparation for the action.
     * @param Action $action the action to be executed.
     * @return boolean whether the action should continue to be executed.
     */
    public function beforeAction($action)
    {
        $arrQueryParams  = Yii::$app->getRequest()->getQueryParams();
        $arrBodyParams   = Yii::$app->getRequest()->getBodyParams();
        $allParams = $arrQueryParams + $arrBodyParams;

        $merchantId = ControllerParameterValidator::getRequestParam($allParams, 'merchant_code',null,Macro::CONST_PARAM_TYPE_INT_GT_ZERO, '商户号错误！');
        if(!$merchantId){
            return false;
        }else{
            return true;
        }
    }
}