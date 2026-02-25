<?php
session_start();

// ==========================================
// KONFIGURASI AUTENTIKASI ADMIN
// ==========================================
$admin_password = "P0ng3lc4h";

// Proses Login / Logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    if ($_POST['password'] === $admin_password) {
        $_SESSION['is_admin'] = true;
    } else {
        $_SESSION['login_error'] = "Password salah!";
    }
    header("Location: ?tab=" . urlencode($_POST['tab'] ?? 'klasemen'));
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: ?tab=" . urlencode($_GET['tab'] ?? 'klasemen'));
    exit;
}

$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
$login_error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']); // Hapus pesan error setelah ditampilkan

// ==========================================
// KONFIGURASI DATABASE (Menggunakan SQLite)
// ==========================================
$db_file = __DIR__ . '/efootball.sqlite';
$pdo = new PDO("sqlite:" . $db_file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Buat tabel jika belum ada
$pdo->exec("CREATE TABLE IF NOT EXISTS teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS matches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team1_id INTEGER,
    team2_id INTEGER,
    score1 INTEGER NULL,
    score2 INTEGER NULL,
    order_index INTEGER DEFAULT 0,
    FOREIGN KEY(team1_id) REFERENCES teams(id),
    FOREIGN KEY(team2_id) REFERENCES teams(id)
)");

// Cek dan tambahkan kolom order_index jika database versi lama belum memilikinya
try {
    $pdo->query("SELECT order_index FROM matches LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE matches ADD COLUMN order_index INTEGER DEFAULT 0");
}

// ==========================================
// PROSES FORM & AJAX (HANYA UNTUK ADMIN)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    $action = $_POST['action'] ?? '';
    $tab = $_POST['tab'] ?? 'klasemen';

    // AJAX: Update Urutan Pertandingan (Drag & Drop)
    if ($action === 'update_order') {
        $orderData = json_decode($_POST['order_data'], true);
        if (is_array($orderData)) {
            $stmt = $pdo->prepare("UPDATE matches SET order_index = :order_index WHERE id = :id");
            $pdo->beginTransaction();
            foreach ($orderData as $item) {
                $stmt->execute(['order_index' => $item['order_index'], 'id' => $item['id']]);
            }
            $pdo->commit();
            echo "OK";
        }
        exit;
    }

    // Manajemen Tim
    if ($action === 'add_team') {
        $name = trim($_POST['name'] ?? '');
        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO teams (name) VALUES (:name)");
            $stmt->execute(['name' => $name]);
        }
    } 
    elseif ($action === 'delete_team') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM matches WHERE team1_id = :id OR team2_id = :id");
        $stmt->execute(['id' => $id]);
        $stmt = $pdo->prepare("DELETE FROM teams WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    // Manajemen Pertandingan
    elseif ($action === 'add_match') {
        $team1_id = $_POST['team1_id'] ?? '';
        $team2_id = $_POST['team2_id'] ?? '';
        $score1 = $_POST['score1'] === '' ? null : $_POST['score1'];
        $score2 = $_POST['score2'] === '' ? null : $_POST['score2'];

        if ($team1_id && $team2_id && $team1_id !== $team2_id) {
            $maxOrder = $pdo->query("SELECT MAX(order_index) FROM matches")->fetchColumn();
            $newOrder = $maxOrder ? $maxOrder + 1 : 1;
            $stmt = $pdo->prepare("INSERT INTO matches (team1_id, team2_id, score1, score2, order_index) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$team1_id, $team2_id, $score1, $score2, $newOrder]);
        }
    }
    elseif ($action === 'update_score') { 
        $id = $_POST['id'] ?? 0;
        $score1 = $_POST['score1'] === '' ? null : $_POST['score1'];
        $score2 = $_POST['score2'] === '' ? null : $_POST['score2'];
        $stmt = $pdo->prepare("UPDATE matches SET score1 = ?, score2 = ? WHERE id = ?");
        $stmt->execute([$score1, $score2, $id]);
    }
    elseif ($action === 'edit_match') { 
        $id = $_POST['id'] ?? 0;
        $team1_id = $_POST['team1_id'] ?? '';
        $team2_id = $_POST['team2_id'] ?? '';
        $score1 = $_POST['score1'] === '' ? null : $_POST['score1'];
        $score2 = $_POST['score2'] === '' ? null : $_POST['score2'];

        if ($team1_id && $team2_id && $team1_id !== $team2_id) {
            $stmt = $pdo->prepare("UPDATE matches SET team1_id = ?, team2_id = ?, score1 = ?, score2 = ? WHERE id = ?");
            $stmt->execute([$team1_id, $team2_id, $score1, $score2, $id]);
        }
    }
    elseif ($action === 'delete_match') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM matches WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }
    elseif ($action === 'delete_all_matches') {
        $pdo->exec("DELETE FROM matches");
    }

    // Drawing Jadwal
    elseif ($action === 'generate_schedule') {
        $format = $_POST['format'] ?? 1;
        $teams_list = $pdo->query("SELECT id FROM teams")->fetchAll(PDO::FETCH_COLUMN);
        shuffle($teams_list);

        if (count($teams_list) % 2 != 0) {
            array_push($teams_list, null); 
        }

        $numTeams = count($teams_list);
        $totalRounds = $numTeams - 1;
        $matchesPerRound = $numTeams / 2;
        $schedule = [];

        for ($round = 0; $round < $totalRounds; $round++) {
            for ($match = 0; $match < $matchesPerRound; $match++) {
                $home = $teams_list[$match];
                $away = $teams_list[$numTeams - 1 - $match];

                if ($match === 0 && $round % 2 === 1) {
                    $temp = $home;
                    $home = $away;
                    $away = $temp;
                }

                if ($home !== null && $away !== null) {
                    $schedule[] = ['home' => $home, 'away' => $away];
                }
            }
            $last = array_pop($teams_list);
            array_splice($teams_list, 1, 0, [$last]);
        }

        $maxOrder = $pdo->query("SELECT MAX(order_index) FROM matches")->fetchColumn();
        $orderIndex = $maxOrder ? $maxOrder + 1 : 1;

        foreach ($schedule as $match) {
            $stmt = $pdo->prepare("INSERT INTO matches (team1_id, team2_id, score1, score2, order_index) VALUES (?, ?, NULL, NULL, ?)");
            $stmt->execute([$match['home'], $match['away'], $orderIndex++]);
        }

        if ($format == 2) {
            foreach ($schedule as $match) {
                $stmt = $pdo->prepare("INSERT INTO matches (team1_id, team2_id, score1, score2, order_index) VALUES (?, ?, NULL, NULL, ?)");
                $stmt->execute([$match['away'], $match['home'], $orderIndex++]);
            }
        }
    }

    header("Location: ?tab=" . urlencode($tab));
    exit;
}

