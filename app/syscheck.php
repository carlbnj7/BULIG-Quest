<?php
/* v217 The System Developer's Home: the update check.
   - Files: every file listed in public/bulig-check.php ($expected, made by tools/update_checker.py) is compared with the
     server copy. Only missing or old files are listed, with the folder to upload them to in FileZilla.
   - Database: each database/migrations/*.sql file is checked by what it makes (its CREATE TABLE and ADD COLUMN lines).
     "Run the missing SQL" asks for the password again, saves a backup in storage/backups, then runs the missing files
     in order and remembers them (sys_migrations). Files that only change lesson content cannot be checked this way. */

const SYS_DESC=['048_activity_time.sql'=>'Roxanne’s activity timer','049_fluency_log.sql'=>'Reading Growth page','050_class_tools.sql'=>'Week plan, groups and notes','047_studio_levels.sql'=>'Levels 8 and later','045_content_studio.sql'=>'Test as Pupil','044_streak_revives.sql'=>'Streak revives','040_hierarchy_roles.sql'=>'Division, municipal and district roles'];

function sys_root():string{return dirname(__DIR__);}
/** [path => sha1] from public/bulig-check.php, or null when that file is missing. */
function sys_expected():?array{
 $f=sys_root().'/public/bulig-check.php';if(!is_file($f))return null;
 if(!preg_match('/^\$expected=\[(.*)\];/m',(string)file_get_contents($f),$m))return null;
 preg_match_all("/'([^']+)'=>'([0-9a-f]{40})'/",$m[1],$x,PREG_SET_ORDER);$o=[];foreach($x as $r)$o[$r[1]]=$r[2];return $o;
}
/** ['bad'=>[[path, 'missing'|'old']], 'ok'=>n, 'total'=>n] or null. */
function sys_files():?array{
 $e=sys_expected();if($e===null)return null;$bad=[];$ok=0;
 foreach($e as $f=>$h){$p=sys_root().'/'.$f;if(!is_file($p))$bad[]=[$f,'missing'];elseif(sha1_file($p)!==$h)$bad[]=[$f,'old'];else $ok++;}
 return ['bad'=>$bad,'ok'=>$ok,'total'=>count($e)];
}

