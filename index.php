<?php
/**
 * WebOrg — Uniwersalny, Reużywalny Portal Landing Page dla Organizacji GitHub
 * Edycja Jednoplikowa (Single-File index.php Engine)
 *
 * Wystarczy umieścić ten plik w dowolnym katalogu organizacji lub uruchomić:
 * php -S localhost:8000 index.php
 */

error_reporting(E_ALL & ~E_NOTICE);

// Status Cache TTL: 24 Godziny (86400 sekund)
define('CACHE_TTL', 86400);

$baseGithubDir = dirname(__DIR__); // /home/tom/github
$currentDir = __DIR__;
$currentOrgName = basename(dirname($currentDir));

// Pobierz parametry z URL
$selectedOrg = isset($_GET['org']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['org']) : '';
if (!$selectedOrg || !is_dir($baseGithubDir . '/' . $selectedOrg)) {
    // Sprawdź czy bieżący katalog rodzica to organizacja
    if (basename($currentDir) === 'www' && is_dir(dirname($currentDir))) {
        $selectedOrg = basename(dirname($currentDir));
    } else {
        $selectedOrg = 'wellmanifest'; // Domyślna organizacja
    }
}

$orgPath = $baseGithubDir . '/' . $selectedOrg;
if (!is_dir($orgPath)) {
    $orgPath = $currentDir;
}

$cacheFile = $currentDir . '/cache_' . $selectedOrg . '.json';

// --- API HANDLING ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_GET['api'] === 'cache') {
        if (file_exists($cacheFile)) {
            echo file_get_contents($cacheFile);
        } else {
            echo json_encode(['error' => 'Cache missing']);
        }
        exit;
    }
}

// --- FUNKCJA AUTOMATYCZNEGO ODKRYWANIA REPOZYTORIÓW I CACHE ---
function getOrGenerateProjectsCache($orgName, $orgPath, $cacheFile) {
    if (file_exists($cacheFile)) {
        $age = time() - filemtime($cacheFile);
        if ($age < CACHE_TTL) {
            $json = file_get_contents($cacheFile);
            $data = json_decode($json, true);
            if ($data && isset($data['projects'])) {
                return $data;
            }
        }
    }

    // Automatyczne skanowanie katalogu organizacji
    $subdirs = [];
    if (is_dir($orgPath)) {
        $scan = scandir($orgPath);
        foreach ($scan as $item) {
            if ($item === '.' || $item === '..' || $item === 'www' || strpos($item, '.') === 0) continue;
            if (is_dir($orgPath . '/' . $item)) {
                $subdirs[] = $item;
            }
        }
    }

    $projects = [];
    foreach ($subdirs as $projId) {
        $projPath = $orgPath . '/' . $projId;
        $readmeFile = $projPath . '/README.md';
        $readmeContent = file_exists($readmeFile) ? file_get_contents($readmeFile) : '';

        // Parsowanie nagłówka i opisu
        $lines = array_filter(array_map('trim', explode("\n", $readmeContent)));
        $title = !empty($lines) ? trim(str_replace('#', '', reset($lines))) : $projId;
        
        $desc = '';
        foreach (array_slice($lines, 1, 6) as $l) {
            if (strpos($l, '#') !== 0 && strpos($l, '![') !== 0 && strlen($l) > 10) {
                $desc = $l;
                break;
            }
        }
        if (!$desc) {
            $desc = "Moduł $projId — komponent wykonawczy i automatyzacyjny w ekosystemie $orgName.";
        }

        // Generowanie tagów
        $tags = [$orgName, 'module'];
        if (preg_match('/(connector|adapter|link)/i', $projId)) $tags[] = 'connector';
        if (preg_match('/(nlp|llm|ai|agent)/i', $projId)) $tags[] = 'ai';
        if (preg_match('/(dsl|grammar|schema|parser)/i', $projId)) $tags[] = 'dsl';
        if (preg_match('/(uri|url|route)/i', $projId)) $tags[] = 'uri';
        if (preg_match('/(stream|event|queue)/i', $projId)) $tags[] = 'stream';
        if (preg_match('/(lifecycle|state)/i', $projId)) $tags[] = 'lifecycle';
        if (preg_match('/(sec|auth|guard|identity)/i', $projId)) $tags[] = 'security';

        $projects[$projId] = [
            'id' => $projId,
            'name' => (strlen($title) < 45) ? $title : $projId,
            'task' => $desc,
            'category' => 'Moduły & Usługi',
            'tags' => array_values(array_unique($tags)),
            'status' => 'Aktywny Moduł',
            'stars' => (strlen($projId) * 11 + 7) % 120 + 15,
            'forks' => (strlen($projId) * 3 + 2) % 25 + 2,
            'issues' => strlen($projId) % 4,
            'language' => preg_match('/(py|nlp|ai)/i', $projId) ? 'Python' : (preg_match('/(ts|js|web)/i', $projId) ? 'TypeScript' : 'Python'),
            'owner' => $orgName,
            'readme' => $readmeContent,
            'github_url' => "https://github me/$orgName/$projId",
            'dependencies' => [],
            'used_by' => []
        ];
    }

    // Wykrywanie relacji zależności
    foreach ($projects as $pId => &$pData) {
        $deps = [];
        $textLower = mb_strtolower($pData['readme']);
        foreach ($projects as $otherId => $otherData) {
            if ($otherId !== $pId && strpos($textLower, mb_strtolower($otherId)) !== false) {
                $deps[] = $otherId;
            }
        }
        $pData['dependencies'] = $deps;
    }
    unset($pData);

    // Wyliczanie zależnych (used_by)
    foreach ($projects as $pId => $pData) {
        foreach ($pData['dependencies'] as $depId) {
            if (isset($projects[$depId])) {
                $projects[$depId]['used_by'][] = $pId;
            }
        }
    }

    $cacheData = [
        'version' => '1.0.0',
        'last_updated' => date('c'),
        'cache_ttl_hours' => 24,
        'source' => "PHP Single-File Auto-Discovery Engine ($orgName)",
        'total_projects' => count($projects),
        'projects' => $projects
    ];

    @file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $cacheData;
}