// ==========================================
// AMBIL DATA DARI DATABASE
// ==========================================
$teams = $pdo->query("SELECT * FROM teams")->fetchAll(PDO::FETCH_ASSOC);

$matches = $pdo->query("
    SELECT m.*, t1.name as team1_name, t2.name as team2_name 
    FROM matches m 
    LEFT JOIN teams t1 ON m.team1_id = t1.id 
    LEFT JOIN teams t2 ON m.team2_id = t2.id 
    ORDER BY m.order_index ASC, m.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// KALKULASI KLASEMEN
// ==========================================
$standings = [];
foreach ($teams as $t) {
    $standings[$t['id']] = [
        'id' => $t['id'], 'name' => $t['name'], 
        'played' => 0, 'win' => 0, 'draw' => 0, 'lose' => 0, 
        'gf' => 0, 'ga' => 0, 'gd' => 0, 'points' => 0
    ];
}

foreach ($matches as $m) {
    $t1 = $m['team1_id'];
    $t2 = $m['team2_id'];

    if (isset($standings[$t1]) && isset($standings[$t2]) && is_numeric($m['score1']) && is_numeric($m['score2'])) {
        $standings[$t1]['played']++;
        $standings[$t2]['played']++;
        
        $standings[$t1]['gf'] += $m['score1'];
        $standings[$t1]['ga'] += $m['score2'];
        $standings[$t2]['gf'] += $m['score2'];
        $standings[$t2]['ga'] += $m['score1'];

        if ($m['score1'] > $m['score2']) {
            $standings[$t1]['win']++;
            $standings[$t1]['points'] += 3;
            $standings[$t2]['lose']++;
        } elseif ($m['score1'] < $m['score2']) {
            $standings[$t2]['win']++;
            $standings[$t2]['points'] += 3;
            $standings[$t1]['lose']++;
        } else {
            $standings[$t1]['draw']++;
            $standings[$t2]['draw']++;
            $standings[$t1]['points'] += 1;
            $standings[$t2]['points'] += 1;
        }

        $standings[$t1]['gd'] = $standings[$t1]['gf'] - $standings[$t1]['ga'];
        $standings[$t2]['gd'] = $standings[$t2]['gf'] - $standings[$t2]['ga'];
    }
}

usort($standings, function($a, $b) {
    if ($b['points'] !== $a['points']) return $b['points'] - $a['points'];
    if ($b['gd'] !== $a['gd']) return $b['gd'] - $a['gd'];
    if ($b['gf'] !== $a['gf']) return $b['gf'] - $a['gf'];
    return strcmp($a['name'], $b['name']);
});

$activeTab = $_GET['tab'] ?? 'klasemen';
// Jika viewer mencoba akses tab drawing, kembalikan ke klasemen
if (!$is_admin && $activeTab === 'drawing') {
    $activeTab = 'klasemen';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liga eFootball Manager</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- html2canvas -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <!-- SortableJS untuk Drag & Drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }
        
        .sortable-ghost { opacity: 0.4; background-color: #374151; }
        .sortable-drag { cursor: grabbing !important; }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 font-sans selection:bg-blue-500 selection:text-white pb-10 min-h-screen">

    <!-- Header -->
    <header class="bg-gray-800 border-b border-gray-700 p-6 shadow-lg">
        <div class="max-w-5xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="p-3 bg-blue-600 rounded-lg shadow-blue-500/20 shadow-lg">
                    <i class="fas fa-gamepad text-white text-2xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-white tracking-wide">Liga eFootball Manager</h1>
                    <p class="text-gray-400 text-sm">Mode: <strong class="<?= $is_admin ? 'text-emerald-400' : 'text-blue-400' ?>"><?= $is_admin ? 'Administrator' : 'Penonton' ?></strong></p>
                </div>
            </div>
            
            <!-- Auth Buttons -->
            <div>
                <?php if ($is_admin): ?>
                    <a href="?action=logout&tab=<?= $activeTab ?>" class="bg-rose-600 hover:bg-rose-700 text-white px-5 py-2.5 rounded-lg text-sm font-bold shadow-lg transition-colors flex items-center gap-2 border border-rose-500">
                        <i class="fas fa-sign-out-alt"></i> Logout Admin
                    </a>
                <?php else: ?>
                    <button onclick="document.getElementById('loginModal').classList.remove('hidden')" class="bg-gray-700 hover:bg-gray-600 border border-gray-600 text-white px-5 py-2.5 rounded-lg text-sm font-bold shadow-lg transition-colors flex items-center gap-2">
                        <i class="fas fa-lock text-gray-400"></i> Login Admin
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Modal Login -->
    <div id="loginModal" class="<?= $login_error ? '' : 'hidden' ?> fixed inset-0 bg-black/80 flex items-center justify-center z-50 px-4 backdrop-blur-sm transition-opacity">
        <div class="bg-gray-800 border border-gray-700 p-6 rounded-xl shadow-2xl w-full max-w-sm relative transform transition-all">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-white flex items-center gap-2">
                    <i class="fas fa-user-shield text-blue-500"></i> Masuk sebagai Admin
                </h3>
                <button type="button" onclick="document.getElementById('loginModal').classList.add('hidden')" class="text-gray-400 hover:text-white bg-gray-700 hover:bg-gray-600 rounded-lg w-8 h-8 flex items-center justify-center transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <?php if ($login_error): ?>
                <div class="bg-rose-500/10 text-rose-400 border border-rose-500/50 p-3 rounded-lg mb-4 text-sm font-medium flex items-center gap-2">
                    <i class="fas fa-exclamation-circle"></i> <?= $login_error ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="tab" value="<?= $activeTab ?>">
                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-400 mb-2">Password Admin</label>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full bg-gray-900 border border-gray-600 rounded-lg p-3 text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 rounded-lg transition-colors shadow-lg flex justify-center items-center gap-2">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>
        </div>
    </div>

    <main class="max-w-5xl mx-auto mt-8 px-4">
        <!-- Navigation Tabs -->
        <div class="flex flex-wrap sm:flex-nowrap gap-2 bg-gray-800 p-2 rounded-xl mb-6 shadow-lg border border-gray-700">
            <a href="?tab=klasemen" class="flex-1 flex items-center justify-center gap-2 py-3 px-2 rounded-lg font-medium transition-all duration-200 text-sm sm:text-base <?= $activeTab === 'klasemen' ? 'bg-blue-600 text-white shadow-md transform scale-[1.02]' : 'text-gray-400 hover:bg-gray-700 hover:text-white' ?>">
                <i class="fas fa-list-ol"></i> <span class="hidden sm:inline">Klasemen</span>
            </a>
            <a href="?tab=pertandingan" class="flex-1 flex items-center justify-center gap-2 py-3 px-2 rounded-lg font-medium transition-all duration-200 text-sm sm:text-base <?= $activeTab === 'pertandingan' ? 'bg-blue-600 text-white shadow-md transform scale-[1.02]' : 'text-gray-400 hover:bg-gray-700 hover:text-white' ?>">
                <i class="fas fa-futbol"></i> <span class="hidden sm:inline">Pertandingan</span>
            </a>
            <?php if ($is_admin): ?>
            <a href="?tab=drawing" class="flex-1 flex items-center justify-center gap-2 py-3 px-2 rounded-lg font-medium transition-all duration-200 text-sm sm:text-base <?= $activeTab === 'drawing' ? 'bg-indigo-600 text-white shadow-md transform scale-[1.02]' : 'text-gray-400 hover:bg-gray-700 hover:text-white' ?>">
                <i class="fas fa-random"></i> <span class="hidden sm:inline">Drawing Jadwal</span>
            </a>
            <?php endif; ?>
            <a href="?tab=tim" class="flex-1 flex items-center justify-center gap-2 py-3 px-2 rounded-lg font-medium transition-all duration-200 text-sm sm:text-base <?= $activeTab === 'tim' ? 'bg-blue-600 text-white shadow-md transform scale-[1.02]' : 'text-gray-400 hover:bg-gray-700 hover:text-white' ?>">
                <i class="fas fa-users"></i> <span class="hidden sm:inline">Tim</span>
            </a>
        </div>

        <!-- TAB: KLASEMEN -->
        <div id="klasemen" class="tab-content <?= $activeTab === 'klasemen' ? 'active' : '' ?>">
            <div class="space-y-4">
                <div class="flex justify-end">
                    <button onclick="downloadAsImage('klasemen-area', 'Klasemen-eFootball')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg font-medium transition-colors flex items-center gap-2 shadow-lg">
                        <i class="fas fa-download"></i> Unduh PNG
                    </button>
                </div>
                <div id="klasemen-area" class="bg-gray-800 rounded-xl shadow-2xl overflow-hidden border border-gray-700">
                    <div class="p-6 border-b border-gray-700 bg-gradient-to-r from-gray-800 to-gray-900 flex justify-between items-center">
                        <div>
                            <h2 class="text-xl font-bold text-white flex items-center gap-2">
                                <i class="fas fa-trophy text-yellow-500"></i> Papan Klasemen
                            </h2>
                            <p class="text-gray-400 text-sm mt-1">Update: <?= date('d F Y') ?></p>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-900/80 text-gray-400 text-sm uppercase tracking-wider border-b border-gray-700">
                                    <th class="p-4 text-center w-16 font-semibold">Pos</th>
                                    <th class="p-4 font-semibold">Klub / Tim</th>
                                    <th class="p-4 text-center font-semibold" title="Main">P</th>
                                    <th class="p-4 text-center font-semibold text-emerald-400" title="Menang">W</th>
                                    <th class="p-4 text-center font-semibold text-yellow-400" title="Seri">D</th>
                                    <th class="p-4 text-center font-semibold text-rose-400" title="Kalah">L</th>
                                    <th class="p-4 text-center font-semibold text-gray-300" title="Gol Memasukkan">GF</th>
                                    <th class="p-4 text-center font-semibold text-gray-300" title="Gol Kemasukan">GA</th>
                                    <th class="p-4 text-center font-semibold text-gray-300" title="Selisih Gol">GD</th>
                                    <th class="p-4 text-center font-bold text-blue-400 text-base" title="Poin">Pts</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-700/50">
                                <?php if (empty($standings)): ?>
                                    <tr><td colspan="10" class="p-10 text-center text-gray-500">Belum ada data tim.</td></tr>
                                <?php else: ?>
                                    <?php foreach (array_values($standings) as $index => $team): ?>
                                    <tr class="hover:bg-gray-700/40 transition-colors">
                                        <td class="p-4 text-center font-medium">
                                            <?php
                                            $badgeClass = 'text-gray-400';
                                            if ($index === 0) $badgeClass = 'bg-yellow-500/20 text-yellow-500 border border-yellow-500/30';
                                            elseif ($index === 1) $badgeClass = 'bg-gray-400/20 text-gray-300 border border-gray-400/30';
                                            elseif ($index === 2) $badgeClass = 'bg-orange-600/20 text-orange-400 border border-orange-600/30';
                                            ?>
                                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full font-bold shadow-sm <?= $badgeClass ?>">
                                                <?= $index + 1 ?>
                                            </span>
                                        </td>
                                        <td class="p-4 font-bold text-white text-base"><?= htmlspecialchars($team['name']) ?></td>
                                        <td class="p-4 text-center text-gray-300 font-medium"><?= $team['played'] ?></td>
                                        <td class="p-4 text-center text-emerald-400 font-medium"><?= $team['win'] ?></td>
                                        <td class="p-4 text-center text-yellow-400 font-medium"><?= $team['draw'] ?></td>
                                        <td class="p-4 text-center text-rose-400 font-medium"><?= $team['lose'] ?></td>
                                        <td class="p-4 text-center text-gray-400"><?= $team['gf'] ?></td>
                                        <td class="p-4 text-center text-gray-400"><?= $team['ga'] ?></td>
                                        <td class="p-4 text-center font-medium text-gray-300"><?= $team['gd'] > 0 ? '+'.$team['gd'] : $team['gd'] ?></td>
                                        <td class="p-4 text-center font-black text-blue-400 text-xl bg-blue-500/5"><?= $team['points'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($is_admin): ?>
        <!-- TAB: DRAWING (Hanya Admin) -->
        <div id="drawing" class="tab-content <?= $activeTab === 'drawing' ? 'active' : '' ?>">
            <div class="max-w-3xl mx-auto space-y-6">
                <div class="bg-indigo-900/30 border border-indigo-500/30 rounded-xl p-6 shadow-xl">
                    <h2 class="text-xl font-bold text-white mb-3 flex items-center gap-2">
                        <i class="fas fa-random text-indigo-400"></i> Undian Jadwal Liga
                    </h2>
                    <p class="text-gray-300 mb-6 text-sm leading-relaxed">
                        Pilih format pertandingan. Jadwal yang diacak otomatis akan ditambahkan ke antrean pertandingan Anda.
                    </p>

                    <div class="flex flex-col gap-4">
                        <form method="POST" action="" class="bg-gray-800 p-5 rounded-lg border border-gray-700">
                            <input type="hidden" name="action" value="generate_schedule">
                            <input type="hidden" name="tab" value="pertandingan">
                            
                            <label class="block text-sm font-medium text-gray-400 mb-2">Sistem Pertandingan:</label>
                            <select name="format" class="w-full bg-gray-900 border border-gray-600 rounded-lg p-3 text-white focus:ring-2 focus:ring-indigo-500 outline-none mb-4">
                                <option value="1">1 Putaran (Bertemu 1 Kali)</option>
                                <option value="2">2 Putaran (Home & Away - Bertemu 2 Kali)</option>
                            </select>

                            <button type="submit" onclick="return confirm('Buat jadwal otomatis sekarang?')" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white px-6 py-3.5 rounded-lg font-bold transition-all shadow-lg flex items-center justify-center gap-2 disabled:opacity-50" <?= count($teams) < 2 ? 'disabled' : '' ?>>
                                <i class="fas fa-magic"></i> Acak & Buat Jadwal
                            </button>
                        </form>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="delete_all_matches">
                            <input type="hidden" name="tab" value="drawing">
                            <button type="submit" onclick="return confirm('PERINGATAN Keras!\nIni akan menghapus SEMUA jadwal dan skor pertandingan yang ada. Anda yakin?')" class="w-full bg-gray-800 border border-rose-500/50 hover:bg-rose-900/30 text-rose-400 px-6 py-3.5 rounded-lg font-bold transition-all shadow-lg flex items-center justify-center gap-2">
                                <i class="fas fa-trash-alt"></i> Hapus Semua Data Jadwal
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB: PERTANDINGAN -->
        <div id="pertandingan" class="tab-content <?= $activeTab === 'pertandingan' ? 'active' : '' ?>">
            <div class="<?= $is_admin ? 'grid lg:grid-cols-5 gap-6' : 'max-w-4xl mx-auto' ?>">
                
                <?php if ($is_admin): ?>
                <!-- Form Input Manual (Hanya Admin) -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-gray-800 rounded-xl shadow-xl p-6 border border-gray-700 sticky top-6">
                        <h2 class="text-lg font-semibold text-white mb-5 flex items-center gap-2">
                            <i class="fas fa-plus-circle text-blue-500"></i> Tambah Laga Manual
                        </h2>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_match">
                            <input type="hidden" name="tab" value="pertandingan">
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-400 mb-1.5">Tuan Rumah (Home)</label>
                                <select name="team1_id" required class="w-full bg-gray-900 border border-gray-600 rounded-lg p-3 text-white focus:ring-2 focus:ring-blue-500 outline-none">
                                    <option value="">-- Pilih Tim Home --</option>
                                    <?php foreach($teams as $t): ?>
                                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="flex items-center gap-4 bg-gray-900/50 p-4 rounded-xl border border-gray-700 mb-4">
                                <div class="flex-1">
                                    <input type="number" name="score1" min="0" placeholder="Skor (Ops.)" class="w-full bg-gray-800 border border-gray-600 rounded-lg p-3 text-center text-xl font-bold text-white focus:ring-2 focus:ring-blue-500 outline-none"/>
                                </div>
                                <span class="text-gray-500 font-bold bg-gray-800 px-3 py-1 rounded text-sm">VS</span>
                                <div class="flex-1">
                                    <input type="number" name="score2" min="0" placeholder="Skor (Ops.)" class="w-full bg-gray-800 border border-gray-600 rounded-lg p-3 text-center text-xl font-bold text-white focus:ring-2 focus:ring-blue-500 outline-none"/>
                                </div>
                            </div>

                            <div class="mb-5">
                                <label class="block text-sm font-medium text-gray-400 mb-1.5">Tamu (Away)</label>
                                <select name="team2_id" required class="w-full bg-gray-900 border border-gray-600 rounded-lg p-3 text-white focus:ring-2 focus:ring-blue-500 outline-none">
                                    <option value="">-- Pilih Tim Away --</option>
                                    <?php foreach($teams as $t): ?>
                                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 rounded-lg transition-all shadow-lg flex items-center justify-center gap-2">
                                <i class="fas fa-save"></i> Simpan Pertandingan
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Riwayat & Jadwal -->
                <div class="<?= $is_admin ? 'lg:col-span-3' : '' ?> space-y-4">
                    <div class="flex justify-end">
                        <button onclick="downloadAsImage('pertandingan-area', 'Jadwal-Hasil-Laga')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg font-medium transition-colors flex items-center gap-2 shadow-lg">
                            <i class="fas fa-download"></i> Unduh Jadwal/Hasil PNG
                        </button>
                    </div>

                    <div id="pertandingan-area" class="bg-gray-800 rounded-xl shadow-xl overflow-hidden border border-gray-700 h-full flex flex-col">
                        <div class="p-6 border-b border-gray-700 bg-gradient-to-r from-gray-800 to-gray-900 flex justify-between items-center">
                            <div>
                                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                                    <i class="fas fa-calendar-alt text-blue-400"></i> Jadwal & Hasil
                                </h2>
                                <?php if ($is_admin): ?>
                                    <p class="text-gray-400 text-sm mt-1">Seret baris untuk ubah urutan. Arahkan kursor untuk hapus/edit.</p>
                                <?php else: ?>
                                    <p class="text-gray-400 text-sm mt-1">Daftar pertandingan yang telah dijadwalkan.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div id="match-list" class="p-5 flex-1 overflow-y-auto max-h-[600px] space-y-3 bg-gray-800/50">
                            <?php if (empty($matches)): ?>
                                <div class="text-center text-gray-500 py-16">
                                    <i class="fas fa-calendar-times text-6xl mb-4 opacity-20"></i>
                                    <p class="text-lg">Belum ada pertandingan.</p>
                                </div>
                            <?php else: ?>
                                <?php 
                                foreach ($matches as $idx => $match): 
                                    $hasPlayed = is_numeric($match['score1']) && is_numeric($match['score2']);
                                    $t1Won = $hasPlayed && $match['score1'] > $match['score2'];
                                    $t2Won = $hasPlayed && $match['score2'] > $match['score1'];
                                    $isDraw = $hasPlayed && $match['score1'] === $match['score2'];
                                ?>
                                <div data-id="<?= $match['id'] ?>" class="bg-gray-900 border border-gray-700 rounded-xl py-4 pr-4 pl-10 sm:pl-12 flex flex-col sm:flex-row items-center justify-between group relative shadow-sm <?= $is_admin ? 'hover:border-gray-500' : '' ?> transition-all gap-4 sm:gap-0">
                                    
                                    <?php if ($is_admin): ?>
                                    <!-- Drag Handle (Admin Only) -->
                                    <div class="absolute left-2 sm:left-3 top-1/2 -translate-y-1/2 cursor-grab text-gray-500 hover:text-blue-400 p-2 match-handle hide-on-export text-lg sm:text-xl transition-colors active:cursor-grabbing" title="Geser urutan">
                                        <i class="fas fa-grip-vertical"></i>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Nomor Urut Laga -->
                                    <div class="absolute -top-2.5 -left-2.5 bg-gray-700 text-gray-300 text-[10px] font-bold px-2 py-0.5 rounded-full border border-gray-600 hide-on-export match-number z-10 <?= !$is_admin ? 'ml-6 sm:ml-8 mt-6' : '' ?>">
                                        #<?= $idx + 1 ?>
                                    </div>

                                    <!-- TAMPILAN NORMAL -->
                                    <div id="display-<?= $match['id'] ?>" class="w-full flex flex-col sm:flex-row items-center justify-between gap-4 sm:gap-0 transition-all">
                                        <!-- Tim 1 (HOME) -->
                                        <div class="flex-1 flex items-center justify-center sm:justify-end text-center sm:text-right px-2 w-full">
                                            <span class="text-xs text-gray-500 mr-2 font-bold bg-gray-800 px-1 rounded hide-on-export">H</span>
                                            <span class="font-bold sm:text-base text-sm <?= $t1Won ? 'text-white' : ($isDraw ? 'text-gray-300' : 'text-gray-400') ?>">
                                                <?= htmlspecialchars($match['team1_name'] ?? 'Tim Terhapus') ?>
                                            </span>
                                        </div>
                                        
                                        <!-- Skor / Input -->
                                        <div class="shrink-0 flex items-center justify-center z-10 relative">
                                            <?php if ($hasPlayed): ?>
                                                <div class="px-4 py-2 rounded-lg text-xl sm:text-2xl font-black tracking-[0.2em] border shadow-inner flex items-center justify-center min-w-[100px] <?= $isDraw ? 'bg-gray-800 border-gray-600 text-yellow-400' : 'bg-gray-900 border-blue-900/50 text-white' ?>">
                                                    <?= $match['score1'] ?> <span class="text-gray-600 mx-1 text-lg">-</span> <?= $match['score2'] ?>
                                                </div>
                                            <?php else: ?>
                                                <?php if ($is_admin): ?>
                                                    <!-- Form Skor Cepat Admin -->
                                                    <form method="POST" action="" class="flex items-center gap-2 bg-gray-800 p-2 rounded-lg border border-dashed border-gray-600">
                                                        <input type="hidden" name="action" value="update_score">
                                                        <input type="hidden" name="tab" value="pertandingan">
                                                        <input type="hidden" name="id" value="<?= $match['id'] ?>">
                                                        <input type="number" name="score1" min="0" required class="w-10 h-10 sm:w-12 text-center font-bold bg-gray-900 text-white border border-gray-700 rounded focus:border-blue-500 outline-none" placeholder="-">
                                                        <span class="text-gray-500 text-xs font-bold">VS</span>
                                                        <input type="number" name="score2" min="0" required class="w-10 h-10 sm:w-12 text-center font-bold bg-gray-900 text-white border border-gray-700 rounded focus:border-blue-500 outline-none" placeholder="-">
                                                        <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white w-10 h-10 rounded shadow flex items-center justify-center hide-on-export" title="Simpan Skor">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <!-- Tampilan Penonton: Belum Dimainkan -->
                                                    <div class="px-4 py-2 bg-gray-800/50 rounded-lg text-lg sm:text-xl font-black border border-gray-700/50 flex items-center justify-center min-w-[100px] text-gray-500">
                                                        - <span class="text-gray-600 mx-2 text-sm font-bold">VS</span> -
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Tim 2 (AWAY) -->
                                        <div class="flex-1 flex items-center justify-center sm:justify-start text-center sm:text-left px-2 w-full">
                                            <span class="font-bold sm:text-base text-sm <?= $t2Won ? 'text-white' : ($isDraw ? 'text-gray-300' : 'text-gray-400') ?>">
                                                <?= htmlspecialchars($match['team2_name'] ?? 'Tim Terhapus') ?>
                                            </span>
                                            <span class="text-xs text-gray-500 ml-2 font-bold bg-gray-800 px-1 rounded hide-on-export">A</span>
                                        </div>

                                        <?php if ($is_admin): ?>
                                        <!-- Tombol Aksi Admin (Edit & Hapus) -->
                                        <div class="absolute right-2 top-2 sm:top-auto hide-on-export opacity-0 group-hover:opacity-100 transition-opacity flex flex-col sm:flex-row gap-1">
                                            <button type="button" onclick="toggleEdit(<?= $match['id'] ?>)" class="p-2 text-blue-400 hover:bg-blue-500/10 rounded-lg bg-gray-900 sm:bg-transparent shadow sm:shadow-none border border-gray-700 sm:border-none" title="Edit Laga">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" action="">
                                                <input type="hidden" name="action" value="delete_match">
                                                <input type="hidden" name="tab" value="pertandingan">
                                                <input type="hidden" name="id" value="<?= $match['id'] ?>">
                                                <button type="submit" onclick="return confirm('Hapus pertandingan ini?')" class="p-2 text-rose-500 hover:bg-rose-500/10 rounded-lg bg-gray-900 sm:bg-transparent shadow sm:shadow-none border border-gray-700 sm:border-none" title="Hapus Laga">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($is_admin): ?>
                                    <!-- TAMPILAN EDIT (Admin Only) -->
                                    <div id="edit-<?= $match['id'] ?>" class="hidden w-full flex-col sm:flex-row items-center justify-between bg-gray-800 p-3 rounded-lg border border-blue-500/30">
                                        <form method="POST" action="" class="w-full flex flex-col sm:flex-row items-center gap-3">
                                            <input type="hidden" name="action" value="edit_match">
                                            <input type="hidden" name="tab" value="pertandingan">
                                            <input type="hidden" name="id" value="<?= $match['id'] ?>">

                                            <select name="team1_id" class="flex-1 w-full sm:w-auto bg-gray-900 border border-gray-600 rounded p-2 text-white text-sm focus:border-blue-500 outline-none">
                                                <?php foreach($teams as $t): ?>
                                                    <option value="<?= $t['id'] ?>" <?= $t['id'] == $match['team1_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>

                                            <div class="flex items-center gap-2 shrink-0">
                                                <input type="number" name="score1" value="<?= $match['score1'] ?>" placeholder="-" class="w-14 p-2 text-center font-bold bg-gray-900 text-white border border-gray-600 rounded focus:border-blue-500 outline-none">
                                                <span class="text-gray-500 font-bold">VS</span>
                                                <input type="number" name="score2" value="<?= $match['score2'] ?>" placeholder="-" class="w-14 p-2 text-center font-bold bg-gray-900 text-white border border-gray-600 rounded focus:border-blue-500 outline-none">
                                            </div>

                                            <select name="team2_id" class="flex-1 w-full sm:w-auto bg-gray-900 border border-gray-600 rounded p-2 text-white text-sm focus:border-blue-500 outline-none">
                                                <?php foreach($teams as $t): ?>
                                                    <option value="<?= $t['id'] ?>" <?= $t['id'] == $match['team2_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>

                                            <div class="flex items-center gap-2 shrink-0 hide-on-export">
                                                <button type="submit" class="p-2.5 bg-emerald-600 text-white rounded hover:bg-emerald-500" title="Simpan Perubahan">
                                                    <i class="fas fa-save"></i>
                                                </button>
                                                <button type="button" onclick="toggleEdit(<?= $match['id'] ?>)" class="p-2.5 bg-gray-600 text-white rounded hover:bg-gray-500" title="Batal Edit">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                    <?php endif; ?>

                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: MANAJEMEN TIM -->
        <div id="tim" class="tab-content <?= $activeTab === 'tim' ? 'active' : '' ?>">
            <div class="max-w-3xl mx-auto">
                
                <?php if ($is_admin): ?>
                <!-- Form Pendaftaran (Hanya Admin) -->
                <div class="bg-gray-800 rounded-xl shadow-xl p-6 border border-gray-700 mb-8">
                    <h2 class="text-xl font-bold text-white mb-5 flex items-center gap-2">
                        <i class="fas fa-users text-blue-500"></i> Pendaftaran Tim Baru
                    </h2>
                    <form method="POST" action="" class="flex flex-col sm:flex-row gap-4">
                        <input type="hidden" name="action" value="add_team">
                        <input type="hidden" name="tab" value="tim">
                        <div class="flex-1">
                            <input type="text" name="name" required placeholder="Contoh: FC Barcelona (Andi)..." class="w-full bg-gray-900 border border-gray-600 rounded-lg p-3.5 text-white focus:ring-2 focus:ring-blue-500 outline-none shadow-inner" />
                        </div>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-500 px-8 py-3.5 rounded-lg font-bold transition-all shadow-lg flex items-center justify-center gap-2 h-[52px] text-white">
                            <i class="fas fa-plus"></i> Daftarkan
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="bg-gray-800 rounded-xl shadow-xl overflow-hidden border border-gray-700">
                    <div class="p-6 border-b border-gray-700 bg-gradient-to-r from-gray-800 to-gray-900 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-white">Daftar Tim Peserta</h2>
                        <span class="bg-blue-900/50 text-blue-400 px-3 py-1 rounded-full text-sm font-bold border border-blue-800">
                            <?= count($teams) ?> Tim
                        </span>
                    </div>
                    <ul class="divide-y divide-gray-700/50">
                        <?php if (empty($teams)): ?>
                            <li class="p-10 text-center text-gray-500 flex flex-col items-center">
                                <i class="fas fa-users text-5xl mb-3 opacity-20"></i>
                                Belum ada tim yang didaftarkan.
                            </li>
                        <?php else: ?>
                            <?php foreach ($teams as $idx => $team): ?>
                            <li class="p-4 sm:p-5 flex items-center justify-between hover:bg-gray-700/40 transition-colors group">
                                <div class="flex items-center gap-4">
                                    <span class="text-gray-500 font-mono text-sm w-6"><?= $idx + 1 ?>.</span>
                                    <span class="font-bold text-lg text-white"><?= htmlspecialchars($team['name']) ?></span>
                                </div>
                                <?php if ($is_admin): ?>
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="delete_team">
                                    <input type="hidden" name="tab" value="tim">
                                    <input type="hidden" name="id" value="<?= $team['id'] ?>">
                                    <button type="submit" onclick="return confirm('Hapus tim ini dan semua pertandingannya?')" class="text-gray-400 hover:text-rose-500 bg-gray-900 hover:bg-rose-500/10 px-3 py-2 rounded-lg transition-all flex items-center gap-2 text-sm border border-gray-700">
                                        <i class="fas fa-trash"></i> <span class="hidden sm:inline">Hapus</span>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </main>

    <!-- JAVASCRIPT: Interaksi UI & html2canvas -->
    <script>
        <?php if ($is_admin): ?>
        // Fungsi Tampilkan/Sembunyikan Form Edit (Admin Only)
        function toggleEdit(matchId) {
            const displayDiv = document.getElementById('display-' + matchId);
            const editDiv = document.getElementById('edit-' + matchId);
            
            if (displayDiv.classList.contains('hidden')) {
                displayDiv.classList.remove('hidden');
                editDiv.classList.add('hidden');
                editDiv.classList.remove('flex');
            } else {
                displayDiv.classList.add('hidden');
                editDiv.classList.remove('hidden');
                editDiv.classList.add('flex');
            }
        }

        // Inisialisasi Drag & Drop dengan SortableJS (Hanya aktif jika admin)
        document.addEventListener('DOMContentLoaded', function() {
            const matchList = document.getElementById('match-list');
            if (matchList && matchList.querySelector('.match-handle')) {
                new Sortable(matchList, {
                    handle: '.match-handle',
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    onEnd: function (evt) {
                        const items = matchList.querySelectorAll('[data-id]');
                        const orderData = [];
                        
                        items.forEach((item, index) => {
                            const badge = item.querySelector('.match-number');
                            if(badge) badge.innerText = '#' + (index + 1);

                            orderData.push({
                                id: item.getAttribute('data-id'),
                                order_index: index + 1
                            });
                        });

                        const formData = new FormData();
                        formData.append('action', 'update_order');
                        formData.append('order_data', JSON.stringify(orderData));

                        fetch('index.php', {
                            method: 'POST',
                            body: formData
                        }).catch(error => console.error('Error AJAX:', error));
                    }
                });
            }
        });
        <?php endif; ?>

        // Fungsi Export Gambar PNG (Bisa digunakan Admin & Viewer)
        function downloadAsImage(elementId, fileName) {
            const element = document.getElementById(elementId);
            if (!element) return;

            const hiddenElements = element.querySelectorAll('.hide-on-export');
            hiddenElements.forEach(el => el.style.display = 'none');
            
            const inputs = element.querySelectorAll('input[type="number"]');
            inputs.forEach(input => {
                 input.style.border = 'none';
                 input.style.backgroundColor = 'transparent';
                 if(!input.value) {
                     input.setAttribute('data-placeholder', input.placeholder);
                     input.placeholder = '-';
                 }
            });

            html2canvas(element, {
                backgroundColor: '#1f2937',
                scale: 2,
                useCORS: true
            }).then(canvas => {
                hiddenElements.forEach(el => el.style.display = '');
                inputs.forEach(input => {
                     input.style.border = '';
                     input.style.backgroundColor = '';
                     if(input.hasAttribute('data-placeholder')){
                         input.placeholder = input.getAttribute('data-placeholder');
                     }
                });

                const link = document.createElement('a');
                link.download = fileName + '-' + new Date().toISOString().slice(0,10) + '.png';
                link.href = canvas.toDataURL("image/png", 1.0);
                link.click();
            }).catch(err => {
                console.error("Gagal export:", err);
                alert("Gagal mengunduh gambar.");
                hiddenElements.forEach(el => el.style.display = '');
            });
        }
    </script>
</body>
</html>