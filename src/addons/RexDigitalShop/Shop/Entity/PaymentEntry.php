<?php

namespace RexDigitalShop\Shop\Entity;

use XF\Mvc\Entity\Structure;

class PaymentEntry extends \XF\Mvc\Entity\Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_rexshop_logs';
        $structure->shortName = 'RexDigitalShop\Shop:PaymentEntry';
        $structure->primaryKey = 'id';
        $structure->getters = [];
        $structure->columns = [
            'id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => false, 'changeLog' => false],
            'transaction_id' => ['type' => self::STR, 'nullable' => true, 'changeLog' => false],
            'product_sku' => ['type' => self::STR, 'nullable' => true, 'changeLog' => false],
            'country' => ['type' => self::STR, 'nullable' => true, 'changeLog' => false],
            'user_id' => ['type' => self::UINT, 'nullable' => false, 'changeLog' => false],
            'transaction_status' => ['type' => self::STR, 'nullable' => false, 'changeLog' => false],
            'suspended_seconds' => ['type' => self::UINT, 'nullable' => false, 'changeLog' => false, 'default' => 0],
            'enddate' => ['type' => self::UINT, 'nullable' => false, 'changeLog' => false],
            'expired' => ['type' => self::UINT, 'nullable' => false, 'changeLog' => false, 'default' => 0],
            'transaction_from' => ['type' => self::UINT, 'nullable' => true, 'changeLog' => false],
        ];
        $structure->relations = [
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true
            ]
        ];

        return $structure;
    }
}
