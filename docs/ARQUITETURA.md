# Arquitetura (visão prática)

## Stack
- **Backend:** PHP (procedural com includes; tipagem estrita em vários ficheiros), sessões nativas.
- **Base de dados:** MySQL/MariaDB (`clinica_dentaria`), acesso via PDO (`db()` em `includes/core/config.php`).
- **Frontend:** HTML renderizado no servidor, CSS único (`css/estilo.css`), JS vanilla (`js/tema.js`, `js/animacoes.js`).
- **Ambiente alvo:** WAMP/Apache em Windows (desenvolvimento local).

## Camadas/módulos

### Núcleo (`includes/core/`)
- `config.php`: arranque (erros, `BASE_URL`, sessão), `db()`, CSRF (`csrf_campo`/`csrf_validar`), autenticação/sessão (`utilizador_atual`, `exigir_login`), helpers (`e()`, `redirecionar`, `flash_*`), formatação, configuração (`config_valor`, `config_valor_texto`, `clinica_dado`), constantes de identidade/DB.
- `conteudo.php`: leitura de `conteudo_site` (cache estático), grupos e helpers (`conteudo`, `conteudo_html`, `conteudo_lista`).
- `cookies.php`: consentimento/preferências de cookies.

### Serviços (`includes/servicos/`)
- `marcacao.php`: fluxo e regras de marcação (carregamento de contexto, disponibilidade, reservas, validações).
- `pagamentos.php` + `gateway.php`: modelo de pagamentos, presenciais/online, abstração de gateway (scaffold), estados e idempotência; callbacks devem ser **autenticados e idempotentes** (ver `pagamento-webhook.php`).
- `gestao.php`, `conta.php`, `stock.php`, `consulta-preparacao.php`: suporte a administração, área pessoal, stock e conteúdos/componentes operacionais.
- `importacao.php`: análise read-only de CSV, deteção de codificação/delimitador, mapeamento automático/manual, validação de campos, sinalização de possíveis duplicados, comparação de email/telefone/nome-data e mascaramento de valores na amostra. Não grava registos importados.

### UI (`includes/ui/`)
- `cabecalho.php`/`rodape.php`: layout comum, navegação, tema, CSRF meta, identidade (logo/favicon/nome configuráveis), scripts de UI.
- Componentes: hero, números, parceiros, testemunhos, redes sociais, WhatsApp, banner/consentimento de cookies.

### Entry points
- Páginas na raiz e `admin/*.php` compõem controladores/páginas. Incluem núcleo/serviços/UI conforme necessário. `cabecalho.php` define `<base href="<?= BASE_URL ?>">`, o que estabiliza muitos links relativos no HTML.
- `admin/importar-dados.php`: ferramenta de pré-visualização CSV exclusiva de administradores; não executa importações para as tabelas operacionais.

## Fluxos críticos
- **Autenticação:** sessão + revalidação de utilizador/ativo/papel; redirecionamentos via `redirecionar()` (prefixa `BASE_URL` para caminhos relativos).
- **Marcações:** passos `marcar*` com estado em sessão + validações/reservas em BD.
- **Pagamentos:** criação/registo e confirmação; online depende de gateway/credenciais/webhook real. **Não assumir** confirmação por URL de retorno do utilizador.
- **Uploads:** validação de tipo/tamanho; armazenamento em `uploads/` com proteções `.htaccess`. CVs com acesso controlado.

## Dados e migrações
- Esquema base + alterações aditivas em `migracoes/*.sql` (atualmente até à migração 008, que cria o livro de reembolsos, o registo idempotente dos eventos de gateway e a gestão de cartões guardados na Stripe). Evitar mudanças destrutivas sem backup.
- O levantamento técnico das integrações de pagamentos existentes está em `docs/ESTADO-ATUAL-INTEGRACOES.md`.
- O processo de descoberta, importação faseada, reconciliação e transição de sistemas antigos está especificado em `docs/PLANO-MIGRACAO-SISTEMA-LEGADO.md`.
- Não desenvolver conectores para um ERP, gateway ou programa de faturação sem identificar primeiro o fornecedor/versão, obter documentação e testar com dados de amostra anonimizados.

## Segurança (princípios aplicados)
- Escape de output (`e()`), prepared statements (na maioria dos acessos), CSRF em formulários, RBAC em admin, proteções `.htaccess`, validação de uploads, segredo de webhook/config sensível tratada com cuidado na UI de configurações.

## Limites conhecidos
- Envio automático de email (SMTP/notificações) **não está integrado** como transporte funcional; apenas campos de configuração.
- Confirmações/reembolsos online reais dependem de credenciais/contratos e testes do fornecedor.
- Reorganização de URLs de entry points não foi aplicada para preservar compatibilidade; usar wrappers/estrutura atual.
