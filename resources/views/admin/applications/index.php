<?php
$view=$viewMode??'board';
$visibleCount=count($applications);
$canDecide=can('applications.decide');
$canBulk=can('applications.assign')||$canDecide;
$statusLabels=[
    'draft'=>'Draft','submitted'=>'Submitted','eligibility_check'=>'Eligibility check','under_review'=>'Under review',
    'correction_required'=>'Corrections','resubmitted'=>'Resubmitted','approved'=>'Approved','selected'=>'Selected',
    'payment_pending'=>'Payment pending','fee_verified'=>'Fee verified','admitted'=>'Admitted','rejected'=>'Rejected','withdrawn'=>'Withdrawn',
];
$transitionLabels=[
    'submitted'=>'Mark submitted','resubmitted'=>'Mark resubmitted','eligibility_check'=>'Begin eligibility check','under_review'=>'Start review','correction_required'=>'Request corrections',
    'approved'=>'Approve application','selected'=>'Select & allocate','payment_pending'=>'Request payment','fee_verified'=>'Mark fee verified',
    'admitted'=>'Admit applicant','rejected'=>'Reject application','withdrawn'=>'Record withdrawal',
];
$filterKeys=['status','q','cycle','program','category','eligibility','payment','reviewer'];
$activeFilters=[];
foreach($filterKeys as $key)if(isset($_GET[$key])&&is_scalar($_GET[$key])&&(string)$_GET[$key]!=='')$activeFilters[$key]=(string)$_GET[$key];
$returnQuery=http_build_query($activeFilters);
$viewUrl=static function(string $target)use($activeFilters):string{return url('admin/applications?'.http_build_query(array_merge($activeFilters,['view'=>$target])));};
$exportQuery=http_build_query($activeFilters);
$selectedStatus=(string)($_GET['status']??'');
$workflowStatuses=array_keys($statusLabels);
$stageIcons=['intake'=>'↓','review'=>'⌕','corrections'=>'↺','approved'=>'✓','selected'=>'◎','payment'=>'₹','verified'=>'◆','admitted'=>'★','closed'=>'×'];
$readiness=static function(array $application):array{
    $documents=(int)($application['document_total']??0);
    $approved=(int)($application['document_verified']??0);
    $corrections=(int)($application['open_corrections']??0);
    $payment=(string)($application['payment_status']??'');
    if($corrections>0)return ['Corrections open','warning'];
    if($documents>0&&$approved<$documents)return ["Documents {$approved}/{$documents}",'warning'];
    if(in_array((string)$application['status'],['payment_pending','fee_verified','admitted'],true))return [$payment!==''?'Payment '.ucwords(str_replace('_',' ',$payment)):'Payment not recorded',$payment==='verified'?'success':'warning'];
    if($documents>0)return ['Documents ready','success'];
    return ['No required documents','neutral'];
};
$recommended=static function(array $application):string{
    if((int)($application['open_corrections']??0)>0)return 'Review applicant corrections';
    return match((string)$application['status']){
        'submitted','resubmitted'=>'Begin eligibility review',
        'eligibility_check'=>'Complete eligibility decision',
        'under_review'=>'Review documents and decide',
        'correction_required'=>'Await applicant resubmission',
        'approved'=>'Open record to allocate a programme',
        'selected'=>'Confirm payment request',
        'payment_pending'=>'Verify received payment',
        'fee_verified'=>'Complete admission checks',
        'admitted'=>'Admission complete',
        'rejected','withdrawn'=>'Record closed',
        default=>'Open guided review',
    };
};
?>
<section class="admin-page-head workflow-page-head">
    <div><span class="eyebrow">Admissions command centre</span><h1>Application workflow</h1><p>Monitor workload, move eligible records through the lifecycle, and open the guided review whenever a decision needs applicant-specific evidence.</p></div>
    <div class="page-actions"><a class="button button-outline" href="<?= e($viewUrl($view==='board'?'table':'board')) ?>"><?= $view==='board'?'Table view':'Pipeline view' ?></a><?php if(can('reports.export')):?><a class="button button-primary" href="<?= url('admin/applications/export'.($exportQuery!==''?'?'.$exportQuery:'')) ?>">Export filtered CSV</a><?php endif ?></div>
