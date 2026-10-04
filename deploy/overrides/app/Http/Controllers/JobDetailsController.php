<?php
namespace App\Http\Controllers;

use App\Models\JobCandidate;
use App\Services\Extraction\JobDetailsEnricher;
use Illuminate\Http\Request;

class JobDetailsController extends Controller
{
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
