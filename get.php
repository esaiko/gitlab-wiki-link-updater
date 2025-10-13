<?php
require('GitlabWikiPageLinkUpdater.php');
$workdir=dirname(__DIR__).'/work';
if(!is_dir($workdir))
	mkdir($workdir,0777,true);
$dumpfile=$workdir.'/dump.json';
$processor=new GitlabWikiPageLinkUpdater();
$processor->get();
$w=$processor->getWikipages();
file_put_contents($dumpfile,json_encode($w,JSON_PRETTY_PRINT));
