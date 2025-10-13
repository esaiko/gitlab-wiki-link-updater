# gitlab-wiki-link-updater

## Description 
This is a simple tool to inject page reference links to Gitlab wiki pages.
Sub pages are listed in the parent page.
Backlinks are listed in the target page. ("who has a link to this page")
Example: page A has a link to page B. 

This is written in PHP 8. This uses [[GitLab PHP API Client|https://github.com/GitLabPHP/Client/]]

## Installation
Ubuntu
```
apt install php  php-mbstring  php-xml
```

```
./composer.sh require "m4tthumphrey/php-gitlab-api:^12.0" "guzzlehttp/guzzle:^7.9.2"
```


