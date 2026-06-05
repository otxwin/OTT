<?php
session_start();

// ============================================================
//  DATABASE LAYER (JSON file-based, no MySQL needed)
// ============================================================
define('DATA_DIR', __DIR__ . '/ot_data/');
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

function users_file()   { return DATA_DIR . 'users.json'; }
function ot_file($user) { return DATA_DIR . 'ot_' . preg_replace('/[^a-z0-9_]/','_',strtolower($user)) . '.json'; }

function load_users() {
    if (!file_exists(users_file())) {
        // seed default users
        $default = [
            'admin'   => ['password' => password_hash('admin123',   PASSWORD_DEFAULT), 'name' => 'ผู้ดูแลระบบ',   'role' => 'admin'],
            'somchai' => ['password' => password_hash('pass1234',   PASSWORD_DEFAULT), 'name' => 'สมชาย ใจดี',    'role' => 'user'],
            'malee'   => ['password' => password_hash('pass1234',   PASSWORD_DEFAULT), 'name' => 'มาลี สวยงาม',   'role' => 'user'],
        ];
        file_put_contents(users_file(), json_encode($default, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        return $default;
    }
    return json_decode(file_get_contents(users_file()), true);
}

function save_users($users) {
    file_put_contents(users_file(), json_encode($users, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

function load_ot($user) {
    if (!file_exists(ot_file($user))) return [];
    return json_decode(file_get_contents(ot_file($user)), true) ?: [];
}

function save_ot($user, $data) {
    file_put_contents(ot_file($user), json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

// ============================================================
//  PERIOD HELPER
// ============================================================
function get_period($date_str) {
    $d = (int)date('d', strtotime($date_str));
    return $d <= 15 ? '1' : '2';
}

function period_label($year, $month, $period) {
    $m = sprintf('%02d', $month);
    if ($period == '1') return "งวด 1 – 15 " . thai_month($month) . " $year";
    $last = date('t', mktime(0,0,0,$month,1,$year));
    return "งวด 16 – $last " . thai_month($month) . " $year";
}

function thai_month($m) {
    $months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
               'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    return $months[(int)$m];
}

// ============================================================
//  ACTION HANDLERS
// ============================================================
$error = ''; $success = '';

// LOGIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'login') {
        $users = load_users();
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        if (isset($users[$u]) && password_verify($p, $users[$u]['password'])) {
            $_SESSION['user']      = $u;
            $_SESSION['user_name'] = $users[$u]['name'];
            $_SESSION['role']      = $users[$u]['role'];
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        } else {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }

    if ($_POST['action'] === 'logout') {
        session_destroy(); header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    // ADD OT
    if ($_POST['action'] === 'add_ot' && isset($_SESSION['user'])) {
        $u    = $_SESSION['user'];
        $data = load_ot($u);
        $date = $_POST['ot_date'] ?? '';
        $hours= floatval($_POST['ot_hours'] ?? 0);
        $rate = floatval($_POST['ot_rate'] ?? 1.5);
        $note = htmlspecialchars(trim($_POST['ot_note'] ?? ''));
        if ($date && $hours > 0) {
            $data[] = [
                'id'    => uniqid(),
                'date'  => $date,
                'hours' => $hours,
                'rate'  => $rate,
                'note'  => $note,
                'period'=> get_period($date),
                'month' => date('n', strtotime($date)),
                'year'  => date('Y', strtotime($date)),
                'created'=> date('Y-m-d H:i:s'),
            ];
            usort($data, fn($a,$b) => strcmp($a['date'],$b['date']));
            save_ot($u, $data);
            $success = 'บันทึก OT เรียบร้อยแล้ว';
        } else {
            $error = 'กรุณากรอกวันที่และชั่วโมง OT ให้ถูกต้อง';
        }
    }

    // DELETE OT
    if ($_POST['action'] === 'delete_ot' && isset($_SESSION['user'])) {
        $u    = $_SESSION['user'];
        $id   = $_POST['ot_id'] ?? '';
        $data = load_ot($u);
        $data = array_values(array_filter($data, fn($r) => $r['id'] !== $id));
        save_ot($u, $data);
        $success = 'ลบรายการเรียบร้อยแล้ว';
    }

    // ADMIN: manage users
    if ($_POST['action'] === 'add_user' && ($_SESSION['role'] ?? '') === 'admin') {
        $users = load_users();
        $nu = trim($_POST['new_username'] ?? '');
        $np = $_POST['new_password'] ?? '';
        $nn = trim($_POST['new_name'] ?? '');
        $nr = $_POST['new_role'] ?? 'user';
        if ($nu && $np && $nn && !isset($users[$nu])) {
            $users[$nu] = ['password' => password_hash($np, PASSWORD_DEFAULT), 'name' => $nn, 'role' => $nr];
            save_users($users);
            $success = "เพิ่มผู้ใช้ '$nu' เรียบร้อย";
        } else {
            $error = 'ข้อมูลไม่ครบหรือชื่อผู้ใช้ซ้ำ';
        }
    }

    if ($_POST['action'] === 'delete_user' && ($_SESSION['role'] ?? '') === 'admin') {
        $users = load_users();
        $du = $_POST['del_username'] ?? '';
        if ($du && $du !== 'admin') { unset($users[$du]); save_users($users); $success = "ลบผู้ใช้แล้ว"; }
    }
}

// ============================================================
//  VIEW DATA
// ============================================================
$logged_in  = isset($_SESSION['user']);
$cur_user   = $_SESSION['user'] ?? '';
$cur_name   = $_SESSION['user_name'] ?? '';
$cur_role   = $_SESSION['role'] ?? '';

$ot_data    = $logged_in ? load_ot($cur_user) : [];

// filter by period
$sel_year   = intval($_GET['year']   ?? date('Y'));
$sel_month  = intval($_GET['month']  ?? date('n'));
$sel_period = $_GET['period'] ?? '';   // '1','2', or ''

$filtered = array_filter($ot_data, function($r) use($sel_year,$sel_month,$sel_period) {
    if ($r['year'] != $sel_year || $r['month'] != $sel_month) return false;
    if ($sel_period !== '' && $r['period'] !== $sel_period) return false;
    return true;
});

$total_hours   = array_sum(array_column($filtered,'hours'));
$wage_per_hour = floatval($_GET['wage'] ?? 62); // default hourly wage
$total_pay     = array_sum(array_map(fn($r) => $r['hours']*$r['rate']*$wage_per_hour, $filtered));

$last_day = date('t', mktime(0,0,0,$sel_month,1,$sel_year));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ระบบลง OT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
/* ========== RESET & ROOT ========== */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:        #0d1117;
  --surface:   #161b22;
  --surface2:  #21262d;
  --surface3:  #30363d;
  --border:    #30363d;
  --accent:    #f78166;
  --accent2:   #ffa657;
  --green:     #3fb950;
  --blue:      #58a6ff;
  --purple:    #bc8cff;
  --text:      #e6edf3;
  --muted:     #8b949e;
  --danger:    #f85149;
  --radius:    12px;
  --radius-sm: 6px;
}

html { scroll-behavior: smooth; }
body {
  font-family: 'Sarabun', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  font-size: 15px;
  line-height: 1.6;
}

/* ========== LOGIN PAGE ========== */
.login-wrap {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background:
    radial-gradient(ellipse 80% 60% at 50% -20%, rgba(247,129,102,0.18) 0%, transparent 65%),
    radial-gradient(ellipse 60% 50% at 80% 80%, rgba(88,166,255,0.12) 0%, transparent 60%),
    var(--bg);
}

.login-card {
  width: 380px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 44px 40px 40px;
  box-shadow: 0 24px 80px rgba(0,0,0,0.5);
  animation: fadeUp .5s ease both;
}

.login-logo {
  text-align: center;
  margin-bottom: 32px;
}
.login-logo .icon {
  width: 64px; height: 64px;
  background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
  border-radius: 18px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 28px; margin-bottom: 14px;
  box-shadow: 0 8px 24px rgba(247,129,102,0.35);
}
.login-logo h1 { font-size: 22px; font-weight: 700; color: var(--text); }
.login-logo p  { font-size: 13px; color: var(--muted); margin-top: 4px; }

.form-group { margin-bottom: 18px; }
.form-group label { display: block; font-size: 13px; font-weight: 600; color: var(--muted); margin-bottom: 7px; letter-spacing: .04em; text-transform: uppercase; }
.form-group input {
  width: 100%; padding: 11px 14px;
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: var(--radius-sm); color: var(--text);
  font-family: inherit; font-size: 15px;
  transition: border-color .2s, box-shadow .2s;
}
.form-group input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(247,129,102,.18); }

.btn-primary {
  width: 100%; padding: 13px;
  background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
  border: none; border-radius: var(--radius-sm);
  color: #fff; font-family: inherit; font-size: 16px; font-weight: 700;
  cursor: pointer; transition: opacity .2s, transform .1s;
  letter-spacing: .02em;
}
.btn-primary:hover { opacity: .9; transform: translateY(-1px); }
.btn-primary:active { transform: translateY(0); }

.alert { padding: 11px 14px; border-radius: var(--radius-sm); font-size: 14px; margin-bottom: 18px; }
.alert-error   { background: rgba(248,81,73,.15); border: 1px solid rgba(248,81,73,.3); color: #ff7b72; }
.alert-success { background: rgba(63,185,80,.15); border: 1px solid rgba(63,185,80,.3); color: #56d364; }

/* ========== MAIN LAYOUT ========== */
.topbar {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 0 24px;
  height: 58px;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 100;
  backdrop-filter: blur(8px);
}
.topbar-brand { display: flex; align-items: center; gap: 10px; }
.topbar-brand .dot {
  width: 34px; height: 34px;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
}
.topbar-brand span { font-size: 17px; font-weight: 700; letter-spacing: -.01em; }

.topbar-right { display: flex; align-items: center; gap: 12px; }
.user-badge {
  display: flex; align-items: center; gap: 8px;
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: 20px; padding: 5px 14px 5px 8px;
}
.user-avatar {
  width: 28px; height: 28px;
  background: linear-gradient(135deg, var(--blue), var(--purple));
  border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700; color: #fff;
}
.user-badge .name { font-size: 13px; font-weight: 600; }
.user-badge .role-tag {
  font-size: 10px; padding: 1px 6px;
  background: rgba(247,129,102,.2); color: var(--accent);
  border-radius: 20px; font-weight: 700;
}

.btn-logout {
  padding: 7px 16px;
  background: transparent; border: 1px solid var(--border);
  border-radius: var(--radius-sm); color: var(--muted);
  font-family: inherit; font-size: 13px; cursor: pointer;
  transition: all .2s;
}
.btn-logout:hover { border-color: var(--danger); color: var(--danger); }

/* ========== TABS ========== */
.tabs {
  display: flex; gap: 4px;
  border-bottom: 1px solid var(--border);
  padding: 0 24px;
  background: var(--surface);
}
.tab-btn {
  padding: 12px 18px; background: none; border: none;
  color: var(--muted); font-family: inherit; font-size: 14px; font-weight: 600;
  cursor: pointer; border-bottom: 2px solid transparent;
  margin-bottom: -1px; transition: all .2s;
}
.tab-btn:hover { color: var(--text); }
.tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }

/* ========== MAIN CONTENT ========== */
.main { max-width: 1100px; margin: 0 auto; padding: 28px 24px 60px; }

/* ========== FILTER BAR ========== */
.filter-bar {
  display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 18px 20px; margin-bottom: 24px;
}
.filter-bar label { font-size: 12px; font-weight: 600; color: var(--muted); display: block; margin-bottom: 5px; text-transform: uppercase; letter-spacing:.04em; }
.filter-bar select, .filter-bar input[type=number] {
  padding: 8px 12px;
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: var(--radius-sm); color: var(--text);
  font-family: inherit; font-size: 14px;
}
.filter-bar select:focus, .filter-bar input:focus { outline:none; border-color:var(--accent); }
.btn-filter {
  padding: 9px 20px;
  background: var(--surface3); border: 1px solid var(--border);
  border-radius: var(--radius-sm); color: var(--text);
  font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
  transition: all .2s; align-self: flex-end;
}
.btn-filter:hover { background: var(--border); }

/* ========== STATS CARDS ========== */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 14px; margin-bottom: 24px; }
.stat-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 18px 20px;
  position: relative; overflow: hidden;
}
.stat-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: var(--c, var(--accent));
}
.stat-card .label { font-size: 12px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }
.stat-card .value { font-size: 28px; font-weight: 700; margin-top: 6px; font-family: 'Space Mono', monospace; }
.stat-card .sub   { font-size: 12px; color: var(--muted); margin-top: 3px; }

/* ========== ADD OT FORM ========== */
.card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 24px; margin-bottom: 24px;
}
.card-title {
  font-size: 15px; font-weight: 700; margin-bottom: 18px;
  display: flex; align-items: center; gap: 8px;
}
.card-title .badge {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--accent); display: inline-block;
}

.add-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: 14px; }
.add-form .form-group { margin: 0; }
.add-form .form-group.full { grid-column: 1/-1; }

