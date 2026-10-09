<?php
namespace App\Http\Controllers;
use App\Enums\CandidateStatus;
use App\Models\HumanReview;
use App\Models\JobCandidate;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index()
    {
        return view('reviews.index',[
            'candidates'=>JobCandidate::where('status',CandidateStatus::NeedsReview)->orderByDesc('confidence_score')->paginate(30)
        ]);
    }

    public function show(JobCandidate $candidate)
    {
        $candidate->load('evidence','reviews');
        return view('reviews.show',compact('candidate'));
    }

    public function decide(Request $r, JobCandidate $candidate)
    {
        $data=$r->validate([
            'action'=>'required|in:approve,reject',
            'job_title'=>'required|max:255',
            'organization'=>'required|max:255',
            'application_last_date'=>'nullable|date',
            'total_vacancies'=>'nullable|integer|min:0',
            'application_url'=>'nullable|url',
            'notes'=>'nullable|string|max:5000'
        ]);

        $before=$candidate->toArray();
        $candidate->fill(collect($data)->except(['action','notes'])->all());
        $candidate->status=$data['action']==='approve'?CandidateStatus::Approved:CandidateStatus::Rejected;

        if ($data['action']==='approve') {
            $extracted=is_array($candidate->extracted_data)?$candidate->extracted_data:[];
            $extracted['claude_pending_approval']=[];
            if(isset($extracted['field_sources'])&&is_array($extracted['field_sources'])){
                foreach($extracted['field_sources'] as $field=>$source){
                    if(is_array($source)&&str_starts_with((string)($source['provider']??''),'claude_')){
                        $source['needs_admin_approval']=false;
                        $source['approved_at']=now()->toIso8601String();
                        $extracted['field_sources'][$field]=$source;
                    }
                }
            }
            $candidate->extracted_data=$extracted;
        }

        $candidate->save();

        HumanReview::create([
            'job_candidate_id'=>$candidate->id,
            'user_id'=>null,
            'action'=>$data['action'],
            'before_data'=>$before,
            'after_data'=>$candidate->fresh()->toArray(),
            'notes'=>$data['notes']??null,
            'reviewed_at'=>now()
        ]);

        return redirect()->route('reviews.index')->with('status','Review saved.');
    }
}
