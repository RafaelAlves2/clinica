# Sistema de Gestão de Clínica Dentária

Aplicação web em PHP/MySQL para gestão de clínica dentária (pacientes, marcações, pagamentos, conteúdos, stock e painel administrativo).

## Tecnologias
- PHP 8.x (WAMP/Apache), tipagem estrita em vários módulos
- MySQL/MariaDB via PDO (prepared statements)
- HTML renderizado no servidor, `css/estilo.css`, JavaScript vanilla (`js/tema.js`, `js/animacoes.js`)

## Estrutura (profissional, compatível)
- **Raiz**: entry points (páginas públicas, autenticação/área pessoal, marcações, pagamentos, políticas/preferências). URLs mantidas para compatibilidade.
- **`admin/`**: painel administrativo (RBAC por perfil).
- **`includes/`**: componentes partilhados com **wrappers de compatibilidade** nos caminhos originais.
  - `includes/core/`: `config.php`, `conteudo.php`, `cookies.php`
  - `includes/servicos/`: `gateway.php`, `pagamentos.php`, `marcacao.php`, `gestao.php`, `stock.php`, `conta.php`, `consulta-preparacao.php`
  - `includes/ui/`: `cabecalho.php`, `rodape.php`, `hero.php`, `banner-cookies.php`, `numeros.php`, `parceiros.php`, `redes-sociais.php`, `testemunhos.php`, `whatsapp.php`
- **`css/`**, **`js/`**: recursos de interface.
- **`migracoes/`**: SQL aditivo (rever/backup antes de executar).
- **`uploads/`**: ficheiros enviados (protegido por `.htaccess`; `cv/` com proteção própria).
- **`docs/`**: documentação interna (`ESTRUTURA.md`, `ARQUITETURA.md`, `ALTERACOES.md`; acesso web negado).
- **`logs/`**: registos locais (acesso web negado; ignorado por git).

Detalhes: `docs/ESTRUTURA.md` e `docs/ARQUITETURA.md`.

## Instalação (WampServer, local)
1. Confirmar Apache/MySQL ativos no WampServer.
2. Colocar o projeto em `C:\wamp64\www\meu-projeto\clinica` (ou ajustar a configuração do Apache/`BASE_URL`).
3. Criar a base de dados `clinica_dentaria`.
4. Importar/restaurar esquema e dados num ambiente controlado. Não expor dumps `.sql` na web. Usar migrações aditivas em `migracoes/` quando aplicável.
5. Configurar credenciais em `includes/core/config.php` (ou via wrapper `includes/config.php`) — `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`. **Em produção, usar utilizador MySQL dedicado** (não `root` sem password).
6. Aceder a `http://localhost/meu-projeto/clinica/` (conforme o WAMP).

## Configuração e segurança
- **MODO_DEV**: desativado por defeito. Detalhes de erro apenas em `localhost` com `includes/.modo_dev` (ou `includes/core/.modo_dev`). **Não usar em produção.**
- **Pagamentos online**: arquitetura com gateway em *scaffold*; confirmação real requer credenciais do fornecedor e webhook **autenticado/idempotente**. `marcar-pagamento-demo.php` é apenas para dev explícito.
- **Envio de emails/SMTP**: campos configuráveis em `admin/configuracoes.php`, mas o transporte automático **não está integrado/testado** por omissão — requer implementação e credenciais.
- **Uploads e ficheiros sensíveis**: `.htaccess` protege extensões sensíveis, documentação interna sensível e diretórios (`docs/`, `logs/`, `uploads/`, CV). Não introduzir credenciais em código.
- **Admin**: personalização (identidade, logo/favicon, navegação via Editor do site, conteúdos, imagens, configurações operacionais) disponível no painel para utilizadores autorizados.

## Validação da reorganização
- Backup criado antes da reorganização: `C:\Users\HP\AppData\Local\Temp\opencode\clinica-backup-reorg-20261109`.
- `php -l`: **108 ficheiros PHP verificados, 0 erros** (automático).
- Testes E2E, pagamentos/webhooks reais, uploads via browser e validação visual completa requerem **validação manual** no ambiente WAMP.

## Documentação e alterações
- `docs/ALTERACOES.md` — alterações, ficheiros criados/alterados/movidos e resultados de testes.
- `RELATORIO-AUDITORIA.md` — auditoria histórica (acesso web negado por `.htaccess` na raiz).

## Notas
- Não versionar/publicar dumps com dados pessoais. Fazer backups fora da pasta pública.
- Antes de produção: credenciais dedicadas, revisão RGPD/legal, integração real de pagamentos/emails, e testes completos.