.period-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 12px; padding: 3px 10px; border-radius: 20px; font-weight: 600;
}
.period-1 { background: rgba(63,185,80,.15); color: var(--green); border: 1px solid rgba(63,185,80,.25); }
.period-2 { background: rgba(88,166,255,.15); color: var(--blue);  border: 1px solid rgba(88,166,255,.25); }

/* ========== TABLE ========== */
.table-wrap { overflow-x: auto; border-radius: var(--radius); }
table { width: 100%; border-collapse: collapse; }
thead th {
  background: var(--surface2); padding: 10px 14px;
  text-align: left; font-size: 12px; font-weight: 700;
  color: var(--muted); text-transform: uppercase; letter-spacing: .06em;
  border-bottom: 1px solid var(--border);
}
tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--surface2); }
tbody td { padding: 11px 14px; font-size: 14px; }
.num { font-family: 'Space Mono', monospace; font-size: 13px; }

.btn-del {
  padding: 4px 12px; background: transparent;
  border: 1px solid rgba(248,81,73,.3); border-radius: var(--radius-sm);
  color: var(--danger); font-size: 12px; cursor: pointer; font-family: inherit;
  transition: all .15s;
}
.btn-del:hover { background: rgba(248,81,73,.12); }

/* ========== PERIOD SUMMARY ========== */
.period-summary {
  display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 24px;
}
.period-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 20px;
}
.period-card h3 { font-size: 13px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing:.06em; margin-bottom: 14px; }
.period-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.period-row:last-child { border-bottom: none; }
.period-row .k { color: var(--muted); }
.period-row .v { font-family: 'Space Mono', monospace; font-weight: 700; }

