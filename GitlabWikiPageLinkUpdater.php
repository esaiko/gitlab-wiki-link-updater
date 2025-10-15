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
    protected $pageLinks=[];
	protected $dumpMD=true;
    protected $dumpData=true;

    protected $tags=[
        'backlinks'=>[
            'beginTag'=>'[//]: # "Backlinks begin THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"',
            'endTag'=>'[//]: # "Backlinks end THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"',
        ],
        'subpages'=>[
            'beginTag'=>'[//]: # "Subpages begin THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"',
            'endTag'=>'[//]: # "Subpages end THIS IS AUTOMATICALLY GENERATED DO NOT EDIT MANUALLY"',
        ],
    ];

	public function __construct($options=[]){
        umask(0000);
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
	public function webhook(){
		$this->verifyWebhookRequest();
		$data=json_decode(file_get_contents('php://input'),true);
		$this->verifyWebhookData($data);
		$n=$this->getAll();


		if(empty($this->config['webhook']['enableUpdate']) || !$this->config['webhook']['enableUpdate']){
			$this->webExit(200,"changed {$n} pages, skip update");
		}
		$n=$this->putAll();
		$this->webExit(200,"updated {$n} pages");
	}
	protected function verifyWebhookData($data){
		if(!$data){
			$this->webExit(404,'missing data');
		}
		if(empty($data['object_kind']) || $data['object_kind']!='wiki_page'){
			$this->webExit(404,'invalid object_kind');
		}
		if(empty($data['project']) || $data['project']['name']!=$this->projectName){
			$this->webExit(404,'invalid project.name');
		}
		if(empty($data['object_attributes']) || $data['object_attributes']['format']!='markdown'){
			$this->webExit(200,'skip format');
		}
		if(!in_array($data['object_attributes']['action'],['create','update'])){
			$this->webExit(200,'skip action');
		}
	}
	protected function verifyWebhookRequest(){
		$headers=getallheaders();
		if(!$headers || empty($headers['X-Gitlab-Event']) || $headers['X-Gitlab-Event']!='Wiki Page Hook'){
			$this->webExit(404,'invalid X-Gitlab-Event');
		}
		if(empty($this->config['webhook']) || empty($this->config['webhook']['secret'])){
			$this->webExit(404,'webhook is disabled');
		}
		if(empty($headers['X-Gitlab-Token']) || $headers['X-Gitlab-Token']!=$this->config['webhook']['secret']){
			$this->webExit(401,'permission denied');
		}
		return true;
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
	public function getAll(){
		$this->info(__FUNCTION__,'begin processing');
		if(!$this->selectProject()){
			throw new Exception('failed to select project');
		}
		$wikipages=$this->client->wiki()->showAll($this->project['id'],['with_content'=>true]);
		$this->wikipages=[];
        foreach($wikipages as $wikipage){
            if($wikipage['format']=='markdown')
                $this->wikipages[]=$wikipage;
        }
        $this->info('Project',$this->project['name'],', processing',count($this->wikipages),'pages');
		$this->collect_slugs();
		$this->backlinks=[];
        $this->subpages=[];
		$this->collect_backlinks();
		$this->collect_subpages();
		$n=$this->process_wikipages();
		if($this->dumpMD){
			$this->dumpMDfiles();
		}
        if($this->dumpData)
            $this->dumpDebugData();
		$this->info(__FUNCTION__,'end processing');
		return $n;
	}
	protected function collect_slugs(){
		$this->slugs=[];
		foreach($this->wikipages as $page){
		    $this->slugs[$page['slug']]=$page['title'];
		}
	}
	protected function find_linked_slugs($content){
        $content2=$this->clean_content($content);
	    $links=[];
	    foreach($this->slugs as $slug=>$title){
            $tags=[
                '('.$slug.')',
                '|'.$slug.']]',
                '[['.$slug.']]',
                $this->project['web_url'].'/-/wikis/'.$slug,
            ];
            foreach($tags as $tag){
                if(mb_strpos($content2,$tag)!==false){
                    if(!in_array($slug,$links)){
                        $links[]=$slug;
                    }
                }
            }
	    }
	    return $links;
	}
	protected function process_page($page){
		if(empty($page['format']) || $page['format']!='markdown'){
			$this->info('ignore format '.$page['format'].' , page '.$page['slug']);
			return $page;
		}
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
            $page['content_orig']=$c0;
            $page['content']=$c;
                $this->info('Changed project',$this->project['name'],'page',$page['slug']);
            }
	    return $page;
	}
	protected function process_content_backlinks($content,$links){
        $content=$this->clean_content($content,'backlinks');
	    if($links) {
            $c=[];
            $c[]=$this->tags['backlinks']['beginTag'];
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
		    $c[]=$this->tags['backlinks']['endTag'];
		    $content.="\r\n\r\n".implode("\r\n\r\n",$c);
	    }
	    return $content;
	}
    protected function clean_content($content,$tag=false){
        if(!$tag){
            $content=$this->clean_content($content,'backlinks');
            $content=$this->clean_content($content,'subpages');
            return $content;
        }
        $beginTag=$this->tags[$tag]['beginTag'];
        $endTag=$this->tags[$tag]['endTag'];
        do {
            $p0=mb_strpos($content,$beginTag);
            $p1=mb_strpos($content,$endTag);
            if($p0!==false) {
                $c1=mb_substr($content,0,$p0);
                $c2=mb_substr($content,$p1+strlen($endTag));
                $content=$c1.$c2;
            }
            $content=rtrim($content);
        } while($p0!==false);
        return $content;
    }
	protected function process_content_subpages($content,$links){
        $content=$this->clean_content($content,'subpages');
	    if($links) {
            $c=[];
            $c[]=$this->tags['subpages']['beginTag'];
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
            $c[]=$this->tags['subpages']['endTag'];
            $content.="\r\n\r\n".implode("\r\n\r\n",$c);
	    }
	    return $content;
	}

	protected function collect_backlinks(){
		foreach($this->wikipages as $page){
		    $this->pageLinks[$page['slug']]=$this->find_linked_slugs($page['content']);
		    foreach($this->pageLinks[$page['slug']] as $slug){
                if(!isset($this->backlinks[$slug]))
                    $this->backlinks[$slug]=[];
                if(!in_array($page['slug'],$this->backlinks[$slug]))
                    $this->backlinks[$slug][]=$page['slug'];
		    }
		}
	}
	protected function collect_subpages(){
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
                    if(!isset($this->backlinks[$slug]))
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
		    $page=$this->process_page($page);
		    if(!empty($page['content_orig']))
			    $n++;
		}
		$this->info('processed',count($this->wikipages),'pages',',changing',$n,'pages');
		return $n;
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
	public function putAll($wikipages=[]){
		$this->info(__FUNCTION__,'begin processing');
		if(!$this->selectProject()){
			throw new Exception('failed to select project');
		}
		if(!$wikipages)
			$wikipages=$this->wikipages;
		$n=0;
		foreach($wikipages as $page){
		    if(empty($page['content_orig']))
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
		return $n;
	}
	protected function webExit($status,...$message){
		$this->_wlog('INFO','HTTP '.$status.' '.implode(' ',$message));
		http_response_code($status);
		die(implode(' ',$message));
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
    protected function dumpDebugData(){
        if(!is_dir($this->workDir.'/debugData')) {
            mkdir($this->workDir . '/debugData', 0777, true);
            chmod($this->workDir . '/debugData', 0777);
        }
        file_put_contents($this->workDir . '/debugData/wikipages.json',json_encode($this->wikipages,JSON_PRETTY_PRINT));
        file_put_contents($this->workDir . '/debugData/slugs.json',json_encode($this->slugs,JSON_PRETTY_PRINT));
        file_put_contents($this->workDir . '/debugData/pageLinks.json',json_encode($this->pageLinks,JSON_PRETTY_PRINT));
        file_put_contents($this->workDir . '/debugData/subpages.json',json_encode($this->subpages,JSON_PRETTY_PRINT));
        file_put_contents($this->workDir . '/debugData/backlinks.json',json_encode($this->backlinks,JSON_PRETTY_PRINT));
        return true;
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

    public function getWorkDir(): string
    {
        return $this->workDir;
    }

    public function setWorkDir(string $workDir): void
    {
        $this->workDir = $workDir;
    }

    public function getConfig(): mixed
    {
        return $this->config;
    }

    public function setConfig(mixed $config): void
    {
        $this->config = $config;
    }

    public function getClient(): \Gitlab\Client
    {
        return $this->client;
    }

    public function setClient(\Gitlab\Client $client): void
    {
        $this->client = $client;
    }

    public function getProjectName(): mixed
    {
        return $this->projectName;
    }

    public function setProjectName(mixed $projectName): void
    {
        $this->projectName = $projectName;
    }

    /**
     * @return mixed
     */
    public function getProject()
    {
        return $this->project;
    }

    /**
     * @param mixed $project
     */
    public function setProject($project): void
    {
        $this->project = $project;
    }

    public function getSlugs(): array
    {
        return $this->slugs;
    }

    public function setSlugs(array $slugs): void
    {
        $this->slugs = $slugs;
    }

    public function getBacklinks(): array
    {
        return $this->backlinks;
    }

    public function setBacklinks(array $backlinks): void
    {
        $this->backlinks = $backlinks;
    }

    public function getSubpages(): array
    {
        return $this->subpages;
    }

    public function setSubpages(array $subpages): void
    {
        $this->subpages = $subpages;
    }

    public function getPageLinks(): array
    {
        return $this->pageLinks;
    }

    public function setPageLinks(array $pageLinks): void
    {
        $this->pageLinks = $pageLinks;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }

}
