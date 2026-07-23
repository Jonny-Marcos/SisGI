<?php
// setup.php - Criação e Configuração do Banco de Dados Modular

$arquivoBanco = __DIR__ . '/banco.db';
$bancoJaExiste = file_exists($arquivoBanco);

try {
    $db = new PDO('sqlite:' . $arquivoBanco);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $mensagemSistema = "";

    if (!$bancoJaExiste) {
        // 1. CRIAÇÃO DAS TABELAS MODULARES
        $db->exec("
            -- Tabela de Cenários (Permite reabrir históricos)
            CREATE TABLE IF NOT EXISTS cenarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                descricao TEXT,
                status TEXT DEFAULT 'ATIVO', -- 'ATIVO', 'FINALIZADO'
                criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            -- Tabela de Locais/Postos (Vinculados a um Cenário)
            CREATE TABLE IF NOT EXISTS locais (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cenario_id INTEGER NOT NULL,
                nome TEXT NOT NULL,
                gerador_ativo INTEGER DEFAULT 0, -- 0 (Inativo), 1 (Ativo)
                intervalo_minutos INTEGER DEFAULT 5,
                criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(cenario_id) REFERENCES cenarios(id) ON DELETE CASCADE
            );

            -- Tabela de Templates (Catálogo de Incidentes baseados no Barema)
            CREATE TABLE IF NOT EXISTS templates_incidentes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                titulo TEXT NOT NULL,
                categoria TEXT NOT NULL,
                descricao TEXT NOT NULL,
                acao_esperada TEXT NOT NULL,
                icone_fa TEXT DEFAULT 'fa-triangle-exclamation'
            );

            -- Tabela de Incidentes Disparados (O que realmente aconteceu no terreno)
            CREATE TABLE IF NOT EXISTS incidentes_disparados (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                template_id INTEGER NOT NULL,
                local_id INTEGER NOT NULL,
                status TEXT DEFAULT 'PENDENTE', -- 'PENDENTE', 'RESOLVIDO'
                resultado_barema TEXT DEFAULT 'N/A', -- 'SIM', 'NÃO', 'OBS', 'N/A'
                observacao_barema TEXT,
                criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(template_id) REFERENCES templates_incidentes(id),
                FOREIGN KEY(local_id) REFERENCES locais(id) ON DELETE CASCADE
            );
        ");

        // 2. INSERÇÃO DE DADOS INICIAIS (SEED)
        
        // Criar o Cenário Inicial baseado na Ordem de Instrução
        $stmtCenario = $db->prepare("INSERT INTO cenarios (nome, descricao) VALUES (?, ?)");
        $stmtCenario->execute(['Campo da IIQ e Op Estb', 'Exercício de Qualificação - Julho 2026']);
        $cenarioId = $db->lastInsertId();

        // Criar Locais previstos no QTS vinculados ao Cenário
        $stmtLocal = $db->prepare("INSERT INTO locais (cenario_id, nome, gerador_ativo, intervalo_minutos) VALUES (?, ?, ?, ?)");
        $stmtLocal->execute([$cenarioId, 'SEF (6º B Com)', 0, 5]);
        $stmtLocal->execute([$cenarioId, 'PPM (8ª Cia Com)', 0, 5]);
        $stmtLocal->execute([$cenarioId, 'Centro de Mensagens', 0, 10]);
        $stmtLocal->execute([$cenarioId, 'Reserva de Armamento', 0, 15]);

        // Criar Templates baseados no Barema
        $stmtTemplate = $db->prepare("INSERT INTO templates_incidentes (titulo, categoria, descricao, acao_esperada, icone_fa) VALUES (?, ?, ?, ?, ?)");
        
        // Comunicações
        $stmtTemplate->execute([
            'Perda de Enlace Rádio', 
            'Comunicações', 
            'O Posto reporta perda de comunicação. Necessário reconfigurar o canal criptografado e realizar nova abertura da rede rádio.', 
            'Configurar o canal e reestabelecer o fluxo de mensagens.', 
            'fa-walkie-talkie'
        ]);

        // C2
        $stmtTemplate->execute([
            'Atualização de Panorama C2', 
            'C2', 
            'Detectada movimentação. Atualizar a Carta de Meios e lançar evoluções no Pacificador.', 
            'Acessar C2 Cmb via IP, lançar calunga/evolução no mapa.', 
            'fa-laptop-code'
        ]);

        // Saúde
        $stmtTemplate->execute([
            'Ferido Leve no Posto', 
            'Saúde', 
            'Militar machucado durante instrução. Aferir sinais vitais e preparar padiola.', 
            'Executar protocolo de APH e imobilização.', 
            'fa-kit-medical'
        ]);

        // Manutenção
        $stmtTemplate->execute([
            'Pane na Viatura', 
            'Logística', 
            'Viatura de apoio falhou ao ligar. Identificar a pane técnica.', 
            'Executar operações de manutenção técnica auto e garantir segurança.', 
            'fa-wrench'
        ]);

        $mensagemSistema = "<div class='success'>✅ Banco de dados modular criado e populado com Cenários, Locais e Templates com sucesso!</div>";
    } else {
        $mensagemSistema = "<div class='info'>ℹ️ O arquivo banco.db já existe. Apague-o se desejar recriar a estrutura do zero.</div>";
    }

} catch (PDOException $e) {
    $mensagemSistema = "<div class='error'>❌ Erro ao configurar o banco de dados: " . $e->getMessage() . "</div>";
}
?>
<!DOCTYPE html>
<html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <title>Setup - Simulador de Incidentes</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; padding: 40px; color: #334155; }
            .container { background-color: white; padding: 30px; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); max-width: 650px; margin: auto; border-top: 5px solid #2563eb; }
            h1 { color: #1e293b; margin-top: 0; }
            .success { color: #15803d; background-color: #dcfce7; padding: 15px; border-radius: 8px; border: 1px solid #bbf7d0; font-weight: 500; }
            .info { color: #1d4ed8; background-color: #dbeafe; padding: 15px; border-radius: 8px; border: 1px solid #bfdbfe; font-weight: 500; }
            .error { color: #b91c1c; background-color: #fee2e2; padding: 15px; border-radius: 8px; border: 1px solid #fecaca; font-weight: 500; }
            .btn { display: inline-block; margin-top: 25px; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; transition: background 0.3s; }
            .btn:hover { background: #1d4ed8; }
            .details { margin-top: 20px; font-size: 0.9em; color: #64748b; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>⚙️ Setup do Sistema</h1>
            <?php echo $mensagemSistema; ?>

            <div class="details">
                <p><strong>Arquivo gerado em:</strong> <?php echo $arquivoBanco; ?></p>
                <p><strong>Tabelas criadas:</strong> cenarios, locais, templates_incidentes, incidentes_disparados.</p>
            </div>

            <a href="index.php" class="btn">Ir para o Painel do Instrutor (index.php) ➔</a>
        </div>
    </body>
</html>