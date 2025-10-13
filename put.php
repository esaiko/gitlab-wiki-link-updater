<?php
require('GitlabWikiPageLinkUpdater.php');
$workdir=dirname(__DIR__).'/work';
if(!is_dir($workdir))
	mkdir($workdir,0777,true);
$dumpfile=$workdir.'/dump.json';
if($argc>1) {
	$dumpfile=$argv[1];
}
if(!is_file($dumpfile)){
	die("missing file ${dumpfile}\n");
}
$wikipages=json_decode(file_get_contents($dumpfile),true);
$processor=new GitlabWikiPageLinkUpdater();
$processor->put($wikipages);