/* ========== ADMIN SECTION ========== */
.user-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: 12px; margin-top: 16px; }
.user-card {
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: var(--radius-sm); padding: 14px 16px;
  display: flex; align-items: center; gap: 12px;
}
.user-card .av {
  width: 36px; height: 36px; border-radius: 50%;
  background: linear-gradient(135deg, var(--blue), var(--purple));
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 14px; flex-shrink: 0;
}
.user-card .info { flex: 1; min-width: 0; }
.user-card .info .n { font-size: 13px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-card .info .u { font-size: 11px; color: var(--muted); }
.btn-del-sm {
  background: none; border: none; color: var(--danger); cursor: pointer; font-size: 16px; padding: 2px;
}

/* ========== EMPTY STATE ========== */
.empty {
  text-align: center; padding: 48px 20px;
  color: var(--muted); font-size: 14px;
}
.empty .ico { font-size: 40px; margin-bottom: 12px; }

/* ========== ANIMATIONS ========== */
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}
.main { animation: fadeUp .4s ease both; }

/* ========== MOBILE ========== */
@media (max-width: 600px) {
  .period-summary { grid-template-columns: 1fr; }
  .topbar-brand span { display: none; }
  .main { padding: 16px 14px 50px; }
  .login-card { width: 90%; padding: 32px 24px; }
}
</style>
</head>
<body>