</section>

<section class="workflow-metrics" aria-label="Current application workload">
    <article class="workflow-metric"><span>Active workload</span><strong><?= (int)($summary['active']??0) ?></strong><small>Open records in your scope</small></article>
    <article class="workflow-metric"><span>Unassigned</span><strong><?= (int)($summary['unassigned']??0) ?></strong><small>Waiting for an owner</small></article>
    <article class="workflow-metric <?= (int)($summary['ageing']??0)>0?'is-warning':'' ?>"><span>Ageing 72h+</span><strong><?= (int)($summary['ageing']??0) ?></strong><small>Submitted work needing attention</small></article>
    <article class="workflow-metric"><span>Corrections</span><strong><?= (int)($summary['corrections']??0) ?></strong><small>Applicant action in progress</small></article>
    <article class="workflow-metric is-success"><span>Admitted</span><strong><?= (int)($summary['admitted']??0) ?></strong><small>Completed admission outcomes</small></article>
</section>

<section class="panel workflow-filter-panel">
    <form method="get" action="<?= url('admin/applications') ?>" class="workflow-filters">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <label class="field"><span>Search</span><input type="search" name="q" value="<?= e($_GET['q']??'') ?>" placeholder="Application no., name, email or mobile"></label>
        <label class="field"><span>Status</span><select name="status"><option value="">All statuses</option><?php foreach($workflowStatuses as $status):?><option value="<?= e($status) ?>" <?= $selectedStatus===$status?'selected':'' ?>><?= e($statusLabels[$status]??ucwords(str_replace('_',' ',$status))) ?></option><?php endforeach ?></select></label>
        <label class="field"><span>Programme</span><select name="program"><option value="">All programmes</option><?php foreach($programs as $program):?><option value="<?= (int)$program['id'] ?>" <?= (string)($_GET['program']??'')===(string)$program['id']?'selected':'' ?>><?= e($program['name']) ?> (<?= e($program['code']) ?>)</option><?php endforeach ?></select></label>
        <label class="field"><span>Cycle</span><select name="cycle"><option value="">All cycles</option><?php foreach($cycles as $cycle):?><option value="<?= (int)$cycle['id'] ?>" <?= (string)($_GET['cycle']??'')===(string)$cycle['id']?'selected':'' ?>><?= e($cycle['name']) ?></option><?php endforeach ?></select></label>
        <label class="field"><span>Category</span><select name="category"><option value="">All categories</option><?php foreach($categories as $category):?><option value="<?= e($category['code']) ?>" <?= (string)($_GET['category']??'')===(string)$category['code']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach ?></select></label>
        <label class="field"><span>Eligibility</span><select name="eligibility"><option value="">Any result</option><?php foreach(['not_evaluated'=>'Not evaluated','eligible'=>'Eligible','needs_review'=>'Needs review','ineligible'=>'Ineligible'] as $value=>$label):?><option value="<?= e($value) ?>" <?= (string)($_GET['eligibility']??'')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
        <label class="field"><span>Payment</span><select name="payment"><option value="">Any payment state</option><?php foreach(['pending'=>'Pending','verified'=>'Verified','rejected'=>'Rejected','refunded'=>'Refunded'] as $value=>$label):?><option value="<?= e($value) ?>" <?= (string)($_GET['payment']??'')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
        <?php if($reviewers):?><label class="field"><span>Reviewer</span><select name="reviewer"><option value="">All reviewers</option><option value="unassigned" <?= ($_GET['reviewer']??'')==='unassigned'?'selected':'' ?>>Unassigned</option><?php foreach($reviewers as $reviewer):?><option value="<?= (int)$reviewer['id'] ?>" <?= (string)($_GET['reviewer']??'')===(string)$reviewer['id']?'selected':'' ?>><?= e($reviewer['name']) ?></option><?php endforeach ?></select></label><?php endif ?>
        <div class="workflow-filter-actions"><button class="button button-primary" type="submit">Apply filters</button><a class="button button-outline" href="<?= url('admin/applications?view='.$view) ?>">Reset</a></div>
    </form>
