<?php

namespace RexDigitalShop\Shop\Library;

class RexShop
{
    private $secret;
    private $apiKey;

    const REXSHOP_STATUS_COMPLETED = 'completed';
    const REXSHOP_STATUS_PENDING = 'waiting for payment';
    const REXSHOP_STATUS_REFUNDED = 'refunded';
    const REXSHOP_STATUS_DISPUTED = 'disputed';
    const REXSHOP_STATUS_DISPUTE_CANCELED = 'dispute canceled';
    const REXSHOP_STATUS_REVERSED = 'reversed';
    const EXPIRED_MEMBERSHIP_GROUP = 2;
    const SECONDS_PER_DAY = 86400;

    public function __construct()
    {
        $app = \XF::app();
        $this->secret = $app->options()->rexshop_secret;
        $this->apiKey = $app->options()->rexshop_api_key;

        if (!defined('TIME_NOW')) {
            define('TIME_NOW', time());
        }
    }

    /**
     * Starts a conversation between two users
     *
     * @param [string] $title
     * @param [string] $body
     * @param [integer] $toUid
     * @param integer $fromUid
     * @param integer $isOpen
     * @return boolean
     */
    public function rexshopStartConversation($title, $body, $toUid, $fromUid = 1, $isOpen = 0)
    {
        $starterUser = \XF::app()->em()->find('XF:User', $fromUid);
        $receiverUser = \XF::app()->em()->find('XF:User', $toUid);

        $creator = \XF::app()->service('XF:Conversation\Creator', $starterUser);
        $creator->setIsAutomated();
        $creator->setOptions([
            'open_invite' => 0,
            'conversation_open' => $isOpen
        ]);

        $creator->setRecipientsTrusted($receiverUser);
        $creator->setContent($title, $body);
        if (!$creator->validate()) {
            return false;
        }
        $creator->save();

        return true;
    }

