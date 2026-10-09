<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Found Jobs</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:0;background:#f4f6f8;color:#111827}
nav{background:#111827;padding:14px 24px}
nav a{color:#fff;text-decoration:none;margin-right:20px}
.wrap{max-width:1180px;margin:28px auto;padding:0 18px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.03)}
h1{margin:0}.heading-row{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:6px}.job-count{font-weight:700;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;padding:8px 12px;border-radius:999px;font-size:14px}
.sub{color:#6b7280;margin:0 0 20px}
table{width:100%;border-collapse:collapse}
th,td{padding:12px 10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
th{font-size:13px;color:#4b5563;background:#f9fafb}
.job-title{font-weight:700;color:#111827;text-decoration:none}
.muted{color:#6b7280;font-size:13px}
.btn{display:inline-block;background:#111827;color:#fff;text-decoration:none;border-radius:7px;padding:8px 12px;font-size:14px}
.empty{text-align:center;padding:50px 15px;color:#6b7280}
.flash{background:#ecfdf5;border:1px solid #a7f3d0;padding:12px;border-radius:8px;margin-bottom:16px}
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;margin-top:18px}.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 10px;border:1px solid #d1d5db;border-radius:7px;text-decoration:none;color:#111827;background:#fff;font-size:14px}.pagination a:hover{background:#f3f4f6}.pagination .active{background:#111827;color:#fff;border-color:#111827}.pagination .disabled{color:#9ca3af;background:#f9fafb;cursor:not-allowed}.page-summary{text-align:center;color:#6b7280;font-size:13px;margin-top:10px}@media(max-width:760px){table{display:block;overflow-x:auto;white-space:nowrap}}
</style>
</head>
<body>
<nav>
<a href="/">Dashboard</a>
<a href="/sources">Sources</a>
<a href="/reviews">Found Jobs</a>
</nav>

<main class="wrap">
<?php if(session('status')): ?>
<div class="flash"><?= e(session('status')) ?></div>
<?php endif; ?>

<div class="card">
<div class="heading-row"><h1>Found Jobs</h1><div class="job-count">Total Found Jobs: <?= (int)$candidates->total() ?></div></div>
<p class="sub">Only jobs discovered by the crawler are shown here.</p>

<?php if($candidates->count() === 0): ?>
<div class="empty">
<strong>No jobs found yet.</strong><br>
Run <b>Crawl Now — All Active Sources</b> from the Sources page.
</div>
<?php else: ?>
<table>
<thead>
<tr>
<th>Job Title</th>
<th>Organization</th>
<th>Vacancies</th>
<th>Last Date</th>
<th>Official Source</th>
<th></th>
</tr>
</thead>
<tbody>
<?php foreach($candidates as $c): ?>
<tr>
<td>
<a class="job-title" href="/reviews/<?= (int)$c->id ?>"><?= e($c->job_title ?: 'Untitled Job') ?></a>
</td>
<td><?= e($c->organization ?: '—') ?></td>
<td><?= e($c->total_vacancies ?: '—') ?></td>
<td><?= e($c->application_last_date ?: '—') ?></td>
<td>
<?php if($c->notification_pdf_url): ?>
<a target="_blank" rel="noopener" href="<?= e($c->notification_pdf_url) ?>">Notification</a>
<?php elseif($c->official_source_url): ?>
<a target="_blank" rel="noopener" href="<?= e($c->official_source_url) ?>">Official page</a>
<?php else: ?>
—
<?php endif; ?>
</td>
<td><a class="btn" href="/reviews/<?= (int)$c->id ?>">View Job</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if($candidates->hasPages()): ?>
<div class="pagination">
<?php if($candidates->onFirstPage()): ?>
<span class="disabled">← Previous</span>
<?php else: ?>
<a href="<?= e($candidates->previousPageUrl()) ?>">← Previous</a>
<?php endif; ?>

<?php
$start=max(1,$candidates->currentPage()-2);
$end=min($candidates->lastPage(),$candidates->currentPage()+2);
?>
<?php if($start>1): ?>
<a href="<?= e($candidates->url(1)) ?>">1</a>
<?php if($start>2): ?><span class="disabled">…</span><?php endif; ?>
<?php endif; ?>

<?php for($page=$start;$page<=$end;$page++): ?>
<?php if($page===$candidates->currentPage()): ?>
<span class="active"><?= (int)$page ?></span>
<?php else: ?>
<a href="<?= e($candidates->url($page)) ?>"><?= (int)$page ?></a>
<?php endif; ?>
<?php endfor; ?>

<?php if($end<$candidates->lastPage()): ?>
<?php if($end<$candidates->lastPage()-1): ?><span class="disabled">…</span><?php endif; ?>
<a href="<?= e($candidates->url($candidates->lastPage())) ?>"><?= (int)$candidates->lastPage() ?></a>
<?php endif; ?>

<?php if($candidates->hasMorePages()): ?>
<a href="<?= e($candidates->nextPageUrl()) ?>">Next →</a>
<?php else: ?>
<span class="disabled">Next →</span>
<?php endif; ?>
</div>
<div class="page-summary">
Showing <?= (int)$candidates->firstItem() ?>–<?= (int)$candidates->lastItem() ?> of <?= (int)$candidates->total() ?> jobs
</div>
<?php endif; ?>
<?php endif; ?>
</div>
</main>
</body>
</html>