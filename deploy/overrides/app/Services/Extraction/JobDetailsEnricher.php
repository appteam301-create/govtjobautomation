<?php
namespace App\Services\Extraction;

use App\Models\JobCandidate;
use Carbon\Carbon;

class JobDetailsEnricher
{
    public const FIELDS = [
        'job_title','organization','department','description','employment_type','job_category','logo',
        'qualification','experience_required','total_vacancies','age_minimum','age_maximum',
        'age_relaxation_details','salary_minimum','salary_maximum','salary_type','pay_scale',
        'application_fee','fee_details','state','city','job_location','application_start_date',
        'application_end_date','exam_date','admit_card_date','application_mode',
        'official_notification_url','apply_url','selection_process','exam_pattern','syllabus',
        'important_instructions','reservation_details','featured_job','urgent_hiring',
        'seo_title','seo_description','seo_keywords'
    ];

    public function enrich(JobCandidate $candidate): void
    {
        $data = is_array($candidate->extracted_data) ? $candidate->extracted_data : [];
        $raw = trim((string)($data['raw_text'] ?? ''));
        $flat = $this->flatten($raw);

        $values = [
            'job_title' => $candidate->job_title,
            'organization' => $candidate->organization,
            'total_vacancies' => $candidate->total_vacancies,
            'application_end_date' => $this->dateString($candidate->application_last_date),
            'apply_url' => $candidate->application_url,
            'official_notification_url' => $candidate->notification_pdf_url ?: $candidate->official_source_url,
            'logo' => $data['logo'] ?? (isset($candidate->logo) ? $candidate->logo : null),
            'featured_job' => false,
            'urgent_hiring' => false,
        ];

        if ($raw !== '') {
            $values += [
                'department' => $this->capture($raw, '/(?:department|dept\.?)[\s:\-]+([^\n]{3,180})/i'),
                'qualification' => $this->section($raw, ['educational qualification','qualification','essential qualification'], ['experience','age limit','age','salary','pay scale','fee','selection']),
                'experience_required' => $this->section($raw, ['experience required','experience'], ['age limit','age','salary','pay scale','fee','selection','qualification']),
                'age_relaxation_details' => $this->section($raw, ['age relaxation','relaxation in age'], ['salary','pay scale','fee','selection','qualification']),
                'fee_details' => $this->section($raw, ['application fee','examination fee','fee details','fee'], ['selection','how to apply','important dates']),
                'selection_process' => $this->section($raw, ['selection process','mode of selection','selection procedure'], ['exam pattern','syllabus','how to apply','important instructions']),
                'exam_pattern' => $this->section($raw, ['exam pattern','scheme of examination','examination pattern'], ['syllabus','selection process','important instructions']),
                'syllabus' => $this->section($raw, ['syllabus'], ['selection process','important instructions','how to apply']),
                'important_instructions' => $this->section($raw, ['important instructions','general instructions','instructions'], ['reservation','how to apply']),
                'reservation_details' => $this->section($raw, ['reservation','category wise vacancy','category-wise vacancy'], ['important instructions','how to apply']),
            ];

            $values['employment_type'] = $this->matchChoice($flat, [
                'Contract' => ['contractual','contract basis','on contract'],
                'Temporary' => ['temporary'],
                'Apprenticeship' => ['apprentice','apprenticeship'],
                'Internship' => ['internship','intern'],
                'Permanent' => ['permanent','regular basis','regular post'],
            ]);

            $values['application_mode'] = $this->matchChoice($flat, [
                'Online' => ['apply online','online application','online mode'],
                'Offline' => ['offline application','offline mode','send the application'],
                'Email' => ['apply by email','application through email','e-mail application'],
            ]);

            $values['total_vacancies'] ??= $this->intCapture($raw, '/(?:total\s+(?:number\s+of\s+)?vacanc(?:y|ies)|no\.?\s*of\s*posts?|vacanc(?:y|ies))[\s:\-]*(\d{1,6})/i');
            $values['age_minimum'] = $this->intCapture($raw, '/(?:minimum|min\.?)[\s\-]*(?:age)?[^\d]{0,15}(\d{2})\s*years?/i');
            $values['age_maximum'] = $this->intCapture($raw, '/(?:maximum|max\.?|upper age limit)[^\d]{0,20}(\d{2})\s*years?/i');
            if (!$values['age_maximum']) {
                $values['age_maximum'] = $this->intCapture($raw, '/(?:age limit|age)[^\n]{0,80}?not exceeding\s+(\d{2})\s*years?/i');
            }

            [$salaryMin,$salaryMax,$payScale] = $this->salary($raw);
            $values['salary_minimum'] = $salaryMin;
            $values['salary_maximum'] = $salaryMax;
            $values['pay_scale'] = $payScale;
            $values['salary_type'] = $this->matchChoice($flat, [
                'Monthly' => ['per month','monthly'],
                'Annual' => ['per annum','annual','p.a.'],
            ]);

            $values['application_fee'] = $this->moneyCapture($raw, '/(?:application|examination)\s*fee[^₹Rs\d]{0,20}(?:₹|Rs\.?|INR)?\s*([\d,]+)/i');

            $values['application_start_date'] = $this->dateNear($raw, ['application start date','online application starts','starting date','opening date']);
            $values['application_end_date'] ??= $this->dateNear($raw, ['application end date','last date','closing date','last date for application']);
            $values['exam_date'] = $this->dateNear($raw, ['exam date','examination date','date of examination']);
            $values['admit_card_date'] = $this->dateNear($raw, ['admit card date','admit card available','hall ticket']);

            $values['job_location'] = $this->capture($raw, '/(?:job location|place of posting|place of duty|posting location)[\s:\-]+([^\n]{2,160})/i');
            $values['state'] = $this->capture($raw, '/(?:state)[\s:\-]+([^\n,]{2,80})/i');
            $values['city'] = $this->capture($raw, '/(?:city|district)[\s:\-]+([^\n,]{2,80})/i');

            $values['apply_url'] ??= $this->urlNear($raw, ['apply online','application link','apply here']);
            $values['official_notification_url'] ??= $candidate->official_source_url;
            $values['job_category'] = $this->capture($raw, '/(?:job category|category of post|post category)[\s:\-]+([^\n]{2,100})/i');
            $values['description'] = $this->firstParagraph($raw);
        }

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $data[$field] = $values[$field] ?? null;
            }
        }

        // Pending list is explicit so reviewers know exactly what still needs human input.
        $data['pending_fields'] = array_values(array_filter(self::FIELDS, fn($f) =>
            !array_key_exists($f, $data) || $data[$f] === null || $data[$f] === ''
        ));

        $data['seo_title'] ??= $data['job_title'] ? trim($data['job_title'].' Recruitment '.date('Y')) : null;
        $data['seo_description'] ??= $this->seoDescription($data);
        $data['seo_keywords'] ??= $this->seoKeywords($data);

        $candidate->extracted_data = $data;
        $candidate->save();
    }

    private function flatten(string $s): string { return mb_strtolower(preg_replace('/\s+/', ' ', $s)); }
    private function capture(string $s,string $p): ?string { return preg_match($p,$s,$m) ? trim($m[1]) : null; }
    private function intCapture(string $s,string $p): ?int { $v=$this->capture($s,$p); return $v!==null ? (int)str_replace(',','',$v) : null; }
    private function moneyCapture(string $s,string $p): ?int { return $this->intCapture($s,$p); }

    private function section(string $raw,array $starts,array $ends): ?string
    {
        $lower=mb_strtolower($raw);
        $best=null;
        foreach($starts as $label){
            $pos=mb_stripos($lower,$label);
            if($pos===false) continue;
            $start=$pos+mb_strlen($label);
            $end=min(mb_strlen($raw),$start+1800);
            foreach($ends as $e){
                $p=mb_stripos($lower,$e,$start);
                if($p!==false && $p<$end) $end=$p;
            }
            $text=trim(preg_replace('/^[\s:\-]+/','',mb_substr($raw,$start,$end-$start)));
            if(mb_strlen($text)>=3) { $best=mb_substr($text,0,1500); break; }
        }
        return $best;
    }

    private function matchChoice(string $flat,array $choices): ?string
    {
        foreach($choices as $value=>$needles) foreach($needles as $n) if(str_contains($flat,$n)) return $value;
        return null;
    }

    private function salary(string $raw): array
    {
        if (preg_match('/(?:pay scale|salary|pay level|emoluments)[^\n]{0,80}?(?:₹|Rs\.?|INR)?\s*([\d,]{4,})\s*(?:-|to|–)\s*(?:₹|Rs\.?|INR)?\s*([\d,]{4,})/i',$raw,$m)) {
            return [(int)str_replace(',','',$m[1]),(int)str_replace(',','',$m[2]),trim($m[0])];
        }
        if (preg_match('/(?:pay level|level)\s*[-:]?\s*(\d{1,2})/i',$raw,$m)) {
            return [null,null,'Pay Level '.$m[1]];
        }
        return [null,null,null];
    }

    private function dateNear(string $raw,array $labels): ?string
    {
        foreach($labels as $label){
            $p='/'.preg_quote($label,'/').'[^\n\d]{0,40}(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}|\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4}|[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4})/i';
            if(preg_match($p,$raw,$m)){
                try { return Carbon::parse($m[1])->format('Y-m-d'); } catch(\Throwable $e){}
            }
        }
        return null;
    }

    private function urlNear(string $raw,array $labels): ?string
    {
        foreach($labels as $label){
            $p='/'.preg_quote($label,'/').'[^\n]{0,120}?(https?:\/\/[^\s<>"\']+)/i';
            if(preg_match($p,$raw,$m)) return rtrim($m[1],').,;');
        }
        return null;
    }

    private function firstParagraph(string $raw): ?string
    {
        $parts=preg_split('/\n\s*\n/',$raw);
        foreach($parts as $p){$p=trim($p); if(mb_strlen($p)>=80) return mb_substr($p,0,1000);}
        return null;
    }

    private function dateString($value): ?string
    {
        if(!$value) return null;
        try { return Carbon::parse($value)->format('Y-m-d'); } catch(\Throwable $e){ return null; }
    }

    private function seoDescription(array $d): ?string
    {
        if(empty($d['job_title']) && empty($d['organization'])) return null;
        $s=trim(($d['organization'] ?? '').' '.($d['job_title'] ?? ''));
        if(!empty($d['total_vacancies'])) $s.=' - '.$d['total_vacancies'].' vacancies';
        if(!empty($d['application_end_date'])) $s.='. Apply by '.$d['application_end_date'];
        return mb_substr(trim($s),0,155);
    }

    private function seoKeywords(array $d): ?string
    {
        $parts=array_filter([$d['job_title']??null,$d['organization']??null,$d['job_category']??null,$d['state']??null,'government jobs']);
        return $parts ? implode(', ',array_unique($parts)) : null;
    }
}