<?php if (!$logged_in): ?>
<!-- ==================== LOGIN ==================== -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">
      <div class="icon">⏱</div>
      <h1>ระบบลง OT</h1>
      <p>Overtime Management System</p>
    </div>
    <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="action" value="login">
      <div class="form-group">
        <label>ชื่อผู้ใช้</label>
        <input type="text" name="username" placeholder="username" autocomplete="username" required>
      </div>
      <div class="form-group">
        <label>รหัสผ่าน</label>
        <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
      </div>
      <button class="btn-primary" type="submit">เข้าสู่ระบบ</button>
    </form>
    <p style="text-align:center;margin-top:18px;font-size:12px;color:var(--muted)">
      Default: admin / admin123 &nbsp;|&nbsp; somchai / pass1234
    </p>
  </div>
</div>

<?php else: ?>
<!-- ==================== MAIN APP ==================== -->
<div class="topbar">
  <div class="topbar-brand">
    <div class="dot">⏱</div>
    <span>OT Manager</span>
  </div>
  <div class="topbar-right">
    <div class="user-badge">
      <div class="user-avatar"><?= mb_substr($cur_name, 0, 1) ?></div>
      <span class="name"><?= htmlspecialchars($cur_name) ?></span>
      <?php if ($cur_role === 'admin'): ?>
        <span class="role-tag">ADMIN</span>
      <?php endif; ?>
    </div>
    <form method="POST" style="margin:0">
      <input type="hidden" name="action" value="logout">
      <button class="btn-logout" type="submit">ออกจากระบบ</button>
    </form>
  </div>
