<?php
require('GitlabWikiPageLinkUpdater.php');
$processor=new GitlabWikiPageLinkUpdater();
$processor->webhook();
