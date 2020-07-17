<?php

namespace RexDigitalShop\Shop\Job;

use XF\Job\AbstractJob;
use RexDigitalShop\Shop\Library\RexShop;

class ExpireSubscriptions extends AbstractJob
{
    public function run($maxRunTime)
    {
        $app = \XF::app();
        $db = $app->db();

        $subscriptions = ($db->query(
            "SELECT r.id, r.expired, r.enddate, r.transaction_status, u.user_id FROM xf_rexshop_logs r
            LEFT JOIN xf_user u ON (u.user_id=r.uid)
            WHERE r.expired = ? AND r.enddate > ? AND r.enddate < ?
            AND r.transaction_status = ?
            LIMIT ?",
            [0, 0, time(), RexShop::REXSHOP_STATUS_COMPLETED, 500]
        ))->fetchAll();

        $RexShop = new RexShop;

        foreach ($subscriptions as $subscription) {
            if (!isset($subscription['uid']) || $subscription['uid'] <= 0) {
                $db->query("UPDATE xf_rexshop_logs SET expired = ? WHERE id = ?", [1, $subscription['id']]);

                continue;
            }

            $RexShop->changeUsergroup($subscription['user_id'], RexShop::EXPIRED_MEMBERSHIP_GROUP);

            $RexShop->rexshopStartConversation(\XF::phrase('rexshop_membership_expired_title'), \XF::phrase('rexshop_membership_expired_body'), $subscription['user_id']);

            $db->query("UPDATE xf_rexshop_logs SET expired = ? WHERE id = ?", [1, $subscription['id']]);
        }

        return $this->complete();
    }

    public function getStatusMessage()
    {
        return 'Checking for expired subscriptions...';
    }

    public function canCancel()
    {
        return false;
    }

    public function canTriggerByChoice()
    {
        return true;
    }
}