</section>

<div class="workflow-view-bar" aria-label="Application view">
    <div class="segmented-control"><a class="<?= $view==='board'?'active':'' ?>" href="<?= e($viewUrl('board')) ?>" <?= $view==='board'?'aria-current="page"':'' ?>>Pipeline</a><a class="<?= $view==='table'?'active':'' ?>" href="<?= e($viewUrl('table')) ?>" <?= $view==='table'?'aria-current="page"':'' ?>>Table</a></div>
    <p><strong><?= $visibleCount ?></strong> of <?= number_format($total) ?> <?= $view==='board'?'filtered records on this board':'filtered records on this page' ?><?php if($view==='board'&&$total>300):?> · refine filters to work within the 300-record board limit<?php endif ?></p>
</div>

<form id="application-bulk-form" method="post" data-bulk-workflow>
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= e($view) ?>"><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
    <?php if($canBulk):?><section class="bulk-workflow-bar" data-bulk-toolbar aria-label="Batch application actions">
        <label class="bulk-select-all"><input type="checkbox" data-select-visible> <span>Select visible</span></label>
        <strong data-selected-count aria-live="polite">0 selected</strong>
        <div class="bulk-workflow-actions">
            <?php if(can('applications.assign')):?><label><span class="sr-only">Assign selected records to</span><select name="assigned_to"><option value="">Choose reviewer…</option><?php foreach($reviewers as $reviewer):?><option value="<?= (int)$reviewer['id'] ?>"><?= e($reviewer['name']) ?></option><?php endforeach ?></select></label><button class="button button-outline button-sm" type="submit" formaction="<?= url('admin/applications/bulk/assign') ?>" data-confirm-bulk="Assign the selected applications to this reviewer? Existing assignments will be replaced.">Assign</button><?php endif ?>
            <?php if(can('applications.decide')):?><label><span class="sr-only">Move selected records to</span><select name="bulk_status"><option value="">Choose status…</option><?php foreach(['eligibility_check','under_review','approved','payment_pending','fee_verified','admitted','rejected','withdrawn'] as $status):?><option value="<?= e($status) ?>"><?= e($statusLabels[$status]??ucwords(str_replace('_',' ',$status))) ?></option><?php endforeach ?></select></label><label class="bulk-reason"><span class="sr-only">Batch decision reason</span><input name="bulk_remarks" placeholder="Reason (required for closure)"></label><button class="button button-primary button-sm" type="submit" formaction="<?= url('admin/applications/bulk/status') ?>" data-confirm-bulk="Apply this transition to every selected application? Each record will still pass server-side workflow validation.">Apply status</button><?php endif ?>
        </div>
    </section><?php endif ?>

    <?php if(!$applications):?>
        <section class="panel empty-state"><span>◇</span><h2>No applications found</h2><p>Adjust the filters or clear the search to see records in your permitted scope.</p></section>
    <?php elseif($view==='board'):?>
        <section class="application-board" data-application-board aria-label="Application lifecycle board">
            <?php foreach($boardColumns as $column):$columnApplications=array_values(array_filter($applications,static fn(array $application):bool=>in_array($application['status'],$column['statuses'],true)));$dropStatus=(string)($column['target']??'');$dropEnabled=$dropStatus!==''&&!in_array($dropStatus,['correction_required','selected'],true);?>
                <section class="workflow-column" data-workflow-column data-drop-status="<?= $dropEnabled?e($dropStatus):'' ?>" aria-labelledby="column-<?= e($column['key']) ?>">
                    <header><span class="workflow-column-icon" aria-hidden="true"><?= e($stageIcons[$column['key']]??'•') ?></span><div><h2 id="column-<?= e($column['key']) ?>"><?= e($column['label']) ?></h2><p><?= e($column['hint']) ?></p></div><b><?= count($columnApplications) ?></b></header>
                    <div class="workflow-card-list">
                        <?php foreach($columnApplications as $application):[$readyText,$readyTone]=$readiness($application);$allowed=$application['allowed_transitions']??[];$age=(int)($application['age_hours']??0);$applicant=(string)$application['applicant_name'];?>
                            <article class="workflow-card <?= $age>=72&&!in_array($application['status'],['admitted','rejected','withdrawn'],true)?'is-ageing':'' ?> <?= $canDecide?'':'is-static' ?>" draggable="<?= $canDecide?'true':'false' ?>" data-workflow-card data-application-id="<?= (int)$application['id'] ?>" data-action-form="workflow-action-<?= (int)$application['id'] ?>" data-allowed-statuses="<?= e(implode(' ',$allowed)) ?>">
                                <div class="workflow-card-top"><?php if($canBulk):?><label class="card-selector"><input type="checkbox" name="application_ids[]" value="<?= (int)$application['id'] ?>" data-application-select><span class="sr-only">Select <?= e($application['application_number']?:'Draft · attempt '.(int)($application['attempt_no']??1)) ?></span></label><?php endif ?><span class="status-badge status-<?= e($application['status']) ?>"><?= e($statusLabels[$application['status']]??ucwords(str_replace('_',' ',$application['status']))) ?></span><span class="age-chip <?= $age>=72?'is-late':'' ?>"><?= $age<1?'New':($age<24?$age.'h':(int)floor($age/24).'d') ?></span></div>
                                <a class="workflow-card-title" href="<?= url('admin/applications/'.$application['id']) ?>"><strong><?= e($application['application_number']?:'Draft · attempt '.(int)($application['attempt_no']??1)) ?></strong><span><?= e($applicant) ?></span></a>
                                <p class="workflow-card-course"><?= e($application['first_preference_name']??'No programme preference') ?></p>
                                <div class="workflow-card-signals"><span class="signal signal-<?= e($readyTone) ?>"><?= e($readyText) ?></span><?php if(!empty($application['eligibility_status'])):?><span class="signal"><?= e(ucwords(str_replace('_',' ',$application['eligibility_status']))) ?></span><?php endif ?></div>
                                <dl class="workflow-card-meta"><div><dt>Owner</dt><dd><?= e($application['reviewer_name']?:'Unassigned') ?></dd></div><div><dt>Next best action</dt><dd><?= e($recommended($application)) ?></dd></div></dl>
                                <div class="workflow-card-actions"><a class="button button-primary button-sm" href="<?= url('admin/applications/'.$application['id']) ?>">Guided review</a><?php if($canDecide&&$allowed):?><details class="workflow-action-menu"><summary class="button button-outline button-sm">Move…</summary><div><?php foreach($allowed as $target):?><?php if(in_array($target,['correction_required','selected'],true)):?><a href="<?= url('admin/applications/'.$application['id'].'#decision') ?>"><?= e($transitionLabels[$target]??$target) ?> <small>Open record</small></a><?php else:?><button type="submit" form="workflow-action-<?= (int)$application['id'] ?>" name="status" value="<?= e($target) ?>" data-workflow-transition data-transition-label="<?= e($transitionLabels[$target]??$target) ?>"><?= e($transitionLabels[$target]??$target) ?></button><?php endif ?><?php endforeach ?></div></details><?php endif ?></div>
                            </article>
                        <?php endforeach ?>
                        <?php if(!$columnApplications):?><div class="workflow-column-empty"><span>Drop eligible cards here</span><small><?= $dropEnabled?'Transitions are validated before saving.':'Use the guided record for this decision.' ?></small></div><?php endif ?>
                    </div>
                </section>
            <?php endforeach ?>
        </section>
    <?php else:?>
        <section class="panel table-card workflow-table-card"><div class="table-responsive"><table class="data-table application-workflow-table"><thead><tr><?php if($canBulk):?><th><span class="sr-only">Select</span></th><?php endif ?><th>Application</th><th>Applicant</th><th>Programme</th><th>Readiness</th><th>Owner</th><th>Status</th><th>Age</th><th>Action</th></tr></thead><tbody><?php foreach($applications as $application):[$readyText,$readyTone]=$readiness($application);$age=(int)($application['age_hours']??0);?><tr><?php if($canBulk):?><td><label class="card-selector"><input type="checkbox" name="application_ids[]" value="<?= (int)$application['id'] ?>" data-application-select><span class="sr-only">Select <?= e($application['application_number']?:'Draft · attempt '.(int)($application['attempt_no']??1)) ?></span></label></td><?php endif ?><td><strong><?= e($application['application_number']?:'Draft · attempt '.(int)($application['attempt_no']??1)) ?></strong><small><?= e($application['cycle_name']) ?></small></td><td><?= e($application['applicant_name']) ?><small><?= e($application['email']) ?></small></td><td><?= e($application['first_preference_name']??'Not selected') ?></td><td><span class="signal signal-<?= e($readyTone) ?>"><?= e($readyText) ?></span></td><td><?= e($application['reviewer_name']?:'Unassigned') ?></td><td><span class="status-badge status-<?= e($application['status']) ?>"><?= e($statusLabels[$application['status']]??ucwords(str_replace('_',' ',$application['status']))) ?></span></td><td><span class="age-chip <?= $age>=72?'is-late':'' ?>"><?= $age<1?'New':($age<24?$age.'h':(int)floor($age/24).'d') ?></span></td><td><a class="button button-outline button-sm" href="<?= url('admin/applications/'.$application['id']) ?>">Review</a></td></tr><?php endforeach ?></tbody></table></div></section>
    <?php endif ?>
