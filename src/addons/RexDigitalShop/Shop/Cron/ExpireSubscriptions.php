<?php

namespace RexDigitalShop\Shop\Cron;

class ExpireSubscriptions
{
    public static function handle($entry)
    {
        \XF::app()
            ->jobManager()
            ->enqueueUnique('rexshop_expire_subscriptions', 'RexDigitalShop\Shop:ExpireSubscriptions', [], false);
    }
}
