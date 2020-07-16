<?php

namespace RexDigitalShop\Shop\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;
use RexDigitalShop\Shop\Library\RexShop;

class ShopController extends AbstractController
{
    private $RexShop;

    public function __construct()
    {
        $this->RexShop = new RexShop;
        define('TIME_NOW', time());
    }

    public function actionIndex(ParameterBag $params)
    {
        $app = \XF::app();
        $session = $app->session();
        $uid = $session->get('userId');
        $finder = \XF::finder('XF\User');
        $user = $finder->where('user_id', '=', $uid)->fetchOne();

        if (!$this->RexShop->allowedToViewStore($user['user_group_id'])) {
            return $this->error('You are not allowed to view the store.', 401);
        }

        return $this->view('', 'rexshop_index', [
            'products' => $this->RexShop->fetchProducts(),
            'client_id' => $this->app()->options()->rexshop_client_id,
            'custom' =>  base64_encode(serialize([
                'uid' => (int) $uid,
            ]))
        ]);
    }

    /**
     * Handles and delicates an incoming verified & non-duplicate webhook message.
     *
     * @return httpresponse
     */
    public function actionWebhook()
    {
        $request = json_decode(file_get_contents("php://input"), true);

        if (!$this->RexShop->verifyWebhook($request)) {
            return $this->rexshopOnSuccess();
        }

        if ($this->RexShop->transactionDuplicate($request)) {
            return $this->rexshopOnSuccess();
        }

        switch (strtolower($request['status'])) {
            case RexShop::REXSHOP_STATUS_COMPLETED:
                return $this->handleCompletedTransaction($request);
            case RexShop::REXSHOP_STATUS_PENDING:
                return $this->handlePendingTransaction($request);
            case RexShop::REXSHOP_STATUS_REFUNDED:
                return $this->handleRefundedTransaction($request);
            case RexShop::REXSHOP_STATUS_DISPUTE_CANCELED:
                return $this->handleDisputeCanceledTransaction($request);
            case RexShop::REXSHOP_STATUS_DISPUTED:
                return $this->handleDisputedTransaction($request);
            case RexShop::REXSHOP_STATUS_REVERSED:
                return $this->handleReversedTransaction($request);
            default:
                break;
        }

        return $this->rexshopOnSuccess();
    }

    /**
     * Handles a completed transaction.
     *
     * @param [array] $request
     * @return httpresponse
     */
    private function handleCompletedTransaction($request)
    {
        $userId = $this->RexShop->userIdFromCustom($request);
        if (!isset($userId) || $userId < 1) {
            return $this->rexshopOnFailure();
        }

        //Figure out what usergroup the user is receiving.
        $usergroup = $this->RexShop->fetchPurchasedUsergroup($request);
        if (!isset($usergroup) || $usergroup < 1) {
            return $this->rexshopOnFailure();
        }

        //How much seconds the user has remaining & expire old purchases.
        $remainingSeconds = $this->RexShop->remainingSeconds($userId);

        //Figure out how much seconds the user has purchased.
        $purchasedSeconds = $this->RexShop->purchasedSeconds($request, $request['order']['products'][0]['sku']);

        $newEnddate = $purchasedSeconds < 0 ? -1 : TIME_NOW + $remainingSeconds + $purchasedSeconds;

        $this->RexShop->storeTransaction($request, $userId, $newEnddate, $request['order']['products'][0]['sku']);

        $this->RexShop->changeUsergroup($userId, $usergroup);

        $this->RexShop->rexshopStartConversation(\XF::phrase('rexshop_purchase_completed_title'), \XF::phrase('rexshop_purchase_completed_body'), $userId);

        return $this->rexshopOnSuccess();
    }

    /**
     * Handles a pending transaction (funds are being processed by the payment processor).
     *
     * @param [array] $request
     * @return httpresponse
     */
    private function handlePendingTransaction($request)
    {
        $userId = $this->RexShop->userIdFromCustom($request);
        if (!isset($userId) || $userId < 1) {
            return $this->rexshopOnFailure();
        }

        $this->RexShop->rexshopStartConversation(\XF::phrase('rexshop_purchase_pending_title'), \XF::phrase('rexshop_purchase_pending_body'), $userId);

        return $this->rexshopOnSuccess();
    }

