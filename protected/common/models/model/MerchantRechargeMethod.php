<?php
namespace app\common\models\model;

/*
 * 商户支付方式表
 */
use yii\db\ActiveRecord;

class MerchantRechargeMethod extends BaseModel
{
    const STATUS_INACTIVE=0;
    const STATUS_ACTIVE=1;

    const ARR_STATUS = [
        self::STATUS_ACTIVE => '启用',
        self::STATUS_INACTIVE => '停用',
    ];

    public static function tableName()
    {
        return '{{%merchant_recharge_methods}}';
    }

    public function getChannelAccount()
    {
        return $this->hasOne(ChannelAccount::className(), ['id'=>'channel_account_id']);
    }


    /**
     * 获取渠道账户支付方式配置信息
     *
     * @param string $appId 商户应用ID
     * @param string $methodId 支付方式ID
     * @return ActiveRecord
     */
    public function getChannelAccountMethodConfig()
    {
        return $this->channelAccount->getPayMethodById($this->method_id);
    }

    /**
     * 获取支付方式配置信息
     *
     * @param string $appId 商户应用ID
     * @param string $methodId 支付方式ID
     * @return ActiveRecord
     */
    public static function getMethodConfigByAppIdAndMethodId(string $appId, string $methodId)
    {
        return self::findOne(['app_id'=>$appId,'method_id'=>$methodId]);
    }

    /**
     * 获取所有上级支付方式配置ID
     *
     * @return array|mixed
     */
    public function getAllParentAgentId()
    {
        return empty($this->all_parent_method_config_id)?[]:json_decode($this->all_parent_method_config_id,true);
    }

    /**
     * 获取所有上级支付方式配置
     *
     * @return array|mixed
     */
    public function getAllParentAgentConfig()
    {
        $pids = $this->getAllParentAgentId();
        return self::findAll(['id'=>$pids]);
    }

    /**
     * 获取某支付方式所有上级支付方式配置
     *
     * @return array|mixed
     */
    public function getMethodAllParentAgentConfig($mid)
    {
        $pids = $this->getAllParentAgentId();
        return self::findAll(['id'=>$pids,'method_id'=>$mid]);
    }

}