</div>

<!-- TABS -->
<div class="tabs">
  <button class="tab-btn <?= (!isset($_GET['tab']) || $_GET['tab']==='ot') ? 'active' : '' ?>"
    onclick="switchTab('ot')">📋 บันทึก OT</button>
  <button class="tab-btn <?= isset($_GET['tab']) && $_GET['tab']==='summary' ? 'active' : '' ?>"
    onclick="switchTab('summary')">📊 สรุปงวด</button>
  <?php if ($cur_role === 'admin'): ?>
  <button class="tab-btn <?= isset($_GET['tab']) && $_GET['tab']==='users' ? 'active' : '' ?>"
    onclick="switchTab('users')">👥 จัดการผู้ใช้</button>
  <?php endif; ?>
</div>

<div class="main">

<?php if ($error):   ?><div class="alert alert-error"   style="margin-bottom:16px"><?= $error ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px"><?= $success ?></div><?php endif; ?>

<!-- ====== TAB: OT ENTRY ====== -->
<div id="tab-ot" class="tab-content">

  <!-- FILTER -->
  <form method="GET" action="">
    <input type="hidden" name="tab" value="ot">
    <div class="filter-bar">
      <div>
        <label>ปี</label>
        <select name="year">
          <?php for ($y = date('Y'); $y >= date('Y')-3; $y--): ?>
            <option value="<?=$y?>" <?=$y==$sel_year?'selected':''?>><?= $y+543 ?> (<?=$y?>)</option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label>เดือน</label>
        <select name="month">
          <?php for ($m=1; $m<=12; $m++): ?>
            <option value="<?=$m?>" <?=$m==$sel_month?'selected':''?>><?= thai_month($m) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label>งวด</label>
        <select name="period">
          <option value=""  <?=$sel_period===''  ?'selected':''?>>ทั้งหมด</option>
          <option value="1" <?=$sel_period==='1'  ?'selected':''?>>งวด 1 (1–15)</option>
          <option value="2" <?=$sel_period==='2'  ?'selected':''?>>งวด 2 (16–<?=$last_day?>)</option>
        </select>
      </div>
      <div>
        <label>ค่าแรง/ชม. (บาท)</label>
        <input type="number" name="wage" value="<?= $wage_per_hour ?>" style="width:90px">
      </div>
      <button class="btn-filter" type="submit">🔍 กรอง</button>
    </div>
  </form>

  <!-- STATS -->
  <div class="stats-grid">
    <div class="stat-card" style="--c:var(--accent)">
      <div class="label">รายการ OT</div>
      <div class="value"><?= count($filtered) ?></div>
      <div class="sub">รายการในงวดที่เลือก</div>
    </div>
    <div class="stat-card" style="--c:var(--accent2)">
      <div class="label">ชั่วโมง OT รวม</div>
      <div class="value"><?= number_format($total_hours,1) ?></div>
      <div class="sub">ชั่วโมง</div>
    </div>
    <div class="stat-card" style="--c:var(--green)">
      <div class="label">ค่า OT รวม</div>
      <div class="value"><?= number_format($total_pay,0) ?></div>
      <div class="sub">บาท (<?= $wage_per_hour ?> บาท/ชม.)</div>
    </div>
    <div class="stat-card" style="--c:var(--blue)">
      <div class="label">งวด</div>
      <div class="value" style="font-size:16px;margin-top:10px"><?= $sel_period ? period_label($sel_year,$sel_month,$sel_period) : 'ทุกงวด' ?></div>
      <div class="sub"><?= thai_month($sel_month) ?> <?= $sel_year ?></div>
    </div>
  </div>

  <!-- ADD OT FORM -->
  <div class="card">
    <div class="card-title"><span class="badge"></span> เพิ่มรายการ OT</div>
    <form method="POST" class="add-form">
      <input type="hidden" name="action" value="add_ot">
      <div class="form-group">
        <label>วันที่ทำ OT</label>
        <input type="date" name="ot_date" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group">
        <label>ชั่วโมง OT</label>
        <input type="number" name="ot_hours" step="0.5" min="0.5" max="24" placeholder="2.5" required>
      </div>
      <div class="form-group">
        <label>อัตราค่า OT</label>
        <select name="ot_rate">
          <option value="1.5">x1.5 (วันธรรมดา)</option>
          <option value="2">x2.0 (วันหยุด)</option>
          <option value="3">x3.0 (วันหยุดนักขัตฤกษ์)</option>
        </select>
      </div>
      <div class="form-group full">
        <label>หมายเหตุ</label>
        <input type="text" name="ot_note" placeholder="เช่น ทำงานวันเสาร์ โปรเจค ABC">
      </div>
      <div class="form-group">
        <button class="btn-primary" type="submit" style="width:auto;padding:10px 28px">➕ บันทึก OT</button>
      </div>
    </form>
  </div>

  <!-- TABLE -->
  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:20px 24px 16px;border-bottom:1px solid var(--border)">
      <div class="card-title" style="margin:0"><span class="badge"></span> รายการ OT</div>
    </div>
    <div class="table-wrap">
      <?php if (empty($filtered)): ?>
        <div class="empty"><div class="ico">📭</div>ไม่มีรายการ OT ในช่วงที่เลือก</div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>วันที่</th>
            <th>งวด</th>
            <th>ชั่วโมง</th>
            <th>อัตรา</th>
            <th>ค่า OT (บาท)</th>
            <th>หมายเหตุ</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1; foreach ($filtered as $r): ?>
          <tr>
            <td class="num" style="color:var(--muted)"><?= $i++ ?></td>
            <td><?= date('d/m/Y', strtotime($r['date'])) ?></td>
            <td>
              <span class="period-badge period-<?= $r['period'] ?>">
                <?= $r['period'] == '1' ? '1–15' : '16–'.$last_day ?>
              </span>
            </td>
            <td class="num"><?= $r['hours'] ?></td>
            <td class="num">x<?= $r['rate'] ?></td>
            <td class="num" style="color:var(--green)"><?= number_format($r['hours']*$r['rate']*$wage_per_hour, 2) ?></td>
            <td style="color:var(--muted);font-size:13px"><?= $r['note'] ?: '–' ?></td>
            <td>
              <form method="POST" onsubmit="return confirm('ลบรายการนี้?')">
                <input type="hidden" name="action" value="delete_ot">
                <input type="hidden" name="ot_id" value="<?= $r['id'] ?>">
                <input type="hidden" name="year"   value="<?= $sel_year ?>">
                <input type="hidden" name="month"  value="<?= $sel_month ?>">
                <input type="hidden" name="period" value="<?= $sel_period ?>">
                <input type="hidden" name="wage"   value="<?= $wage_per_hour ?>">
                <button class="btn-del" type="submit">ลบ</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:var(--surface2);border-top:2px solid var(--border)">
            <td colspan="3" style="padding:10px 14px;font-weight:700;color:var(--muted);font-size:12px">รวม</td>
            <td class="num" style="font-weight:700"><?= $total_hours ?></td>
            <td></td>
            <td class="num" style="font-weight:700;color:var(--green)"><?= number_format($total_pay, 2) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div><!-- /tab-ot -->