</form>

<?php if($view==='table'&&$pages>1):?><nav class="pagination" aria-label="Application pages"><?php if($page>1):$previousQuery=http_build_query(array_merge($activeFilters,['view'=>'table','page'=>$page-1]));?><a href="<?= url('admin/applications?'.$previousQuery) ?>">← Previous</a><?php endif ?><b>Page <?= $page ?> of <?= $pages ?></b><?php if($page<$pages):$nextQuery=http_build_query(array_merge($activeFilters,['view'=>'table','page'=>$page+1]));?><a href="<?= url('admin/applications?'.$nextQuery) ?>">Next →</a><?php endif ?></nav><?php endif ?>

<?php if($view==='board'&&$canDecide):?><?php foreach($applications as $application):?>
<form id="workflow-action-<?= (int)$application['id'] ?>" action="<?= url('admin/applications/'.$application['id'].'/status') ?>" method="post" class="workflow-action-form" data-workflow-action-form>
    <?= csrf_field() ?><input type="hidden" name="status_version" value="<?= (int)$application['status_version'] ?>"><input type="hidden" name="return_to" value="board"><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>"><input type="hidden" name="remarks" value="" data-transition-remarks>
</form>
<?php endforeach ?><?php endif ?>

<dialog class="workflow-dialog" data-workflow-dialog aria-labelledby="workflow-dialog-title">
    <form method="dialog"><button class="dialog-close" value="cancel" aria-label="Close">×</button><span class="eyebrow">Confirm workflow action</span><h2 id="workflow-dialog-title" data-dialog-title>Move application?</h2><p data-dialog-copy>The server will verify permissions, eligibility, fees, allocation and record version before anything changes.</p><label class="field" data-dialog-reason-wrap><span>Decision note <small data-dialog-reason-hint>(optional)</small></span><textarea rows="3" data-dialog-reason placeholder="Add a concise audit note"></textarea></label><div class="dialog-actions"><button class="button button-outline" value="cancel">Cancel</button><button class="button button-primary" value="confirm" data-dialog-confirm>Confirm action</button></div></form>
</dialog>
