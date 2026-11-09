<?php
require __DIR__.'/src/bootstrap.php';require_login();
if(time()-(int)($_SESSION['last_activity']??time())>1800){session_destroy();header('Location: login.php?expired=1');exit;}$_SESSION['last_activity']=time();
$q=db()->prepare("SELECT c.*,u.display_name assignee_name FROM claims_cases c LEFT JOIN users u ON u.id=c.assigned_to WHERE c.institution_id=? AND c.stage<>'closed' ORDER BY COALESCE(c.next_action_at,c.opened_at),c.opened_at");$q->execute([tenant_id()]);$cases=$q->fetchAll();
$closed=db()->prepare("SELECT COUNT(*) FROM claims_cases WHERE institution_id=? AND stage='closed'");$closed->execute([tenant_id()]);$closedCount=(int)$closed->fetchColumn();
render_start('Active cases');
echo '<div class="page-title"><div><p class="eyebrow">'.h(user()['institution_name']).'</p><h1>Claims Returned queue</h1><p class="muted">'.count($cases).' active · '.$closedCount.' closed</p></div>'.(can_edit()?'<a class="button" href="case.php">＋ New case</a>':'').'</div>';
echo '<section class="card table-wrap"><table><thead><tr><th>Case / Patron</th><th>Item</th><th>Stage</th><th>Partner</th><th>Next action</th><th>Assigned</th></tr></thead><tbody>';
foreach($cases as $c){echo '<tr><td><a href="case.php?id='.(int)$c['id'].'"><strong>'.h($c['public_id']).'</strong></a><br>'.h($c['patron_name']).'<small>'.h($c['patron_primary_id']).'</small></td><td>'.h($c['item_title']).'<small>'.h($c['item_barcode']??'').'</small></td><td><span class="badge">'.h(str_replace('_',' ',$c['stage'])).'</span></td><td>'.h($c['loan_type']==='local'?'—':$c['partner_status']).'</td><td>'.h($c['next_action_at']?date('M j, Y',strtotime($c['next_action_at'])):'—').'</td><td>'.h($c['assignee_name']??'Unassigned').'</td></tr>';}
if(!$cases)echo '<tr><td colspan="6" class="empty">No active cases. Create one when a claim is received.</td></tr>';echo '</tbody></table></section>';
render_end();
