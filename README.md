# gitlab-wiki-link-updater

## Description 
This is a simple tool to inject page reference links to Gitlab wiki pages.
Sub pages are listed in the parent page.
Backlinks are listed in the target page. ("who has a link to this page")
Example: page A has a link to page B. 

I wrote this because I did not find any tool or extension to Gitlab wiki that would automatically maintain the list of subpages in the parent page content, or maintain list of "who is linking to this page" on a page.
I hate maintaining such stuff manually. 

You can run this in cron or trigger this by gitlab wiki webhook.

NOTE: I have tested this only with a very small Gitlab wiki project. I am NOT an expert on Gitlab wiki - there may very well be better ways to achieve what I have done with this.

List of subpages and backlinks are listed at the end of the page. 
This tags the generated wiki content with special comments in the page. See below:
```
[//]: # "Subpages begin THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"
...
[//]: # "Subpages end THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"
```

This is written in PHP 8. This uses [GitLab PHP API Client](https://github.com/GitLabPHP/Client/)

## Already known issues
- page slug must be ASCII only. Gitlab wiki UI accepts UTF8 in the page slug but such slugs cause problems in the API
  

## Installation
Ubuntu
```
apt install php  php-mbstring  php-xml git
```

```
mkdir gitlab-tool && cd gitlab-tool

git clone git@github.com:esaiko/gitlab-wiki-link-updater.git
cd gitlab-wiki-link-updater
./composer.sh require "m4tthumphrey/php-gitlab-api:^12.0" "guzzlehttp/guzzle:^7.9.2"

# create a writable directory for logs and dump files
# this must be writable by your web server if you use webhooks.
mkdir ../work && chmod 777 ../work

```

## Configuration
You need Gitlab wiki [Access Token](https://docs.gitlab.com/user/project/settings/project_access_tokens/) and the project name.

Use config.example.php as an example and create file config.php. Set at least parameters serverUrl, authToken and projectName.

If you want to trigger this by a webhook, you must set also parameters webhook.secret and webhook.enableUpdate=>true.


## Command line usage

### Pull pages 
This action just pulls the pages from Gitlab wiki and makes the changes. This does NOT update content in Gitlab.
You can view the proposed changes in fikes ../work/MD/...

```
php getAll.php
```

This creates file ../work/debugData/wikipages.json with the wiki content. Modified pages have property 'content_orig' with the original content. 


### Update pages
This action makes changes to wiki pages and updates content in Gitlab. 
NOTE: Only changed pages are updated in Gitlab wiki. 
```
./update-Gitlab-links.sh
```
or
```
php getAll-and-putAkk.php
```

### Just put pages back to Gitlab wiki
This action puts changed pages to Gitlab
```
php putAll.php [../work/debugData/wikipages.json]
```

## Webhook 
### Configure webhook
Configure a webserver as your webhook server. 
Example Apache:
```
Alias "/webhook" /usr/local/gitlab-tool/gitlab-wiki-link-updater/webhook.php
<Directory /usr/local/gitlab-tool/gitlab-wiki-link-updater>
   Require all granted
   DirectoryIndex index.php index.html
   Options Indexes FollowSymLinks MultiViews
   AllowOverride All
</Directory>
```

Edit src/config.php and set webhook.secret and webhook.enableUpdate.

Add webhook in Gitlab. 
Example:
```
URL: https://gitlab-webhook.mydomain.fi:9443/webhook
Secret token: your secret
```

You probably must allow outgoing connections, too, unless you run webhooks locally in Gitlab server (127.0.0.1).

### Test webhook

Point your browser to you webhook server (example: https://gitlab-webhook.mydomain.fi:9443/webhook). The response should be 'invalid X-Gitlab-Event'.
Check also logs in your webhook server work/logs/YYYY-MM-DD.log:
```
2025-10-15 11:10:59 [688458] INFO HTTP 404 invalid X-Gitlab-Event
```

Test in Gitlab: select the webhook in Gitlab and click on Test -> Wiki page events. You should see response 'Hook executed successfully: HTTP 200'.  
Check also logs in your webhook server work/logs/YYYY-MM-DD.log:
```
2025-10-15 11:12:05 [688841] INFO getAll begin processing
2025-10-15 11:12:05 [688841] INFO Project Koe projekti A , processing 8 pages
2025-10-15 11:12:05 [688841] INFO Changed project Koe projekti A page home
2025-10-15 11:12:05 [688841] INFO processed 8 pages ,changing 0 pages
2025-10-15 11:12:05 [688841] INFO Dumped md files in /usr/local/gitlab-hack/work/MD
2025-10-15 11:12:05 [688841] INFO getAll end processing
2025-10-15 11:12:05 [688841] INFO putAll begin processing
2025-10-15 11:12:05 [688841] INFO putAll end processing updated 0 pages
2025-10-15 11:12:05 [688841] INFO HTTP 200 updated 0 pages
```


## Logs
Logfile is ../work/log/YYYY-MM-DD.log



