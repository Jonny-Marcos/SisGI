# SisGI - Sistema de Geração de Incidentes

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%キャラクター-blue.svg)](https://php.net/)

O **SisGI** é uma ferramenta web leve, rápida e minimalista desenvolvida em **PHP puro** e **SQLite**, criada com o objetivo de realizar injeção controlada de incidentes operacionais, simulações de terreno e avaliação de tempo de resposta em tempo real.

---

## 🚀 Principais Funcionalidades

- **Gerenciamento de Cenários e Locais:** Crie e edite cenários de treinamento e postos de comando avançados.
- **Geradores Automáticos por Categoria:** Disparo automático de incidentes baseado em intervalos configuráveis (Comunicações, C2, Logística, Saúde, etc.).
- **Catálogo Barema:** Sistema flexível de templates com ações esperadas e importação/exportação rápida via JSON.
- **Painel de Avaliação em Tempo Real:** Avaliação instantânea de incidentes pendentes (SIM / NÃO / APENAS OBS) com fechamento em massa.
- **Relatórios Avançados e Métricas:** Gráficos dinâmicos integrados (Chart.js) e cálculos automáticos de tempo de resolução (Média, Mediana, Mínimo e Máximo).
- **Modo Instruendo (Telão):** Tela de visualização limpa e restrita para acompanhamento em tempo real pelos operadores no terreno.

---

## 🛠️ Requisitos de Instalação

Como o sistema foi desenhado para ser enxuto e pragmático, os requisitos são mínimos:
- Servidor Web com suporte a **PHP 7.4 ou superior** (Apache ou Nginx).
- Extensões PHP habilitadas: `PDO` e `SQLite3`.

---

## 📦 Instalação Rápida

1. Clone ou baixe o repositório para o diretório raiz do seu servidor web:
   ```bash
   git clone [https://github.com/Jonny-Marcos/SisGI.git](https://github.com/Jonny-Marcos/SisGI.git)

   Certifique-se de que a pasta onde o arquivo index.php está localizado possui permissões de escrita para que o SQLite possa criar e atualizar o arquivo do banco de dados automaticamente.

2. Acesse o sistema pelo navegador. Na primeira execução, as tabelas e estruturas padrão serão configuradas.

---

## 💡 Casos de Uso
Embora idealizado para treinamentos táticos e de comunicações, o SisGI pode ser adaptado para diversos cenários de crise:
- Defesa Civil: Simulação de incidentes em desastres naturais e respostas rápidas.
- Cibersegurança: Tabletop exercises e testes de resposta a incidentes de TI.
- Saúde: Gestão de incidentes com múltiplas vítimas (MCI) e esgotamento de suprimentos.

---

## 📄 Licença
Este projeto é distribuído sob a licença MIT. Sinta-se à vontade para contribuir, modificar e adaptar para os seus treinamentos!
