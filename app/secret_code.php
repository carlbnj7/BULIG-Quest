<?php
/* v219 Secret Code: every account (pupil, teacher, every administrator) types a 4-number code after the password,
   the QR pass or a saved sign-in. It is a popup on the sign-in page, not a separate page.
   - The first time, the person makes the code (typed twice). Not 0000, not the same number 4 times, not 1234 (valid_pin()).
   - Pupils: 5 wrong tries, then a 5-minute rest. Teachers and administrators: 3 wrong tries, then 15 minutes.
   - Codes are stored scrambled (password_hash) in admin_security, the table the old admin PIN used. Nobody can read them.
   - Forgot it: a teacher clears a pupil's code (My Pupils › Secret Codes); a new password made by the person above
     (teacher, school admin, …) clears it too. Then the person makes a new one at the next sign-in. */

const SC_STEP_SECONDS=600;
function sc_tries(string $role):int{return $role==='pupil'?5:3;}
function sc_lock_minutes(string $role):int{return $role==='pupil'?5:15;}
function sc_word(string $role):string{return $role==='pupil'?'Secret Code':'PIN';}
function sc_row(int $uid):array{return admin_security($uid);}
function sc_has(int $uid):bool{return (bool)sc_row($uid)['pin_hash'];}
function sc_clear(int $uid):void{q('DELETE FROM admin_security WHERE user_id=?',[$uid]);}
function sc_unlock(int $uid):void{q('UPDATE admin_security SET failed_pins=0,locked_until=NULL WHERE user_id=?',[$uid]);}
function sc_locked(int $uid):?int{return admin_pin_locked($uid);}

/** Hold the person at the Secret Code step (not signed in yet). $flags are the session values set after the code. */
function sc_gate(array $u,array $flags,string $how,bool $json=false):never{
 session_regenerate_id(true);
 foreach(['uid','pupil_welcome','teacher_welcome','hello','offer_save'] as $k)unset($_SESSION[$k]);
 $_SESSION['pin_pending']=['uid'=>(int)$u['id'],'until'=>time()+SC_STEP_SECONDS,'flags'=>$flags,'how'=>$how];$_SESSION['csrf']=bin2hex(random_bytes(32));
 if($json)saved_login_json(['ok'=>true,'url'=>'?page=login&code=1']);
 go('?page=login&code=1');
}
function sc_pending_user():?array{
 $uid=admin_pin_pending();if(!$uid)return null;
 $u=one('SELECT * FROM users WHERE id=? AND active=1',[$uid]);if(!$u){unset($_SESSION['pin_pending']);return null;}return $u;
}
function sc_finish(array $u):never{
 $p=$_SESSION['pin_pending']??[];$flags=(array)($p['flags']??[]);
 unset($_SESSION['pin_pending']);session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];$_SESSION['csrf']=bin2hex(random_bytes(32));
 foreach($flags as $k=>$v)$_SESSION[$k]=$v;
 audit('login',$u['role'].(($p['how']??'')!==''?' · '.$p['how']:''));go('?page=dashboard');
}

