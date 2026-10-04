<?php
namespace App\Services\Crawling;

use Symfony\Component\DomCrawler\Crawler;

class HtmlDiscovery
{
    public function discover(string $html,string $baseUrl): array
    {
        if(trim($html)==='') return [];
        $crawler=new Crawler($html,$baseUrl);
        $items=[];
        try{
            $crawler->filter('a[href]')->each(function(Crawler $node) use(&$items,$baseUrl){
                $href=trim((string)$node->attr('href'));
                if($href==='' || str_starts_with($href,'#') || preg_match('/^(javascript:|mailto:|tel:)/i',$href)) return;

                $url=$this->resolve($baseUrl,$href);
                if(!$url || !preg_match('#^https?://#i',$url)) return;

                $title=trim(preg_replace('/\s+/u',' ',$node->text('',true)));
                $generic=$title==='' || mb_strlen($title)<5 || preg_match('/^(click here|read more|view|details|download|pdf|link|notification)$/i',$title);

                if($generic){
                    $dom=$node->getNode(0);
                    $p=$dom?->parentNode;
                    for($i=0;$p && $i<4;$i++,$p=$p->parentNode){
                        if(in_array(strtolower((string)$p->nodeName),['tr','li','article','section','div'],true)){
                            $candidate=trim(preg_replace('/\s+/u',' ',(string)$p->textContent));
                            if(mb_strlen($candidate)>=8){ $title=mb_substr($candidate,0,500); break; }
                        }
                    }
                }

                $path=strtolower((string)(parse_url($url,PHP_URL_PATH)??''));
                $type=str_ends_with($path,'.pdf')?'pdf':'html';
                $key=hash('sha256',$url.'|'.mb_strtolower($title));
                $items[$key]=['url'=>$url,'title'=>$title?:basename($path),'type'=>$type];
            });
        }catch(\Throwable $e){
            return [];
        }
        return array_values($items);
    }

    private function resolve(string $base,string $href): ?string
    {
        if(preg_match('#^https?://#i',$href)) return $href;
        $b=parse_url($base);
        if(!$b || empty($b['scheme']) || empty($b['host'])) return null;
        if(str_starts_with($href,'//')) return $b['scheme'].':'.$href;

        $origin=$b['scheme'].'://'.$b['host'].(isset($b['port'])?':'.$b['port']:'');
        if(str_starts_with($href,'/')) return $origin.$href;

        $path=$b['path']??'/';
        $dir=preg_replace('#/[^/]*$#','/',$path);
        $full=$dir.$href;
        $segments=[];
        foreach(explode('/',$full) as $seg){
            if($seg==='' || $seg==='.') continue;
            if($seg==='..') array_pop($segments); else $segments[]=$seg;
        }
        return $origin.'/'.implode('/',$segments);
    }
}
