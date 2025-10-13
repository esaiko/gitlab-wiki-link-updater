<?php
/*
 * https://github.com/GitLabPHP/Client
 * https://docs.gitlab.com/api/wikis/#create-a-new-wiki-page
 * https://docs.gitlab.com/api/wikis/#edit-an-existing-wiki-page
 */
require('vendor/autoload.php');
class GitlabWikiPageLinkUpdater {
	protected $configFile=__DIR__.'/config.php';
	protected $workDir;
	protected $config;
	protected $serverUrl;
	protected $authToken;
	protected $client;
	protected $projectName;
	protected $project;
	protected $wikipages=[];
	protected $slugs=[];
	protected $backlinks=[];
	protected $subpages=[];
	protected $dumpMD=true;

	public function __construct($options=[]){
		if($options && is_array($options)){
			foreach($options as $key=>$value){
				if($key=='configFile') $this->setConfigFile($value);
				if($key=='serverUrl') $this->setServerUrl($value);
				if($key=='authToken') $this->setAuthToken($value);
				if($key=='dumpMD') $this->setDumpMD($value);
			}
		}
		$this->config=require($this->configFile);
		if(empty($this->config['serverUrl']))
			throw  new Exception('missing config serverUrl');
		$this->serverUrl=$this->config['serverUrl'];
		if(empty($this->config['authToken']))
			throw  new Exception('missing config authToken');
		if(empty($this->config['projectName']))
			throw  new Exception('missing config projectName');
		$this->workDir=dirname(__DIR__).'/work';
		$this->authToken=$this->config['authToken'];
		$this->projectName=$this->config['projectName'];
		$this->client = new Gitlab\Client();
		$this->client->setUrl($this->serverUrl);
		$this->client->authenticate($this->authToken, Gitlab\Client::AUTH_HTTP_TOKEN);
	}
	public function selectProject(){
		$projects=$this->client->projects()->all();
		foreach($projects as $project){
			if($project['name']==$this->projectName){
				$this->project=$project;
				return $this->project;
			}
		}
		return false;
	}
	/*
	 * Code to pull pages and change page content
	 */
	public function get(){
		$this->info(__FUNCTION__,'begin processing');
		if(!$this->selectProject()){
			throw new Exception('failed to select project');
		}
		$this->wikipages=$this->client->wiki()->showAll($this->project['id'],['with_content'=>true]);
		$this->info('Project',$this->project['name'],', processing',count($this->wikipages),'pages');
		$this->collect_slugs();
		// We must collect backlinks before subpages
		// Subpages adds to backlinks.
		$this->collect_backlinks();
		$this->collect_subpages();
		$this->process_wikipages();
		if($this->dumpMD){
			$this->dumpMDfiles();
		}
		$this->info(__FUNCTION__,'end processing');
		return $this;
	}
	protected function collect_slugs(){
		$this->slugs=[];
		foreach($this->wikipages as $page){
		    $this->slugs[$page['slug']]=$page['title'];
		}
	}
	protected function find_linked_slugs($content){
	    $links=[];
	    foreach($this->slugs as $slug=>$title){
		if(strpos($content,'('.$slug.')')!==false || strpos($content,'|'.$slug.']]')!==false || strpos($content,'[['.$slug.']]')!==false){
		    $links[]=$slug;
		}
	    }
	    return $links;
	}
	protected function process_page($page){
	    $backlinks=[];
	    if(isset($this->backlinks[$page['slug']]))
		$backlinks=$this->backlinks[$page['slug']];
	    $subpages=[];
	    if(isset($this->subpages[$page['slug']]))
		$subpages=$this->subpages[$page['slug']];
	    $c0=$page['content'];
	    $c=$page['content'];
	    $c=$this->process_content_subpages($c,$subpages);
	    $c=$this->process_content_backlinks($c,$backlinks);
	    // Ignore diff in white space
	    $c0_w=str_replace([' ',"\t","\r","\n"],'',$c0);
	    $c_w=str_replace([' ',"\t","\r","\n"],'',$c);
	    if($c_w!=$c0_w) {
		$page['content_old']=$c0;
		$page['content']=$c;
		    $this->info('Changed project',$this->project['name'],'page',$page['slug']);
	    }
	    return $page;
	}
	protected function process_content_backlinks($content,$links){
		$beginTag='[//]: # "Backlinks begin THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"';
		$endTag='[//]: # "Backlinks end THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"';
		
	    $p0=mb_strpos($content,$beginTag);
	    $p1=mb_strpos($content,$endTag);
	    if($p0!==false) {
		$c1=mb_substr($content,0,$p0);
		$c2=mb_substr($content,$p1+strlen($endTag));
		$content=$c1.$c2;
	    }
	    if($links) {
		$c=[];
		$c[]=$beginTag;
		$t0='#';
		if(!empty($this->config['BacklinksLevel']))
			$t0=$this->config['BacklinksLevel'];
		$t1='Related pages';
		if(!empty($this->config['BacklinksTitle']))
			$t1=$this->config['BacklinksTitle'];
		$c[]=$t0.' '.$t1;
		foreach($links as $link){
			$title=$link;
			if(isset($this->slugs[$link]))
				$title=$this->slugs[$link];
		    $c[]='[['.$title.'|'.$link.']]';
		}
		$c[]=$endTag;
		$content.="\r\n\r\n".implode("\r\n\r\n",$c);
	    }
	    return $content;
	}
	protected function process_content_subpages($content,$links){
		$beginTag='[//]: # "Subpages begin THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"';
		$endTag='[//]: # "Subpages end THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"';
		
	    $p0=mb_strpos($content,$beginTag);
	    $p1=mb_strpos($content,$endTag);
	    if($p0!==false) {
		$c1=mb_substr($content,0,$p0);
		$c2=mb_substr($content,$p1+strlen($endTag));
		$content=$c1.$c2;
	    }
	    if($links) {
		$c=[];
		$c[]=$beginTag;
		$t0='#';
		if(!empty($this->config['SubpagesLevel']))
			$t0=$this->config['SubpagesLevel'];
		$t1='Sub pages';
		if(!empty($this->config['SubpagesTitle']))
			$t1=$this->config['SubpagesTitle'];
		$c[]=$t0.' '.$t1;
		foreach($links as $link){
			$title=$link;
			if(isset($this->slugs[$link]))
				$title=$this->slugs[$link];
		    $c[]='[['.$title.'|'.$link.']]';
		}
		$c[]=$endTag;
		$content.="\r\n\r\n".implode("\r\n\r\n",$c);
	    }
	    return $content;
	}
	protected function process_content($content,$links){
		$beginTag='[//]: # "Page links begin THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"';
		$endTag='[//]: # "Page links end THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"';
	    $p0=mb_strpos($content,$beginTag);
	    $p1=mb_strpos($content,$endTag);
	    if($p0!==false) {
		$c1=mb_substr($content,0,$p0);
		$c2=mb_substr($content,$p1+strlen($endTag));
		$content=$c1.$c2;
	    }
	    if($links) {
		$c=[];
		$c[]=$beginTag;
		$t0='#';
		if(!empty($this->config['RelatedPagesLevel']))
			$t0=$this->config['RelatedPagesLevel'];
		$t1='Related pages';
		if(!empty($this->config['RelatedPagesTitle']))
			$t1=$this->config['RelatedPagesTitle'];
		$c[]=$t0.' '.$t1;
		foreach($links as $link){
			$title=$link;
			if(isset($this->slugs[$link]))
				$title=$this->slugs[$link];
		    $c[]='[['.$title.'|'.$link.']]';
		}
		$c[]=$endTag;
		$content.="\r\n\r\n".implode("\r\n\r\n",$c);
	    }
	    return $content;
	}
	protected function collect_backlinks(){
		$this->backlinks=[];
		foreach($this->wikipages as $page){
		    $links=$this->find_linked_slugs($page['content']);
		    foreach($links as $slug){
			foreach($this->wikipages as $linkedPage){
			    if($linkedPage['slug']==$slug) {
				if(!isset($this->backlinks[$slug]))
				    $this->backlinks[$slug]=[];
				if(!in_array($page['slug'],$this->backlinks[$slug]))
					$this->backlinks[$slug][]=$page['slug'];
			    }
			}
		    }
		}
	}
	protected function collect_subpages(){
		$this->subpages=[];
		foreach($this->wikipages as $page){
		    foreach($this->slugs as $slug=>$title){
			if($slug==$page['slug'])
			    continue;
			$ss=explode('/',$slug);
			array_pop($ss);
			$ss=implode('/',$ss);
			if(!$ss)
				continue;
			if($ss==$page['slug']){
			    if(!isset($this->subpages[$page['slug']]))
				$this->subpages[$page['slug']]=[];
			    if(!in_array($slug, $this->subpages[$page['slug']]))
				    $this->subpages[$page['slug']][]=$slug;
			    // Add parent page as a backlink!
			    if(!isset($this->backlinks[$page['slug']]))
				$this->backlinks[$slug]=[];
			    if(!in_array($page['slug'],$this->backlinks[$slug]))
				    $this->backlinks[$slug][]=$page['slug'];
			}
		    }
		}
	}
	protected function process_wikipages(){
		$n=0;
		foreach($this->wikipages as &$page){
		    $links=[];
		    if(isset($this->backlinks[$page['slug']]))
			$links=array_merge($links,$this->backlinks[$page['slug']]);
		    if(isset($this->subpages[$page['slug']]))
			$links=array_merge($links,$this->subpages[$page['slug']]);
		    $page=$this->process_page($page,$links);
		    if(!empty($page['content_old']))
			    $n++;
		}
		$this->info('processed',count($this->wikipages),'pages',',changing',$n,'pages');
	}
	protected function dumpMDfiles(){
		$dir=$this->workDir.'/MD';
		if(!is_dir($dir))
			mkdir($dir,0777,true);
		foreach($this->wikipages as $page){
			$d=$dir.'/'.dirname($page['slug']);
			if(!is_dir($d))
				mkdir($d,0777,true);
			$f=$dir.'/'.$page['slug'].'.md';
			file_put_contents($f,$page['content']);
		}
		$this->info('Dumped md files in',$dir);

	}
	/*
	 * Code to upload modified content
	 */
	public function put($wikipages=[]){
		$this->info(__FUNCTION__,'begin processing');
		if(!$this->selectProject()){
			throw new Exception('failed to select project');
		}
		if(!$wikipages)
			$wikipages=$this->wikipages;
		$n=0;
		foreach($wikipages as $page){
		    if(empty($page['content_old']))
			continue;
		    $params=[
			    'content'=>$page['content'],
			    'title'=>$page['title']
		    ];
		    $res=$this->client->wiki()->update($this->project['id'],$page['slug'],$params);
		    $this->info('Updated project',$this->project['name'],'page',$page['slug']);
		    $n++;
		}
		$this->info(__FUNCTION__,'end processing','updated',$n,'pages');
	}
	protected function info(...$message){
		return $this->_wlog('INFO',implode(' ',$message));
	}
	protected function warn(...$message){
		return $this->_wlog('WARN',implode(' ',$message));
	}
	protected function err(...$message){
		return $this->_wlog('ERROR',implode(' ',$message));
	}
	protected function _wlog($level,$message){
		file_put_contents($this->logfile(),
			implode(' ',
			[
				date('Y-m-d H:i:s'),
				'['.getmypid().']',
				$level,
				$message,
			])."\n",FILE_APPEND);
	}
	protected function logfile(){
		$dir=$this->workDir.'/log';
		if(!is_dir($dir)){
			mkdir($dir,0777,true);
			chmod($dir,0777);
		}
		return $dir.'/'.date('Y-m-d').'.log';

	}
	public function getConfigFile(){
		return $this->configFile;
	}
	public function setConfigFile($arg){
		return $this->configFile=$arg;
	}
	public function getServerUrl(){
		return $this->serverUrl;
	}
	public function setServerUrl($arg){
		return $this->serverUrl=$arg;
	}
	public function getAuthToken(){
		return $this->authToken;
	}
	public function setAuthToken($arg){
		return $this->authToken=$arg;
	}
	public function getWikipages(){
		return $this->wikipages;
	}
	public function setDumpMD($arg){
		return $this->DumpMD=$arg;
	}
	public function getDumpMD(){
		return $this->DumpMD;
	}
}
