<?php
namespace app\common\models\model;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

class User extends BaseModel
{

    const STATUS_INACTIVE=0;
    const STATUS_ACTIVE=10;
    const STATUS_BANED=20;
    const ARR_STATUS = [
        self::STATUS_INACTIVE => '未激活',
        self::STATUS_ACTIVE => '正常',
        self::STATUS_BANED => '已禁用',
    ];

    const GROUP_ADMIN = 10;
    const GROUP_AGENT = 20;
    const GROUP_MERCHANT = 30;
    const ARR_GROUP = [
        self::GROUP_ADMIN => '管理员',
        self::GROUP_MERCHANT => '商户',
        self::GROUP_AGENT => '代理',
    ];

    const ARR_GROUP_EN = [
        self::GROUP_ADMIN => 'admin',
        self::GROUP_MERCHANT => 'merchant',
        self::GROUP_AGENT => 'agent',
    ];
    const DEFAULT_RECHARGE_RATE = 0.6;
    const DEFAULT_REMIT_FEE = 0.6;

    public static function tableName()
    {
        return '{{%users}}';
    }

    public function behaviors() {
        return [TimestampBehavior::className(),];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            ['status','default','value'=>self::STATUS_INACTIVE],
            ['status','in','range'=>[self::STATUS_ACTIVE,self::STATUS_INACTIVE,self::STATUS_BANED]],
        ];
    }

    public function getPaymentInfo()
    {
        return $this->hasOne(UserPaymentInfo::className(), ['user_id'=>'id']);
    }

    public static function findActive($id){
        return static::findOne(['id'=>$id,'status'=>self::STATUS_ACTIVE]);
    }

    public static function getUserByMerchantId($id){
        $user = static::findOne(['app_id'=>$id,'status'=>self::STATUS_ACTIVE]);

        return $user;
    }

    public static function findByUsername($username){
        return static::findOne(['username'=>$username,'status'=>self::STATUS_ACTIVE]);
    }

    public function getAllParentAgentId()
    {
        return empty($this->all_parent_agent_id)?[]:json_decode($this->all_parent_agent_id,true);
    }

    public function getParentAgent()
    {
        return $this->hasOne(User::className(), ['id'=>'parent_agent_id']);
    }
    /*
    * 获取状态描述
    *
    * @return string
    * @author bootmall@gmail.com
    */
    public function getStatusStr()
    {
        return self::ARR_STATUS[$this->status]??'-';
    }

    /*
    * 获取分组描述
    *
    * @return string
    * @author bootmall@gmail.com
    */
    public function getGroupStr()
    {
        return self::ARR_GROUP[$this->group_id]??'-';
    }

    /*
    * 获取分组英文描述
    *
    * @return string
    * @author bootmall@gmail.com
    */
    public function getGroupEnStr()
    {
        return self::ARR_GROUP_EN[$this->group_id]??'-';
    }

    /**
     * 注销
     */
    public function logOut()
    {
        $this->access_token = '';
        $this->save();
    }
}