<!-- ====== TAB: SUMMARY ====== -->
<div id="tab-summary" class="tab-content" style="display:none">
  <?php
  // compute per-period summary for current month
  $all_month = array_filter($ot_data, fn($r) => $r['year']==$sel_year && $r['month']==$sel_month);
  $p1 = array_filter($all_month, fn($r) => $r['period']==='1');
  $p2 = array_filter($all_month, fn($r) => $r['period']==='2');
  $p1h = array_sum(array_column($p1,'hours'));
  $p2h = array_sum(array_column($p2,'hours'));
  $p1pay = array_sum(array_map(fn($r)=>$r['hours']*$r['rate']*$wage_per_hour,$p1));
  $p2pay = array_sum(array_map(fn($r)=>$r['hours']*$r['rate']*$wage_per_hour,$p2));
  ?>

  <div class="card">
    <div class="card-title"><span class="badge"></span> สรุปค่า OT รายงวด – <?= thai_month($sel_month) ?> <?= $sel_year ?> (<?=$sel_year+543?>)</div>
    <p style="color:var(--muted);font-size:13px;margin-bottom:18px">ใช้ค่าแรง <?= $wage_per_hour ?> บาท/ชม. — ปรับได้ที่แท็บบันทึก OT</p>
    <div class="period-summary">
      <div class="period-card" style="border-top:3px solid var(--green)">
        <h3 style="color:var(--green)">งวด 1 &nbsp;(วันที่ 1 – 15)</h3>
        <div class="period-row"><span class="k">จำนวนรายการ</span><span class="v"><?= count($p1) ?> รายการ</span></div>
        <div class="period-row"><span class="k">ชั่วโมง OT</span><span class="v"><?= $p1h ?> ชม.</span></div>
        <div class="period-row"><span class="k">ค่า OT</span><span class="v" style="color:var(--green)"><?= number_format($p1pay,2) ?> ฿</span></div>
      </div>
      <div class="period-card" style="border-top:3px solid var(--blue)">
        <h3 style="color:var(--blue)">งวด 2 &nbsp;(วันที่ 16 – <?= $last_day ?>)</h3>
        <div class="period-row"><span class="k">จำนวนรายการ</span><span class="v"><?= count($p2) ?> รายการ</span></div>
        <div class="period-row"><span class="k">ชั่วโมง OT</span><span class="v"><?= $p2h ?> ชม.</span></div>
        <div class="period-row"><span class="k">ค่า OT</span><span class="v" style="color:var(--blue)"><?= number_format($p2pay,2) ?> ฿</span></div>
      </div>
    </div>
    <div class="stat-card" style="--c:var(--accent2)">
      <div class="label">รวมทั้งเดือน</div>
      <div class="value" style="color:var(--accent2)"><?= number_format($p1pay+$p2pay,2) ?> ฿</div>
      <div class="sub"><?= $p1h+$p2h ?> ชั่วโมง OT รวม</div>
    </div>
  </div>

  <!-- all-time list per month -->
  <div class="card">
    <div class="card-title"><span class="badge"></span> ประวัติย้อนหลัง (ทุกเดือน)</div>
    <?php
    // group all OT by year-month
    $groups = [];
    foreach ($ot_data as $r) {
        $key = $r['year'].'-'.sprintf('%02d',$r['month']);
        $groups[$key][] = $r;
    }
    krsort($groups);
    if (empty($groups)):
    ?>
      <div class="empty"><div class="ico">📭</div>ยังไม่มีข้อมูล OT</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>เดือน-ปี</th>
              <th>งวด 1 (ชม.)</th>
              <th>งวด 2 (ชม.)</th>
              <th>รวมชม.</th>
              <th>ค่า OT รวม (฿)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($groups as $key => $rows):
            [$y,$m] = explode('-',$key);
            $g1 = array_filter($rows, fn($r)=>$r['period']==='1');
            $g2 = array_filter($rows, fn($r)=>$r['period']==='2');
            $gh1 = array_sum(array_column($g1,'hours'));
            $gh2 = array_sum(array_column($g2,'hours'));
            $gpay = array_sum(array_map(fn($r)=>$r['hours']*$r['rate']*$wage_per_hour,$rows));
          ?>
            <tr>
              <td><?= thai_month((int)$m) ?> <?= $y ?></td>
              <td class="num"><?= $gh1 ?></td>
              <td class="num"><?= $gh2 ?></td>
              <td class="num" style="font-weight:700"><?= $gh1+$gh2 ?></td>
              <td class="num" style="color:var(--green);font-weight:700"><?= number_format($gpay,2) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div><!-- /tab-summary -->

