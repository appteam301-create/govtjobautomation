<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Review Job</title><style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:0;background:#f4f6f8;color:#111827}nav{background:#111827;padding:14px 24px}nav a{color:#fff;text-decoration:none;margin-right:20px}.wrap{max-width:1280px;margin:28px auto;padding:0 18px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:18px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.full{grid-column:1/-1}label{font-weight:600;font-size:14px}input,select,textarea{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:7px;box-sizing:border-box;margin:5px 0 4px}textarea{min-height:110px}.pending{font-size:12px;color:#b45309;margin-bottom:8px}.ok{font-size:12px;color:#166534;margin-bottom:8px}.btn{background:#111827;color:#fff;border:0;border-radius:7px;padding:10px 14px;cursor:pointer}.save{background:#1d4ed8}.approve{background:#166534}.reject{background:#991b1b}.flash{background:#ecfdf5;border:1px solid #a7f3d0;padding:12px;border-radius:8px;margin-bottom:16px}.evidence{white-space:pre-wrap;max-height:600px;overflow:auto;background:#f9fafb;padding:14px;border-radius:8px}.title-row{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}.fetch-btn{background:#7c3aed}.retry-btn{background:#0369a1}.na-btn{background:#475569}.action-row{display:flex;gap:8px;flex-wrap:wrap}.fetch-btn[disabled]{opacity:.65;cursor:not-allowed}.ai-source{font-size:12px;margin:5px 0 8px;padding:7px 9px;border-radius:7px;background:#f5f3ff;border:1px solid #ddd6fe;color:#5b21b6}.ai-source a{color:#5b21b6;font-weight:700}.progress-box{display:none;margin-top:14px;padding:12px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe}.progress-box.show{display:block}.progress-line{font-weight:600;color:#1d4ed8}.fetch-error{color:#991b1b}.fetch-success{color:#166534}@media(max-width:800px){.grid{grid-template-columns:1fr}}
</style></head><body><nav><a href="/">Dashboard</a><a href="/sources">Sources</a><a href="/reviews">Review Queue</a></nav><main class="wrap"><?php if(session('status')): ?><div class="flash"><?= e(session('status')) ?></div><?php endif; ?><?php $d=is_array($candidate->extracted_data)?$candidate->extracted_data:[]; $pending=$d['pending_fields']??[]; $claudePending=is_array($d['claude_pending_approval']??null)?$d['claude_pending_approval']:[]; $adminNA=is_array($d['admin_not_available_fields']??null)?$d['admin_not_available_fields']:[]; $sources=is_array($d['field_sources']??null)?$d['field_sources']:[]; $unavailableFields=array_keys(array_filter($sources,fn($s)=>is_array($s)&&(($s['provider']??null)==='claude_unavailable'))); $v=fn($k,$fallback=null)=>$d[$k]??$fallback; $mark=function($k) use ($pending,$claudePending,$adminNA){ if(in_array($k,$adminNA,true)) return '<div class="ok">Not Available · Admin approved</div>'; if(in_array($k,$claudePending,true)) return '<div class="pending">Claude fetched · Pending admin approval</div>'; return in_array($k,$pending,true)?'<div class="pending">Pending</div>':'<div class="ok">Extracted / provided</div>'; }; $sourceMark=function($k) use ($sources){ $s=$sources[$k]??null; if(!is_array($s)) return ''; $provider=(string)($s['provider']??''); $url=(string)($s['source_url']??''); if($provider==='admin_not_available'){ $ref=$s['reference']??'Admin approved this field as Not Available.'; return '<div class="ai-source"><strong>Not Available · Admin approved</strong>'.($ref?'<div>'.e($ref).'</div>':'').'</div>'; } if($provider==='claude_unavailable'){ $ref=$s['reference']??'Reliable source unavailable.'; return '<div class="ai-source"><strong>Source unavailable</strong>'.($ref?'<div>'.e($ref).'</div>':'').'</div>'; } if($url==='') return ''; $label=in_array($provider,['claude_official_evidence','claude_retry_official'],true)?'Claude · Official evidence':(in_array($provider,['claude_web_search','claude_retry_web_search'],true)?'Claude · Web search':'AI/Web fetched'); if(!empty($s['needs_admin_approval'])) $label.=' · Admin approval required'; if(!empty($s['source_page'])) $label.=' · '.$s['source_page']; $title=$s['source_title']??'View source'; $ref=$s['reference']??null; return '<div class="ai-source"><strong>'.e($label).'</strong> · <a target="_blank" rel="noopener" href="'.e($url).'">'.e($title).'</a>'.($ref?'<div>'.e($ref).'</div>':'').'</div>'; }; ?><div class="card"><div class="title-row"><div class="action-row"><a href="/reviews" class="btn" style="text-decoration:none;background:#374151">← Back</a><h1 style="margin:0">Job Review</h1></div><div class="action-row"><button type="button" id="fetchAllDataBtn" class="btn fetch-btn" data-url="/reviews/<?= (int)$candidate->id ?>/fetch-all-data">Fetch All Data with Claude</button><?php if(count($unavailableFields)>0): ?><button type="button" id="retryMissingBtn" class="btn retry-btn" data-url="/reviews/<?= (int)$candidate->id ?>/retry-missing-only">Retry Missing Only (<?= count($unavailableFields) ?>)</button><?php endif; ?></div></div><div id="fetchProgress" class="progress-box"><div id="fetchProgressText" class="progress-line">Fetching missing job information...</div><div id="fetchProgressDetail" style="margin-top:6px"></div></div><p>Confidence: <strong><?= e($candidate->confidence_score) ?>%</strong>. Fields not found reliably are kept as <strong>Pending</strong>. Claude-fetched fields show their source URL and remain <strong>Pending admin approval</strong> until you approve this job. <strong>Retry Missing Only</strong> checks only Source unavailable fields, checks known official/related URLs first, then allows at most one targeted web search. If still unresolved, you can approve them as <strong>Not Available</strong>.</p></div><form method="post" action="/reviews/<?= (int)$candidate->id ?>/details"><?= csrf_field() ?><div class="card"><div class="grid">
<div><label>Job Title *</label><input name="job_title" value="<?= e($v('job_title',$candidate->job_title)) ?>"><?= $mark('job_title') ?><?= $sourceMark('job_title') ?></div>
<div><label>Organization *</label><input name="organization" value="<?= e($v('organization',$candidate->organization)) ?>"><?= $mark('organization') ?><?= $sourceMark('organization') ?></div>
<div><label>Department</label><input name="department" value="<?= e($v('department')) ?>"><?= $mark('department') ?><?= $sourceMark('department') ?></div>
<div><label>Employment Type</label><input name="employment_type" value="<?= e($v('employment_type')) ?>"><?= $mark('employment_type') ?><?= $sourceMark('employment_type') ?></div>
<div><label>Job Category</label><input name="job_category" value="<?= e($v('job_category')) ?>"><?= $mark('job_category') ?><?= $sourceMark('job_category') ?></div>
<div><label>Qualification</label><textarea name="qualification"><?= e($v('qualification')) ?></textarea><?= $mark('qualification') ?><?= $sourceMark('qualification') ?></div>
<div><label>Experience Required</label><textarea name="experience_required"><?= e($v('experience_required')) ?></textarea><?= $mark('experience_required') ?><?= $sourceMark('experience_required') ?></div>
<div><label>Total Vacancies</label><input type="number" name="total_vacancies" value="<?= e($v('total_vacancies',$candidate->total_vacancies)) ?>"><?= $mark('total_vacancies') ?><?= $sourceMark('total_vacancies') ?></div>
<div><label>Age Minimum</label><input type="number" name="age_minimum" value="<?= e($v('age_minimum')) ?>"><?= $mark('age_minimum') ?><?= $sourceMark('age_minimum') ?></div>
<div><label>Age Maximum</label><input type="number" name="age_maximum" value="<?= e($v('age_maximum')) ?>"><?= $mark('age_maximum') ?><?= $sourceMark('age_maximum') ?></div>
<div class="full"><label>Age Relaxation Details</label><textarea name="age_relaxation_details"><?= e($v('age_relaxation_details')) ?></textarea><?= $mark('age_relaxation_details') ?><?= $sourceMark('age_relaxation_details') ?></div>
<div><label>Salary Minimum</label><input type="number" name="salary_minimum" value="<?= e($v('salary_minimum')) ?>"><?= $mark('salary_minimum') ?><?= $sourceMark('salary_minimum') ?></div>
<div><label>Salary Maximum</label><input type="number" name="salary_maximum" value="<?= e($v('salary_maximum')) ?>"><?= $mark('salary_maximum') ?><?= $sourceMark('salary_maximum') ?></div>
<div><label>Salary Type</label><input name="salary_type" value="<?= e($v('salary_type')) ?>"><?= $mark('salary_type') ?><?= $sourceMark('salary_type') ?></div>
<div><label>Pay Scale</label><input name="pay_scale" value="<?= e($v('pay_scale')) ?>"><?= $mark('pay_scale') ?><?= $sourceMark('pay_scale') ?></div>
<div><label>Application Fee</label><input type="number" name="application_fee" value="<?= e($v('application_fee')) ?>"><?= $mark('application_fee') ?><?= $sourceMark('application_fee') ?></div>
<div><label>Fee Details</label><textarea name="fee_details"><?= e($v('fee_details')) ?></textarea><?= $mark('fee_details') ?><?= $sourceMark('fee_details') ?></div>
<div><label>State</label><input name="state" value="<?= e($v('state')) ?>"><?= $mark('state') ?><?= $sourceMark('state') ?></div>
<div><label>City</label><input name="city" value="<?= e($v('city')) ?>"><?= $mark('city') ?><?= $sourceMark('city') ?></div>
<div class="full"><label>Job Location</label><input name="job_location" value="<?= e($v('job_location')) ?>"><?= $mark('job_location') ?><?= $sourceMark('job_location') ?></div>
<div><label>Application Start Date</label><input type="date" name="application_start_date" value="<?= e($v('application_start_date')) ?>"><?= $mark('application_start_date') ?><?= $sourceMark('application_start_date') ?></div>
<div><label>Application End Date</label><input type="date" name="application_end_date" value="<?= e($v('application_end_date',optional($candidate->application_last_date)->format('Y-m-d'))) ?>"><?= $mark('application_end_date') ?><?= $sourceMark('application_end_date') ?></div>
<div><label>Exam Date</label><input type="date" name="exam_date" value="<?= e($v('exam_date')) ?>"><?= $mark('exam_date') ?><?= $sourceMark('exam_date') ?></div>
<div><label>Admit Card Date</label><input type="date" name="admit_card_date" value="<?= e($v('admit_card_date')) ?>"><?= $mark('admit_card_date') ?><?= $sourceMark('admit_card_date') ?></div>
<div><label>Application Mode</label><input name="application_mode" value="<?= e($v('application_mode')) ?>"><?= $mark('application_mode') ?><?= $sourceMark('application_mode') ?></div>
<div><label>Official Notification URL</label><input name="official_notification_url" value="<?= e($v('official_notification_url',$candidate->notification_pdf_url ?: $candidate->official_source_url)) ?>"><?= $mark('official_notification_url') ?><?= $sourceMark('official_notification_url') ?></div>
<div class="full"><label>Apply URL</label><input name="apply_url" value="<?= e($v('apply_url',$candidate->application_url)) ?>"><?= $mark('apply_url') ?><?= $sourceMark('apply_url') ?></div>
<div class="full"><label>Description</label><textarea name="description"><?= e($v('description')) ?></textarea><?= $mark('description') ?><?= $sourceMark('description') ?></div>
<div class="full"><label>Selection Process</label><textarea name="selection_process"><?= e($v('selection_process')) ?></textarea><?= $mark('selection_process') ?><?= $sourceMark('selection_process') ?></div>
<div class="full"><label>Exam Pattern</label><textarea name="exam_pattern"><?= e($v('exam_pattern')) ?></textarea><?= $mark('exam_pattern') ?><?= $sourceMark('exam_pattern') ?></div>
<div class="full"><label>Syllabus</label><textarea name="syllabus"><?= e($v('syllabus')) ?></textarea><?= $mark('syllabus') ?><?= $sourceMark('syllabus') ?></div>
<div class="full"><label>Important Instructions</label><textarea name="important_instructions"><?= e($v('important_instructions')) ?></textarea><?= $mark('important_instructions') ?><?= $sourceMark('important_instructions') ?></div>
<div class="full"><label>Reservation Details</label><textarea name="reservation_details"><?= e($v('reservation_details')) ?></textarea><?= $mark('reservation_details') ?><?= $sourceMark('reservation_details') ?></div>
<div><label><input style="width:auto" type="checkbox" name="featured_job" value="1" <?= $v('featured_job')?'checked':'' ?>> Featured Job</label></div>
<div><label><input style="width:auto" type="checkbox" name="urgent_hiring" value="1" <?= $v('urgent_hiring')?'checked':'' ?>> Urgent Hiring</label></div>
<div><label>SEO Title</label><input name="seo_title" value="<?= e($v('seo_title')) ?>"><?= $mark('seo_title') ?><?= $sourceMark('seo_title') ?></div>
<div class="full"><label>SEO Description</label><textarea name="seo_description"><?= e($v('seo_description')) ?></textarea><?= $mark('seo_description') ?><?= $sourceMark('seo_description') ?></div>
<div class="full"><label>SEO Keywords</label><input name="seo_keywords" value="<?= e($v('seo_keywords')) ?>"><?= $mark('seo_keywords') ?><?= $sourceMark('seo_keywords') ?></div>
</div><button class="btn save" type="submit">Save Details</button></div></form><div class="card"><h2>Official Evidence</h2><p><a target="_blank" rel="noopener" href="<?= e($candidate->official_source_url) ?>">Open official source</a><?php if($candidate->notification_pdf_url): ?> · <a target="_blank" rel="noopener" href="<?= e($candidate->notification_pdf_url) ?>">Open notification PDF</a><?php endif; ?></p><div class="evidence"><?= e($v('raw_text','')) ?></div></div><div class="card"><h2>Decision</h2><form method="post" action="/reviews/<?= (int)$candidate->id ?>"><?= csrf_field() ?><input type="hidden" name="job_title" value="<?= e($candidate->job_title) ?>"><input type="hidden" name="organization" value="<?= e($candidate->organization) ?>"><input type="hidden" name="total_vacancies" value="<?= e($candidate->total_vacancies) ?>"><input type="hidden" name="application_last_date" value="<?= e(optional($candidate->application_last_date)->format('Y-m-d')) ?>"><input type="hidden" name="application_url" value="<?= e($candidate->application_url) ?>"><textarea name="notes" placeholder="Reviewer notes"></textarea><button class="btn approve" name="action" value="approve">Approve</button> <button class="btn reject" name="action" value="reject">Reject</button></form></div></main><script>
(function(){
const btn=document.getElementById('fetchAllDataBtn');
const box=document.getElementById('fetchProgress');
const title=document.getElementById('fetchProgressText');
const detail=document.getElementById('fetchProgressDetail');
const retryBtn=document.getElementById('retryMissingBtn');
if(!btn) return;
const steps=[
'Reading official notification/PDF first...',
'Checking official recruitment source...',
'Asking Claude only for missing fields...',
'Using limited web search only for fields official evidence cannot verify...',
'Validating source URLs...',
'Validating results...',
'Saving information...'
];
btn.addEventListener('click',async function(){
  btn.disabled=true;
  box.classList.add('show');
  title.className='progress-line';
  title.textContent='Fetching missing job information...';
  let i=0;
  detail.textContent=steps[0];
  const timer=setInterval(()=>{ i=(i+1)%steps.length; detail.textContent=steps[i]; },1200);
  try{
    const res=await fetch(btn.dataset.url,{
      method:'POST',
      headers:{
        'Accept':'application/json',
        'Content-Type':'application/json',
        'X-CSRF-TOKEN':'<?= csrf_token() ?>'
      },
      body:'{}'
    });
    const data=await res.json();
    clearInterval(timer);
    if(!res.ok||!data.ok) throw new Error(data.message||'Unable to fetch job information.');
    title.className='progress-line fetch-success';
    title.textContent='Successfully fetched '+(data.fetched_count||0)+' fields.';
    detail.textContent=(data.not_found&&data.not_found.length)
      ? 'Could not find: '+data.not_found.map(x=>x.replaceAll('_',' ')).join(', ')
      : 'All searchable missing fields were checked.';
    setTimeout(()=>window.location.reload(),1600);
  }catch(e){
    clearInterval(timer);
    title.className='progress-line fetch-error';
    title.textContent='Fetch failed';
    detail.textContent=e.message||'Unable to fetch missing information.';
    btn.disabled=false;
  }
});

async function runSecondaryAction(button, startText, steps, successPrefix){
  if(!button) return;
  button.disabled=true;
  box.classList.add('show');
  title.className='progress-line';
  title.textContent=startText;
  let i=0;
  detail.textContent=steps[0];
  const timer=setInterval(()=>{i=(i+1)%steps.length;detail.textContent=steps[i];},1200);
  try{
    const res=await fetch(button.dataset.url,{
      method:'POST',
      headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'},
      body:'{}'
    });
    const data=await res.json();
    clearInterval(timer);
    if(!res.ok||!data.ok) throw new Error(data.message||'Action failed.');
    title.className='progress-line fetch-success';
    title.textContent=successPrefix;
    detail.textContent=data.message||'Completed.';
    setTimeout(()=>window.location.reload(),1700);
  }catch(e){
    clearInterval(timer);
    title.className='progress-line fetch-error';
    title.textContent='Action failed';
    detail.textContent=e.message||'Unable to complete action.';
    button.disabled=false;
  }
}

if(retryBtn){
  retryBtn.addEventListener('click',()=>runSecondaryAction(
    retryBtn,
    'Retrying Source unavailable fields only...',
    ['Checking related official URLs...','Reading official notification/page links...','Running one targeted fallback search if needed...','Validating sources...','Saving retry results...'],
    'Retry completed'
  ));
}

})();
</script></body></html>