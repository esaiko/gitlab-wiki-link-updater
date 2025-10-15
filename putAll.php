<?php
require('GitlabWikiPageLinkUpdater.php');
$dumpfile='';
if($argc>1) {
	$dumpfile=$argv[1];
}
if(!is_file($dumpfile)){
	die("missing file {$dumpfile}\n");
}
$wikipages=json_decode(file_get_contents($dumpfile),true);
$processor=new GitlabWikiPageLinkUpdater();
$processor->putAll($wikipages);
