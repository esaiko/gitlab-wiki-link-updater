<?php
require('GitlabWikiPageLinkUpdater.php');
$processor=new GitlabWikiPageLinkUpdater();
$processor->getAll();
$wikipages=$processor->getWikipages();

