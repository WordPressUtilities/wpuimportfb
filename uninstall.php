<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit();

require_once dirname( __FILE__ ).'/wpuimportfb.php';

$WPUImportFb->uninstall();
