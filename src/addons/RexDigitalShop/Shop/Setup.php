<?php

namespace RexDigitalShop\Shop;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	public function installStep1()
	{
		$this->schemaManager()->createTable('xf_rexshop_logs', function (Create $table) {
			$table->addColumn('id', 'int')->autoIncrement();
			$table->addColumn('transaction_id', 'varchar', 192)->nullable(true);
			$table->addColumn('product_sku', 'varchar', 192)->nullable(true);
			$table->addColumn('country', 'varchar', 192)->nullable(true);
			$table->addColumn('uid', 'int', 11);
			$table->addColumn('transaction_status', 'varchar', 192);
			$table->addColumn('suspended_seconds', 'int', 11)->setDefault(0);
			$table->addColumn('enddate', 'int', 12);
			$table->addColumn('expired', 'tinyint', 1)->setDefault(0);
			$table->addColumn('addons', 'text')->setDefault('');
			$table->addColumn('transaction_from', 'int', 12)->nullable(true);
		});
	}

	public function uninstallStep1()
	{
		$this->query("DROP TABLE `xf_rexshop_logs`;");
	}
}
