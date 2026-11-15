<?php
/* v215 Test as Pupil, and the new levels made earlier (database/migrations/045 and 047).
   v217 The Content Studio editor (upload a module, new levels, lessons and activities, the Division Admin's review) was
   removed. The Content Developer is now the System Developer: Home is the update check (app/syscheck.php).
   What stays here:
   - Levels already made in the studio (Level 8 and later): published ones work for pupils as before, drafts stay hidden.
   - "Test as Pupil": each System Developer has one hidden test pupil (users.role 'tester'). It answers for real, with every
     level open. It has no school, class or teacher, so it never appears in reports, lists or leaderboards. */
const STUDIO_ACTIONS=['studio_test_start','studio_test_reset'];

function studio_ready():bool{static $ok;if($ok===null){try{$ok=(bool)one("SHOW TABLES LIKE 'studio_lessons'");}catch(Throwable $e){$ok=false;}}return $ok;}
/** A Content Developer's hidden test pupil. */
function is_tester(int $pid):bool{static $c=[];if(!$pid)return false;return $c[$pid]??=studio_ready()&&(string)val('SELECT role FROM users WHERE id=?',[$pid])==='tester';}
/** A lesson made in the studio that pupils cannot see yet (draft or waiting for review). */
function studio_draft_lesson(int $lid):bool{static $c=[];if(!studio_ready())return false;return $c[$lid]??=(bool)val("SELECT 1 FROM studio_lessons WHERE lesson_id=? AND status<>'published'",[$lid]);}
function studio_row(int $lid):?array{return studio_ready()?(one('SELECT * FROM studio_lessons WHERE lesson_id=?',[$lid])?:null):null;}
/** The test pupil sees the studio's draft activities in a draft lesson. */
function studio_test_draft(int $pid,int $lid):bool{return $pid>0&&is_tester($pid)&&studio_draft_lesson($lid);}
/** Which lessons the test pupil opens: every published lesson and every studio draft, for its grade's module. */
function studio_tester_lesson_ok(int $pid,int $lid):bool{
 $l=one('SELECT l.published,m.level_id,m.grade_level g FROM lessons l JOIN modules m ON m.id=l.module_id WHERE l.id=?',[$lid]);if(!$l)return false;
 if(!(int)$l['published']&&!studio_draft_lesson($lid))return false;
 if($l['g']!==null&&(int)$l['g']!==pupil_grade($pid))return false;
 return level_published((int)$l['level_id'])||studio_draft_level((int)$l['level_id']);
}
/** Studio lessons are extra: they never decide whether a level is finished (so publishing one never re-locks a level). */
function studio_not_extra_sql(string $l='l'):string{if(!studio_ready())return '';return ' AND '.$l.'.id NOT IN (SELECT sx.lesson_id FROM studio_lessons sx JOIN lessons lx ON lx.id=sx.lesson_id JOIN modules mx ON mx.id=lx.module_id'.(studio_levels_ready()?' WHERE mx.level_id NOT IN (SELECT level_id FROM studio_levels)':'').')';}   // a new level's own lessons do count for that level

/* ---------- v216 new levels (database/migrations/047_studio_levels.sql) ---------- */
function studio_levels_ready():bool{static $ok;if($ok===null){try{$ok=(bool)one("SHOW TABLES LIKE 'studio_levels'");}catch(Throwable $e){$ok=false;}}return $ok;}
/** Every new level (any status), in the order they were made: they come after Level 7. */
function studio_levels_all():array{static $c=null;if($c===null){$c=[];if(studio_levels_ready())foreach(rows('SELECT * FROM studio_levels ORDER BY level_id') as $r)$c[(int)$r['level_id']]=$r;}return $c;}
function studio_extra_levels():array{return array_keys(studio_levels_all());}
function studio_level(int $id):?array{return studio_levels_all()[$id]??null;}
function studio_draft_level(int $id):bool{$l=studio_level($id);return $l!==null&&$l['status']!=='published';}
/** "Level 8", "Level 9" … in order after Level 7. */
function studio_level_label(int $id):string{$i=array_search($id,studio_extra_levels(),true);return $i===false?'Level '.$id:'Level '.(8+$i);}
function studio_level_cover(int $id):string{$l=studio_level($id);return $l&&$l['cover_path']&&resolved_image_path((string)$l['cover_path'])?(string)resolved_image_path((string)$l['cover_path']):'';}
/** Hides draft new levels from pupils and teachers (the Content Developer's test pupil and the studio still see them). */
function studio_hide_levels_sql(string $col):string{
 if(!studio_levels_ready())return '';$u=function_exists('current_user')?current_user():null;
 if($u&&(!empty($u['is_tester'])||($u['role']??'')==='studio'))return '';
 return ' AND '.$col." NOT IN (SELECT level_id FROM studio_levels WHERE status<>'published')";
}
/** Lessons a pupil sees: published ones; the test pupil also sees the studio's drafts. */
function studio_pub_sql(string $l,int $pid):string{return is_tester($pid)&&studio_ready()?'('.$l.".published=1 OR ".$l.".id IN (SELECT lesson_id FROM studio_lessons WHERE status<>'published'))":$l.'.published=1';}
/** Levels where the studio can add lessons: Level 1 and every new level (they use the Level 1 screens). */
function studio_lessons_allowed(int $level):bool{return $level===1||studio_level($level)!==null;}
/** v216 A level with a separate module for each grade: [grade => module id]; empty for one module. */
function studio_grade_modules(int $level):array{static $c=[];return $c[$level]??=array_map('intval',array_column(rows('SELECT id,grade_level FROM modules WHERE level_id=? AND grade_level IS NOT NULL ORDER BY grade_level',[$level]),'id','grade_level'));}
function studio_level_module(int $level):int{return (int)(val('SELECT id FROM modules WHERE level_id=? ORDER BY id LIMIT 1',[$level])?:0);}
function studio_css_link():string{$u=function_exists('current_user')?current_user():null;return ($u&&(($u['role']??'')==='studio'||($u['active_role']??'')==='central_admin'||!empty($_SESSION['studio_dev'])))?'<link rel="stylesheet" href="'.asset_url('studio.css').'">':'';}
function studio_is_dev(?array $u):bool{return $u&&($u['role']??'')==='studio';}

