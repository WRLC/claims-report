<?php
require __DIR__.'/src/bootstrap.php';
require_login();
if(time()-(int)($_SESSION['last_activity']??time())>1800){session_destroy();header('Location: login.php?expired=1');exit;}
$_SESSION['last_activity']=time();

$showClosed=($_GET['show']??'')==='closed';
$allInstitutions=can_manage_all_submissions();
$institutionFilter=$allInstitutions?(int)($_GET['institution_id']??0):tenant_id();
$condition=$showClosed?"c.stage='closed'":"c.stage<>'closed'";
$params=[];
if($institutionFilter>0){$condition.=' AND c.institution_id=?';$params[]=$institutionFilter;}
$q=db()->prepare("SELECT c.*,i.name institution_name,u.display_name assignee_name FROM claims_cases c JOIN institutions i ON i.id=c.institution_id LEFT JOIN users u ON u.id=c.assigned_to WHERE $condition ORDER BY COALESCE(c.next_action_at,c.opened_at),c.opened_at");
$q->execute($params);$cases=$q->fetchAll();
$closedSql="SELECT COUNT(*) FROM claims_cases WHERE stage='closed'";$closedParams=[];
if($institutionFilter>0){$closedSql.=' AND institution_id=?';$closedParams[]=$institutionFilter;}
$closed=db()->prepare($closedSql);$closed->execute($closedParams);$closedCount=(int)$closed->fetchColumn();
$institutions=[];
if($allInstitutions){$institutions=db()->query('SELECT id,name FROM institutions WHERE active=1 ORDER BY name')->fetchAll();}
render_start('Claims Returned queue');
$scope=$allInstitutions?($institutionFilter?'Filtered institution':'All institutions'):institution_label((string)user()['institution_name']);
echo '<div class="page-title"><div><p class="eyebrow">'.h($scope).'</p><h1>Claims Returned queue</h1><p class="muted">'.($showClosed?$closedCount.' closed':count($cases).' active').' · '.$closedCount.' closed</p></div>'.(can_edit()?'<a class="button" href="case.php" style="color:#fff !important">＋ New case</a>':'').'</div>';
if($allInstitutions){echo '<form class="queue-filter" method="get"><input type="hidden" name="show" value="'.($showClosed?'closed':'active').'"><label>Institution<select name="institution_id" onchange="this.form.submit()"><option value="0">All institutions</option>';foreach($institutions as $institution)echo '<option value="'.(int)$institution['id'].'" '.($institutionFilter===(int)$institution['id']?'selected':'').'>'.h(institution_label((string)$institution['name'])).'</option>';echo '</select></label></form>';}
echo '<p class="queue-filter"><a class="button '.(!$showClosed?'':'secondary'). '" href="index.php'.($allInstitutions&&$institutionFilter?'?institution_id='.$institutionFilter:'').'">Active cases</a> <a class="button '.($showClosed?'':'secondary').'" href="index.php?show=closed'.($allInstitutions&&$institutionFilter?'&institution_id='.$institutionFilter:'').'">Closed cases ('.$closedCount.')</a></p>';
echo '<section class="card table-wrap"><table class="claims-queue"><thead><tr><th>Case / Patron</th><th>Institution</th><th>Item</th><th>Case type</th><th>Partner institution</th><th>Overall status</th><th>Next action</th><th>Assigned</th></tr></thead><tbody>';
foreach($cases as $c){$status=$c['stage']==='closed'?($c['resolution']==='complete'?'Complete':'Not found'):str_replace('_',' ',$c['stage']);$isPartner=$c['loan_type']!=='local';echo '<tr><td><a href="case.php?id='.(int)$c['id'].'"><strong>'.h($c['public_id']).'</strong></a><br>'.h($c['patron_name']).'<small>'.h($c['patron_primary_id']).'</small></td><td>'.h(institution_label((string)$c['institution_name'])).'</td><td>'.h($c['item_title']).'<small>'.h($c['item_barcode']??'').'</small></td><td>'.h($c['loan_type']).'</td><td>'.h($isPartner?($c['partner_name']?:'Not specified'):'—').'</td><td><span class="badge">'.h($status).'</span></td><td>'.h($c['next_action_at']?date('M j, Y',strtotime($c['next_action_at'])):'—').'</td><td>'.h($c['assignee_name']??'Unassigned').'</td></tr>';}
if(!$cases)echo '<tr><td colspan="8" class="empty">'.($showClosed?'No closed cases.':'No active cases. Create one when a claim is received.').'</td></tr>';
echo '</tbody></table></section>';
echo '<script src="assets/claims-queue-sort.js" defer></script>';
render_end();