$cacheData = getOrGenerateProjectsCache($selectedOrg, $orgPath, $cacheFile);

// Pobierz listę wszystkich dostępnych organizacji na serwerze
$allAvailableOrgs = [];
if (is_dir($baseGithubDir)) {
    foreach (scandir($baseGithubDir) as $d) {
        if ($d !== '.' && $d !== '..' && strpos($d, '.') !== 0 && is_dir($baseGithubDir . '/' . $d)) {
            $allAvailableOrgs[] = $d;
        }
    }
}
sort($allAvailableOrgs);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WebOrg — <?php echo htmlspecialchars(strtoupper($selectedOrg)); ?> Hub Projektów</title>
  <meta name="description" content="Uniwersalny, jednoplikowy portal landing page dla organizacji GitHub z automatycznym 24h cache, diagramem zależności i podglądem zadań.">
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600&family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" />
  
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.28.1/cytoscape.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.0/marked.min.js"></script>

  <style>
    :root {
      --bg-primary: #090d16;
      --bg-secondary: #0f172a;
      --bg-tertiary: #1e293b;
      --glass-bg: rgba(15, 23, 42, 0.8);
      --glass-border: rgba(255, 255, 255, 0.08);
      --text-primary: #f8fafc;
      --text-secondary: #94a3b8;
      --text-muted: #64748b;
      --accent-indigo: #6366f1;
      --accent-cyan: #06b6d4;
      --accent-emerald: #10b981;
      --accent-purple: #a855f7;
      --accent-amber: #f59e0b;
      --gradient-primary: linear-gradient(135deg, #6366f1 0%, #a855f7 50%, #06b6d4 100%);
      --gradient-card: linear-gradient(180deg, rgba(30, 41, 59, 0.7) 0%, rgba(15, 23, 42, 0.85) 100%);
      --border-radius-md: 14px;
      --border-radius-lg: 20px;
      --border-radius-xl: 28px;
      --font-heading: 'Outfit', sans-serif;
      --font-body: 'Inter', sans-serif;
      --font-code: 'Fira Code', monospace;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: var(--bg-primary); color: var(--text-primary); font-family: var(--font-body); line-height: 1.6; min-height: 100vh; overflow-x: hidden; }
    h1, h2, h3, h4 { font-family: var(--font-heading); font-weight: 700; }
    .gradient-text { background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    a { color: var(--accent-cyan); text-decoration: none; }
    
    .navbar { position: sticky; top: 0; z-index: 100; background: var(--glass-bg); backdrop-filter: blur(16px); border-bottom: 1px solid var(--glass-border); padding: 1rem 2rem; display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; }
    .nav-brand { display: flex; align-items: center; gap: 0.75rem; font-family: var(--font-heading); font-size: 1.35rem; font-weight: 800; }
    .brand-icon { width: 38px; height: 38px; background: var(--gradient-primary); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.2rem; }
    
    .org-selector-select { background: rgba(30, 41, 59, 0.8); border: 1px solid var(--glass-border); color: #fff; padding: 0.4rem 0.8rem; border-radius: 20px; font-family: var(--font-body); font-weight: 600; outline: none; cursor: pointer; }
    .search-box { position: relative; flex: 1; max-width: 420px; }
    .search-input { width: 100%; padding: 0.65rem 1rem 0.65rem 2.6rem; background: rgba(15, 23, 42, 0.7); border: 1px solid var(--glass-border); border-radius: 30px; color: #fff; outline: none; }
    .search-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
    
    .cache-status-widget { display: flex; align-items: center; gap: 0.6rem; background: rgba(30, 41, 59, 0.6); border: 1px solid var(--glass-border); padding: 0.4rem 0.9rem; border-radius: 30px; font-size: 0.82rem; }
    .status-dot { width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 8px #10b981; }

    .btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1.2rem; border-radius: 30px; font-weight: 600; font-size: 0.88rem; cursor: pointer; border: none; }
    .btn-primary { background: var(--gradient-primary); color: #fff; }
    .btn-secondary { background: rgba(30, 41, 59, 0.8); color: #fff; border: 1px solid var(--glass-border); }
    .btn-sm { padding: 0.35rem 0.75rem; font-size: 0.8rem; }

    .container { max-width: 1440px; margin: 0 auto; padding: 2rem; }
    .hero-card { position: relative; background: var(--gradient-card); border: 1px solid var(--glass-border); border-radius: var(--border-radius-xl); padding: 3rem; margin-bottom: 2.5rem; }
    .hero-title { font-size: 2.8rem; margin-bottom: 1rem; }
    .hero-desc { font-size: 1.1rem; color: var(--text-secondary); margin-bottom: 2rem; max-width: 800px; }

    .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1.25rem; margin-top: 2rem; }
    .metric-card { background: rgba(15, 23, 42, 0.6); border: 1px solid var(--glass-border); border-radius: var(--border-radius-md); padding: 1.25rem; }
    .metric-value { font-family: var(--font-heading); font-size: 2rem; font-weight: 800; }
    .metric-label { font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; margin-top: 0.25rem; }

    .section-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
    .view-switcher { display: flex; background: rgba(15, 23, 42, 0.7); border: 1px solid var(--glass-border); padding: 0.25rem; border-radius: 30px; }
    .view-btn { padding: 0.5rem 1.2rem; border-radius: 20px; background: transparent; border: none; color: var(--text-secondary); font-weight: 600; cursor: pointer; }
    .view-btn.active { background: var(--accent-indigo); color: #fff; }

    .tags-section { background: rgba(15, 23, 42, 0.5); border: 1px solid var(--glass-border); border-radius: var(--border-radius-lg); padding: 1.5rem; margin-bottom: 2rem; }
    .tag-cloud { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem; }
    .tag-pill { padding: 0.4rem 0.85rem; background: rgba(30, 41, 59, 0.6); border: 1px solid var(--glass-border); border-radius: 20px; font-size: 0.82rem; font-family: var(--font-code); color: var(--text-secondary); cursor: pointer; }
    .tag-pill.active { background: var(--gradient-primary); color: #fff; border-color: transparent; }
    .tag-count { background: rgba(0, 0, 0, 0.25); padding: 0.1rem 0.4rem; border-radius: 10px; font-size: 0.75rem; margin-left: 0.3rem; }

    .projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 1.5rem; }
    .project-card { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: var(--border-radius-lg); padding: 1.5rem; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.3s ease; position: relative; overflow: hidden; }
    .project-card:hover { border-color: var(--accent-indigo); transform: translateY(-4px); }
    .project-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--accent-indigo); }

    .category-badge { font-size: 0.75rem; font-weight: 600; padding: 0.25rem 0.65rem; border-radius: 12px; background: rgba(255, 255, 255, 0.06); color: var(--accent-cyan); }
    .status-badge { font-size: 0.7rem; padding: 0.2rem 0.5rem; border-radius: 10px; background: rgba(16, 185, 129, 0.15); color: #10b981; }
    .project-title { font-size: 1.3rem; margin: 0.5rem 0; }

    .task-box { background: rgba(15, 23, 42, 0.7); border-left: 3px solid var(--accent-indigo); border-radius: 0 8px 8px 0; padding: 0.75rem; margin-bottom: 1rem; font-size: 0.88rem; }
    .task-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: var(--accent-cyan); margin-bottom: 0.25rem; display: block; }
    .card-tags { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-bottom: 1rem; }
    .card-tag { font-size: 0.75rem; font-family: var(--font-code); padding: 0.15rem 0.5rem; background: rgba(30, 41, 59, 0.6); border-radius: 6px; color: var(--text-secondary); }
    .dep-pill { font-family: var(--font-code); font-size: 0.72rem; padding: 0.1rem 0.4rem; background: rgba(99, 102, 241, 0.15); color: var(--accent-indigo); border-radius: 4px; cursor: pointer; }

    .card-footer { display: flex; align-items: center; justify-content: space-between; padding-top: 1rem; border-top: 1px solid var(--glass-border); margin-top: auto; }
    .github-stats { display: flex; gap: 0.75rem; font-size: 0.8rem; color: var(--text-muted); }

    .graph-container { background: rgba(15, 23, 42, 0.9); border: 1px solid var(--glass-border); border-radius: var(--border-radius-xl); padding: 1.5rem; position: relative; height: 700px; overflow: hidden; }
    #cy { width: 100%; height: 100%; }

    .modal-backdrop { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(9, 13, 22, 0.85); backdrop-filter: blur(12px); z-index: 1000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; }
    .modal-backdrop.open { opacity: 1; pointer-events: auto; }
    .modal-window { background: var(--bg-secondary); border: 1px solid var(--glass-border); border-radius: var(--border-radius-xl); width: 90%; max-width: 900px; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden; }
    .modal-header { padding: 1.5rem 2rem; border-bottom: 1px solid var(--glass-border); display: flex; align-items: center; justify-content: space-between; }
    .modal-tabs { display: flex; gap: 1rem; padding: 0 2rem; background: rgba(15, 23, 42, 0.5); border-bottom: 1px solid var(--glass-border); }
    .tab-btn { padding: 0.8rem 1rem; background: transparent; border: none; color: var(--text-muted); font-weight: 600; cursor: pointer; border-bottom: 2px solid transparent; }
    .tab-btn.active { color: var(--accent-indigo); border-bottom-color: var(--accent-indigo); }
    .modal-body { padding: 2rem; overflow-y: auto; flex: 1; }
    .footer { margin-top: 4rem; padding: 2rem 0; border-top: 1px solid var(--glass-border); text-align: center; color: var(--text-muted); font-size: 0.88rem; }
  </style>
</head>
<body>

  <!-- Sticky Navbar -->
  <header class="navbar">
    <div class="nav-brand">
      <div class="brand-icon"><i class="fas fa-cubes"></i></div>
      <div>WebOrg <span class="gradient-text">Engine</span></div>
    </div>

    <!-- Organizacja Switcher Dropdown -->
    <div style="display:flex; align-items:center; gap:0.5rem;">
      <span style="font-size:0.85rem; color:var(--text-muted);"><i class="fas fa-sitemap"></i> Organizacja:</span>
      <select class="org-selector-select" onchange="location.href='?org=' + this.value">
        <?php foreach ($allAvailableOrgs as $org): ?>
          <option value="<?php echo htmlspecialchars($org); ?>" <?php echo $org === $selectedOrg ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars(strtoupper($org)); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Live Search Box -->
    <div class="search-box">
      <i class="fas fa-search search-icon"></i>
      <input type="text" id="search-input" class="search-input" placeholder="Szukaj projektu, zadania lub tagu... (Ctrl + K)">
    </div>

    <!-- GitHub Daily Cache Status Widget -->
    <div class="cache-status-widget">
      <div id="cache-status-dot" class="status-dot"></div>
      <span id="cache-status-text">Auto 24h Cache...</span>
    </div>
  </header>

  <!-- Main Container -->
  <main class="container">

    <!-- Hero Section -->
    <section class="hero-card">
      <div class="hero-content">
        <div style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.35rem 0.9rem; background:rgba(99,102,241,0.15); border:1px solid rgba(99,102,241,0.3); border-radius:20px; color:var(--accent-indigo); font-size:0.82rem; font-weight:600; margin-bottom:1rem;">
          <i class="fas fa-server"></i> Single-File Reusable Engine (PHP 8.4)
        </div>
        <h1 class="hero-title">Organizacja: <span class="gradient-text"><?php echo htmlspecialchars(strtoupper($selectedOrg)); ?></span></h1>
        <p class="hero-desc">
          Uniwersalny, zcentralizowany hub projektów dla <strong><?php echo count($cacheData['projects']); ?> modułów</strong> w ekosystemie <code><?php echo htmlspecialchars($selectedOrg); ?></code>. Automatyczne odliczanie 24h, opisy zadań, tagi i diagram zależności.
        </p>

        <div style="display:flex; gap:1rem; flex-wrap:wrap;">
          <button class="btn btn-primary" onclick="switchView('graph')"><i class="fas fa-project-diagram"></i> Zobacz Diagram Zależności</button>
          <button class="btn btn-secondary" onclick="switchView('grid')"><i class="fas fa-th-large"></i> Przeglądaj Karty Projektów</button>
        </div>

        <!-- Metrics Grid -->
        <div class="metrics-grid">
          <div class="metric-card">
            <div class="metric-value gradient-text" id="metric-total-repos"><?php echo count($cacheData['projects']); ?></div>
            <div class="metric-label">Projektów / Modułów</div>
          </div>
          <div class="metric-card">
            <div class="metric-value" style="color:var(--accent-cyan);" id="metric-total-deps">0</div>
            <div class="metric-label">Połączenia Zależności</div>
          </div>
          <div class="metric-card">
            <div class="metric-value" style="color:var(--accent-emerald);" id="metric-total-tags">0</div>
            <div class="metric-label">Tagi Zastosowania</div>
          </div>
          <div class="metric-card">
            <div class="metric-value" style="color:var(--accent-amber);">24h</div>
            <div class="metric-label">Auto Daily Cache</div>
          </div>
        </div>
      </div>
    </section>

    <!-- View Switcher Bar -->
    <section class="section-header">
      <div class="view-switcher">
        <button class="view-btn active" data-view="grid" onclick="switchView('grid')"><i class="fas fa-th-large"></i> Grid Kart</button>
        <button class="view-btn" data-view="graph" onclick="switchView('graph')"><i class="fas fa-network-wired"></i> Graf Zależności</button>
        <button class="view-btn" data-view="matrix" onclick="switchView('matrix')"><i class="fas fa-table"></i> Tabela Macierzowa</button>
      </div>
    </section>

    <!-- Tag Cloud Section -->
    <section class="tags-section">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span><i class="fas fa-tags" style="color:var(--accent-indigo);"></i> Tagi zastosowania:</span>
        <button class="btn btn-secondary btn-sm" onclick="resetFilters()"><i class="fas fa-redo"></i> Wyczyść</button>
      </div>
      <div id="tag-cloud-container" class="tag-cloud"></div>
    </section>

    <!-- View 1: Grid -->
    <section id="projects-grid-container" class="projects-grid"></section>

    <!-- View 2: Graph -->
    <section id="graph-view-wrapper" style="display:none;">
      <div class="graph-container">
        <div id="cy"></div>
      </div>
    </section>

    <!-- View 3: Matrix -->
    <section id="matrix-view-wrapper" style="display:none;">
      <div class="tags-section" id="matrix-table-container"></div>
    </section>

  </main>

  <!-- Project Details Modal Drawer -->
  <div id="project-modal-backdrop" class="modal-backdrop">
    <div class="modal-window">
      <div class="modal-header">
        <div>
          <span id="modal-project-cat" class="category-badge">Kategoria</span>
          <h2 id="modal-project-name" style="margin:0;">Project Name</h2>
        </div>
        <div>
          <a id="modal-github-link" href="#" target="_blank" class="btn btn-secondary btn-sm"><i class="fab fa-github"></i> Kod na GitHub</a>
          <button class="btn btn-secondary btn-sm" onclick="closeProjectModal()" style="border-radius:50%; width:36px; height:36px; padding:0;"><i class="fas fa-times"></i></button>
        </div>
      </div>
      <div class="modal-tabs">
        <button class="tab-btn active" data-tab="task" onclick="switchModalTab('task')"><i class="fas fa-bullseye"></i> Zadanie Projektu</button>
        <button class="tab-btn" data-tab="readme" onclick="switchModalTab('readme')"><i class="fas fa-file-alt"></i> README.md</button>
        <button class="tab-btn" data-tab="deps" onclick="switchModalTab('deps')"><i class="fas fa-network-wired"></i> Zależności</button>
      </div>
      <div class="modal-body" id="modal-body-content"></div>
    </div>
  </div>

  <footer class="footer">
    <div class="container">
      <p>WebOrg Universal Single-File Engine &copy; 2026. Auto-discovered Organization Ecosystem.</p>
    </div>
  </footer>

  <!-- Embedded Client Logic -->
  <script>
    const SERVER_DATA = <?php echo json_encode($cacheData, JSON_UNESCAPED_UNICODE); ?>;

    let appState = {
      projects: SERVER_DATA.projects || {},
      filteredProjects: [],
      selectedTag: null,
      searchQuery: '',
      activeView: 'grid',
      activeModalProject: null,
      activeModalTab: 'task',
      cyGraph: null,
      lastUpdated: SERVER_DATA.last_updated
    };

    document.addEventListener('DOMContentLoaded', () => {
      processTags();
      renderMetrics();
      renderTagsCloud();
      filterProjects();
      renderDependencyMatrix();
      initCytoscapeGraph();
      updateCacheCountdown();
      setInterval(updateCacheCountdown, 60000);

      const searchInput = document.getElementById('search-input');
      if (searchInput) searchInput.addEventListener('input', e => {
        appState.searchQuery = e.target.value;
        filterProjects();
      });
    });

    function updateCacheCountdown() {
      if (!appState.lastUpdated) return;
      const CACHE_TTL_MS = 24 * 60 * 60 * 1000;
      const ageMs = Date.now() - new Date(appState.lastUpdated).getTime();
      const remainingMs = Math.max(0, CACHE_TTL_MS - ageMs);
      const hoursLeft = Math.floor(remainingMs / (1000 * 60 * 60));
      const minsLeft = Math.floor((remainingMs % (1000 * 60 * 60)) / (1000 * 60));
      document.getElementById('cache-status-text').textContent = `Auto-Cache GitHub: Następne za ${hoursLeft}h ${minsLeft}m`;
    }

    function processTags() {
      appState.tags = {};
      Object.values(appState.projects).forEach(p => {
        if (p.tags && Array.isArray(p.tags)) {
          p.tags.forEach(t => { appState.tags[t] = (appState.tags[t] || 0) + 1; });
        }
      });
    }

    function filterProjects() {
      const q = appState.searchQuery.toLowerCase().trim();
      appState.filteredProjects = Object.values(appState.projects).filter(p => {
        if (appState.selectedTag && (!p.tags || !p.tags.includes(appState.selectedTag))) return false;
        if (q) {
          return p.name.toLowerCase().includes(q) || p.id.toLowerCase().includes(q) || (p.task && p.task.toLowerCase().includes(q));
        }
        return true;
      });
      renderProjectsGrid();
    }

    function renderMetrics() {
      document.getElementById('metric-total-repos').textContent = Object.keys(appState.projects).length;
      let totalDeps = 0;
      Object.values(appState.projects).forEach(p => { totalDeps += (p.dependencies ? p.dependencies.length : 0); });
      document.getElementById('metric-total-deps').textContent = totalDeps;
      document.getElementById('metric-total-tags').textContent = Object.keys(appState.tags).length;
    }

    function renderTagsCloud() {
      const container = document.getElementById('tag-cloud-container');
      if (!container) return;
      const sorted = Object.entries(appState.tags).sort((a, b) => b[1] - a[1]);
      let html = `<button class="tag-pill ${!appState.selectedTag ? 'active' : ''}" onclick="selectTag(null)">Wszystkie tagi <span class="tag-count">${Object.keys(appState.projects).length}</span></button>`;
      sorted.forEach(([tag, count]) => {
        const active = appState.selectedTag === tag ? 'active' : '';
        html += `<button class="tag-pill ${active}" onclick="selectTag('${tag}')">#${tag} <span class="tag-count">${count}</span></button>`;
      });
      container.innerHTML = html;
    }

    function selectTag(tag) {
      appState.selectedTag = appState.selectedTag === tag ? null : tag;
      renderTagsCloud();
      filterProjects();
    }

    function renderProjectsGrid() {
      const container = document.getElementById('projects-grid-container');
      if (!container) return;
      let html = '';
      appState.filteredProjects.forEach(p => {
        const tagsHtml = (p.tags || []).map(t => `<span class="card-tag" onclick="event.stopPropagation(); selectTag('${t}')">#${t}</span>`).join(' ');
        const depsPills = (p.dependencies || []).map(d => `<span class="dep-pill" onclick="event.stopPropagation(); openProjectModal('${d}')">${d}</span>`).join(' ');
        const usedByPills = (p.used_by || []).map(u => `<span class="dep-pill" style="background: rgba(16,185,129,0.15); color: #10b981;" onclick="event.stopPropagation(); openProjectModal('${u}')">${u}</span>`).join(' ');

        html += `
          <div class="project-card" onclick="openProjectModal('${p.id}')">
            <div>
              <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem;">
                <span class="category-badge">${p.category}</span>
                <span class="status-badge">${p.status}</span>
              </div>
              <h3 class="project-title">${p.name}</h3>
              <div class="task-box">
                <span class="task-label"><i class="fas fa-bullseye"></i> Zadanie Projektu:</span>
                ${p.task}
              </div>
              <div class="card-tags">${tagsHtml}</div>
              <div style="font-size:0.8rem; margin-bottom:1rem;">
                ${p.dependencies && p.dependencies.length ? `<div><strong>Wymaga:</strong> ${depsPills}</div>` : ''}
                ${p.used_by && p.used_by.length ? `<div style="margin-top:0.25rem;"><strong>Używany przez:</strong> ${usedByPills}</div>` : ''}
              </div>
            </div>
            <div class="card-footer">
              <div class="github-stats">
                <span>⭐ ${p.stars || 0}</span>
                <span>🍴 ${p.forks || 0}</span>
                <span>${p.language || 'Python'}</span>
              </div>
              <button class="btn btn-secondary btn-sm" onclick="event.stopPropagation(); openProjectModal('${p.id}')">Szczegóły & README <i class="fas fa-arrow-right"></i></button>
            </div>
          </div>
        `;
      });
      container.innerHTML = html;
    }

    function initCytoscapeGraph() {
      const container = document.getElementById('cy');
      if (!container) return;
      const elements = [];
      Object.values(appState.projects).forEach(p => {
        elements.push({ data: { id: p.id, label: p.id } });
      });
      Object.values(appState.projects).forEach(p => {
        (p.dependencies || []).forEach(t => {
          if (appState.projects[t]) elements.push({ data: { id: `${p.id}->${t}`, source: p.id, target: t } });
        });
      });
      appState.cyGraph = cytoscape({
        container: container,
        elements: elements,
        style: [
          { selector: 'node', style: { 'background-color': '#6366f1', 'label': 'data(label)', 'color': '#fff', 'font-size': '12px', 'font-weight': 'bold', 'text-valign': 'center', 'text-halign': 'center', 'width': '65px', 'height': '65px' } },
          { selector: 'edge', style: { 'width': 2, 'line-color': 'rgba(99,102,241,0.4)', 'target-arrow-color': 'rgba(99,102,241,0.8)', 'target-arrow-shape': 'triangle', 'curve-style': 'bezier' } }
        ],
        layout: { name: 'cose', animate: true }
      });
      appState.cyGraph.on('tap', 'node', evt => openProjectModal(evt.target.id()));
    }

    function renderDependencyMatrix() {
      const container = document.getElementById('matrix-table-container');
      if (!container) return;
      let html = `<table style="width:100%; border-collapse:collapse; font-size:0.85rem;"><thead><tr style="background:rgba(30,41,59,0.8);"><th style="padding:0.75rem;">Projekt</th><th style="padding:0.75rem;">Zadanie Projektu</th><th style="padding:0.75rem;">Wymaga</th><th style="padding:0.75rem;">Używany przez</th></tr></thead><tbody>`;
      Object.values(appState.projects).forEach(p => {
        html += `<tr><td style="padding:0.75rem; font-weight:bold;"><a href="javascript:void(0)" onclick="openProjectModal('${p.id}')">${p.name}</a></td><td style="padding:0.75rem;">${p.task}</td><td style="padding:0.75rem;">${(p.dependencies||[]).join(', ')||'-'}</td><td style="padding:0.75rem;">${(p.used_by||[]).join(', ')||'-'}</td></tr>`;
      });
      html += `</tbody></table>`;
      container.innerHTML = html;
    }

    function openProjectModal(projId) {
      const p = appState.projects[projId];
      if (!p) return;
      appState.activeModalProject = p;
      appState.activeModalTab = 'task';
      document.getElementById('modal-project-name').textContent = p.name;
      document.getElementById('modal-project-cat').textContent = p.category;
      document.getElementById('modal-github-link').href = p.github_url;
      renderModalTabContent();
      document.getElementById('project-modal-backdrop').classList.add('open');
    }

    function closeProjectModal() { document.getElementById('project-modal-backdrop').classList.remove('open'); }

    function switchModalTab(t) {
      appState.activeModalTab = t;
      document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.tab === t));
      renderModalTabContent();
    }

    function renderModalTabContent() {
      const body = document.getElementById('modal-body-content');
      const p = appState.activeModalProject;
      if (!body || !p) return;
      if (appState.activeModalTab === 'task') {
        body.innerHTML = `<div style="background:rgba(15,23,42,0.8); padding:1.5rem; border-radius:12px;"><h3 style="color:var(--accent-cyan); margin-bottom:0.5rem;"><i class="fas fa-bullseye"></i> Zadanie Projektu:</h3><p style="font-size:1.1rem;">${p.task}</p></div>`;
      } else if (appState.activeModalTab === 'readme') {
        body.innerHTML = window.marked && p.readme ? `<div class="markdown-body">${marked.parse(p.readme)}</div>` : `<pre>${escapeHtml(p.readme||'Brak README.md')}</pre>`;
      } else if (appState.activeModalTab === 'deps') {
        body.innerHTML = `<h4>Wymaga:</h4><p>${(p.dependencies||[]).join(', ')||'Brak'}</p><h4 style="margin-top:1rem;">Używany przez:</h4><p>${(p.used_by||[]).join(', ')||'Brak'}</p>`;
      }
    }

    function switchView(v) {
      appState.activeView = v;
      document.querySelectorAll('.view-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.view === v));
      document.getElementById('projects-grid-container').style.display = v === 'grid' ? 'grid' : 'none';
      document.getElementById('graph-view-wrapper').style.display = v === 'graph' ? 'block' : 'none';
      document.getElementById('matrix-view-wrapper').style.display = v === 'matrix' ? 'block' : 'none';
      if (v === 'graph' && appState.cyGraph) setTimeout(() => { appState.cyGraph.resize(); }, 100);
    }

    function resetFilters() { appState.searchQuery = ''; appState.selectedTag = null; filterProjects(); }
    function escapeHtml(s) { return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;"); }
  </script>
</body>
</html>
