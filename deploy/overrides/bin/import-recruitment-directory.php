<?php

use App\Models\GovernmentSource;
use App\Models\SourceRule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';

$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$marker='/data/recruitment-directory-20261005-222-v2.done';
if(is_file($marker)){
    echo "Recruitment directory already imported.\n";
    exit(0);
}

$files=glob(__DIR__.'/../data/recruitment_directory_*.tsv') ?: [];
sort($files,SORT_NATURAL);

$rows=[];
foreach($files as $file){
    $handle=fopen($file,'rb');
    if(!$handle) continue;

    while(($line=fgets($handle))!==false){
        $line=rtrim($line,"\r\n");
        if($line==='') continue;

        $parts=explode("\t",$line);
        if(count($parts)<7) continue;

        [$directoryId,$level,$region,$organization,$pageType,$url,$domain]=array_map('trim',array_slice($parts,0,7));

        if(!filter_var($url,FILTER_VALIDATE_URL)) continue;
        if($organization==='') $organization=$domain ?: parse_url($url,PHP_URL_HOST);

        $rows[]=[
            'directory_id'=>(int)$directoryId,
            'level'=>$level,
            'region'=>$region,
            'organization'=>$organization,
            'page_type'=>$pageType,
            'url'=>$url,
            'domain'=>$domain ?: (string)parse_url($url,PHP_URL_HOST),
        ];
    }

    fclose($handle);
}

$rowsByUrl=[];
foreach($rows as $row){
    $key=strtolower(rtrim($row['url'],'/'));
    $rowsByUrl[$key]=$row;
}
$rows=array_values($rowsByUrl);
usort($rows,fn($a,$b)=>$a['directory_id']<=>$b['directory_id']);

$include=[
    'recruitment','vacancy','vacancies','advertisement','advt','applications invited',
    'apply online','direct recruitment','walk-in','walk in','apprentice','faculty position',
    'non-faculty','project position','research associate','junior research fellow',
    'senior research fellow','assistant professor','associate professor','professor',
    'consultant','resident','technician','officer','assistant','engineer','stenographer',
    'clerk','manager','executive','medical officer','staff nurse','postdoctoral',
    'fellowship','trainee'
];

$ignore=[
    'final result','provisional result','admit card','hall ticket','answer key','merit list',
    'shortlist','shortlisted','eligible candidates','not eligible','interview schedule',
    'exam schedule','examination schedule','objection','response sheet','cut off','cutoff',
    'appointment order','appointment letter','tender','procurement','auction'
];

$created=0;
$updated=0;

DB::transaction(function() use($rows,$include,$ignore,&$created,&$updated){
    foreach($rows as $row){
        $level=match($row['level']){
            'Central'=>'Central',
            'Union Territory'=>'UT',
            default=>'State',
        };

        $urlLower=strtolower($row['url']);
        $isPortal=in_array($row['page_type'],['Recruitment portal','Mixed notice board','Exam notifications'],true)
            || str_contains($urlLower,'.aspx')
            || str_contains($urlLower,'servlet')
            || str_contains($urlLower,'pariksha')
            || str_contains($urlLower,'default.aspx');

        $mode=$isPortal ? 'AUTO' : 'HTML';
        $listingIsRecruitment=$row['page_type']!=='Mixed notice board';

        $profile='generic';
        if(preg_match('#/(notice_category|document-category)/(recruitment|recruitments)#i',$row['url'])) $profile='nic-recruitment';
        elseif($isPortal) $profile='portal';
        elseif($row['page_type']==='Advertisements') $profile='advertisements';
        elseif($row['page_type']==='Exam notifications') $profile='exam-notifications';

        $source=GovernmentSource::query()->where('recruitment_url',$row['url'])->first();
        $wasNew=!$source;

        if(!$source) $source=new GovernmentSource();

        $source->fill([
            'name'=>mb_substr($row['organization'],0,255),
            'organization'=>mb_substr($row['organization'],0,255),
            'government_level'=>$level,
            'state'=>$level==='Central'?null:mb_substr($row['region'],0,100),
            'department'=>null,
            'official_domain'=>mb_substr($row['domain'],0,255),
            'recruitment_url'=>$row['url'],
            'crawler_mode'=>$mode,
            'status'=>'active',
            'check_frequency_minutes'=>360,
            'next_crawl_at'=>now(),
            'settings'=>[
                'directory_id'=>$row['directory_id'],
                'page_type'=>$row['page_type'],
                'listing_is_recruitment'=>$listingIsRecruitment,
                'source_profile'=>$profile,
                'force_browser'=>false,
                'max_items'=>120,
                'verified'=>true,
                'verified_at'=>'2026-10-05',
                'imported_from'=>'India_Government_Recruitment_Pages.xlsx',
            ],
        ]);
        $source->save();

        SourceRule::updateOrCreate(
            ['government_source_id'=>$source->id],
            [
                'include_keywords'=>$include,
                'ignore_keywords'=>$ignore,
                'enabled'=>true,
            ]
        );

        if($wasNew) $created++; else $updated++;
    }
});

file_put_contents($marker,json_encode([
    'imported_at'=>now()->toIso8601String(),
    'created'=>$created,
    'updated'=>$updated,
    'unique_sources'=>count($rows),
    'input_files'=>array_map('basename',$files),
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

echo "Recruitment directory import complete: {$created} created, {$updated} updated, ".count($rows)." unique sources.\n";