/* ---------- database ---------- */
function sys_mig_ready():void{static $d=false;if($d)return;$d=true;q('CREATE TABLE IF NOT EXISTS sys_migrations(file VARCHAR(120) NOT NULL PRIMARY KEY,ran_at DATETIME NOT NULL,ran_by INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');}
/** What a migration makes: [[table, column|null], ...]. */
function sys_probes(string $sql):array{
 $sql=preg_replace(['~/\*.*?\*/~s','~^\s*(--|#).*$~m'],'',$sql);$p=[];
 if(preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i',$sql,$m))foreach($m[1] as $t)$p[]=[strtolower($t),null];
 if(preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i',$sql,$m,PREG_SET_ORDER))foreach($m as $r)if(!in_array(strtoupper($r[2]),['INDEX','KEY','UNIQUE','PRIMARY','CONSTRAINT','FOREIGN','FULLTEXT'],true))$p[]=[strtolower($r[1]),strtolower($r[2])];
 return $p;
}
function sys_schema():array{static $s=null;if($s===null){$s=[];foreach(rows('SELECT LOWER(TABLE_NAME) t,LOWER(COLUMN_NAME) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()') as $r){$s[$r['t']][$r['c']]=true;}}return $s;}
/** Every migration: ['file','status'=>'done'|'todo'|'unknown','desc']. */
function sys_migrations():array{
 $dir=sys_root().'/database/migrations';$files=glob($dir.'/*.sql')?:[];natsort($files);
 sys_mig_ready();$ran=array_flip(array_column(rows('SELECT file FROM sys_migrations'),'file'));$s=sys_schema();$o=[];
 foreach($files as $f){$b=basename($f);$p=sys_probes((string)file_get_contents($f));
  if(isset($ran[$b]))$st='done';elseif(!$p)$st='unknown';
  else{$st='done';foreach($p as [$t,$c])if(!isset($s[$t])||($c!==null&&!isset($s[$t][$c]))){$st='todo';break;}}
  $o[]=['file'=>$b,'status'=>$st,'desc'=>SYS_DESC[$b]??''];}
 return $o;
}
/** The statements of a .sql file (splits on ; outside quotes and comments). */
function sys_split_sql(string $sql):array{
 $out=[];$cur='';$n=strlen($sql);$q=null;
 for($i=0;$i<$n;$i++){$ch=$sql[$i];$nx=$i+1<$n?$sql[$i+1]:'';
  if($q!==null){$cur.=$ch;if($ch==='\\'&&$q!=='`'){$cur.=$nx;$i++;continue;}if($ch===$q){if($nx===$q){$cur.=$nx;$i++;}else $q=null;}continue;}
  if($ch==='-'&&$nx==='-'&&($i+2>=$n||ctype_space($sql[$i+2]))||$ch==='#'){while($i<$n&&$sql[$i]!=="\n")$i++;$cur.="\n";continue;}
  if($ch==='/'&&$nx==='*'){$e=strpos($sql,'*/',$i+2);$i=$e===false?$n:$e+1;continue;}
  if($ch==="'"||$ch==='"'||$ch==='`'){$q=$ch;$cur.=$ch;continue;}
  if($ch===';'){if(trim($cur)!=='')$out[]=trim($cur);$cur='';continue;}
  $cur.=$ch;}
 if(trim($cur)!=='')$out[]=trim($cur);return $out;
}

/* ---------- v217 error log (written by bulig_log_error in app/bootstrap.php) ---------- */
function sys_log_dir():string{return sys_root().'/storage/logs';}
/** The errors, newest first, the same error counted once: ['k','m','f','l','p','t'(last),'first','n']. */
function sys_errors(int $days=30):array{
 $g=[];$since=date('Y-m-d H:i:s',time()-$days*86400);
 foreach(['errors-old.log','errors.log'] as $fn){$f=sys_log_dir().'/'.$fn;if(!is_file($f))continue;
  foreach(file($f,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $ln){$e=json_decode($ln,true);if(!is_array($e)||($e['t']??'')<$since)continue;
   $k=md5(($e['k']??'').'|'.($e['m']??'').'|'.($e['f']??'').'|'.($e['l']??''));
   if(!isset($g[$k]))$g[$k]=$e+['first'=>$e['t'],'n'=>0,'pages'=>[]];$g[$k]['n']++;$g[$k]['t']=$e['t'];if(($e['p']??'')!=='')$g[$k]['pages'][$e['p']]=true;}}
 usort($g,fn($a,$b)=>strcmp($b['t'],$a['t']));return $g;
}
/** An error in plain words: [title, what to do]. */
function sys_err_plain(array $e):array{
 $m=(string)($e['m']??'');$f=(string)($e['f']??'');
 if(preg_match("/Table '[^.']*\.?([\w]+)' doesn't exist/i",$m,$x))return ['A database table is missing ('.$x[1].')','Run the missing SQL above.'];
 if(preg_match("/Unknown column '([^']+)'/i",$m,$x))return ['A database column is missing ('.$x[1].')','Run the missing SQL above.'];
 if(preg_match('/Call to undefined function ([\w]+)/i',$m,$x))return ['A file is missing or old ('.$x[1].' was not found)','Upload the files listed above again, then check again.'];
 if(preg_match('/Failed opening required|No such file or directory/i',$m))return ['A file is missing on the server','Upload the files listed above again.'];
 if(preg_match('/SQLSTATE\[HY000\] \[2002\]|Connection refused|Too many connections|server has gone away/i',$m))return ['The database could not be reached','Wait a few minutes. If it keeps happening, check the database in Hostinger.'];
 if(preg_match('/Allowed memory size|Maximum execution time/i',$m))return ['A page needed too much time or memory','Try again later. A very big report or backup can do this.'];
 if(preg_match('/Disk quota|No space left/i',$m))return ['The server is full','Delete old backups in storage/backups, or ask Hostinger for more space.'];
 $k=(string)($e['k']??'');
 return [$k==='fatal'||$k==='stopped'?'A page stopped working':($k==='warning'?'Something small went wrong':'A small warning'),'Send this to the BULIG Quest developer: the message and the file below.'];
}
function sys_errors_card(bool $can_clear):string{
 $E=sys_errors();$bad=count(array_filter($E,fn($e)=>in_array($e['k']??'',['fatal','stopped'],true)));
 $h='<section class="card sys-err" id="sys-errors"><div class="ws-hd"><h2>'.icon('alert').'Recent errors</h2><span class="sys-pill '.($E?($bad?'bad':'wait'):'ok').'">'.($E?count($E).' in 30 days':'None').'</span></div>';
 if(!$E)return $h.'<p class="okline-sys">'.icon('check').'No errors in the last 30 days.</p></section>';
 $h.='<ul class="sys-elist">';
 foreach(array_slice($E,0,12) as $e){[$t,$do]=sys_err_plain($e);if(!$can_clear)$do='The System Developer sees this on the Update check and can fix it.';$stop=in_array($e['k']??'',['fatal','stopped'],true);
  $h.='<li class="'.($stop?'bad':'warn').'"><div class="sys-eh"><b>'.e($t).'</b><span>'.e(date('M j, g:i A',strtotime((string)$e['t']))).((int)$e['n']>1?' · '.(int)$e['n'].' times':'').'</span></div><p>'.e($do).'</p>'
   .'<details><summary>Details</summary><code>'.e((string)$e['m']).'</code><small>'.e((string)$e['f']).((int)($e['l']??0)?' · line '.(int)$e['l']:'').($e['pages']?' · page: '.e(implode(', ',array_keys($e['pages']))):'').'</small></details></li>';}
 $h.='</ul>';if(count($E)>12)$h.='<p class="muted st-small">and '.(count($E)-12).' older ones.</p>';
 if($can_clear)$h.='<form method="post">'.csrf_field().'<input type="hidden" name="action" value="sys_clear_errors"><button class="btn secondary small" data-confirm="Clear the error list? Do this after you fixed them.">'.icon('replay').'Clear the list</button></form>';
 return $h.'</section>';
}

/* ---------- action ---------- */
function sys_action(string $action):never{
 $u=current_user();if(!$u||($u['role']??'')!=='studio')fail('Only the System Developer can do this.',403);
 if($action==='sys_clear_errors'){foreach(['errors.log','errors-old.log'] as $fn)@unlink(sys_log_dir().'/'.$fn);audit('sys_clear_errors','');flash('The error list is clear.');go('?page=dashboard#sys-errors');}
 if($action!=='sys_run_sql')fail('Unknown action.');
 if(!password_verify((string)($_POST['password']??''),(string)$u['password_hash']))fail('Your password is not right. Nothing was changed.');
 $todo=array_values(array_filter(sys_migrations(),fn($m)=>$m['status']==='todo'));
 if(!$todo){flash('Nothing to run: the database is up to date.');go('?page=dashboard');}
 try{$bk=account_ids_backup('before-sql');}catch(Throwable $e){fail('The backup could not be saved, so no SQL was run. '.$e->getMessage());}
 @set_time_limit(900);$done=[];
 foreach($todo as $m){$st=sys_split_sql((string)file_get_contents(sys_root().'/database/migrations/'.$m['file']));
  foreach($st as $k=>$s){try{db()->exec($s);}catch(Throwable $e){
   audit('sys_run_sql','failed '.$m['file'].' #'.($k+1));
   fail(($done?'Done: '.implode(', ',$done).'. ':'').'Stopped at '.$m['file'].', part '.($k+1).' of '.count($st).': '.mb_substr($e->getMessage(),0,300).' A backup was saved first ('.$bk.').');}}
  q('INSERT INTO sys_migrations(file,ran_at,ran_by) VALUES(?,NOW(),?) ON DUPLICATE KEY UPDATE ran_at=NOW(),ran_by=VALUES(ran_by)',[$m['file'],(int)$u['id']]);$done[]=$m['file'];}
 audit('sys_run_sql',implode(', ',$done).' · backup '.$bk);
 flash('Done: '.implode(', ',$done).'. A backup was saved first ('.$bk.').');go('?page=dashboard');
}

/* ---------- page ---------- */
function sys_home(array $u):void{
 $F=sys_files();$M=sys_migrations();$todo=array_values(array_filter($M,fn($m)=>$m['status']==='todo'));$done=array_values(array_filter($M,fn($m)=>$m['status']==='done'));$unk=count($M)-count($todo)-count($done);
 $nf=$F?count($F['bad']):0;$fix=($F?($nf?1:0):1)+($todo?1:0);
 echo hm_head('SYSTEM DEVELOPER','Update check','After you upload an update with FileZilla, check here that every file arrived and the database is ready.');
 $say=[];if($F===null)$say[]='public/bulig-check.php is missing, so the files cannot be checked';elseif($nf)$say[]=$nf.' '.($nf===1?'file did':'files did').' not upload';
 if($todo)$say[]=count($todo).' SQL '.(count($todo)===1?'file is':'files are').' not imported yet';
 echo '<section class="card sys-sum'.($fix?' bad':' ok').'"><img src="'.e(friend_img('jarvis',$fix?'point':'thumbs')).'" alt="" width="90" height="110"><div><span class="eyebrow">JARVIS · '.($fix?$fix.' '.($fix===1?'THING':'THINGS').' TO FIX':'ALL GOOD').'</span><h2>'.e($fix?ucfirst(implode(', and ',$say)).'.':'Everything is up to date.').'</h2><p class="muted">'.($nf?'Fix the files first in FileZilla, then run the SQL. Pupils see “being updated” while a file is missing.':($todo?'The files are fine. Run the missing SQL below.':'Every file is the new version, and the database has every update.')).'</p></div><a class="btn secondary small" href="?page=dashboard">'.icon('replay').'Check again</a></section>';
 echo '<div class="sys-grid"><section class="card"><div class="ws-hd"><h2>'.icon('download').'Files on the server</h2><span class="sys-pill '.($F&&!$nf?'ok':'bad').'">'.($F?($nf?$nf.' to fix':'All new'):'Cannot check').'</span></div>';
 if($F===null)echo '<p class="muted">Upload <code>public/bulig-check.php</code> from the update ZIP, then choose Check again.</p>';
 else{echo '<ul class="st-can sys-list">';foreach(array_slice($F['bad'],0,40) as [$f,$why])echo '<li class="no">'.icon($why==='missing'?'close':'replay').'<span><code>'.e($f).'</code><small>'.($why==='missing'?'Missing':'Old copy').' · upload to the <b>'.e(dirname($f)).'/</b> folder</small></span></li>';
  if($nf>40)echo '<li class="no">'.icon('close').'<span>and '.($nf-40).' more</span></li>';
  echo '<li class="ok">'.icon('check').'<span>'.number_format($F['ok']).' '.($nf?'other ':'').'files are the new versions</span></li></ul>';
  if($nf)echo '<details class="sys-how"><summary>How to fix this in FileZilla</summary><ol><li>On the left (your computer), open the unzipped update folder.</li><li>On the right (the server), open the same folder as above.</li><li>Drag each file across and choose <b>Overwrite</b>.</li><li>At the bottom, open <b>Failed transfers</b>. If anything is there, right-click → <b>Reset and requeue all</b>.</li><li>Come back here and choose <b>Check again</b>.</li></ol></details>';}
 echo '</section><section class="card" id="sys-sql"><div class="ws-hd"><h2>'.icon('db').'Database (SQL)</h2><span class="sys-pill '.($todo?'wait':'ok').'">'.($todo?count($todo).' not imported':'Up to date').'</span></div>';
 if(!$M)echo '<p class="muted">The <code>database/migrations</code> folder is not on the server, so the SQL cannot be checked.</p>';
 else{echo '<ul class="st-can sys-list">';foreach($todo as $m)echo '<li class="wait">'.icon('clock').'<span><code>'.e($m['file']).'</code><small>Not yet'.($m['desc']!==''?' · '.e($m['desc']):'').'</small></span></li>';
  echo '<li class="ok">'.icon('check').'<span>'.count($done).' SQL files imported</span></li></ul>';
  if($unk)echo '<p class="muted st-small">'.$unk.' older SQL files only change lesson content, so they cannot be checked here.</p>';
  if($todo)echo '<form method="post" class="sys-run">'.csrf_field().'<input type="hidden" name="action" value="sys_run_sql"><label>Your password<input type="password" name="password" autocomplete="current-password" required></label><button class="btn primary" data-confirm="Run '.count($todo).' SQL '.(count($todo)===1?'file':'files').' now? A backup is saved first.">'.icon('play').'Run the missing SQL</button></form><p class="muted st-small">A backup of the whole database is saved in <b>storage/backups</b> first. If a file stops with an error, nothing after it runs.</p>';}
 echo '</section></div>';
 echo sys_errors_card(true);
 echo '<section class="card sys-test"><div><h2>'.icon('play').'Test as Pupil</h2><p class="muted">After an update, open BULIG Quest as a hidden test pupil and answer a few activities.</p></div><a class="btn secondary" href="?page=studio_test">Open Test as Pupil</a></section>';
}
