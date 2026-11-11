<?php
declare(strict_types=1);
date_default_timezone_set('America/Santo_Domingo');

/* JSON API for Blood Control RD. Configure BCRD_DB_* environment variables. */
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? '1' : '0');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
set_exception_handler(static function (Throwable $e): void { error_log('Blood Control RD API: '.$e->getMessage()); reply(['ok'=>false,'error'=>'Error interno del servidor.'],500); });

function reply(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function input(): array {
    $raw = file_get_contents('php://input');
    $v = json_decode($raw ?: '{}', true);
    return is_array($v) ? $v : [];
}
function fail(string $message, int $status = 400): never { reply(['ok'=>false,'error'=>$message], $status); }
function pdo(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $host = getenv('BCRD_DB_HOST') ?: '127.0.0.1';
    $name = getenv('BCRD_DB_NAME') ?: 'blood_control_rd';
    $user = getenv('BCRD_DB_USER') ?: 'bcrd_app';
    $pass = getenv('BCRD_DB_PASSWORD');
    if ($pass === false || $pass === '') fail('El servidor no tiene configuradas las credenciales de la base de datos.', 503);
    try {
        $db = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    } catch (Throwable $e) { fail('No se pudo conectar con la base de datos.', 503); }
    return $db;
}
function bodyString(array $b, string $key, int $max = 255): string {
    $v = trim((string)($b[$key] ?? ''));
    if ($v === '' || mb_strlen($v) > $max) fail("El campo $key es obligatorio o excede su longitud.");
    return $v;
}
function owner(): int {
    if (empty($_SESSION['uid'])) fail('Inicia sesión para continuar.', 401);
    return (int)$_SESSION['uid'];
}
function method(string $m): void { if ($_SERVER['REQUEST_METHOD'] !== $m) fail('Método no permitido.', 405); }
function validCedula(string $doc): bool {
    if (!preg_match('/^\d{11}$/',$doc)) return false;
    $sum=0; for($i=0;$i<10;$i++){$v=(int)$doc[$i]*($i%2===0?1:2);if($v>9)$v-=9;$sum+=$v;}
    return ((10-($sum%10))%10)===(int)$doc[10];
}
function validDial(string $dial): bool { return in_array($dial, ['+1','+34','+52','+57'], true); }
function sameOrigin(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') return;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $expected = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        if (!hash_equals($expected, $origin)) fail('Origen no permitido.', 403);
    }
}

sameOrigin();
$db = pdo();
$action = (string)($_GET['action'] ?? '');
$b = input();
$types = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
$components = ['whole_blood'=>42,'red_cells'=>42,'platelets'=>5,'plasma'=>365];

