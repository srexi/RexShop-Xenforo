<?php

namespace RexDigitalShop\Shop\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;
use RexDigitalShop\Shop\Library\RexShop;

class AdminController extends AbstractController
{
    public function actionIndex(ParameterBag $params)
    {
        $products = (new RexShop)->fetchProducts(true);

        return $this->view('', 'rexshop_admin_memberships', [
            'products' => $products,
            'csrf_token' => \XF::app()->get('csrf.token')
        ]);
    }

    public function actionGift()
    {
        if (!isset($_POST) || !isset($_POST['_xfToken'])) {
            return $this->error('Invalid Request.', 400);
        }

        $RexShop = new RexShop;
        $planId = (int) $_POST['plan_id'];
        $products = $RexShop->fetchProducts(true);
        $usergroupAndPlan = $RexShop->fetchUsergroupAndPlan($products, $planId);

        if ($usergroupAndPlan['usergroup'] !== -1) {
            $finder = \XF::finder('XF:User');
            $user = $finder->where('username', '=', $_POST['username'])->fetchOne();

            $time = time();

            $enddate = $RexShop->pricingPlanToSeconds($usergroupAndPlan['plan']) + $RexShop->remainingSeconds($user['user_id']) + $time;

            $RexShop->storeTransaction([
                'transaction_status' => RexShop::REXSHOP_STATUS_COMPLETED,
                'order' => [
                    'transaction_id' => null,
                    'initiated_at' => $time,
                ],
                'customer' => [
                    'country' => 'us'
                ]
            ], $user['user_id'], $enddate);

            $RexShop->changeUsergroup($user['user_id'], $usergroupAndPlan['usergroup']);

            $RexShop->rexshopStartConversation(\XF::phrase('admin_gift_upgrade_title'), \XF::phrase('admin_gift_upgrade_body'), $user['user_id']);
        }

        return $this->redirect($this->buildLink('rex-shop'), 'Gift was sent succesfully');
    }
}
