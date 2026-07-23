# SisGI - Sistema de Geração de Incidentes

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-blue.svg)](https://php.net/)

O **SisGI - Sistema de Geração de Incidentes** é uma aplicação web projetada para auxiliar na simulação e gerenciamento de incidentes em tempo real. Esta aplicação foi desenvolvida pelo Capitão de Comunicações Barbosa Oliveira, no 6º Batalhão de Comunicações, com o objetivo de apoiar as instruções práticas do período de qualificação e adestramento em Organizações Militares do Exército Brasileiro, mas pode ser utilizado livremente por outras organizações e empresas.

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

2. Certifique-se de que a pasta onde o arquivo index.php está localizado possui permissões de escrita para que o SQLite possa criar e atualizar o arquivo do banco de dados automaticamente.

3. Acesse o sistema pelo navegador. Na primeira execução, as tabelas e estruturas padrão serão configuradas.

---

## 💡 Casos de Uso
Embora idealizado para treinamentos táticos e de comunicações, o SisGI pode ser adaptado para diversos cenários de crise:
- Defesa Civil: Simulação de incidentes em desastres naturais e respostas rápidas.
- Cibersegurança: Tabletop exercises e testes de resposta a incidentes de TI.
- Saúde: Gestão de incidentes com múltiplas vítimas (MCI) e esgotamento de suprimentos.

---

## 📄 Licença
Este projeto é distribuído sob a licença MIT. Sinta-se à vontade para contribuir, modificar e adaptar para os seus treinamentos!
