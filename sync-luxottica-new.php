<?php
$cron = $argv[1];
error_reporting(E_ALL);
ini_set('display_errors', true);

require('./engine/CMSMain.inc.php');
require_once('./engine/simple_html_dom.php');
CMSGlobal::setTEXTHeader();
if (!$cron && !CMSLogicAdmin::getInstance()->isLoggedUser()) {
	echo 'LOGIN REQUIRED';
}

CMSPluginSession::getInstance()->close();

$parser = new CMSClassGlassesParserLuxotticaNew(array('userName' => '', 'userPassword' => ''));

// if (!$parser->syncLock()) {
// 	die('Luxottica parser already running');
// }

echo 'Syncing Luxottica', "\n";

try {
	$parser->sync();
} catch (Exception $e) {
	echo "Exeption try sync-luxottica.php\n";
	print_r($e); die;
}

$syncBrandsIds = $parser->getSyncedBrandsIds();


unset($parser);

$parser = new CMSClassGlassesParserLuxotticaNew(array('userName' => '', 'userPassword' => ''), $syncBrandsIds);


echo 'Syncing Luxottica 2', "\n";

try {
	$parser->sync();
} catch (Exception $e) {
	echo "Exeption try sync-luxottica.php\n";
	print_r($e); die;
}


$parser->syncUnlock();

die('dsdsds');


$sssss = updateAvlTimeForItems();

echo "DONE\n";
?>
