# gitlab-wiki-link-updater

## Description 
This is a simple tool to inject page reference links to Gitlab wiki pages.
Sub pages are listed in the parent page.
Backlinks are listed in the target page. ("who has a link to this page")
Example: page A has a link to page B. 

This is written in PHP 8. This uses [GitLab PHP API Client](https://github.com/GitLabPHP/Client/)

NOTE: I have tested this only with a very small Gitlab wiki project. 

List of subpages and backlinks are listed at the end of the page. 
This tags the generated wiki content with special comments in the page. See below:
```
[//]: # "Subpages begin THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"
...
[//]: # "Subpages end THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"
```


## Already known issues
- wiki pages MUST use markdown, this will breake other formats
- page slug must be ASCII only. Gitlab wiki UI accepts UTF8 in the page slug but such slugs cause problems in the API
  

## Installation
Ubuntu
```
apt install php  php-mbstring  php-xml git
```

```
mkdir gitlab-tool && cd gitlab-tool
git@github.com:esaiko/gitlab-wiki-link-updater.git
./composer.sh require "m4tthumphrey/php-gitlab-api:^12.0" "guzzlehttp/guzzle:^7.9.2"

```

## Configuration
You need Gitlab wiki [Access Token](https://docs.gitlab.com/user/project/settings/project_access_tokens/) and the project name.

Use config.example.php as an example and create file config.php. Set at least parameters serverUrl, authToken and projectName.

## Usage

### Pull pages
This action just pulls the pages from Gitlab wiki and makes the changes. This does NOT update content in Gitlab.
You can view the proposed changes in fikes ../work/MD/...

```
php get.php
```

This creates file ../work/dump.json with the wiki content. Modified pages have property 'content_old' with the original content. 


### Update pages
This action makes changes to wiki pages and updates content in Gitlab. 
NOTE: Only changed pages are updated in Gitlab wiki. 
```
./update-Gitlab-links.sh
```
or
```
php get-and-put.php
```

### Just put pages back to Gitlab wiki
This action puts changed pages to Gitlab
```
php put.php
```








## Logs
Logfile is ../work/log/YYYY-MM-DD.log