    /**
     * Fetches the products from rex shop using your api key
     *
     * @param [boolean] $inAdminPanel
     * @return array
     */
    public function fetchProducts($inAdminPanel = false)
    {
        $app = \XF::app();
        $session = $app->session();
        $uid = $session->get('userId');

        if (!$uid) {
            return [];
        }

        if (!isset($this->apiKey)) {
            return [];
        }

        $ch = curl_init("https://shop.rexdigital.group/api/v1/products?api_key={$this->apiKey}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        $productResponse = $result['products']['data'] ?? [];
        $finder = \XF::finder('XF:User');
        $user = $finder->where('user_id', '=', $uid)->fetchOne();

        $products = [];
        if ($inAdminPanel === false) {
            foreach ($productResponse as $product) {
                $newProduct = $product;
                $newProduct['prices'] = [];

                foreach ($product['prices'] as &$price) {
                    foreach ($price['addons'] as $addon) {
                        if (!in_array(strtolower($addon['name']), ['onlyusergroups', 'excludeusergroups'])) {
                            continue;
                        }

                        $usergroups = explode(',', ltrim(rtrim(trim($addon['value'], ' '), ','), ','));
                        if (empty($usergroups)) {
                            continue;
                        }

                        foreach ($usergroups as $usergroup) {
                            $usergroupId = $this->fetchUsergroupId($usergroup);
                            if ($usergroupId === false) {
                                continue;
                            }

                            if (strtolower($addon['name']) === 'onlyusergroups') {
                                if ((int) $user->user_group_id !== (int) $usergroupId) {
                                    continue 3;
                                }
                            } else if (strtolower($addon['name']) === 'excludeusergroups') {
                                if ((int) $user->user_group_id === (int) $usergroupId) {
                                    $newProduct['prices'][] = $price;
                                    continue 3;
                                }
                            }
                        }
                    }

                    $newProduct['prices'][] = $price;
                }

                $products[] = $newProduct;
            }
        } else {
            $db = \XF::db();
            $products = $productResponse;

            foreach ($products as &$product) {
                foreach ($product['prices'] as &$price) {
                    foreach ($price['addons'] as $addon) {
                        if (strtolower($addon['name']) !== 'usergroup') {
                            continue;
                        }

                        $usergroupId = $this->fetchUsergroupId($addon['value']);
                        if ($usergroupId === false) {
                            continue;
                        }

                        $usergroupQuery = $db->query("SELECT * FROM xf_user_group WHERE user_group_id = ?", $usergroupId);
                        $usergroup = $usergroupQuery->fetch();

                        $price['usergroup_title'] = $usergroup['title'];
                        continue 2;
                    }
                }
            }
        }

        return $products;
    }

    /**
     * Checks the authenticity of the webhook request. By verifying the signature against the payload and your secret key.
     *
     * @param [array] $request
     * @return boolean
     */
    public function verifyWebhook($request)
    {
        return $request['RDG_WH_SIGNATURE'] === hash_hmac(
            'sha256',
            $request['order']['transaction_id'] . $request['status'],
            $this->secret
        );
    }

    /**
     * Changes the users usergroup.
     *
     * @param [integer] $userId
     * @param [integer] $newGroupId
     * @return void
     */
    public function changeUsergroup($userId, $usergroup)
    {
        $db = \XF::db();

        $db->query("UPDATE xf_user SET user_group_id = ? WHERE user_id = ?", [$usergroup, $userId]);
    }

    /**
     * Checks if this transaction has already been registered in the system.
     *
     * @param [array] $request
     * @return boolean
     */
    public function transactionDuplicate($request)
    {
        $app = \XF::app();
        $db = $app->db();
        $query = $db->query(
            "SELECT COUNT(*) as resultCount FROM xf_rexshop_logs 
            WHERE transaction_id ? AND transaction_status = ? 
            LIMIT 1",
            [
                $request['order']['transaction_id'],
                $request['status']
            ]
        );

        $result = $query->fetch();

        return $result['resultCount'] > 0;
    }

    /**
     * Fetches the customer's user id from custom parameter in webhook.
     *
     * @param [array] $request
     * @return integer
     */
    public function userIdFromCustom($request)
    {
        $userId = -1;

        if (!isset($request['custom'])) {
            return $userId;
        }

        $decoded = unserialize(base64_decode($request['custom']));

        return (int) $decoded['uid'] ?? (int) $userId;
    }

    /**
     * Stores the transaction in the database.
     *
     * @param [array] $request
     * @param [integer] $uid
     * @param [integer] $enddate
     * @return void
     */
    public function storeTransaction($request, $uid, $enddate, $productSku = null)
    {
        $db = \XF::db();

        $db->insert('xf_rexshop_logs', [
            'uid' => intval($uid),
            'product_sku' => is_null($productSku) ? null : $this->regexEscape($productSku, '/[^a-zA-Z0-9]/'),
            'transaction_id' => $this->regexEscape($request['order']['transaction_id'], '/[^a-zA-Z0-9]/'),
            'transaction_status' => $this->regexEscape($request['transaction_status'], '/[^a-zA-Z0-9]/'),
            'transaction_from' => (int) $request['order']['initiated_at'],
            'country' => $this->regexEscape($request['customer']['country'], '/[^a-zA-Z]/'),
            'addons' => json_encode($request['order']['products'][0]['addons'] ?? []),
            'enddate' => (int) $enddate,
            'expired' => ($enddate <= TIME_NOW && $enddate > -1 ? 1 : 0),
        ]);
    }

    /**
     * Regex based escape-and-replace for a given string
     *
     * @param string $input
     * @param [string|null] $regex
     * @param [string] $replacement
     * @return string
     */
    public function regexEscape($input, $regex = null, $replacement = '')
    {
        if (!isset($regex)) {
            return $input;
        }

        return preg_replace($regex, $replacement, $input);
    }

    /**
     * Fetches the remaining subscription seconds for a user. By default it also expires any currently non expired subscription.
     *
     * @param [integer] $userId
     * @param boolean $expiresExisting
     * @return integer
     */
    public function remainingSeconds($userId, $expireExisting = true)
    {
        $remainingSeconds = 0;

        $db = \XF::db();

        $query = $db->query("SELECT * FROM xf_rexshop_logs WHERE uid = ? AND expired = ?", [$userId, 0]);
        $result = $query->fetchAll();

        if (count($result) <= 0) {
            return $remainingSeconds;
        }

        for ($i = 0; $i < count($result); $i++) {
            if (strtolower($result[$i]["transaction_status"]) !== self::REXSHOP_STATUS_COMPLETED) {
                continue;
            }

            $remainingSeconds += $result[$i]["enddate"] - TIME_NOW;
        }

        if ($expireExisting === true) {
            $db->query("UPDATE xf_rexshop_logs SET expired = ? WHERE uid = ?", [1, $userId]);
        }

        return intval($remainingSeconds);
    }

    /**
     * Fetches the amount of purchased seconds
     *
     * @param [array] $request
     * @param [string] $sku
     * @return integer
     */
    public function purchasedSeconds($request, $sku)
    {
        $purchasedSeconds = 0;

        $pricePerSeconds = 1;
        foreach ($request['order']['products'] as $product) {
            if (strtolower($product['sku']) == strtolower($sku)) {
                continue;
            }

            $pricePerSeconds = $this->pricePerSecond($product['price'], $product['total_purchased_seconds']);
            break;
        }

        foreach ($request['order']['products'] as $product) {
            if (strtolower($product['sku']) == strtolower($sku)) {
                if ($product['total_purchased_seconds'] < 0) {
                    return -1;
                }

                $purchasedSeconds += $product['total_purchased_seconds'];
                continue;
            }

            $productPricePerSecond = $this->pricePerSecond($product['price'], $product['total_purchased_seconds']);

            $purchasedSeconds += ($pricePerSeconds / $productPricePerSecond) * $product['total_purchased_seconds'];
        }

        return intval($purchasedSeconds);
    }

    /**
     * Calculates the amount of seconds a given plan awards.
     *
     * @param [array] $plan
     * @return integer
     */
    public function pricingPlanToSeconds($plan)
    {
        $seconds = 0;

        if (isset($plan['duration']) && isset($plan['time'])) {
            switch (strtolower($plan['duration'])) {
                case 'day':
                case 'days':
                    return self::SECONDS_PER_DAY * (int) $plan['time'];
                case 'week':
                case 'weeks':
                    return self::SECONDS_PER_DAY * (int) $plan['time'] * 7;
                case 'month':
                case 'months':
                    return self::SECONDS_PER_DAY * (int) $plan['time'] * 30;
                case 'year':
                case 'years':
                    return self::SECONDS_PER_DAY * (int) $plan['time'] * 365;
                default:
                    break;
            }
        }

        return $seconds;
    }

    /**
     * Fetches the purchased usergroup.
     *
     * @param [array] $request
     * @return integer
     */
    public function fetchPurchasedUsergroup($request)
    {
        $usergroup = -1;

        foreach ($request['order']['products'] as $product) {
            foreach ($product['addons'] as $addon) {
                if (strtolower($addon['name']) !== 'usergroup') {
                    continue;
                }

                $usergroup = $this->fetchUsergroupId($addon['value']);
                if ($usergroup === false) {
                    $usergroup = -1;
                    continue;
                }

                break 2;
            }
        }

        return $usergroup;
    }

    /**
     * Checks if the user has permissions to view the store.
     *
     * @param [integer] $usergroup
     * @return boolean
     */
    public function allowedToViewStore($usergroup)
    {
        $app = \XF::app();

        $excludedUsergroups = $app->options()->rexshop_excluded_usergroups;
        if (!empty($excludedUsergroups)) {
            $usergroups = explode(',', ltrim(rtrim(trim($excludedUsergroups, ' '), ','), ','));

            return !in_array((int) $usergroup, $usergroups);
        }

        return true;
    }

    /**
     * Checks if a user is banned.
     *
     * @param [integer] $userId
     * @return boolean
     */
    public function isBanned($userId)
    {
        $db = \XF::db();

        $query = $db->query("SELECT * FROM xf_user_ban WHERE user_id = ? AND enddate > ? AND enddate >= ? LIMIT 1", [$userId, 0, TIME_NOW]);

        $result = $query->fetch();

        return count($result) > 0;
    }

    /**
     * Bans a user.
     *
     * @param [array] $user
     * @param string $reason
     * @param string $banTime
     * @param integer $bannedBy
     * @return boolean
     */
    public function banUser($userId, $reason = null, $banTime = 0, $bannedBy = 1)
    {
        if (is_null($reason)) {
            $reason = \XF::phrase('rexshop_ban_reason_chargeback');
        }

        $db = \XF::db();

        if ($this->isBanned($userId)) {
            $db->query(
                "UPDATE xf_user_ban 
                SET user_reason = ?, end_date = ?, ban_user_id = ? 
                WHERE user_id = ?
                ORDER BY end_date DESC 
                LIMIT 1",
                [
                    $reason, $banTime, $bannedBy, $userId
                ]
            );

            return true;
        }

        $db->query(
            "INSERT INTO xf_user_ban (user_reason,end_date,ban_user_id) VALUES (?,?,?,?)",
            [
                $reason, $banTime, $bannedBy, $userId
            ]
        );

        return true;
    }

    /**
     * Unbans a user.
     *
     * @param [array] $user
     * @param string $reason
     * @return boolean
     */
    public function unbanUser($userId, $reason = null)
    {
        if (!$this->isBanned($userId)) {
            return true;
        }

        $db = \XF::db();
        $db->query("DELETE FROM xf_user_ban WHERE user_id = ?", [$userId]);

        return true;
    }

    /**
     * Fetches the usergroup id, from an addon input.
     *
     * @param [string|integer] $usergroup
     * @return integer|false
     */
    public function fetchUsergroupId($usergroup)
    {
        if (is_numeric($usergroup)) {
            return (int) $usergroup;
        }

        $db = \XF::db();
        $query = $db->query("SELECT * FROM xf_user_group WHERE LOWER(`title`) = ? LIMIT 1", [strtolower($usergroup)]);

        $result = $query->fetchOne();

        if (count($result) > 0) {
            return (int) $result['user_group_id'];
        }

        return false;
    }

    /**
     * Fetches the usergroup and plan from a plan.
     *
     * @param [array] $products
     * @param [integer] $planId
     * @return array
     */
    public function fetchUsergroupAndPlan($products, $planId)
    {
        $usergroup = -1;

        foreach ($products as $product) {
            foreach ($product['prices'] as $price) {
                if ($planId !== $price['plan_id']) {
                    continue;
                }

                foreach ($price['addons'] as $addon) {
                    if (strtolower($addon['name']) !== 'usergroup') {
                        continue;
                    }

                    return ['usergroup' => $this->fetchUsergroupId($addon['value']), 'plan' => $price];
                }
            }
        }

        return ['usergroup' => $usergroup, 'plan' => []];
    }

    /**
     * Calculates the price per second.
     *
     * @param [float] $price
     * @param [int] $seconds
     * @return float
     */
    private function pricePerSecond($price, $seconds)
    {
        if ($seconds < 0) {
            return $price;
        }

        return $price / $seconds;
    }
}