if ($action === 'register') {
    method('POST');
    $first = bodyString($b,'first_name',80); $last = bodyString($b,'last_name',80);
    $username = strtolower(bodyString($b,'username',40));
    $email = strtolower(bodyString($b,'email',254)); $phone = bodyString($b,'phone',24);
    $dial = bodyString($b,'phone_country_code',8); $doc = preg_replace('/\D/','',(string)($b['identity_document'] ?? ''));
    $password = (string)($b['password'] ?? '');
    if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/',$email)) fail('Correo electrónico inválido.');
    if (!preg_match("/^[\\p{L}]+(?:[ '\\-][\\p{L}]+)*$/u",$first) || !preg_match("/^[\\p{L}]+(?:[ '\\-][\\p{L}]+)*$/u",$last)) fail('Escribe un nombre y apellido válidos.');
    if (!preg_match('/^[a-z0-9_]{3,40}$/',$username)) fail('El usuario debe tener entre 3 y 40 caracteres: letras, números o guion bajo.');
    if (!validCedula($doc)) fail('La cédula debe tener 11 dígitos y un dígito de verificación válido.');
    if (strlen(preg_replace('/\D/','',$phone))<7 || strlen(preg_replace('/\D/','',$phone))>15) fail('Teléfono inválido.');
    if (strlen($password)<8) fail('La contraseña debe tener al menos 8 caracteres.');
    try {
        $q=$db->prepare('INSERT INTO users(first_name,last_name,username,email,phone_country_code,phone,identity_document,password_hash) VALUES(?,?,?,?,?,?,?,?)');
        $q->execute([$first,$last,$username,$email,$dial,$phone,$doc,password_hash($password,PASSWORD_DEFAULT)]);
    } catch (PDOException $e) { if ($e->getCode()==='23000') fail('Ya existe una cuenta con ese correo, documento o nombre de usuario.',409); throw $e; }
    session_regenerate_id(true); $_SESSION['uid']=(int)$db->lastInsertId();
    reply(['ok'=>true,'user'=>['id'=>$_SESSION['uid'],'first_name'=>$first,'last_name'=>$last,'username'=>$username,'display_name_mode'=>'username','email'=>$email,'phone'=>$dial.' '.$phone,'theme'=>'light','compact_mode'=>false,'profile_photo'=>null]]);
}
if ($action === 'login') {
    method('POST'); $email=strtolower(bodyString($b,'email',254)); $password=(string)($b['password']??'');
    $q=$db->prepare('SELECT id,first_name,last_name,username,display_name_mode,email,phone_country_code,phone,theme,compact_mode,profile_photo,profile_photo_mime,password_hash FROM users WHERE email=?'); $q->execute([$email]); $u=$q->fetch();
    if (!$u || !password_verify($password,$u['password_hash'])) fail('Contraseña o usuario incorrecto',401);
    session_regenerate_id(true); $_SESSION['uid']=(int)$u['id'];
    $avatar=$u['profile_photo'] ? 'data:'.$u['profile_photo_mime'].';base64,'.base64_encode($u['profile_photo']) : null;
    reply(['ok'=>true,'user'=>['id'=>(int)$u['id'],'first_name'=>$u['first_name'],'last_name'=>$u['last_name'],'username'=>$u['username'],'display_name_mode'=>$u['display_name_mode'],'email'=>$u['email'],'phone'=>$u['phone_country_code'].' '.$u['phone'],'theme'=>$u['theme'],'compact_mode'=>(bool)$u['compact_mode'],'profile_photo'=>$avatar]]);
}
if ($action === 'logout') { method('POST'); $_SESSION=[]; session_destroy(); reply(['ok'=>true]); }
if ($action === 'me') {
    if (empty($_SESSION['uid'])) reply(['user'=>null]);
    $q=$db->prepare('SELECT id,first_name,last_name,username,display_name_mode,email,phone_country_code,phone,theme,compact_mode,profile_photo,profile_photo_mime FROM users WHERE id=?');$q->execute([(int)$_SESSION['uid']]);$u=$q->fetch();if($u){$u['profile_photo']=$u['profile_photo']?'data:'.$u['profile_photo_mime'].';base64,'.base64_encode($u['profile_photo']):null;unset($u['profile_photo_mime']);$u['compact_mode']=(bool)$u['compact_mode'];$u['phone']=$u['phone_country_code'].' '.$u['phone'];unset($u['phone_country_code']);}reply(['user'=>$u ?: null]);
}

