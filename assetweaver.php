<?php
if (!isset($argv[1])) {
	print "Usage: ".basename(__FILE__)." manual <database> <user> (<prefix>)\n";
	print "  e.g. ".basename(__FILE__)." pihlajamaki_uusi pihlajamaki j17_\n";
	print "Or: ".basename(__FILE__)." <configfile>\n";
	print "append argument 'verbose' for more details, 'ok' to execute\n";
	die();
}
if ((isset($argv[1])) && ($argv[1] == 'manual')) {
	$dbname = $argv[2];
	$user = $argv[3];
	$pwd = trim(readline('Database password for '.$user.': '));
	$prefix = 'j17_';
	if (isset($argv[4])) $prefix = $argv[4];
} else {
	$configfile = $argv[1];
	print 'Reading configuration from '.$configfile."\n";
	include($configfile);
	if (class_exists('JConfig')) {
		$conf = new JConfig();
		$dbname = $conf->db;
		$user = $conf->user;
		$pwd = $conf->password;
		$prefix = $conf->dbprefix;
	} elseif (isset($mosConfig_db)) {
		$dbname = $mosConfig_db;
		$user = $mosConfig_user;
		$pwd = $mosConfig_password;
		$prefix = $mosConfig_dbprefix;
	}
	if (!isset($dbname)) die('Fail!'."\n");
}
$cmd = '';
if (isset($argv[2])) {
	$cmd = array_pop($argv);
}


//$prefix = 'jos_';
//$dbname = 'base17';
//$prefix = 'j17_';
//$dbname = 'artasset';
//$dbname = 'pih17';


require_once('assetweaver-classes.php');

// SELECT a.id,c.asset_id,a.parent_id,a.name,a.title,c.id,c.parent_id FROM j17_assets AS a JOIN j17_categories AS c ON (c.asset_id = a.id) WHERE a.name LIKE "%category%";

// select count(*) as ct,substring_index(name,'.',2) AS cat from j17_assets GROUP BY (cat) ORDER BY ct DESC;


//$user = 'root';
//$pwd = 'rootpwd';

print 'Connecting to '.$dbname.' as '.$user.' to read tables: '.$prefix.'assets'."\n";

$dbc = mysql_connect('localhost',$user,$pwd);
if (!$dbc) { print mysql_error()."\n"; print "\nCannot conncect to database\n"; die(); }

mysql_select_db($dbname,$dbc);


$assets = new AssetContainer();

$sql = 'SELECT id,parent_id,lft,rgt,level,name,title FROM '.$prefix.'assets';
$res = mysql_query($sql,$dbc);
if (!$res) { print mysql_error()."\n"; mysql_close($dbc); die(); }
while ($row = mysql_fetch_assoc($res)) {

	$assets->newAsset($row);
}

$sql = 'SELECT id,asset_id,catid FROM '.$prefix.'content';
$res = mysql_query($sql,$dbc);
if (!$res) { print mysql_error()."\n"; mysql_close($dbc); die(); }
while ($row = mysql_fetch_assoc($res)) {
	$assets->assetArticleLink($row);
	
}

$sql = 'SELECT id,asset_id,level,parent_id,title FROM '.$prefix.'categories';
$res = mysql_query($sql,$dbc);
if (!$res) { print mysql_error()."\n"; mysql_close($dbc); die(); }
while ($row = mysql_fetch_assoc($res)) {

	$assets->assetCategoryLink($row);

}
mysql_close($dbc);

$assets->linkAssets();
$assets->checkConsistency();

if ($cmd == 'tree') {
	$assets->printTree();
}
print "\nErrors: ".count($assets->errors)." (".$assets->countActiveErrors().")\n";
$assets->printErrors();
print "\nApplied fixes: ".count($assets->fixed)."\n";


$sqls = $assets->generateSQL($prefix);
print "SQL to execute: ".count($sqls)."\n";
//foreach ($sqls as $sql) { if (@$count++ < 5) print $sql."\n"; }
if ($cmd == 'verbose') {
	print 'Printing all fixed assets'."\n";
	$assets->printFixes();
}
if ($cmd == 'veryverbose') {
	print 'Printing all SQL queries'."\n";
	foreach ($sqls as $sql) { print $sql."\n"; }
	print 'Printing all known errors'."\n";
	$assets->printErrors(true);
}

$execute = false;
if ($cmd == 'ok') $execute = true;

print "\n";
if ($execute) {

	print 'Reconnecting to '.$dbname.' as '.$user.' to write changes to: '.$prefix.'assets'."\n";

	$dbc = mysql_connect('localhost',$user,$pwd);
	if (!$dbc) { print mysql_error()."\n"; print "\nCannot conncect to database\n"; die(); }

	mysql_select_db($dbname,$dbc);

	print 'Executing SQL: ';
	foreach ($sqls as $sql) {
		mysql_query($sql,$dbc);
		print '.';
		$err = mysql_error($dbc);
		if ($err) {
			print $err;
		}
	}
	print " done.\n";
	mysql_close($dbc);
} else {
	print '*** SKIPPING SQL EXECUTION'."\n";
}


?>