function sc_actions(string $action):never{
 if($action==='sc_cancel'){unset($_SESSION['pin_pending']);go('?page=login');}
 if($action==='sc_clear'||$action==='sc_unlock'){
  $u=require_role('teacher');$pid=(int)($_POST['id']??0);own_pupil($pid);
  $name=(string)val('SELECT name FROM users WHERE id=?',[$pid]);
  if($action==='sc_clear'){sc_clear($pid);audit('sc_clear',(string)$pid);flash(name_case($name).' makes a new Secret Code at the next sign-in.');}
  else{sc_unlock($pid);audit('sc_unlock',(string)$pid);flash(name_case($name).' can try the Secret Code again.');}
  go('?page=manage#sc-card');}
 $u=sc_pending_user();if(!$u)go('?page=login');$uid=(int)$u['id'];$w=sc_word($u['role']);
 if($action==='sc_go'){if(empty($_SESSION['pin_pending']['made']))go('?page=login&code=1');sc_finish($u);}
 $pin=preg_replace('/\D/','',(string)($_POST['pin']??''));$s=sc_row($uid);
 if($action==='sc_create'){
  if($s['pin_hash'])go('?page=login&code=1');
  if(!valid_pin($pin)){$_SESSION['login_error']='Pick 4 numbers that are not 0000, not the same number 4 times, and not 1234.';go('?page=login&code=1');}
  $first=(string)($_SESSION['pin_pending']['first']??'');
  if($first===''){$_SESSION['pin_pending']['first']=password_hash($pin,PASSWORD_DEFAULT);go('?page=login&code=1');}
  if(!password_verify($pin,$first)){unset($_SESSION['pin_pending']['first']);$_SESSION['login_error']='The two '.($u['role']==='pupil'?'codes':'PINs').' were not the same. Let’s start again.';go('?page=login&code=1');}
  save_admin_pin($uid,$pin);unset($_SESSION['pin_pending']['first']);admin_pin_audit($uid,'sc_created',$u['role']);
  if($u['role']==='pupil'&&!val('SELECT 1 FROM settings WHERE setting_key=?',['sc_xp_'.$uid])){
   q('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)',['sc_xp_'.$uid,date('Y-m-d H:i:s')]);
   q('INSERT INTO pupil_xp(pupil_id,total) VALUES(?,20) ON DUPLICATE KEY UPDATE total=total+20',[$uid]);}
  $_SESSION['pin_pending']['made']=1;go('?page=login&code=1');
 }
 if($action==='sc_enter'){
  if(!$s['pin_hash'])go('?page=login&code=1');
  if($left=sc_locked($uid)){$m=(int)ceil($left/60);unset($_SESSION['pin_pending']);$_SESSION['login_error']='Too many wrong tries. Rest for '.$m.' minute'.($m===1?'':'s').', then sign in again'.($u['role']==='pupil'?', or ask your teacher.':'.');go('?page=login');}
  if(password_verify($pin,(string)$s['pin_hash'])){sc_unlock($uid);sc_finish($u);}
  $failed=(int)$s['failed_pins']+1;admin_pin_audit($uid,'sc_failed',(string)$failed);$max=sc_tries($u['role']);
  if($failed>=$max){q('UPDATE admin_security SET failed_pins=?,locked_until=DATE_ADD(NOW(),INTERVAL '.sc_lock_minutes($u['role']).' MINUTE) WHERE user_id=?',[$failed,$uid]);unset($_SESSION['pin_pending']);
   $_SESSION['login_error']='Too many wrong tries. Rest for '.sc_lock_minutes($u['role']).' minutes, then sign in again'.($u['role']==='pupil'?', or ask your teacher.':'.');go('?page=login');}
  q('UPDATE admin_security SET failed_pins=? WHERE user_id=?',[$failed,$uid]);$left=$max-$failed;
  $_SESSION['sc_wrong']=$left;go('?page=login&code=1');
 }
 fail('Unknown action.');
}

