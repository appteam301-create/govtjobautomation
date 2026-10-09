<?php
namespace App\Http\Controllers;

use App\Models\JobCandidate;
use App\Services\Extraction\JobDetailsEnricher;
use App\Services\Extraction\JobMissingDataFetcher;
use Illuminate\Http\Request;

class JobDetailsController extends Controller
{

    public function fetchAllData(JobCandidate $candidate, JobMissingDataFetcher $fetcher)
    {
        try {
            $result = $fetcher->fetch($candidate);
            $names = array_map(fn($field) => ucwords(str_replace('_',' ',$field)), $result['not_found'] ?? []);
            $message = 'Successfully fetched '.(int)($result['fetched_count'] ?? 0).' fields with Claude.';
            $mode = (string)($result['source_mode'] ?? '');
            $searches = (int)($result['web_searches_used'] ?? 0);
            if ($mode === 'official_evidence_first') {
                $message .= ' Official evidence was checked first.';
                $message .= $searches > 0
                    ? ' Claude then used '.$searches.' limited web search(es) only for unresolved fields.'
                    : ' No web search was needed.';
            } elseif ($mode === 'web_search_only') {
                $message .= ' Official evidence was unavailable/unreadable, so Claude used '.$searches.' limited web search(es).';
            }
            if (!empty($result['requires_admin_approval'])) {
                $message .= ' Review the source shown for each fetched field and approve the job before publishing.';
            }
            if ($names) {
                $message .= ' Could not find: '.implode(', ', $names).'.';
            }
            session()->flash('status', $message);

            return response()->json([
                'ok'=>true,
                ...$result,
                'message'=>$message,
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'ok'=>false,
                'message'=>$e->getMessage(),
            ], 422);
        }
    }


    public function retryMissingOnly(JobCandidate $candidate, JobMissingDataFetcher $fetcher)
    {
        try {
            $result = $fetcher->retryMissingOnly($candidate);
            $names = array_map(fn($field) => ucwords(str_replace('_',' ',$field)), $result['not_found'] ?? []);
            $message = 'Retry fetched '.(int)($result['fetched_count'] ?? 0).' fields.';
            $message .= ' Checked '.(int)($result['official_sources_checked'] ?? 0).' official/related source(s) first.';
            $message .= ' Used '.(int)($result['web_searches_used'] ?? 0).' targeted web search(es) (max 1).';
            if ($names) $message .= ' Still unavailable: '.implode(', ', $names).'.';

            return response()->json(['ok'=>true,...$result,'message'=>$message]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['ok'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function approveUnavailable(JobCandidate $candidate, JobMissingDataFetcher $fetcher)
    {
        try {
            $result = $fetcher->approveUnavailable($candidate);
            return response()->json([
                'ok'=>true,
                ...$result,
                'message'=>'Marked '.(int)($result['approved_count'] ?? 0).' remaining field(s) as Not Available with admin approval.',
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['ok'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function update(Request $request, JobCandidate $candidate)
    {
        $fields = JobDetailsEnricher::FIELDS;
        $data = is_array($candidate->extracted_data) ? $candidate->extracted_data : [];

        foreach ($fields as $field) {
            if (!$request->has($field)) {
                continue;
            }

            $value = $request->input($field);

            if (in_array($field, ['featured_job','urgent_hiring'], true)) {
                $data[$field] = $request->boolean($field);
                continue;
            }

            if (in_array($field, ['total_vacancies','age_minimum','age_maximum','salary_minimum','salary_maximum','application_fee'], true)) {
                $data[$field] = ($value === null || $value === '') ? null : (int)$value;
                continue;
            }

            $data[$field] = is_string($value) ? trim($value) : $value;
            if ($data[$field] === '') {
                $data[$field] = null;
            }
        }

        $data['pending_fields'] = array_values(array_filter($fields, fn($field) =>
            !array_key_exists($field, $data) || $data[$field] === null || $data[$field] === ''
        ));

        $candidate->extracted_data = $data;

        // Keep existing top-level searchable fields in sync.
        if (!empty($data['job_title'])) $candidate->job_title = $data['job_title'];
        if (!empty($data['organization'])) $candidate->organization = $data['organization'];
        if (array_key_exists('total_vacancies',$data)) $candidate->total_vacancies = $data['total_vacancies'];
        if (array_key_exists('application_end_date',$data)) $candidate->application_last_date = $data['application_end_date'];
        if (array_key_exists('apply_url',$data)) $candidate->application_url = $data['apply_url'];

        $candidate->save();

        return back()->with('status','Job details saved. Missing information remains marked Pending.');
    }
}
