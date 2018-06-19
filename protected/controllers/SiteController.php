<?php
namespace app\controllers;

use app\common\models\logic\LogicUser;
use app\common\models\model\Channel;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Financial;
use app\common\models\model\Order;
use app\common\models\model\Remit;
use app\common\models\model\User;
use app\components\Macro;
use app\components\RpcPaymentGateway;
use app\lib\helpers\SignatureHelper;
use app\lib\payment\channels\mf\MfBasePayment;
use app\modules\gateway\models\logic\LogicChannelAccount;
use app\modules\gateway\models\logic\LogicOrder;
use app\modules\gateway\models\logic\LogicRemit;
use function GuzzleHttp\Psr7\parse_query;
use Yii;
use power\yii2\helpers\ResponseHelper;

class SiteController extends \yii\web\Controller
{
    public function actionIndex()
    {
        exit('nginx 2.1.17');
    }

    public function actionT_df4419838b2dc89473fce6c7d19c96c7()
    {

        exit;

        //        $filter = ['!=','notify_status',Order::NOTICE_STATUS_SUCCESS];
        //
        //        $orders = Order::find($filter)->all();
        //var_dump($orders);
        //        exit;
        //        $order = Order::findOne(['order_no'=>'P18052922491788078']);
        //
        //
        //        LogicOrder::queryChannelOrderStatus($order);

        $remit = Remit::findOne(['order_no'=>'218060419094760767']);
        //        $remit = LogicRemit::processRemit($remit,$remit->channelAccount);

        //
        $ret = LogicRemit::queryChannelRemitStatus($remit);

        //        $accounts = ChannelAccount::findOne(['id'=>13]);
        //        LogicChannelAccount::syncBalance($accounts);


    }

    /**
     * This is the default 'index' action that is invoked
     * when an action is not explicitly requested by users.
     */
    public function actionError()
    {

    }
}
