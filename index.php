<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

$arquivoBanco = __DIR__ . '/banco.db';
if (!file_exists($arquivoBanco)) {
    die("<div style='padding:20px; font-family:sans-serif;'>O banco de dados não existe. Por favor, execute o arquivo <b>setup.php</b> primeiro.</div>");
}

$db = new PDO('sqlite:' . $arquivoBanco);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON;');

// Silent schema check for resolvido_em column in case it's an older DB
try {
    $db->exec("ALTER TABLE incidentes_disparados ADD COLUMN resolvido_em TEXT");
} catch (Exception $e) {
    // Column might already exist, ignore
}

function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect($msg = '') {
    $url = 'index.php' . ($msg ? '?msg=' . urlencode($msg) : '');
    header("Location: $url");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // -- Trocar Cenário Ativo --
    if ($action === 'set_cenario') {
        $_SESSION['cenario_ativo'] = (int)$_POST['cenario_id'];
        redirect('Cenário alterado com sucesso.');
    }

    // -- CRUD Cenários --
    if ($action === 'add_cenario') {
        $stmt = $db->prepare("INSERT INTO cenarios (nome, descricao) VALUES (?, ?)");
        $stmt->execute([trim($_POST['nome']), trim($_POST['descricao'])]);
        $_SESSION['cenario_ativo'] = (int)$db->lastInsertId();
        redirect('Cenário criado com sucesso.');
    }
    if ($action === 'edit_cenario') {
        $stmt = $db->prepare("UPDATE cenarios SET nome = ?, descricao = ? WHERE id = ?");
        $stmt->execute([trim($_POST['nome']), trim($_POST['descricao']), (int)$_POST['cenario_id']]);
        redirect('Cenário atualizado com sucesso.');
    }
    if ($action === 'delete_cenario') {
        $cenarioId = (int)$_POST['cenario_id'];
        $stmtDelInc = $db->prepare("DELETE FROM incidentes_disparados WHERE local_id IN (SELECT id FROM locais WHERE cenario_id = ?)");
        $stmtDelInc->execute([$cenarioId]);
        $stmtDelLoc = $db->prepare("DELETE FROM locais WHERE cenario_id = ?");
        $stmtDelLoc->execute([$cenarioId]);
        $stmtDelCen = $db->prepare("DELETE FROM cenarios WHERE id = ?");
        $stmtDelCen->execute([$cenarioId]);
        if (($_SESSION['cenario_ativo'] ?? 0) == $cenarioId) {
            unset($_SESSION['cenario_ativo']);
        }
        redirect('Cenário excluído com sucesso.');
    }

    // -- CRUD Categorias --
    if ($action === 'add_categoria') {
        $cat = trim($_POST['nome_categoria']);
        if ($cat) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO categorias (nome) VALUES (?)");
            $stmt->execute([$cat]);
        }
        redirect('Categoria adicionada.');
    }
    if ($action === 'delete_categoria') {
        $catId = (int)$_POST['categoria_id'];
        $stmt = $db->prepare("DELETE FROM categorias WHERE id = ?");
        $stmt->execute([$catId]);
        redirect('Categoria removida.');
    }

    // -- CRUD Locais --
    if ($action === 'add_local') {
        $stmt = $db->prepare("INSERT INTO locais (cenario_id, nome) VALUES (?, ?)");
        $stmt->execute([(int)$_SESSION['cenario_ativo'], trim($_POST['nome'])]);
        redirect('Local adicionado.');
    }
    if ($action === 'delete_local') {
        $stmt = $db->prepare("DELETE FROM locais WHERE id = ?");
        $stmt->execute([(int)$_POST['local_id']]);
        redirect('Local removido.');
    }

    // -- CRUD Templates --
    if ($action === 'save_template') {
        $id = $_POST['template_id'] ?? '';
        if ($id) {
            $stmt = $db->prepare("UPDATE templates_incidentes SET titulo=?, categoria=?, descricao=?, acao_esperada=?, icone_fa=? WHERE id=?");
            $stmt->execute([trim($_POST['titulo']), trim($_POST['categoria']), trim($_POST['descricao']), trim($_POST['acao_esperada']), trim($_POST['icone_fa']), (int)$id]);
            redirect('Incidente atualizado no catálogo.');
        } else {
            $stmt = $db->prepare("INSERT INTO templates_incidentes (titulo, categoria, descricao, acao_esperada, icone_fa) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([trim($_POST['titulo']), trim($_POST['categoria']), trim($_POST['descricao']), trim($_POST['acao_esperada']), trim($_POST['icone_fa'])]);
            redirect('Incidente salvo no catálogo.');
        }
    }
    if ($action === 'clone_template') {
        $stmt = $db->prepare("INSERT INTO templates_incidentes (titulo, categoria, descricao, acao_esperada, icone_fa) SELECT titulo || ' (Cópia)', categoria, descricao, acao_esperada, icone_fa FROM templates_incidentes WHERE id = ?");
        $stmt->execute([(int)$_POST['template_id']]);
        redirect('Incidente clonado no catálogo.');
    }
    if ($action === 'delete_template') {
        $stmt = $db->prepare("DELETE FROM templates_incidentes WHERE id = ?");
        $stmt->execute([(int)$_POST['template_id']]);
        redirect('Incidente removido do catálogo.');
    }

    // -- Importação JSON em Lote --
    if ($action === 'import_json') {
        $jsonString = $_POST['json_data'] ?? '';
        $data = json_decode($jsonString, true);
        if (is_array($data)) {
            $stmt = $db->prepare("INSERT INTO templates_incidentes (titulo, categoria, descricao, acao_esperada, icone_fa) VALUES (?, ?, ?, ?, ?)");
            $db->beginTransaction();
            foreach ($data as $item) {
                $stmt->execute([
                    $item['titulo'] ?? 'Sem Título',
                    $item['categoria'] ?? 'Geral',
                    $item['descricao'] ?? '',
                    $item['acao_esperada'] ?? '',
                    $item['icone_fa'] ?? 'fa-triangle-exclamation'
                ]);
            }
            $db->commit();
            redirect('Lote importado via JSON com sucesso.');
        } else {
            redirect('Erro: JSON inválido.');
        }
    }

    // -- Alternar Gerador por Categoria --
    if ($action === 'toggle_cat_generator') {
        $localId = (int)$_POST['local_id'];
        $categoria = $_POST['categoria'];
        $status = (int)$_POST['status'];
        $intervalo = (int)$_POST['intervalo'];

        $stmtDel = $db->prepare("DELETE FROM local_geradores WHERE local_id = ? AND categoria = ?");
        $stmtDel->execute([$localId, $categoria]);

        $stmtIns = $db->prepare("INSERT INTO local_geradores (local_id, categoria, gerador_ativo, intervalo_minutos) VALUES (?, ?, ?, ?)");
        $stmtIns->execute([$localId, $categoria, $status, $intervalo]);

        // Se o gerador foi ativado, dispara um incidente imediatamente para esta categoria e local
        if ($status === 1) {
            $stmtT = $db->prepare("SELECT * FROM templates_incidentes WHERE categoria = ? ORDER BY RANDOM() LIMIT 1");
            $stmtT->execute([$categoria]);
            $t = $stmtT->fetch(PDO::FETCH_ASSOC);
            if ($t) {
                $stmtInsInc = $db->prepare("INSERT INTO incidentes_disparados (template_id, local_id, status, criado_em) VALUES (?, ?, 'PENDENTE', ?)");
                $stmtInsInc->execute([$t['id'], $localId, date('Y-m-d H:i:s')]);
            }
        }

        jsonResponse(['success' => true]);
    }

    // -- Função que Roda em Background (Tick por Categoria) --
    if ($action === 'auto_tick') {
        $stmtGens = $db->query("
            SELECT lg.*, l.id as local_id 
            FROM local_geradores lg
            JOIN locais l ON lg.local_id = l.id
            WHERE lg.gerador_ativo = 1
        ");
        $geradores = $stmtGens->fetchAll(PDO::FETCH_ASSOC);
        
        $dispatchedCount = 0;
        
        foreach ($geradores as $gen) {
            $localId = $gen['local_id'];
            $categoria = $gen['categoria'];
            $intervaloMin = (int)$gen['intervalo_minutos'];

            $stmtLast = $db->prepare("
                SELECT idisp.criado_em 
                FROM incidentes_disparados idisp
                JOIN templates_incidentes t ON idisp.template_id = t.id
                WHERE idisp.local_id = ? AND t.categoria = ? 
                ORDER BY idisp.id DESC LIMIT 1
            ");
            $stmtLast->execute([$localId, $categoria]);
            $lastIncidente = $stmtLast->fetch(PDO::FETCH_ASSOC);
            
            $deveDisparar = false;
            if (!$lastIncidente) {
                $deveDisparar = true; 
            } else {
                $agora = time();
                $ultimoTempo = strtotime($lastIncidente['criado_em']);
                $minutosPassados = ($agora - $ultimoTempo) / 60;
                if ($minutosPassados >= $intervaloMin) {
                    $deveDisparar = true;
                }
            }

            if ($deveDisparar) {
                $stmtT = $db->prepare("SELECT * FROM templates_incidentes WHERE categoria = ? ORDER BY RANDOM() LIMIT 1");
                $stmtT->execute([$categoria]);
                $templates = $stmtT->fetchAll(PDO::FETCH_ASSOC);

                if (count($templates) > 0) {
                    $t = $templates[0];
                    $stmtIns = $db->prepare("INSERT INTO incidentes_disparados (template_id, local_id, status, criado_em) VALUES (?, ?, 'PENDENTE', ?)");
                    $stmtIns->execute([$t['id'], $localId, date('Y-m-d H:i:s')]);
                    $dispatchedCount++;
                }
            }
        }
        jsonResponse(['dispatched' => $dispatchedCount]);
    }

    // -- Disparo Manual --
    if ($action === 'disparar_manual') {
        $stmtIns = $db->prepare("INSERT INTO incidentes_disparados (template_id, local_id, status, criado_em) VALUES (?, ?, 'PENDENTE', ?)");
        $stmtIns->execute([(int)$_POST['template_id'], (int)$_POST['local_id'], date('Y-m-d H:i:s')]);
        redirect('Incidente disparado manualmente.');
    }

    // -- Avaliação Única --
    if ($action === 'evaluate') {
        $stmt = $db->prepare("UPDATE incidentes_disparados SET status = 'RESOLVIDO', resultado_barema = ?, observacao_barema = ?, resolvido_em = ? WHERE id = ?");
        $stmt->execute([$_POST['resultado'], $_POST['obs'], date('Y-m-d H:i:s'), (int)$_POST['incidente_id']]);
        jsonResponse(['success' => true]);
    }

    // -- Avaliação em Massa --
    if ($action === 'evaluate_bulk') {
        $ids = isset($_POST['incidentes_ids']) ? explode(',', $_POST['incidentes_ids']) : [];
        $resultado = $_POST['resultado'];
        $obs = $_POST['obs'];

        if (!empty($ids)) {
            $ids = array_map('intval', $ids);
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $params = array_merge([$resultado, $obs, date('Y-m-d H:i:s')], $ids);
            $stmt = $db->prepare("UPDATE incidentes_disparados SET status = 'RESOLVIDO', resultado_barema = ?, observacao_barema = ?, resolvido_em = ? WHERE id IN ($placeholders)");
            $stmt->execute($params);
        }
        jsonResponse(['success' => true]);
    }
    
    // -- Edição/Exclusão do Relatório Histórico --
    if ($action === 'edit_historico') {
        $stmt = $db->prepare("UPDATE incidentes_disparados SET resultado_barema = ?, observacao_barema = ? WHERE id = ?");
        $stmt->execute([$_POST['resultado'], $_POST['obs'], (int)$_POST['incidente_id']]);
        redirect('Avaliação atualizada no histórico.');
    }
    if ($action === 'delete_historico') {
        $stmt = $db->prepare("DELETE FROM incidentes_disparados WHERE id = ?");
        $stmt->execute([(int)$_POST['incidente_id']]);
        redirect('Registro removido do histórico.');
    }
}

$cenarios = $db->query("SELECT * FROM cenarios ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$templates = $db->query("SELECT * FROM templates_incidentes ORDER BY categoria, titulo")->fetchAll(PDO::FETCH_ASSOC);
$categorias_db = $db->query("SELECT DISTINCT nome FROM categorias ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);

if(empty($categorias_db)){
    $categorias_db = ['C2', 'Comunicações', 'Saúde', 'Logística', 'Geral'];
}

$cenarioAtivoId = (int)($_SESSION['cenario_ativo'] ?? ($cenarios[0]['id'] ?? 0));
$cenarioAtivo = null;
$locais = [];
$incidentesAtivos = [];
$historico = [];

if ($cenarioAtivoId > 0) {
    $stmtC = $db->prepare("SELECT * FROM cenarios WHERE id = ?");
    $stmtC->execute([$cenarioAtivoId]);
    $cenarioAtivo = $stmtC->fetch(PDO::FETCH_ASSOC);

    $stmtL = $db->prepare("SELECT * FROM locais WHERE cenario_id = ?");
    $stmtL->execute([$cenarioAtivoId]);
    $locais = $stmtL->fetchAll(PDO::FETCH_ASSOC);

    $stmtInc = $db->prepare("
        SELECT idisp.*, t.titulo, t.categoria, t.descricao, t.acao_esperada, t.icone_fa, l.nome as local_nome 
        FROM incidentes_disparados idisp
        JOIN templates_incidentes t ON idisp.template_id = t.id
        JOIN locais l ON idisp.local_id = l.id
        WHERE l.cenario_id = ? AND idisp.status = 'PENDENTE'
        ORDER BY idisp.id DESC
    ");
    $stmtInc->execute([$cenarioAtivoId]);
    $incidentesAtivos = $stmtInc->fetchAll(PDO::FETCH_ASSOC);

    $stmtHist = $db->prepare("
        SELECT idisp.*, t.titulo, t.categoria, l.nome as local_nome 
        FROM incidentes_disparados idisp
        JOIN templates_incidentes t ON idisp.template_id = t.id
        JOIN locais l ON idisp.local_id = l.id
        WHERE l.cenario_id = ? AND idisp.status = 'RESOLVIDO'
        ORDER BY idisp.id DESC LIMIT 500
    ");
    $stmtHist->execute([$cenarioAtivoId]);
    $historico = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
}

// ====================================================================================
// TELA DO INSTRUENDO (MODO SOMENTE LEITURA / TELÃO)
// ====================================================================================
$view = $_GET['view'] ?? 'admin';
if ($view === 'telao'):
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telão de Incidentes - Direção de Exercício</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/all.min.css">
    <script>
        setInterval(() => { window.location.reload(); }, 30000);
    </script>
</head>
<body class="bg-slate-900 min-h-screen text-slate-200 font-sans p-6">
    <header class="border-b border-slate-700 pb-4 mb-6 flex justify-between items-center">
        <div class="flex items-center gap-3">
            <i class="fa-solid fa-satellite-dish text-blue-500 text-3xl"></i>
            <div>
                <h1 class="text-2xl font-bold tracking-wide text-white">Quadro de Incidentes (Ativos)</h1>
                <p class="text-slate-400 text-sm">Cenário: <?= $cenarioAtivo ? htmlspecialchars($cenarioAtivo['nome']) : 'Nenhum' ?></p>
            </div>
        </div>
        <div class="text-right">
            <span class="bg-red-500/20 text-red-400 border border-red-500/30 px-3 py-1 rounded-full text-sm font-bold flex items-center gap-2">
                <i class="fa-solid fa-circle-dot animate-pulse"></i> Acompanhamento em Tempo Real (Instruendo)
            </span>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($incidentesAtivos as $inc): ?>
            <div class="bg-slate-800 rounded-lg shadow-lg border-l-4 border-red-500 p-5 flex flex-col">
                <div class="flex items-center justify-between mb-3 border-b border-slate-700 pb-2">
                    <span class="bg-slate-700 text-white text-xs font-bold px-2 py-1 rounded"><?= htmlspecialchars($inc['local_nome']) ?></span>
                    <span class="text-slate-400 text-xs"><i class="fa-regular fa-clock"></i> <?= date('H:i', strtotime($inc['criado_em'])) ?></span>
                </div>
                <h4 class="font-bold text-xl text-white mb-2 flex items-center">
                    <i class="fa-solid <?= htmlspecialchars($inc['icone_fa']) ?> text-blue-400 mr-2"></i> <?= htmlspecialchars($inc['titulo']) ?>
                </h4>
                <p class="text-slate-300 text-sm mb-4 flex-1 border-l-2 border-slate-600 pl-3">
                    <?= htmlspecialchars($inc['descricao']) ?>
                </p>
                <div class="mt-auto bg-slate-900 p-3 rounded border border-slate-700">
                    <p class="text-xs text-slate-400 uppercase font-bold mb-1">Ação Requerida:</p>
                    <p class="text-green-400 font-medium text-sm"><?= htmlspecialchars($inc['acao_esperada']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if(empty($incidentesAtivos)): ?>
            <div class="col-span-full text-center py-20">
                <i class="fa-solid fa-check-double text-6xl mb-4 text-slate-700"></i>
                <h2 class="text-2xl font-bold text-slate-500">Nenhum incidente ativo no momento.</h2>
                <p class="text-slate-600">Aguardando novos disparos da Direção de Exercício...</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php 
exit;
endif; 
// ====================================================================================
// FIM TELA INSTRUENDO
// ====================================================================================
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SisGI - Sistema de Gerenciamento de Incidentes</title>
    <script src="assets/tailwind.js"></script>
    <link rel="stylesheet" href="assets/all.min.css">
    <script src="assets/chartjs.js"></script>
    <style>
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .pulse-green { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
    </style>
    <script>
        if (window.history.replaceState && window.location.search.includes('msg=')) {
            window.history.replaceState(null, null, window.location.pathname);
        }
    </script>
</head>
<body class="bg-slate-100 min-h-screen text-slate-800 font-sans flex flex-col">

    <header class="bg-slate-900 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <i class="fa-solid fa-satellite-dish text-blue-400 text-2xl"></i>
                <h1 class="text-xl font-bold tracking-wide">SisGI - Direção de Exercício</h1>
            </div>
            
            <div class="flex items-center gap-4">
                <?php if ($cenarioAtivo): ?>
                    <a href="index.php?view=telao" target="_blank" class="bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-600 text-xs font-bold py-1 px-3 rounded flex items-center transition" title="Abrir telão para instruendos">
                        <i class="fa-solid fa-display mr-2 text-blue-400"></i> Telão Instruendo
                    </a>
                    
                    <span class="text-sm text-slate-400 ml-2 border-l border-slate-700 pl-4">Cenário Ativo:</span>
                    <form method="POST" class="inline m-0">
                        <input type="hidden" name="action" value="set_cenario">
                        <select name="cenario_id" onchange="this.form.submit()" class="bg-slate-800 text-white text-sm border border-slate-700 rounded p-1">
                            <?php foreach ($cenarios as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $c['id'] == $cenarioAtivoId ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php else: ?>
                    <span class="text-sm text-red-400 font-bold">Nenhum cenário ativo</span>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="flex-1 max-w-7xl w-full mx-auto flex overflow-hidden">
        
        <aside class="w-64 flex-shrink-0 py-6 pr-6 border-r border-slate-200">
            <nav class="flex flex-col gap-2">
                <button onclick="showTab('tab-dashboard')" id="btn-tab-dashboard" class="text-left px-4 py-2 rounded font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 transition-colors">
                    <i class="fa-solid fa-gauge mr-2"></i> Painel de Controle
                </button>
                <button onclick="showTab('tab-locais')" id="btn-tab-locais" class="text-left px-4 py-2 rounded font-medium text-slate-600 hover:bg-slate-200 transition-colors">
                    <i class="fa-solid fa-map-location-dot mr-2"></i> Gestão de Locais
                </button>
                <button onclick="showTab('tab-templates')" id="btn-tab-templates" class="text-left px-4 py-2 rounded font-medium text-slate-600 hover:bg-slate-200 transition-colors">
                    <i class="fa-solid fa-list-check mr-2"></i> Catálogo Barema
                </button>
                <button onclick="showTab('tab-relatorio')" id="btn-tab-relatorio" class="text-left px-4 py-2 rounded font-medium text-slate-600 hover:bg-slate-200 transition-colors">
                    <i class="fa-solid fa-chart-line mr-2"></i> Relatório Avançado
                </button>
                <button onclick="showTab('tab-cenarios')" id="btn-tab-cenarios" class="text-left px-4 py-2 rounded font-medium text-slate-600 hover:bg-slate-200 transition-colors mt-6 border-t border-slate-200 pt-4">
                    <i class="fa-solid fa-folder-tree mr-2"></i> Mudar Cenário
                </button>
            </nav>
        </aside>

        <main class="flex-1 py-6 pl-6 overflow-y-auto">
            
            <?php if (isset($_GET['msg'])): ?>
                <div id="msg-alert" class="mb-4 p-3 bg-green-100 text-green-800 border border-green-200 rounded flex justify-between items-center">
                    <span><i class="fa-solid fa-check-circle mr-2"></i> <?= htmlspecialchars($_GET['msg']) ?></span>
                    <button onclick="document.getElementById('msg-alert').style.display='none'"><i class="fa-solid fa-xmark"></i></button>
                </div>
            <?php endif; ?>

            <!-- DASHBOARD / PAINEL DE CONTROLE -->
            <section id="tab-dashboard" class="tab-content active">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-slate-800">Controle: <?= $cenarioAtivo ? htmlspecialchars($cenarioAtivo['nome']) : 'Nenhum' ?></h2>
                    <div class="flex items-center gap-3 bg-white px-3 py-1.5 rounded border shadow-sm text-sm">
                        <div class="flex items-center gap-1.5 border-r pr-3">
                            <span class="text-xs text-slate-500 font-bold">Atualização:</span>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" id="refresh-toggle-chk" class="sr-only peer" checked onchange="updateRefreshSettings()">
                                <div class="w-7 h-3.5 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-2.5 after:w-2.5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                            <select id="refresh-interval-select" onchange="updateRefreshSettings()" class="text-xs border rounded p-0.5 bg-slate-50">
                                <option value="15">15s</option>
                                <option value="30" selected>30s</option>
                                <option value="60">1 min</option>
                                <option value="120">2 min</option>
                            </select>
                        </div>
                        <div>
                            Auto-Gerador: <span id="sync-status" class="text-green-600 font-bold ml-1"><i class="fa-solid fa-circle pulse-green text-xs"></i> Ativo</span>
                        </div>
                    </div>
                </div>

                <?php if (!$cenarioAtivo): ?>
                    <div class="p-6 bg-yellow-50 border border-yellow-200 rounded text-yellow-800">
                        Crie ou selecione um cenário na aba "Mudar Cenário" para começar.
                    </div>
                <?php else: ?>
                    
                    <h3 class="text-lg font-semibold mb-3 border-b pb-2">Postos Ativos e Configuração de Geradores por Categoria</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
                        <?php foreach ($locais as $l): ?>
                            <div class="bg-white p-4 rounded shadow-sm border border-slate-200">
                                <h4 class="font-bold text-slate-700 text-lg mb-2 flex items-center justify-between">
                                    <span><i class="fa-solid fa-location-pin text-red-500 mr-1"></i> <?= htmlspecialchars($l['nome']) ?></span>
                                </h4>
                                
                                <div class="bg-slate-50 p-3 rounded mb-3 border">
                                    <span class="text-xs font-bold text-slate-500 uppercase block mb-2">Geradores Automáticos & Cronômetro</span>
                                    <div class="space-y-2">
                                        <?php foreach ($categorias_db as $catName): 
                                            $stmtCGen = $db->prepare("SELECT * FROM local_geradores WHERE local_id = ? AND categoria = ?");
                                            $stmtCGen->execute([$l['id'], $catName]);
                                            $cGenConfig = $stmtCGen->fetch(PDO::FETCH_ASSOC);
                                            $isGenActive = $cGenConfig['gerador_ativo'] ?? 0;
                                            $genInterval = $cGenConfig['intervalo_minutos'] ?? 5;
                                        ?>
                                            <div class="flex items-center justify-between bg-white p-2 rounded border text-sm hover:bg-slate-50 transition">
                                                <span class="font-medium text-slate-700 text-xs w-28 truncate" title="<?= htmlspecialchars($catName) ?>"><?= htmlspecialchars($catName) ?></span>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-[10px] text-slate-400 ml-1">Int:</span>
                                                    <input type="number" id="int_<?= $l['id'] ?>_<?= md5($catName) ?>" value="<?= $genInterval ?>" min="1" class="w-12 p-0.5 border rounded text-xs text-center focus:ring-1 focus:ring-blue-500" onchange="toggleCategoryGenerator(<?= $l['id'] ?>, '<?= addslashes($catName) ?>', document.getElementById('chk_<?= $l['id'] ?>_<?= md5($catName) ?>').checked, this.value)">
                                                    <label class="relative inline-flex items-center cursor-pointer ml-1">
                                                        <input type="checkbox" id="chk_<?= $l['id'] ?>_<?= md5($catName) ?>" class="sr-only peer" onchange="toggleCategoryGenerator(<?= $l['id'] ?>, '<?= addslashes($catName) ?>', this.checked, document.getElementById('int_<?= $l['id'] ?>_<?= md5($catName) ?>').value)" <?= $isGenActive ? 'checked' : '' ?>>
                                                        <div class="w-8 h-4 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-green-500"></div>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <form method="POST" class="border-t pt-2">
                                    <input type="hidden" name="action" value="disparar_manual">
                                    <input type="hidden" name="local_id" value="<?= $l['id'] ?>">
                                    <div class="flex gap-2">
                                        <select name="template_id" class="flex-1 text-sm border rounded p-1" required>
                                            <option value="">Disparar Manualmente...</option>
                                            <?php foreach ($templates as $t): ?>
                                                <option value="<?= $t['id'] ?>">[<?= htmlspecialchars($t['categoria']) ?>] <?= htmlspecialchars($t['titulo']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white p-1 px-3 rounded text-sm" title="Disparar Imediatamente"><i class="fa-solid fa-bolt"></i></button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($locais)): ?>
                            <p class="text-slate-500 text-sm col-span-2">Nenhum local configurado. Adicione locais na aba "Gestão de Locais".</p>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-col md:flex-row justify-between items-center mb-3 border-b pb-2 gap-4">
                        <h3 class="text-lg font-semibold text-red-600 m-0"><i class="fa-solid fa-tower-broadcast mr-2"></i> Incidentes Pendentes no Terreno</h3>
                        
                        <div class="flex flex-col md:flex-row gap-3 w-full md:w-auto items-center">
                            <select id="filter-pendentes-categoria" onchange="filterPendentes()" class="text-sm p-1.5 border rounded bg-white">
                                <option value="">Todas as Categorias</option>
                                <?php foreach ($categorias_db as $catName): ?>
                                    <option value="<?= htmlspecialchars($catName) ?>"><?= htmlspecialchars($catName) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <div class="bg-slate-100 border p-1.5 rounded flex items-center gap-2">
                                <label class="flex items-center gap-1 text-sm font-bold text-slate-700 mr-2 cursor-pointer">
                                    <input type="checkbox" id="chk-all-pendentes" onchange="toggleAllPendentes(this)" class="w-4 h-4"> Todos
                                </label>
                                <input type="text" id="obs_bulk" placeholder="Obs em massa..." class="w-32 text-sm p-1 border rounded">
                                <button onclick="evaluateBulk('SIM')" class="bg-green-600 hover:bg-green-700 text-white font-bold py-1 px-2 rounded text-xs">SIM</button>
                                <button onclick="evaluateBulk('NÃO')" class="bg-red-600 hover:bg-red-700 text-white font-bold py-1 px-2 rounded text-xs">NÃO</button>
                                <button onclick="evaluateBulk('OBS')" class="bg-slate-600 hover:bg-slate-700 text-white font-bold py-1 px-2 rounded text-xs">OBS</button>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <?php foreach ($incidentesAtivos as $inc): ?>
                            <div class="bg-white rounded shadow border-l-4 border-red-500 overflow-hidden inc-pendente-card" id="inc_card_<?= $inc['id'] ?>" data-category="<?= htmlspecialchars($inc['categoria']) ?>">
                                <div class="p-4 flex flex-col md:flex-row gap-4 justify-between items-start md:items-center">
                                    <div class="flex items-start gap-3 flex-1">
                                        <input type="checkbox" class="chk-pendente mt-1 w-5 h-5 cursor-pointer" value="<?= $inc['id'] ?>">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="bg-red-100 text-red-800 text-xs font-bold px-2 py-1 rounded"><?= htmlspecialchars($inc['local_nome']) ?></span>
                                                <span class="bg-blue-100 text-blue-800 text-xs font-bold px-2 py-1 rounded"><?= htmlspecialchars($inc['categoria']) ?></span>
                                                <span class="text-slate-500 text-xs"><i class="fa-regular fa-clock"></i> Disparado: <?= date('d/m H:i', strtotime($inc['criado_em'])) ?></span>
                                            </div>
                                            <h4 class="font-bold text-lg text-slate-800"><i class="fa-solid <?= htmlspecialchars($inc['icone_fa']) ?> mr-1"></i> <?= htmlspecialchars($inc['titulo']) ?></h4>
                                            <p class="text-slate-600 mt-2 text-sm"><strong>Cenário:</strong> <?= htmlspecialchars($inc['descricao']) ?></p>
                                            <p class="text-slate-600 text-sm mt-1"><strong>Ação Esperada (Barema):</strong> <?= htmlspecialchars($inc['acao_esperada']) ?></p>
                                        </div>
                                    </div>
                                    <div class="md:w-64 bg-slate-50 p-3 rounded border flex flex-col justify-center shrink-0">
                                        <input type="text" id="obs_<?= $inc['id'] ?>" placeholder="Observações (opcional)..." class="w-full text-sm p-1.5 border rounded mb-2">
                                        <div class="flex gap-2">
                                            <button onclick="evaluateIncidente(<?= $inc['id'] ?>, 'SIM')" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-bold py-1.5 rounded text-sm transition">SIM</button>
                                            <button onclick="evaluateIncidente(<?= $inc['id'] ?>, 'NÃO')" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-1.5 rounded text-sm transition">NÃO</button>
                                        </div>
                                        <button onclick="evaluateIncidente(<?= $inc['id'] ?>, 'OBS')" class="w-full mt-2 bg-slate-600 hover:bg-slate-700 text-white font-bold py-1 rounded text-sm transition">APENAS OBS</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($incidentesAtivos)): ?>
                            <div class="text-center py-10 bg-slate-50 border rounded text-slate-500">
                                <i class="fa-solid fa-check-double text-4xl mb-3 text-slate-300"></i>
                                <p>Nenhum incidente pendente no terreno neste momento.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- GESTÃO DE LOCAIS -->
            <section id="tab-locais" class="tab-content">
                <h2 class="text-2xl font-bold text-slate-800 mb-6 border-b pb-2">Gestão de Locais / Postos</h2>
                <?php if ($cenarioAtivo): ?>
                    <form method="POST" class="bg-white p-4 rounded shadow border mb-6 flex gap-4 items-end">
                        <input type="hidden" name="action" value="add_local">
                        <div class="flex-1">
                            <label class="block text-sm font-bold text-slate-700 mb-1">Nome do Posto/Local</label>
                            <input type="text" name="nome" required placeholder="Ex: PPM (8ª Cia Com)" class="w-full p-2 border rounded">
                        </div>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded"><i class="fa-solid fa-plus mr-1"></i> Adicionar Local</button>
                    </form>

                    <div class="bg-white shadow rounded overflow-hidden border">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Local</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase">Ação</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <?php foreach ($locais as $l): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-800"><?= htmlspecialchars($l['nome']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <form method="POST" class="inline" onsubmit="return confirm('Deseja excluir este local?')">
                                                <input type="hidden" name="action" value="delete_local">
                                                <input type="hidden" name="local_id" value="<?= $l['id'] ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-900"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-red-600">Selecione um cenário primeiro.</p>
                <?php endif; ?>
            </section>

            <!-- CATÁLOGO BAREMA -->
            <section id="tab-templates" class="tab-content">
                <h2 class="text-2xl font-bold text-slate-800 mb-6 border-b pb-2">Catálogo de Incidentes (Barema)</h2>
                
                <!-- Gestão de Categorias -->
                <div class="bg-white p-4 rounded shadow border mb-6">
                    <h3 class="font-bold text-slate-700 text-sm mb-2"><i class="fa-solid fa-tags mr-1 text-blue-500"></i> Gerenciar Categorias</h3>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <?php foreach($categorias_db as $catName): ?>
                            <span class="bg-slate-100 border px-3 py-1 rounded text-xs flex items-center gap-2 font-medium">
                                <?= htmlspecialchars($catName) ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Excluir categoria?')">
                                    <input type="hidden" name="action" value="delete_categoria">
                                    <?php 
                                        $catIdRes = $db->prepare("SELECT id FROM categorias WHERE nome = ?");
                                        $catIdRes->execute([$catName]);
                                        $catIdVal = $catIdRes->fetchColumn();
                                    ?>
                                    <input type="hidden" name="categoria_id" value="<?= $catIdVal ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700"><i class="fa-solid fa-xmark"></i></button>
                                </form>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <form method="POST" class="flex gap-2 max-w-md">
                        <input type="hidden" name="action" value="add_categoria">
                        <input type="text" name="nome_categoria" placeholder="Nova Categoria..." required class="text-xs p-2 border rounded flex-1">
                        <button type="submit" class="bg-slate-800 text-white px-3 py-1.5 rounded text-xs font-bold hover:bg-slate-900">Adicionar</button>
                    </form>
                </div>

                <form method="POST" class="bg-white p-5 rounded shadow border mb-6 relative">
                    <input type="hidden" name="action" id="form-template-action" value="save_template">
                    <input type="hidden" name="template_id" id="form-template-id" value="">
                    
                    <div class="flex justify-between items-center mb-4 border-b pb-2">
                        <h3 class="font-bold text-slate-800 text-lg" id="form-template-title">Adicionar Novo Incidente</h3>
                        <button type="button" id="btn-template-cancel" onclick="cancelEditTemplate()" class="hidden text-sm text-red-600 hover:text-red-800 font-bold"><i class="fa-solid fa-xmark mr-1"></i> Cancelar Edição</button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-1">Título</label>
                            <input type="text" name="titulo" required placeholder="Ex: Pane Elétrica" class="w-full p-2 border rounded">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-1">Categoria</label>
                            <select name="categoria" class="w-full p-2 border rounded bg-white" required>
                                <option value="">Selecione a Categoria...</option>
                                <?php foreach ($categorias_db as $catName): ?>
                                    <option value="<?= htmlspecialchars($catName) ?>"><?= htmlspecialchars($catName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-bold text-slate-700 mb-1">Descrição (Situação repassada para a equipe)</label>
                        <textarea name="descricao" required rows="2" class="w-full p-2 border rounded outline-none"></textarea>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-bold text-slate-700 mb-1">Ação Esperada (Critério do Barema)</label>
                        <input type="text" name="acao_esperada" required class="w-full p-2 border rounded outline-none">
                    </div>
                    <div class="mb-5">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Selecionar Ícone Visual</label>
                        <input type="hidden" name="icone_fa" id="icone_fa_input" value="fa-triangle-exclamation">
                        <div class="grid grid-cols-6 sm:grid-cols-8 md:grid-cols-12 gap-2 max-h-40 overflow-y-auto p-2 border rounded bg-slate-50">
                            <?php 
                            $icons = [
                                'fa-walkie-talkie', 'fa-headset', 'fa-satellite-dish', 'fa-tower-broadcast', 'fa-network-wired', 
                                'fa-laptop-code', 'fa-envelope', 'fa-phone', 'fa-shield-halved', 'fa-lock', 'fa-key',
                                'fa-kit-medical', 'fa-heart-pulse', 'fa-truck-medical', 'fa-bed-pulse', 'fa-suitcase-medical', 'fa-hospital', 'fa-droplet',
                                'fa-wrench', 'fa-bolt', 'fa-fire-extinguisher', 'fa-car-side', 'fa-truck-fast', 'fa-truck-convoy',
                                'fa-boxes-packing', 'fa-bowl-food', 'fa-pump-soap', 'fa-toolbox', 'fa-gun', 'fa-person-rifle',
                                'fa-triangle-exclamation', 'fa-users', 'fa-user-secret', 'fa-land-mine-on', 'fa-magnifying-glass', 
                                'fa-file-lines', 'fa-book', 'fa-map', 'fa-mountain', 'fa-rope', 'fa-camera', 'fa-person-falling-burst'
                            ];
                            foreach($icons as $ic): ?>
                                <button type="button" class="icon-select-btn flex items-center justify-center h-12 w-full rounded border text-slate-600 hover:bg-blue-100 hover:text-blue-600 transition <?= $ic == 'fa-triangle-exclamation' ? 'bg-blue-100 border-blue-500 text-blue-700 ring-2 ring-blue-500' : 'bg-white border-slate-200' ?>" data-icon="<?= $ic ?>" onclick="selectIcon('<?= $ic ?>')">
                                    <i class="fa-solid <?= $ic ?> text-xl"></i>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" id="btn-template-submit" class="bg-slate-800 hover:bg-slate-900 text-white font-bold py-2 px-6 rounded transition"><i class="fa-solid fa-save mr-1"></i> Salvar Incidente</button>
                </form>

                <div class="bg-white p-5 rounded shadow border mb-6">
                    <h3 class="font-bold text-slate-700 mb-1"><i class="fa-solid fa-file-code text-blue-500 mr-2"></i>Importação em Lote via JSON</h3>
                    <p class="text-xs text-slate-500 mb-3">
                        Cole abaixo um array JSON válido contendo os incidentes. Cada objeto deve possuir as chaves: 
                        <code class="bg-slate-100 text-slate-700 px-1 py-0.5 rounded">titulo</code>, 
                        <code class="bg-slate-100 text-slate-700 px-1 py-0.5 rounded">categoria</code>, 
                        <code class="bg-slate-100 text-slate-700 px-1 py-0.5 rounded">descricao</code>, 
                        <code class="bg-slate-100 text-slate-700 px-1 py-0.5 rounded">acao_esperada</code> e 
                        <code class="bg-slate-100 text-slate-700 px-1 py-0.5 rounded">icone_fa</code> (ex: <code class="bg-slate-100 text-slate-700 px-1 py-0.5 rounded">fa-walkie-talkie</code>).
                    </p>
                    <form method="POST">
                        <input type="hidden" name="action" value="import_json">
                        <textarea name="json_data" rows="4" required placeholder='[{"titulo": "Exemplo", "categoria": "Comunicações", "descricao": "...", "acao_esperada": "...", "icone_fa": "fa-walkie-talkie"}]' class="w-full p-2 border rounded font-mono text-xs mb-3 focus:outline-none focus:border-blue-500"></textarea>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded text-sm transition">Importar Lote JSON</button>
                    </form>
                </div>

                <div class="bg-white shadow rounded overflow-hidden border">
                    <div class="p-3 bg-slate-50 border-b flex gap-3 items-center">
                        <input type="text" id="template-search" placeholder="Pesquisar catálogo..." onkeyup="filterTemplatesTable()" class="flex-1 p-2 border rounded text-sm">
                        <select id="template-cat-filter" onchange="filterTemplatesTable()" class="p-2 border rounded text-sm bg-white">
                            <option value="">Todas as Categorias</option>
                            <?php foreach ($categorias_db as $catName): ?>
                                <option value="<?= htmlspecialchars($catName) ?>"><?= htmlspecialchars($catName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
                        <table class="min-w-full divide-y divide-slate-200" id="templates-table">
                            <thead class="bg-slate-50 sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-slate-500 uppercase">Ícone</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Título / Categoria</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Descrição & Ação Esperada</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-slate-500 uppercase">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                <?php foreach ($templates as $t): ?>
                                    <tr class="hover:bg-slate-50 transition template-row" data-category="<?= htmlspecialchars($t['categoria']) ?>">
                                        <td class="px-4 py-4 whitespace-nowrap text-center text-xl text-slate-500">
                                            <i class="fa-solid <?= htmlspecialchars($t['icone_fa']) ?>"></i>
                                        </td>
                                        <td class="px-4 py-4">
                                            <span class="inline-block bg-blue-100 text-blue-800 text-[10px] font-bold px-2 py-0.5 rounded uppercase mb-1"><?= htmlspecialchars($t['categoria']) ?></span>
                                            <div class="font-bold text-slate-800 text-sm template-title"><?= htmlspecialchars($t['titulo']) ?></div>
                                        </td>
                                        <td class="px-4 py-4 text-xs text-slate-600">
                                            <div class="mb-1 template-desc"><strong>Situação:</strong> <?= htmlspecialchars($t['descricao']) ?></div>
                                            <div class="text-green-700 bg-green-50 p-1.5 rounded border border-green-100 template-action"><strong>Avaliação:</strong> <?= htmlspecialchars($t['acao_esperada']) ?></div>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap text-center text-sm font-medium">
                                            <div class="flex justify-center items-center gap-2">
                                                <button type="button" onclick="loadTemplateIntoForm(<?= $t['id'] ?>, '<?= addslashes(htmlspecialchars($t['titulo'])) ?>', '<?= addslashes(htmlspecialchars($t['categoria'])) ?>', '<?= addslashes(htmlspecialchars($t['descricao'])) ?>', '<?= addslashes(htmlspecialchars($t['acao_esperada'])) ?>', '<?= addslashes(htmlspecialchars($t['icone_fa'])) ?>')" class="text-blue-500 hover:text-blue-700 p-1" title="Editar"><i class="fa-solid fa-pen-to-square"></i></button>
                                                <form method="POST" class="inline m-0 p-0">
                                                    <input type="hidden" name="action" value="clone_template">
                                                    <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
                                                    <button type="submit" class="text-slate-500 hover:text-slate-800 p-1" title="Clonar"><i class="fa-solid fa-copy"></i></button>
                                                </form>
                                                <form method="POST" class="inline m-0 p-0" onsubmit="return confirm('Deseja excluir este template?');">
                                                    <input type="hidden" name="action" value="delete_template">
                                                    <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
                                                    <button type="submit" class="text-red-500 hover:text-red-700 p-1" title="Excluir"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- RELATÓRIO AVANÇADO -->
            <section id="tab-relatorio" class="tab-content">
                <h2 class="text-2xl font-bold text-slate-800 mb-6 border-b pb-2">Relatório Avançado: <?= $cenarioAtivo ? htmlspecialchars($cenarioAtivo['nome']) : '' ?></h2>
                <?php if ($cenarioAtivo): ?>
                    
                    <div class="bg-white p-4 rounded shadow border mb-6 flex gap-4 items-center">
                        <div class="flex-1 relative">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-400"></i>
                            <input type="text" id="hist-search" placeholder="Buscar incidente ou local..." class="w-full pl-10 p-2 border rounded text-sm" oninput="updateHistoryView()">
                        </div>
                        <div class="w-64">
                            <select id="hist-cat" class="w-full p-2 border rounded text-sm bg-white" onchange="updateHistoryView()">
                                <option value="ALL">Todas as Categorias</option>
                                <?php foreach ($categorias_db as $catName): ?>
                                    <option value="<?= htmlspecialchars($catName) ?>"><?= htmlspecialchars($catName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Cartões de Métricas -->
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
                        <div class="bg-white p-3 rounded shadow border text-center">
                            <div class="text-slate-500 text-[10px] font-bold uppercase mb-1">Total</div>
                            <div class="text-2xl font-black text-slate-700" id="met-total">0</div>
                        </div>
                        <div class="bg-white p-3 rounded shadow border border-green-200 text-center">
                            <div class="text-green-600 text-[10px] font-bold uppercase mb-1">Acertos (SIM)</div>
                            <div class="text-2xl font-black text-green-500" id="met-sim">0</div>
                        </div>
                        <div class="bg-white p-3 rounded shadow border border-red-200 text-center">
                            <div class="text-red-600 text-[10px] font-bold uppercase mb-1">Erros (NÃO)</div>
                            <div class="text-2xl font-black text-red-500" id="met-nao">0</div>
                        </div>
                        <div class="bg-white p-3 rounded shadow border border-blue-200 text-center">
                            <div class="text-blue-600 text-[10px] font-bold uppercase mb-1">Apenas OBS</div>
                            <div class="text-2xl font-black text-blue-500" id="met-obs">0</div>
                        </div>
                        <div class="bg-white p-3 rounded shadow border text-center">
                            <div class="text-slate-500 text-[10px] font-bold uppercase mb-1">Média / Mediana</div>
                            <div class="text-lg font-bold text-slate-700"><span id="met-avg">0m</span> / <span id="met-med">0m</span></div>
                        </div>
                        <div class="bg-slate-800 p-3 rounded shadow text-center text-white">
                            <div class="text-slate-300 text-[10px] font-bold uppercase mb-1">Mín / Máx</div>
                            <div class="text-lg font-bold"><span id="met-min">0m</span> - <span id="met-max">0m</span></div>
                        </div>
                    </div>

                    <!-- Gráficos Responsivos -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="bg-white p-4 rounded shadow border h-[300px] flex flex-col items-center justify-center">
                            <h3 class="text-sm font-bold text-slate-700 mb-2">Desempenho Geral</h3>
                            <div class="relative w-full h-[230px]">
                                <canvas id="chart-results"></canvas>
                            </div>
                        </div>
                        <div class="bg-white p-4 rounded shadow border h-[300px] flex flex-col items-center justify-center">
                            <h3 class="text-sm font-bold text-slate-700 mb-2">Incidentes por Categoria</h3>
                            <div class="relative w-full h-[230px]">
                                <canvas id="chart-categories"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white shadow rounded overflow-hidden border">
                        <div class="overflow-x-auto max-h-[500px] overflow-y-auto">
                            <table class="min-w-full divide-y divide-slate-200" id="historico-table">
                                <thead class="bg-slate-50 sticky top-0 z-10">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Data/Hora</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Local & Categoria</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Incidente</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-slate-500 uppercase">Desempenho</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Obs</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-slate-500 uppercase">Tempo</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-slate-500 uppercase">Ações</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 bg-white">
                                    <?php foreach ($historico as $h): 
                                        $resolvido = $h['resolvido_em'] ? strtotime($h['resolvido_em']) : strtotime($h['criado_em']);
                                        $criado = strtotime($h['criado_em']);
                                        $diffMinutes = max(0, round(($resolvido - $criado) / 60));
                                    ?>
                                        <tr class="hover:bg-slate-50 transition hist-row" 
                                            data-category="<?= htmlspecialchars($h['categoria']) ?>" 
                                            data-result="<?= htmlspecialchars($h['resultado_barema']) ?>"
                                            data-duration="<?= $diffMinutes ?>"
                                            data-search="<?= strtolower(htmlspecialchars($h['titulo'] . ' ' . $h['local_nome'] . ' ' . $h['observacao_barema'])) ?>">
                                            
                                            <td class="px-4 py-3 whitespace-nowrap text-[10px] text-slate-500">
                                                Início: <?= date('d/m H:i', $criado) ?><br>
                                                Fim: <?= $h['resolvido_em'] ? date('d/m H:i', $resolvido) : '--' ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-xs">
                                                <div class="font-bold text-slate-800"><?= htmlspecialchars($h['local_nome']) ?></div>
                                                <div class="text-blue-600 uppercase text-[10px] font-bold"><?= htmlspecialchars($h['categoria']) ?></div>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-slate-700 font-medium"><?= htmlspecialchars($h['titulo']) ?></td>
                                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                                <?php if ($h['resultado_barema'] == 'SIM'): ?>
                                                    <span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-1 rounded">SIM</span>
                                                <?php elseif ($h['resultado_barema'] == 'NÃO'): ?>
                                                    <span class="bg-red-100 text-red-800 text-xs font-bold px-2 py-1 rounded">NÃO</span>
                                                <?php else: ?>
                                                    <span class="bg-blue-100 text-blue-800 text-xs font-bold px-2 py-1 rounded">OBS</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-xs text-slate-500 max-w-[200px] truncate" title="<?= htmlspecialchars($h['observacao_barema']) ?>">
                                                <?= htmlspecialchars($h['observacao_barema']) ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-center text-xs font-bold text-slate-600">
                                                <?= $diffMinutes ?>m
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-center text-sm font-medium">
                                                <button type="button" onclick="openEditHist(<?= $h['id'] ?>, '<?= $h['resultado_barema'] ?>', '<?= addslashes(htmlspecialchars($h['observacao_barema'])) ?>')" class="text-blue-600 hover:text-blue-900 mr-2" title="Editar Avaliação"><i class="fa-solid fa-pen"></i></button>
                                                <form method="POST" class="inline m-0 p-0" onsubmit="return confirm('Apagar registro definitivamente?')">
                                                    <input type="hidden" name="action" value="delete_historico">
                                                    <input type="hidden" name="incidente_id" value="<?= $h['id'] ?>">
                                                    <button type="submit" class="text-red-600 hover:text-red-900" title="Apagar Registro"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Modal Edição Histórico -->
                    <div id="modal-edit-hist" class="hidden fixed inset-0 bg-slate-900 bg-opacity-50 flex items-center justify-center z-50">
                        <div class="bg-white p-6 rounded shadow-lg w-full max-w-sm">
                            <h3 class="font-bold text-lg mb-4 text-slate-800">Corrigir Avaliação do Barema</h3>
                            <form method="POST">
                                <input type="hidden" name="action" value="edit_historico">
                                <input type="hidden" name="incidente_id" id="edit_hist_id">
                                <div class="mb-4">
                                    <label class="block text-sm font-bold text-slate-700 mb-1">Desempenho</label>
                                    <select name="resultado" id="edit_hist_resultado" class="w-full p-2 border rounded" required>
                                        <option value="SIM">SIM</option>
                                        <option value="NÃO">NÃO</option>
                                        <option value="OBS">Apenas Observação (OBS)</option>
                                    </select>
                                </div>
                                <div class="mb-6">
                                    <label class="block text-sm font-bold text-slate-700 mb-1">Observações do Avaliador</label>
                                    <input type="text" name="obs" id="edit_hist_obs" class="w-full p-2 border rounded">
                                </div>
                                <div class="flex justify-end gap-2">
                                    <button type="button" onclick="closeEditHist()" class="px-4 py-2 bg-slate-200 text-slate-800 rounded font-bold hover:bg-slate-300">Cancelar</button>
                                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded font-bold hover:bg-blue-700">Salvar Alteração</button>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php endif; ?>
            </section>

            <!-- MUDAR / GERENCIAR CENÁRIOS -->
            <section id="tab-cenarios" class="tab-content">
                <h2 class="text-2xl font-bold text-slate-800 mb-6 border-b pb-2">Gerenciar Cenários</h2>
                
                <form method="POST" class="bg-white p-5 rounded shadow border mb-6 flex gap-4 items-end">
                    <input type="hidden" name="action" value="add_cenario">
                    <div class="flex-1">
                        <label class="block text-sm font-bold text-slate-700 mb-1">Nome do Exercício/Cenário</label>
                        <input type="text" name="nome" required placeholder="Ex: Operação GLO - Julho 2026" class="w-full p-2 border rounded">
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-bold text-slate-700 mb-1">Descrição Curta</label>
                        <input type="text" name="descricao" class="w-full p-2 border rounded">
                    </div>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded"><i class="fa-solid fa-plus mr-1"></i> Criar Cenário</button>
                </form>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($cenarios as $c): ?>
                        <div class="bg-white p-5 rounded shadow border relative <?= $c['id'] == $cenarioAtivoId ? 'ring-2 ring-blue-500' : '' ?>">
                            <div class="absolute top-2 right-2 flex gap-1">
                                <button type="button" onclick="openEditCenario(<?= $c['id'] ?>, '<?= addslashes(htmlspecialchars($c['nome'])) ?>', '<?= addslashes(htmlspecialchars($c['descricao'])) ?>')" class="p-1 text-slate-400 hover:text-blue-500" title="Editar"><i class="fa-solid fa-pen"></i></button>
                                <form method="POST" class="inline m-0 p-0" onsubmit="return confirm('AVISO: Excluir um cenário apagará locais e históricos associados. Continuar?');">
                                    <input type="hidden" name="action" value="delete_cenario">
                                    <input type="hidden" name="cenario_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="p-1 text-slate-400 hover:text-red-500" title="Excluir"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </div>

                            <div class="flex justify-between items-start mb-2 pr-12">
                                <h4 class="font-bold text-lg text-slate-800"><?= htmlspecialchars($c['nome']) ?></h4>
                            </div>
                            <p class="text-sm text-slate-500 mb-4 h-10 overflow-hidden"><?= htmlspecialchars($c['descricao']) ?></p>
                            
                            <?php if ($c['id'] == $cenarioAtivoId): ?>
                                <button disabled class="w-full bg-blue-100 text-blue-800 font-bold py-2 rounded text-sm cursor-default border border-blue-200">
                                    <i class="fa-solid fa-circle-check mr-1"></i> Cenário Atual
                                </button>
                            <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="set_cenario">
                                    <input type="hidden" name="cenario_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold py-2 rounded text-sm transition">
                                        Carregar este Cenário
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Modal Edição de Cenário -->
                <div id="modal-edit-cenario" class="hidden fixed inset-0 bg-slate-900 bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white p-6 rounded shadow-lg w-full max-w-md">
                        <h3 class="font-bold text-lg mb-4 text-slate-800">Editar Cenário</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="edit_cenario">
                            <input type="hidden" name="cenario_id" id="edit_cen_id">
                            <div class="mb-4">
                                <label class="block text-sm font-bold text-slate-700 mb-1">Nome</label>
                                <input type="text" name="nome" id="edit_cen_nome" required class="w-full p-2 border rounded">
                            </div>
                            <div class="mb-6">
                                <label class="block text-sm font-bold text-slate-700 mb-1">Descrição</label>
                                <input type="text" name="descricao" id="edit_cen_desc" class="w-full p-2 border rounded">
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" onclick="document.getElementById('modal-edit-cenario').classList.add('hidden')" class="px-4 py-2 bg-slate-200 text-slate-800 rounded font-bold hover:bg-slate-300">Cancelar</button>
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded font-bold hover:bg-blue-700">Salvar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script>
        // Limpar query string ?msg=
        if (window.history.replaceState && window.location.search.includes('msg=')) {
            const url = new URL(window.location);
            url.searchParams.delete('msg');
            window.history.replaceState(null, null, url);
        }

        function showTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('aside nav button').forEach(el => {
                el.classList.remove('text-blue-700', 'bg-blue-50');
                el.classList.add('text-slate-600');
            });
            document.getElementById(tabId).classList.add('active');
            const btn = document.getElementById('btn-' + tabId);
            if(btn) {
                btn.classList.remove('text-slate-600');
                btn.classList.add('text-blue-700', 'bg-blue-50');
            }
            if(tabId === 'tab-relatorio') { updateHistoryView(); }
        }

        function selectIcon(iconClass) {
            document.getElementById('icone_fa_input').value = iconClass;
            document.querySelectorAll('.icon-select-btn').forEach(btn => {
                if (btn.getAttribute('data-icon') === iconClass) {
                    btn.classList.add('bg-blue-100', 'border-blue-500', 'text-blue-700', 'ring-2', 'ring-blue-500');
                    btn.classList.remove('bg-white', 'border-slate-200', 'text-slate-600');
                } else {
                    btn.classList.remove('bg-blue-100', 'border-blue-500', 'text-blue-700', 'ring-2', 'ring-blue-500');
                    btn.classList.add('bg-white', 'border-slate-200', 'text-slate-600');
                }
            });
        }

        function loadTemplateIntoForm(id, titulo, categoria, descricao, acao, icone) {
            document.getElementById('form-template-action').value = 'save_template';
            document.getElementById('form-template-id').value = id;
            document.getElementById('form-template-title').innerText = 'Editar Incidente Cadastrado';
            document.getElementById('btn-template-submit').innerHTML = '<i class="fa-solid fa-sync mr-1"></i> Atualizar Incidente';
            document.querySelector('input[name="titulo"]').value = titulo;
            document.querySelector('select[name="categoria"]').value = categoria;
            document.querySelector('textarea[name="descricao"]').value = descricao;
            document.querySelector('input[name="acao_esperada"]').value = acao;
            selectIcon(icone || 'fa-triangle-exclamation');
            document.getElementById('btn-template-cancel').classList.remove('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function cancelEditTemplate() {
            document.getElementById('form-template-action').value = 'save_template';
            document.getElementById('form-template-id').value = '';
            document.getElementById('form-template-title').innerText = 'Adicionar Novo Incidente';
            document.getElementById('btn-template-submit').innerHTML = '<i class="fa-solid fa-save mr-1"></i> Salvar Incidente';
            document.querySelector('input[name="titulo"]').value = '';
            document.querySelector('select[name="categoria"]').value = '';
            document.querySelector('textarea[name="descricao"]').value = '';
            document.querySelector('input[name="acao_esperada"]').value = '';
            selectIcon('fa-triangle-exclamation');
            document.getElementById('btn-template-cancel').classList.add('hidden');
        }

        function filterTemplatesTable() {
            const searchInput = document.getElementById('template-search').value.toLowerCase();
            const catFilter = document.getElementById('template-cat-filter').value;
            const rows = document.querySelectorAll('.template-row');
            rows.forEach(row => {
                const title = row.querySelector('.template-title').innerText.toLowerCase();
                const desc = row.querySelector('.template-desc').innerText.toLowerCase();
                const category = row.getAttribute('data-category');
                const matchesSearch = title.includes(searchInput) || desc.includes(searchInput);
                const matchesCat = !catFilter || category === catFilter;
                row.style.display = (matchesSearch && matchesCat) ? '' : 'none';
            });
        }

        function openEditHist(id, resultado, obs) {
            document.getElementById('edit_hist_id').value = id;
            document.getElementById('edit_hist_resultado').value = resultado;
            document.getElementById('edit_hist_obs').value = obs;
            document.getElementById('modal-edit-hist').classList.remove('hidden');
        }
        function closeEditHist() { document.getElementById('modal-edit-hist').classList.add('hidden'); }
        
        function openEditCenario(id, nome, desc) {
            document.getElementById('edit_cen_id').value = id;
            document.getElementById('edit_cen_nome').value = nome;
            document.getElementById('edit_cen_desc').value = desc;
            document.getElementById('modal-edit-cenario').classList.remove('hidden');
        }

        function toggleCategoryGenerator(localId, categoria, isChecked, intervalo) {
            const formData = new URLSearchParams();
            formData.append('action', 'toggle_cat_generator');
            formData.append('local_id', localId);
            formData.append('categoria', categoria);
            formData.append('status', isChecked ? 1 : 0);
            formData.append('intervalo', intervalo);
            fetch('index.php', { method: 'POST', body: formData }).then(() => {
                if(isChecked) {
                    // Recarrega levemente após disparo imediato para exibir o novo incidente
                    setTimeout(() => { window.location.reload(); }, 600);
                }
            });
        }

        function evaluateIncidente(incidenteId, resultado) {
            const obsValue = document.getElementById('obs_' + incidenteId).value;
            const formData = new URLSearchParams();
            formData.append('action', 'evaluate');
            formData.append('incidente_id', incidenteId);
            formData.append('resultado', resultado);
            formData.append('obs', obsValue);
            fetch('index.php', { method: 'POST', body: formData }).then(() => {
                const card = document.getElementById('inc_card_' + incidenteId);
                if (card) card.remove();
            });
        }

        function filterPendentes() {
            const filterVal = document.getElementById('filter-pendentes-categoria').value;
            const cards = document.querySelectorAll('.inc-pendente-card');
            cards.forEach(card => {
                if (!filterVal || card.getAttribute('data-category') === filterVal) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                    const chk = card.querySelector('.chk-pendente');
                    if (chk) chk.checked = false;
                }
            });
            document.getElementById('chk-all-pendentes').checked = false;
        }

        function toggleAllPendentes(source) {
            document.querySelectorAll('.chk-pendente').forEach(chk => {
                if (chk.closest('.inc-pendente-card').style.display !== 'none') chk.checked = source.checked;
            });
        }

        function evaluateBulk(resultado) {
            const ids = Array.from(document.querySelectorAll('.chk-pendente:checked')).map(c => c.value);
            if (ids.length === 0) return;
            const obsValue = document.getElementById('obs_bulk').value;
            const formData = new URLSearchParams();
            formData.append('action', 'evaluate_bulk');
            formData.append('incidentes_ids', ids.join(','));
            formData.append('resultado', resultado);
            formData.append('obs', obsValue);
            fetch('index.php', { method: 'POST', body: formData }).then(() => {
                ids.forEach(id => document.getElementById('inc_card_' + id)?.remove());
                document.getElementById('chk-all-pendentes').checked = false;
                document.getElementById('obs_bulk').value = '';
            });
        }

        let chartResultsInstance = null;
        let chartCatsInstance = null;

        function updateHistoryView() {
            const searchVal = document.getElementById('hist-search').value.toLowerCase();
            const catVal = document.getElementById('hist-cat').value;
            const rows = document.querySelectorAll('.hist-row');
            
            let sim=0, nao=0, obs=0, total=0;
            let durations = [];
            let catCount = {};

            rows.forEach(row => {
                const matchesSearch = row.getAttribute('data-search').includes(searchVal);
                const matchesCat = (catVal === 'ALL' || row.getAttribute('data-category') === catVal);
                
                if(matchesSearch && matchesCat) {
                    row.style.display = '';
                    total++;
                    
                    const result = row.getAttribute('data-result');
                    if(result === 'SIM') sim++;
                    if(result === 'NÃO') nao++;
                    if(result === 'OBS') obs++;
                    
                    const cat = row.getAttribute('data-category');
                    catCount[cat] = (catCount[cat] || 0) + 1;

                    const dur = parseInt(row.getAttribute('data-duration'));
                    if(!isNaN(dur)) durations.push(dur);
                } else {
                    row.style.display = 'none';
                }
            });

            // Métricas estatísticas de tempo
            let sum=0, min=0, max=0, avg=0, med=0;
            if(durations.length > 0) {
                durations.sort((a,b) => a - b);
                min = durations[0];
                max = durations[durations.length - 1];
                sum = durations.reduce((a,b) => a + b, 0);
                avg = Math.round(sum / durations.length);
                const mid = Math.floor(durations.length / 2);
                med = durations.length % 2 !== 0 ? durations[mid] : Math.round((durations[mid - 1] + durations[mid]) / 2);
            }

            document.getElementById('met-total').innerText = total;
            document.getElementById('met-sim').innerText = sim;
            document.getElementById('met-nao').innerText = nao;
            document.getElementById('met-obs').innerText = obs;
            document.getElementById('met-avg').innerText = avg + 'm';
            document.getElementById('met-med').innerText = med + 'm';
            document.getElementById('met-min').innerText = min + 'm';
            document.getElementById('met-max').innerText = max + 'm';

            // Gráficos Chart.js
            if(chartResultsInstance) chartResultsInstance.destroy();
            const ctxR = document.getElementById('chart-results').getContext('2d');
            chartResultsInstance = new Chart(ctxR, {
                type: 'doughnut',
                data: {
                    labels: ['SIM', 'NÃO', 'OBS'],
                    datasets: [{ data: [sim, nao, obs], backgroundColor: ['#22c55e', '#ef4444', '#3b82f6'], borderWidth: 0 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });

                if(chartCatsInstance) chartCatsInstance.destroy();
                const ctxC = document.getElementById('chart-categories').getContext('2d');
                chartCatsInstance = new Chart(ctxC, {
                    type: 'bar',
                    data: {
                        labels: Object.keys(catCount),
                        datasets: [{ label: 'Qtd', data: Object.values(catCount), backgroundColor: '#64748b', borderRadius: 4 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
                });
            }

                                                                        // Configuração Dinâmica do Auto-Refresh e Proteção contra recarga durante digitação
                                                                        let autoRefreshTimer = null;
                                                                        let refreshInterval = 30000; // Padrão: 30 segundos
                                                                        let isAutoRefreshActive = true;

                                                                        function updateRefreshSettings() {
                const toggle = document.getElementById('refresh-toggle-chk');
                const select = document.getElementById('refresh-interval-select');

                isAutoRefreshActive = toggle ? toggle.checked : true;
                if (select) {
                    refreshInterval = parseInt(select.value) * 1000;
                }
                restartPolling();
            }

            function restartPolling() {
                if (autoRefreshTimer) clearInterval(autoRefreshTimer);
                if (!isAutoRefreshActive || refreshInterval <= 0) return;

                autoRefreshTimer = setInterval(() => {
                    // Pausa o recarregamento automático se o usuário estiver focado digitando em algum campo
                    const activeEl = document.activeElement;
                    const isTyping = activeEl && (
                        activeEl.tagName === 'INPUT' || 
                        activeEl.tagName === 'TEXTAREA' || 
                        activeEl.tagName === 'SELECT'
                    );

                    if (isTyping) {
                        return; 
                    }

                    const formData = new URLSearchParams({ action: 'auto_tick' });
                    fetch('index.php', { method: 'POST', body: formData })
                        .then(res => res.json())
                        .then(data => {
                        window.location.reload();
                    }).catch(() => {
                        const syncStatus = document.getElementById('sync-status');
                        if(syncStatus) syncStatus.innerHTML = '<span class="text-red-500">Erro de Sinc.</span>';
                    });
                }, refreshInterval);
            }

            // Inicializar no carregamento
            window.addEventListener('DOMContentLoaded', () => {
                restartPolling();
                if(document.getElementById('tab-relatorio').classList.contains('active')) {
                    updateHistoryView();
                }
            });
    </script>
    </body>
</html>