$uid=owner();
if ($action === 'profile') {
    method('POST');$username=strtolower(bodyString($b,'username',40));if(!preg_match('/^[a-z0-9_]{3,40}$/',$username))fail('El usuario debe tener entre 3 y 40 caracteres: letras, números o guion bajo.');
    $mode=(string)($b['display_name_mode']??'username');$theme=(string)($b['theme']??'light');$compact=!empty($b['compact_mode'])?1:0;
    if(!in_array($mode,['username','real'],true)||!in_array($theme,['light','dark'],true))fail('Preferencia inválida.');
    $photo=null;$mime=null;$raw=(string)($b['profile_photo']??'');if($raw!==''){
        if(!preg_match('#^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=]+)$#',$raw,$m))fail('La foto debe ser PNG, JPG o WebP.');
        $photo=base64_decode($m[2],true);$mime=$m[1];if($photo===false||strlen($photo)>1200000)fail('La foto no puede superar 1.2 MB.');
    }
    try{$sql='UPDATE users SET username=?,display_name_mode=?,theme=?,compact_mode=?';$args=[$username,$mode,$theme,$compact];if($photo!==null){$sql.=',profile_photo=?,profile_photo_mime=?';$args[]=$photo;$args[]=$mime;}elseif(!empty($b['remove_photo'])){$sql.=',profile_photo=NULL,profile_photo_mime=NULL';}$sql.=' WHERE id=?';$args[]=$uid;$q=$db->prepare($sql);$q->execute($args);}catch(PDOException $e){if($e->getCode()==='23000')fail('Ese nombre de usuario ya está ocupado.',409);throw $e;}
    reply(['ok'=>true]);
}
if ($action === 'banks') { $province=trim((string)($_GET['province']??''));if($province===''){$q=$db->query('SELECT id,name,province,unit_price FROM blood_banks ORDER BY province,name');reply(['banks'=>$q->fetchAll()]);}$q=$db->prepare('SELECT id,name,province,unit_price FROM blood_banks WHERE province=? ORDER BY name');$q->execute([$province]);reply(['banks'=>$q->fetchAll()]); }
if ($action === 'donors') {
    if ($_SERVER['REQUEST_METHOD']==='GET') { $q=$db->prepare("SELECT d.id,d.full_name AS name,d.identity_document AS document,d.phone_country_code AS dial,d.phone,d.blood_type AS type,d.province,b.name AS bank,d.created_at FROM donors d LEFT JOIN blood_banks b ON b.id=d.bank_id WHERE d.owner_id=? ORDER BY d.created_at DESC");$q->execute([$uid]);reply(['donors'=>$q->fetchAll()]); }
    method('POST');$name=bodyString($b,'name',160);$doc=preg_replace('/\D/','',(string)($b['document']??''));$type=(string)($b['type']??'');
    if(!validCedula($doc))fail('La cédula debe tener 11 dígitos y un dígito de verificación válido.');if(!in_array($type,$types,true))fail('Tipo de sangre inválido.');
    if (!preg_match("/^[\\p{L}]+(?:[ '\\-][\\p{L}]+)*$/u",$name)) fail('Escribe un nombre válido, sin números.');
    if(strlen(preg_replace('/\D/','',(string)($b['phone']??'')))<7||strlen(preg_replace('/\D/','',(string)($b['phone']??'')))>15)fail('Teléfono inválido.');
    $q=$db->prepare('SELECT id FROM blood_banks WHERE name=? AND province=? LIMIT 1');$q->execute([bodyString($b,'bank',160),bodyString($b,'province',80)]);$bank=$q->fetchColumn() ?: null;if(!$bank)fail('Selecciona un banco válido para esa provincia.');
    $dial=(string)($b['dial']??'+1');if(!validDial($dial))fail('Prefijo telefónico inválido.');
    $q=$db->prepare('INSERT INTO donors(owner_id,full_name,identity_document,phone_country_code,phone,blood_type,province,bank_id) VALUES(?,?,?,?,?,?,?,?)');
    $q->execute([$uid,$name,$doc,$dial,bodyString($b,'phone',24),$type,bodyString($b,'province',80),$bank]);reply(['ok'=>true,'id'=>(int)$db->lastInsertId()],201);
}
if ($action === 'deposits') {
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $q=$db->prepare('SELECT d.id,d.donor_name AS donor,d.donor_document AS document,d.blood_type AS type,d.component,d.quantity,d.available,d.collected_at AS collected,d.expires_at AS expiry,b.name AS bank,b.province,b.unit_price FROM blood_deposits d LEFT JOIN blood_banks b ON b.id=d.bank_id WHERE d.owner_id=? AND d.deleted_at IS NULL ORDER BY d.collected_at DESC');$q->execute([$uid]);$rows=$q->fetchAll();
        $stats=$db->prepare("SELECT COALESCE(SUM(CASE WHEN expires_at>NOW() THEN available ELSE 0 END),0) total,COALESCE(SUM(CASE WHEN expires_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 7 DAY) THEN available ELSE 0 END),0) soon FROM blood_deposits WHERE owner_id=? AND deleted_at IS NULL");$stats->execute([$uid]);reply(['deposits'=>$rows,'stats'=>$stats->fetch()]);
    }
    method('POST');$type=(string)($b['type']??'');if(!in_array($type,$types,true))fail('Tipo de sangre inválido.');$qty=filter_var($b['quantity']??null,FILTER_VALIDATE_INT);if(!$qty||$qty<1||$qty>500)fail('Cantidad inválida.');
    if(($b['operation']??'')==='delete'){$q=$db->prepare('UPDATE blood_deposits SET deleted_at=NOW() WHERE id=? AND owner_id=? AND deleted_at IS NULL');$q->execute([(int)($b['id']??0),$uid]);reply(['ok'=>true]);}
    if(($b['operation']??'')==='update'){
        $id=(int)($b['id']??0);$date=(string)($b['collected']??'');$time=strtotime($date);if(!$time||$time>time())fail('Fecha u hora no válida.');
        $q=$db->prepare('SELECT component FROM blood_deposits WHERE id=? AND owner_id=? AND deleted_at IS NULL');$q->execute([$id,$uid]);$comp=$q->fetchColumn();if(!$comp)fail('Registro no encontrado.',404);
        $expire=date('Y-m-d H:i:s',$time+$components[$comp]*86400);$q=$db->prepare('UPDATE blood_deposits SET collected_at=?,expires_at=? WHERE id=? AND owner_id=?');$q->execute([date('Y-m-d H:i:s',$time),$expire,$id,$uid]);reply(['ok'=>true]);
    }
    $donorId=filter_var($b['donor_id']??null,FILTER_VALIDATE_INT);if(!$donorId||$donorId<1)fail('Selecciona un donante registrado.');
    $q=$db->prepare('SELECT id,full_name,identity_document,blood_type FROM donors WHERE id=? AND owner_id=?');$q->execute([$donorId,$uid]);$registeredDonor=$q->fetch();if(!$registeredDonor)fail('El donante no existe en esta cuenta.',404);
    if($registeredDonor['blood_type']!==$type)fail('El tipo de sangre debe coincidir con el registro del donante.');
    $donor=$registeredDonor['full_name'];$doc=$registeredDonor['identity_document'];
    if(!validCedula($doc))fail('La cédula registrada del donante no es válida.');
    $component=(string)($b['component']??'whole_blood');if(!isset($components[$component]))fail('Componente inválido.');$collected=strtotime((string)($b['collected']??'now'));if(!$collected||$collected>time())fail('Fecha de extracción inválida.');
    $bankName=bodyString($b,'bank',160);$province=bodyString($b,'province',80);$bank=$db->prepare('SELECT id FROM blood_banks WHERE name=? AND province=? LIMIT 1');$bank->execute([$bankName,$province]);$bankId=$bank->fetchColumn() ?: null;if(!$bankId)fail('Selecciona un banco válido para esa provincia.');
    $expiry=date('Y-m-d H:i:s',$collected+$components[$component]*86400);$db->beginTransaction();
    try{$q=$db->prepare('INSERT INTO blood_deposits(owner_id,donor_id,donor_name,donor_document,blood_type,component,quantity,available,bank_id,collected_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$uid,$donorId,$donor,$doc,$type,$component,$qty,$qty,$bankId,date('Y-m-d H:i:s',$collected),$expiry]);$dep=(int)$db->lastInsertId();$q=$db->prepare("INSERT INTO inventory_movements(owner_id,deposit_id,movement,blood_type,quantity,reason) VALUES(?,?,'entry',?,?,?)");$q->execute([$uid,$dep,$type,$qty,'Donación']);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}reply(['ok'=>true,'id'=>$dep],201);
}
if ($action === 'exit') {
    method('POST');$type=(string)($b['type']??'');if(!in_array($type,$types,true))fail('Tipo de sangre inválido.');$qty=filter_var($b['quantity']??null,FILTER_VALIDATE_INT);if(!$qty||$qty<1||$qty>65535)fail('Cantidad inválida.');
    $bank=$db->prepare('SELECT id FROM blood_banks WHERE name=? AND province=? LIMIT 1');$bank->execute([bodyString($b,'bank',160),bodyString($b,'province',80)]);$bankId=$bank->fetchColumn();if(!$bankId)fail('Selecciona un banco válido para esa provincia.');
    $patientName=bodyString($b,'patient_name',160);if(!preg_match("/^[\\p{L}]+(?:[ '\\-][\\p{L}]+)*$/u",$patientName))fail('Escribe un nombre válido para la persona que recibirá la sangre.');
    $patientDocument=preg_replace('/\\D/','',(string)($b['patient_document']??''));if(!validCedula($patientDocument))fail('La cédula debe tener 11 dígitos y un dígito de verificación válido.');
    $patientDial=(string)($b['patient_dial']??'+1');if(!validDial($patientDial))fail('Prefijo telefónico inválido.');
    $patientPhone=bodyString($b,'patient_phone',24);$phoneDigits=preg_replace('/\\D/','',$patientPhone);if(strlen($phoneDigits)<7||strlen($phoneDigits)>15)fail('Teléfono de contacto inválido.');
    if(empty($b['patient_authorized']))fail('Confirma que tienes autorización para registrar los datos personales.');
    $reason=bodyString($b,'reason',255);
    $db->beginTransaction();try{
        $q=$db->prepare('INSERT INTO patients(owner_id,full_name,identity_document,phone_country_code,phone) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),full_name=VALUES(full_name),phone_country_code=VALUES(phone_country_code),phone=VALUES(phone)');
        $q->execute([$uid,$patientName,$patientDocument,$patientDial,$patientPhone]);$patientId=(int)$db->lastInsertId();
        if(!$patientId){$q=$db->prepare('SELECT id FROM patients WHERE owner_id=? AND identity_document=?');$q->execute([$uid,$patientDocument]);$patientId=(int)$q->fetchColumn();}
        $q=$db->prepare('SELECT id,available FROM blood_deposits WHERE owner_id=? AND bank_id=? AND blood_type=? AND available>0 AND expires_at>NOW() AND deleted_at IS NULL ORDER BY expires_at FOR UPDATE');$q->execute([$uid,$bankId,$type]);$remaining=$qty;$lots=$q->fetchAll();
        foreach($lots as $lot){$take=min($remaining,(int)$lot['available']);$u=$db->prepare('UPDATE blood_deposits SET available=available-? WHERE id=? AND owner_id=?');$u->execute([$take,$lot['id'],$uid]);$m=$db->prepare("INSERT INTO inventory_movements(owner_id,deposit_id,patient_id,movement,blood_type,quantity,reason,patient_authorized_at) VALUES(?,?,?,'exit',?,?,?,NOW())");$m->execute([$uid,$lot['id'],$patientId,$type,$take,$reason]);$remaining-=$take;if($remaining===0)break;}
        if($remaining>0){$db->rollBack();fail('Ese banco no tiene unidades suficientes para esta salida.',409);}
        $db->commit();reply(['ok'=>true,'patient_id'=>$patientId]);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
if ($action === 'requests') {
    if ($_SERVER['REQUEST_METHOD']==='GET'){$q=$db->prepare('SELECT r.id,r.patient_id,r.hospital,r.province,r.blood_type AS type,r.quantity,r.priority,r.status,r.created_at AS date,r.patient_authorized_at,p.full_name AS patient_name,p.identity_document AS patient_document,p.phone_country_code AS patient_dial,p.phone AS patient_phone FROM blood_requests r LEFT JOIN patients p ON p.id=r.patient_id AND p.owner_id=r.owner_id WHERE r.owner_id=? ORDER BY r.created_at DESC');$q->execute([$uid]);reply(['requests'=>$q->fetchAll()]);}
    method('POST');if(($b['operation']??'')==='cancel'){$q=$db->prepare("UPDATE blood_requests SET status='cancelled' WHERE id=? AND owner_id=? AND status='pending'");$q->execute([(int)($b['id']??0),$uid]);reply(['ok'=>true]);}
    $type=(string)($b['type']??'');if(!in_array($type,$types,true))fail('Tipo de sangre inválido.');$qty=filter_var($b['quantity']??null,FILTER_VALIDATE_INT);if(!$qty||$qty<1||$qty>65535)fail('La cantidad debe estar entre 1 y 65,535 unidades.');$priority=(string)($b['priority']??'medium');if(!in_array($priority,['low','medium','high','critical'],true))$priority='medium';
    $patientName=bodyString($b,'patient_name',160);if(!preg_match("/^[\\p{L}]+(?:[ '\\-][\\p{L}]+)*$/u",$patientName))fail('Escribe un nombre válido para la persona.');
    $patientDocument=preg_replace('/\\D/','',(string)($b['patient_document']??''));if(!validCedula($patientDocument))fail('La cédula debe tener 11 dígitos y un dígito de verificación válido.');
    $patientDial=(string)($b['patient_dial']??'+1');if(!validDial($patientDial))fail('Prefijo telefónico inválido.');
    $patientPhone=bodyString($b,'patient_phone',24);$phoneDigits=preg_replace('/\\D/','',$patientPhone);if(strlen($phoneDigits)<7||strlen($phoneDigits)>15)fail('Teléfono de contacto inválido.');
    if(empty($b['patient_authorized']))fail('Confirma que tienes autorización para registrar los datos personales.');
    $hospital=bodyString($b,'hospital',160);$province=bodyString($b,'province',80);
    $db->beginTransaction();try{
        $q=$db->prepare('INSERT INTO patients(owner_id,full_name,identity_document,phone_country_code,phone) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),full_name=VALUES(full_name),phone_country_code=VALUES(phone_country_code),phone=VALUES(phone)');
        $q->execute([$uid,$patientName,$patientDocument,$patientDial,$patientPhone]);$patientId=(int)$db->lastInsertId();
        if(!$patientId){$q=$db->prepare('SELECT id FROM patients WHERE owner_id=? AND identity_document=?');$q->execute([$uid,$patientDocument]);$patientId=(int)$q->fetchColumn();}
        $q=$db->prepare('INSERT INTO blood_requests(owner_id,patient_id,hospital,province,blood_type,quantity,priority,reason,patient_authorized_at) VALUES(?,?,?,?,?,?,?,?,NOW())');
        $q->execute([$uid,$patientId,$hospital,$province,$type,$qty,$priority,substr(trim((string)($b['reason']??'')),0,500)]);$requestId=(int)$db->lastInsertId();$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    reply(['ok'=>true,'id'=>$requestId,'patient_id'=>$patientId],201);
}
if ($action === 'movements') { $q=$db->prepare("SELECT m.movement AS kind,m.blood_type AS type,m.quantity,m.reason AS detail,m.created_at AS date,b.name AS bank,m.patient_id,m.patient_authorized_at,p.full_name AS patient_name,p.identity_document AS patient_document,p.phone_country_code AS patient_dial,p.phone AS patient_phone FROM inventory_movements m LEFT JOIN blood_deposits d ON d.id=m.deposit_id LEFT JOIN blood_banks b ON b.id=d.bank_id LEFT JOIN patients p ON p.id=m.patient_id AND p.owner_id=m.owner_id WHERE m.owner_id=? ORDER BY m.created_at DESC LIMIT 500");$q->execute([$uid]);reply(['movements'=>$q->fetchAll()]); }
if ($action === 'sales') {
    if ($_SERVER['REQUEST_METHOD']==='GET'){$q=$db->prepare('SELECT id,invoice_number,currency,subtotal,status,payment_method,created_at FROM sales WHERE owner_id=? ORDER BY created_at DESC');$q->execute([$uid]);reply(['sales'=>$q->fetchAll()]);}
    method('POST');$method=(string)($b['payment_method']??'');if(!in_array($method,['cashapp','paypal','debit','credit'],true))fail('Método de pago inválido.');$items=$b['items']??[];if(!is_array($items)||!count($items))fail('El carrito está vacío.');
    $buyer=bodyString($b,'buyer_name',160);$buyerDocument=preg_replace('/\\D/','',(string)($b['buyer_document']??''));if(!validCedula($buyerDocument))fail('La cédula del comprador debe tener 11 dígitos y un dígito de verificación válido.');$email=strtolower(bodyString($b,'buyer_email',254));$phone=bodyString($b,'buyer_phone',32);$province=bodyString($b,'province',80);$bankName=bodyString($b,'bank',160);
    if(!preg_match("/^[\\p{L}]+(?:[ '\\-][\\p{L}]+)*$/u",$buyer))fail('Escribe un nombre de comprador válido.');if(!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/',$email))fail('Correo de comprador inválido.');if(strlen(preg_replace('/\D/','',$phone))<7||strlen(preg_replace('/\D/','',$phone))>15)fail('Teléfono de comprador inválido.');
    $bank=$db->prepare('SELECT id,unit_price FROM blood_banks WHERE name=? AND province=? LIMIT 1');$bank->execute([$bankName,$province]);$bankInfo=$bank->fetch();if(!$bankInfo)fail('Selecciona un banco válido para esa provincia.');$bankId=(int)$bankInfo['id'];
    $db->beginTransaction();try{$subtotal=0;$normalized=[];foreach($items as $it){$type=(string)($it['type']??'');$units=filter_var($it['units']??null,FILTER_VALIDATE_INT);if(!in_array($type,$types,true)||!$units||$units<1||$units>500)fail('Artículo inválido.');$unit=(float)$bankInfo['unit_price']*(str_contains($type,'-')?1.06:1);$line=round($units*$unit,2);$subtotal+=$line;$normalized[]=[$type,$units,$unit,$line];}
        $invoice='BCRD-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));$q=$db->prepare('INSERT INTO sales(owner_id,invoice_number,subtotal,status,payment_method,buyer_name,buyer_document,buyer_email,buyer_phone,province,bank_id) VALUES(?,?,?,\'pending\',?,?,?,?,?,?,?)');$q->execute([$uid,$invoice,$subtotal,$method,$buyer,$buyerDocument,$email,$phone,$province,$bankId]);$sale=(int)$db->lastInsertId();$q=$db->prepare('INSERT INTO sale_items(sale_id,blood_type,units,unit_price,line_total) VALUES(?,?,?,?,?)');foreach($normalized as $it)$q->execute([$sale,...$it]);$db->commit();reply(['ok'=>true,'id'=>$sale,'invoice_number'=>$invoice,'total'=>$subtotal,'status'=>'pending']);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
fail('Acción desconocida.',404);