<?php if ($cur_role === 'admin'): ?>
<!-- ====== TAB: USERS (ADMIN) ====== -->
<div id="tab-users" class="tab-content" style="display:none">
  <div class="card">
    <div class="card-title"><span class="badge"></span> เพิ่มผู้ใช้ใหม่</div>
    <form method="POST" class="add-form">
      <input type="hidden" name="action" value="add_user">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="new_username" placeholder="ชื่อล็อกอิน" required>
      </div>
      <div class="form-group">
        <label>รหัสผ่าน</label>
        <input type="password" name="new_password" placeholder="••••••••" required>
      </div>
      <div class="form-group">
        <label>ชื่อ-สกุล</label>
        <input type="text" name="new_name" placeholder="ชื่อแสดงผล" required>
      </div>
      <div class="form-group">
        <label>สิทธิ์</label>
        <select name="new_role">
          <option value="user">พนักงาน</option>
          <option value="admin">ผู้ดูแล</option>
        </select>
      </div>
      <div class="form-group">
        <button class="btn-primary" type="submit" style="width:auto;padding:10px 24px">➕ เพิ่มผู้ใช้</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-title"><span class="badge"></span> ผู้ใช้ทั้งหมด</div>
    <div class="user-grid">
      <?php foreach (load_users() as $uname => $uinfo): ?>
      <div class="user-card">
        <div class="av"><?= mb_substr($uinfo['name'],0,1) ?></div>
        <div class="info">
          <div class="n"><?= htmlspecialchars($uinfo['name']) ?></div>
          <div class="u">@<?= htmlspecialchars($uname) ?> · <?= $uinfo['role'] === 'admin' ? 'ผู้ดูแล' : 'พนักงาน' ?></div>
        </div>
        <?php if ($uname !== 'admin'): ?>
        <form method="POST" onsubmit="return confirm('ลบผู้ใช้?')">
          <input type="hidden" name="action" value="delete_user">
          <input type="hidden" name="del_username" value="<?= $uname ?>">
          <button class="btn-del-sm" type="submit" title="ลบ">✕</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

</div><!-- /main -->

<script>
const activeTab = '<?= htmlspecialchars($_GET['tab'] ?? 'ot') ?>';
document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
const show = document.getElementById('tab-' + activeTab);
if (show) show.style.display = 'block';
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const id = btn.onclick.toString().match(/'(\w+)'/)[1]; // read from onclick attr already set
  });
});

function switchTab(name) {
  document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
  const el = document.getElementById('tab-' + name);
  if (el) { el.style.display = 'block'; el.style.animation = 'fadeUp .3s ease both'; }
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');
  // update URL without reload
  const url = new URL(location.href);
  url.searchParams.set('tab', name);
  history.replaceState(null,'',url);
}
</script>

<?php endif; ?>
</body>
</html>