    /**
     * Handles a refunded transaction (you voluntarily refunded the transaction).
     *
     * @param [array] $request
     * @return httpresponse
     */
    private function handleRefundedTransaction($request)
    {
        $userId = $this->RexShop->userIdFromCustom($request);
        if (!isset($userId) || $userId < 1) {
            return $this->rexshopOnFailure();
        }

        //Figure out what usergroup the user is receiving.
        $usergroup = $this->RexShop->fetchPurchasedUsergroup($request);
        if (!isset($usergroup) || $usergroup < 1) {
            return $this->rexshopOnFailure();
        }

        //Check how many seconds was bought
        $purchasedTime = $this->RexShop->purchasedSeconds($request, $request['order']['products'][0]['sku']);

        //Check what the price per second is
        $pricePerSecond = $purchasedTime / $request['order']['amount'];

        //Check how much money was refunded
        $refundedAmount = $request['refund']['amount'];

        //Figure out what that is in seconds.
        $refundedSeconds = $refundedAmount * $pricePerSecond;

        //Check how much time the user has left
        $remainingSeconds = $this->RexShop->remainingSeconds($userId, false);

        //Subtract the time that was refunded.
        $newEndDate = TIME_NOW + ($remainingSeconds - $refundedSeconds);

        //Check if the subscription is now expired
        $expired = ($newEndDate <= TIME_NOW);

        //Update the users subscription.
        $db = \XF::db();
        $db->query(
            "UPDATE xf_rexshop_logs 
            SET enddate = ?, expired = ?
            WHERE uid = ? 
            ORDER BY id DESC 
            LIMIT 1",
            [
                $newEndDate, $expired, $userId
            ]
        );

        $this->RexShop->storeTransaction($request, $userId, 0, $request['order']['products'][0]['sku']);

        $this->RexShop->rexshopStartConversation(\XF::phrase('rexshop_purchase_refunded_title'), \XF::phrase('rexshop_purchase_refunded_body'), $userId);

        return $this->rexshopOnSuccess();
    }

    /**
     * Handles a canceled disputed transaction (merchant won the dispute).
     *
     * @param [array] $request
     * @return httpresponse
     */
    private function handleDisputeCanceledTransaction($request)
    {
        $userId = $this->RexShop->userIdFromCustom($request);
        if (!isset($userId) || $userId < 1) {
            return $this->rexshopOnFailure();
        }

        //Unban the user.
        if ($this->RexShop->isBanned($userId)) {
            $this->RexShop->unbanUser($userId);
        }

        $this->RexShop->rexshopStartConversation(\XF::phrase('rexshop_purchase_dispute_canceled_title'), \XF::phrase('rexshop_purchase_dispute_canceled_body'), $userId);

        return $this->rexshopOnSuccess();
    }

    /**
     * Handles a disputed transaction.
     *
     * @param [array] $request
     * @return httpresponse
     */
    private function handleDisputedTransaction($request)
    {
        $userId = $this->RexShop->userIdFromCustom($request);
        if (!isset($userId) || $userId < 1) {
            return $this->rexshopOnFailure();
        }

        //The user is not currently banned, it is time to ban.
        if (!$this->RexShop->isBanned($userId)) {
            $this->RexShop->banUser($userId);
        }

        $this->RexShop->rexshopStartConversation(\XF::phrase('rexshop_purchase_disputed_title'), \XF::phrase('rexshop_purchase_disputed_body'), $userId);

        return $this->rexshopOnSuccess();
    }

    /**
     * Handles a reversed transaction (merchant lost the dispute).
     *
     * @param [array] $request
     * @return httpresponse
     */
    private function handleReversedTransaction($request)
    {
        $userId = $this->RexShop->userIdFromCustom($request);
        if (!isset($userId) || $userId < 1) {
            return $this->rexshopOnFailure();
        }

        $this->RexShop->rexshopStartConversation(\XF::phrase('rexshop_purchase_reversed_title'), \XF::phrase('rexshop_purchase_reversed_body'), $userId);

        return $this->rexshopOnSuccess();
    }

    private function rexshopOnSuccess()
    {
        header("Status: 200 OK");
        exit;
    }

    private function rexshopOnFailure()
    {
        header('Status: 400 Bad Request');
        exit;
    }
}