/* ---------- the yellow TEST MODE bar (on every page while a Content Developer tests as a pupil) ---------- */
function studio_test_bar():string{
 if(empty($_SESSION['studio_dev']))return '';$u=current_user();if(!$u||empty($u['is_tester']))return '';
 return '<div class="st-testbar" role="status"><span class="st-tb-tag">'.icon('eye').'TEST MODE</span><span class="st-tb-tx">You are testing as a pupil. All levels are open and nothing here counts in any report.</span><form method="post">'.csrf_field().'<input type="hidden" name="action" value="studio_test_back"><button class="st-tb-btn">'.icon('arrow','flip').'Back to <span class="st-tb-long">Content </span>Studio</button></form></div>';
}

/* ---------- the test pupil ---------- */
function studio_tester_of(int $dev):int{return (int)(val('SELECT pupil_id FROM studio_testers WHERE dev_id=?',[$dev])?:0);}
function studio_tester_make(array $dev,int $grade):int{
 $tid=studio_tester_of((int)$dev['id']);
 if($tid){q('UPDATE pupils SET grade_level=? WHERE user_id=?',[$grade,$tid]);return $tid;}
 db()->beginTransaction();try{
  $pid='X'.$dev['public_id'];$first=explode(' ',trim((string)$dev['name']))[0];
  q('INSERT INTO users(public_id,role,name,password_hash,school_id) VALUES(?,?,?,?,NULL)',[$pid,'tester','Test Pupil ('.$first.')',password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);$tid=(int)db()->lastInsertId();
  q('INSERT INTO pupils(user_id,grade_level,section) VALUES(?,?,?)',[$tid,$grade,'Test mode']);
  q('INSERT IGNORE INTO teachers(user_id) VALUES(?)',[$tid]);   // only so the level row below has an "assigned by" (no teacher page reads this table)
  q('INSERT INTO pupil_level_assignments(pupil_id,level_id,assigned_by) VALUES(?,?,?)',[$tid,LEVEL_ORDER[0],$tid]);
  q('INSERT INTO studio_testers(dev_id,pupil_id) VALUES(?,?)',[$dev['id'],$tid]);
  db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 return $tid;
}
/** Removes everything the test pupil did (answers, recordings, XP, badges, progress) and the test pupil itself. */
function studio_tester_wipe(int $tid):void{
 if(!is_tester($tid))return;
 $done='SELECT id FROM activity_completion WHERE pupil_id=?';
 q('DELETE FROM response_history WHERE completion_id IN('.$done.') OR actor_id=?',[$tid,$tid]);
 if(function_exists('rv_ready')&&rv_ready())q('DELETE FROM reading_verifications WHERE pupil_id=?',[$tid]);
 foreach(['activity_completion','activity_drafts','assessment_attempts','reading_assessments','pupil_progress','pupil_badges','pupil_rewards','pupil_xp','xp_transactions','pupil_streaks','learning_days','pupil_level_assignments','pupil_sections','teacher_pupils','pupil_details','help_requests','reading_checks'] as $t)
  del_q($t,'DELETE FROM '.$t.' WHERE pupil_id=?',[$tid]);
 foreach(studio_pupil_tables() as $t)del_q($t,'DELETE FROM '.$t.' WHERE pupil_id=?',[$tid]);
 del_user_rows($tid);q('DELETE FROM studio_testers WHERE pupil_id=?',[$tid]);q('DELETE FROM pupils WHERE user_id=?',[$tid]);q('DELETE FROM teachers WHERE user_id=?',[$tid]);
 del_unlink_actor($tid);del_settings($tid);q("DELETE FROM users WHERE id=? AND role='tester'",[$tid]);
}
/** Any other table with a pupil_id column (added by later versions), so a reset never leaves rows behind. */
function studio_pupil_tables():array{return array_column(rows("SELECT TABLE_NAME t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='pupil_id' AND TABLE_NAME NOT IN ('pupils','studio_testers')"),'t');}


/* ---------- actions: Test as Pupil only ---------- */
function studio_action(string $action):never{
 if(!studio_ready())fail('Test as Pupil needs database/migrations/045_content_studio.sql.');
 $u=current_user();if(!$u)fail('Please sign in again.',401);
 if($action==='studio_test_back'){
  $dev=(int)($_SESSION['studio_dev']??0);if(!$dev||empty($u['is_tester'])||studio_tester_of($dev)!==(int)$u['id'])fail('Not in test mode.',403);
  session_regenerate_id(true);$_SESSION['uid']=$dev;unset($_SESSION['studio_dev']);$_SESSION['active_role']='content_dev:0';$_SESSION['csrf']=bin2hex(random_bytes(32));
  go('?page='.(($b=(string)($_SESSION['studio_back']??''))!==''?$b:'studio_test'));}
 if(!studio_is_dev($u))fail('Only the System Developer can do this.',403);
 if($action==='studio_test_start'){
  $grade=max(1,min(10,(int)($_POST['grade']??1)));$tid=studio_tester_make($u,$grade);$_SESSION['studio_back']='studio_test';
  session_regenerate_id(true);$_SESSION['uid']=$tid;$_SESSION['studio_dev']=(int)$u['id'];unset($_SESSION['active_role'],$_SESSION['role_pop'],$_SESSION['hello']);$_SESSION['csrf']=bin2hex(random_bytes(32));
  go('?page=lessons');}
 if($action==='studio_test_reset'){$tid=studio_tester_of((int)$u['id']);if($tid)studio_tester_wipe($tid);audit('studio_test_reset','');flash('Your test pupil is fresh again: no answers, XP or badges.');go('?page=studio_test');}
 fail('The Content Studio was removed in v217.',410);
}
function studio_setup_card():bool{if(studio_ready())return false;echo '<section class="card"><h1>Test as Pupil</h1><p>'.icon('info').' Back up the database, then import <b>database/migrations/045_content_studio.sql</b> in phpMyAdmin to turn on Test as Pupil.</p></section>';return true;}
function studio_test_view(array $u):void{
 if(studio_setup_card())return;$tid=studio_tester_of((int)$u['id']);$g=$tid?(int)val('SELECT grade_level FROM pupils WHERE user_id=?',[$tid]):1;
 $st=$tid?one('SELECT (SELECT COALESCE(total,0) FROM pupil_xp WHERE pupil_id=?) xp,(SELECT COUNT(*) FROM activity_completion WHERE pupil_id=?) acts,(SELECT COUNT(*) FROM pupil_progress WHERE pupil_id=? AND completed_at IS NOT NULL) lessons',[$tid,$tid,$tid]):null;
 echo hm_head('SYSTEM DEVELOPER','Test as Pupil','Open BULIG Quest exactly as a pupil sees it, and answer for real.');
 echo '<div class="st-two"><section class="card st-testcard"><img src="'.e(friend_img('george','map')).'" alt=""><div><h2>Your test pupil</h2><ul class="st-can"><li class="ok">'.icon('check').'Every level and lesson is open, in any order</li><li class="ok">'.icon('check').'Speak, read with Word Coach, draw, earn XP and badges</li><li class="ok">'.icon('check').'Hidden: no school, class or teacher, never in reports or leaderboards</li></ul>';
 echo '<form method="post" class="st-form st-inline">'.csrf_field().'<input type="hidden" name="action" value="studio_test_start"><label>Test as a pupil in<select name="grade">';for($i=1;$i<=10;$i++)echo '<option value="'.$i.'"'.($i===$g?' selected':'').'>Grade '.$i.($i>=7?' (Junior High)':'').'</option>';
 echo '</select></label><button class="btn primary">'.icon('play').'Start testing</button></form><p class="muted st-small">Levels 4 to 7 have a different module for each grade. Choose the grade to test.</p></div></section>';
 echo '<section class="card"><h2>'.icon('chart').'What your test pupil did</h2>'.($st?admin_kpis([['bolt',(int)$st['xp'],'XP','',false],['check',(int)$st['acts'],'Activities answered','',false],['book',(int)$st['lessons'],'Lessons finished','',false]]):'<p class="muted">Nothing yet.</p>')
  .'<form method="post">'.csrf_field().'<input type="hidden" name="action" value="studio_test_reset"><button class="btn secondary"'.($tid?'':' disabled').' data-confirm="Clear everything your test pupil did? Answers, recordings, XP and badges are deleted.">'.icon('replay').'Reset test pupil</button></form><p class="muted st-small">Reset clears answers, recordings, XP and badges, so you can test again from the start.</p></section></div>';
}