/* ---------- the popup on the sign-in page ---------- */
function sc_pad(string $label):string{
 $h='<div class="pin-field sc-field" data-pin-field><label class="pin-label sc-hidden-label">'.$label.'<input class="pin-input" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" autocomplete="off" required data-pin-input autofocus></label><div class="pin-boxes sc-dots" aria-hidden="true" data-pin-boxes><b></b><b></b><b></b><b></b></div></div><div class="pin-pad sc-pad" data-pin-pad aria-label="Number pad">';
 foreach(['1','2','3','4','5','6','7','8','9'] as $d)$h.='<button type="button" data-digit="'.$d.'">'.$d.'</button>';
 return $h.'<button type="button" class="sc-x" data-digit="back" aria-label="Delete">⌫</button><button type="button" data-digit="0">0</button><button type="submit" class="sc-ok" aria-label="Done">'.icon('check').'</button></div>';
}
function sc_popup(array $u):string{
 $uid=(int)$u['id'];$pupil=$u['role']==='pupil';$w=sc_word($u['role']);$first=name_case(explode(' ',trim((string)$u['name']))[0]);
 $p=$_SESSION['pin_pending']??[];$made=!empty($p['made']);$has=sc_has($uid);$again=!$has&&!empty($p['first']);
 $err=(string)($_SESSION['login_error']??'');unset($_SESSION['login_error']);$wrong=$_SESSION['sc_wrong']??null;unset($_SESSION['sc_wrong']);
 $img=fn($pose)=>'<img class="sc-ch" src="'.e(friend_img('claude',$pose)).'" alt="Claude the Protector" width="110" height="150">';
 $h='<div class="sc-shade" role="dialog" aria-modal="true" aria-labelledby="sc-title"><div class="sc-box">';
 if($made){
  $h.=$img('hello').'<span class="wn-badge">'.icon('shield').($pupil?'SECRET CODE READY':'PIN READY').'</span><h2 id="sc-title">'.($pupil?'Your Secret Code is ready!':'Your PIN is ready.').'</h2><p class="muted">'.($pupil?'You earned <b>+20 XP</b>. Every time you sign in, type your Secret Code after your password.':'Type it after your password every time you sign in.').'</p>'
   .'<form method="post">'.csrf_field().'<input type="hidden" name="action" value="sc_go"><button class="btn primary full" type="submit" autofocus>'.($pupil?'Let’s go!':'Continue').icon('arrow').'</button></form>';
 }else{
  $wrongNow=$wrong!==null;$title=$has?($wrongNow?($pupil?'Oops, not that one!':'Wrong PIN'):($pupil?'Hi, '.$first.'! Type your Secret Code':'Type your PIN, '.$first)):($again?'Type it one more time':($pupil?'Make your Secret Code, '.$first.'!':'Make your 4-digit PIN'));
  $h.=$img($wrongNow?'worried':($has?'shield':($again?'thumbs':'shield')));
  if(!$has&&!$again)$h.='<span class="wn-badge">'.icon('shield').'NEW · '.strtoupper($w).'</span>';
  $h.='<h2 id="sc-title">'.e($title).'</h2>';
  if($err!=='')$h.='<p class="sc-err" role="alert">'.icon('alert').e($err).'</p>';
  if($wrongNow)$h.='<p class="muted">Try again. You have <b>'.(int)$wrong.' more '.($wrong===1?'try':'tries').'</b>.'.($pupil?' After that, rest for 5 minutes or ask your teacher.':'').'</p>';
  elseif($has)$h.='';
  elseif($again)$h.='<p class="muted">To check you remember it.</p>';
  else $h.='<p class="muted">'.($pupil?'<b>Claude the Protector:</b> Pick 4 numbers only you know. Then no one else can open your stars and badges.':'<b>Claude the Protector:</b> BULIG Quest now asks every account for a PIN after the password. Pick 4 numbers only you know.').'</p>';
  $h.='<form method="post" class="pin-form sc-form" data-pin-form>'.csrf_field().'<input type="hidden" name="action" value="'.($has?'sc_enter':'sc_create').'">'.sc_pad($w).'</form>';
  if(!$has&&!$again)$h.='<ul class="sc-rules"><li>4 numbers</li><li>Not 0000, and not the same number 4 times</li><li>Not 1234</li></ul>';
  if($again)$h.='<p class="sc-tip">'.($pupil?'Tip: tell only your parent. Never tell a classmate.':'Keep it private. Do not write it on your ID card.').'</p>';
  if($has)$h.='<p class="sc-tip">'.($pupil?'Forgot it? Ask your teacher to clear your Secret Code.':'Forgot it? Ask the person who manages your account for a new password; it clears your PIN.').'</p>';
  $h.='<form method="post" class="sc-cancel">'.csrf_field().'<input type="hidden" name="action" value="sc_cancel"><button type="submit" class="link-btn">Not '.e($first).'? Go back</button></form>';
 }
 return $h.'</div></div>';
}

/* ---------- the teacher's card (My Pupils) ---------- */
function sc_teacher_card(array $u):string{
 $rows=rows("SELECT u.id,u.name,s.pin_hash,s.locked_until FROM teacher_pupils t JOIN users u ON u.id=t.pupil_id AND u.active=1 AND u.role='pupil' LEFT JOIN admin_security s ON s.user_id=u.id WHERE t.teacher_id=? ORDER BY u.name",[(int)$u['id']]);
 if(!$rows)return '';
 $made=0;$list='';
 foreach($rows as $r){$has=(bool)$r['pin_hash'];$lock=$r['locked_until']&&strtotime((string)$r['locked_until'])>time();if($has)$made++;
  $btn=fn($a,$t)=>'<form method="post">'.csrf_field().'<input type="hidden" name="action" value="'.$a.'"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn secondary small" type="submit"'.($a==='sc_clear'?' data-confirm="Clear this Secret Code? The pupil makes a new one at the next sign-in."':'').'>'.$t.'</button></form>';
  $list.='<li><span class="sc-n">'.e(name_case((string)$r['name'])).'</span><span class="sc-s '.($lock?'bad':($has?'ok':'mid')).'">'.($lock?'Locked':($has?'Made':'Not yet')).'</span><span class="sc-a">'.($lock?$btn('sc_unlock','Unlock'):'').($has?$btn('sc_clear','Clear code'):'').'</span></li>';}
 return '<section class="card sc-card" id="sc-card"><div class="ws-hd"><h2>'.icon('shield').'Secret Codes</h2><span class="sc-s '.($made===count($rows)?'ok':'mid').'">'.$made.' of '.count($rows).' made</span></div>'
  .'<p class="muted">Each pupil makes their own 4-number Secret Code at their next sign-in. You never see it. If a pupil forgets it, choose <b>Clear code</b>: they make a new one at the next sign-in.</p>'
  .'<details class="sc-more"'.(count($rows)<=6?' open':'').'><summary>Show every pupil</summary><ul class="sc-list">'.$list.'</ul></details></section>';
}
