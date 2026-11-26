# Plano de integração de sistema legado e migração de dados

## Objetivo

Permitir que uma clínica passe a utilizar a nova plataforma sem perder o histórico nem interromper desnecessariamente o trabalho. A migração deve ser reversível, auditável, repetível e validada pela clínica.

Não se deve substituir o sistema existente antes de identificar os dados, as dependências, os contratos e as operações que ele executa.

## Fase 0 — Descoberta (necessária antes de desenvolver um conector)

Recolher com autorização da clínica:

- Nome do software, fabricante, versão, módulos e contacto de suporte.
- Onde está instalado: computador local, servidor próprio, alojamento externo ou cloud.
- Base de dados usada, versão, tamanho, possibilidade de backup e acesso de leitura.
- Formatos de exportação disponíveis: CSV, Excel, XML, JSON, SQL, PDF ou API.
- Se existe documentação de API, webhooks, SDK, licenças de integração e custos.
- Lista de módulos utilizados: pacientes, processos clínicos, agenda, tratamentos, imagens, documentos, seguros, faturação, pagamentos, stock, funcionários e relatórios.
- Sistema de faturação/contabilidade, certificado quando aplicável, e ligação à Autoridade Tributária.
- Prestador de pagamentos, banco/adquirente, terminais físicos, MB WAY, Multibanco, cartões, transferências, débitos diretos e contratos existentes.
- Operações que a clínica não aceita mudar durante a primeira fase.

**Resultado obrigatório:** inventário de sistemas, formatos, dados, dependências, responsáveis, custos, limitações e plano de testes aprovado pela clínica.

## Fase 1 — Backup e proteção da origem

1. Obter autorização escrita e definir responsáveis.
2. Fazer backup completo da base de dados e dos ficheiros/documentos/imagens.
3. Preservar uma cópia original, imutável e com hash SHA-256.
4. Guardar cópias cifradas, com acesso limitado e prazo de retenção definido.
5. Testar a restauração do backup num ambiente isolado.
6. Nunca testar importações diretamente na base de dados de produção.

## Fase 2 — Mapeamento

Criar um mapa de cada entidade de origem para a entidade de destino. Exemplos:

| Origem | Destino provável | Cuidados |
|---|---|---|
| Clientes/pacientes | `pacientes` + eventualmente `utilizadores` | Não criar contas de acesso sem necessidade/consentimento; detetar duplicados. |
| Médicos/dentistas | `dentistas` + `utilizadores` | Preservar identificadores profissionais, especialidades e estado. |
| Funcionários | `utilizadores` e perfil adequado | Nunca importar palavras-passe em texto simples; emitir convite/redefinição segura. |
| Consultas/marcações | `consultas` / `marcacoes` | Preservar datas, estados, responsáveis e relações. |
| Tratamentos | Tabelas de tratamentos e relações | Preservar códigos antigos, preços históricos e descrições. |
| Pagamentos | `pagamentos` e movimentos associados | Preservar fornecedor, referência, data, valor, estado e comprovativos. Não simular cobranças. |
| Documentos/imagens | Arquivo protegido e ligações internas | Preservar origem, tipo, data, titular e permissões. |
| Seguros/planos | `seguradoras`, planos e benefícios | Rever regras e contratos antes de converter. |

O mapa definitivo depende do esquema real da origem. Os nomes acima são apenas destinos prováveis na base de dados atual.

## Fase 3 — Área de staging e importador

A importação deve seguir um pipeline e não escrever diretamente nos dados finais:

1. Receber uma exportação autorizada.
2. Verificar formato, tamanho, codificação, colunas e consistência.
3. Importar os registos originais para uma área temporária (staging), sem os alterar.
4. Registar sistema de origem, entidade e identificador original.
5. Aplicar regras de transformação explícitas e versionadas.
6. Detetar duplicados e conflitos; nunca fundir automaticamente registos clínicos ambíguos.
7. Produzir pré-visualização com totais, avisos e erros.
8. Permitir executar um ensaio sem gravar no destino (dry-run).
9. Importar em lotes transacionais, com chave de idempotência para evitar duplicação ao repetir.
10. Registar cada decisão e erro, sem guardar dados sensíveis desnecessários em logs.

O importador deve suportar adaptadores por formato/origem. Não deve assumir que todos os sistemas exportam as mesmas colunas.

## Fase 4 — Validação e reconciliação

Antes da mudança, comparar pelo menos:

- Contagem de pacientes, utilizadores, funcionários e profissionais.
- Registos clínicos e ligações entre paciente, profissional, consulta e tratamento.
- Datas, fuso horário, estados e identificadores externos.
- Número e tamanho dos documentos/imagens, com hashes quando possível.
- Totais de pagamentos por período, método e estado.
- Pagamentos pendentes, reembolsos, dívidas e saldos.
- Faturas e documentos fiscais, sem alterar a sua origem.
- Relatórios-chave usados pela clínica.

A clínica deve aprovar formalmente as diferenças e exceções. Uma contagem igual não prova que o conteúdo ou as relações estejam corretos.

## Fase 5 — Transição sem interrupção

Quando tecnicamente possível:

1. Executar uma primeira importação de teste.
2. Corrigir mapeamentos e repetir.
3. Executar uma importação inicial em ambiente de produção, sem mudar ainda o sistema principal.
4. Manter um período de operação paralela.
5. Definir uma janela de corte e um procedimento para alterações feitas entre a primeira importação e o corte.
6. Fazer a importação incremental final.
7. Validar novamente e obter aprovação dos responsáveis.
8. Só então tornar a nova plataforma o sistema principal.
9. Manter a origem em modo de consulta durante o período acordado.

Não permitir escrita simultânea nos dois sistemas sem uma estratégia explícita de sincronização: isso cria conflitos e duplicações.

## Arquitetura de integrações de pagamentos

### Separações obrigatórias

- **Método do pagador:** cartão, MB WAY, Multibanco, transferência ou outro.
- **Prestador/adquirente:** empresa que processa o pagamento.
- **Conta de recebimentos:** conta do comerciante onde o prestador liquida os valores.
- **Destino bancário:** IBAN/conta confirmado pelo prestador, sujeito a titularidade e verificação.
- **Cartão guardado de paciente:** token/Payment Method do paciente no prestador; não é a conta de recebimentos da clínica.

O cartão usado por um paciente para pagar não define para onde vai o dinheiro. Não se deve associar o cartão de um familiar ao destino de recebimentos da empresa. Os dados de liquidação devem ser configurados pelo titular/representante autorizado no prestador e só podem ser alterados no nosso site quando exista API e autorização para isso.

### Contrato dos adaptadores

Cada adaptador deverá declarar capacidades e implementar apenas operações que o fornecedor efetivamente suporte:

- Identificação e versão do adaptador.
- Verificação de ligação/credenciais.
- Métodos de pagamento aceites.
- Criação de pagamento com chave idempotente.
- Consulta do estado de uma transação.
- Validação de webhook e normalização de eventos.
- Reembolso total/parcial, quando permitido.
- Gestão de métodos guardados, quando suportada.
- Gestão de conta comercial/recebimentos, apenas quando a API permitir.
- Limites, estados e erros normalizados.

O código de negócio usa um contrato comum; cada fornecedor tem o seu adaptador. Não se deve fingir que todos suportam as mesmas operações.

### Vários fornecedores e mudança de prestador

- O pagamento fica permanentemente associado ao fornecedor e à conta que o criou.
- Novos pagamentos usam o fornecedor ativo escolhido pela administração.
- Pagamentos pendentes e eventos tardios continuam a ser tratados pelo fornecedor original.
- Cada endpoint/webhook usa autenticação e segredo próprios do fornecedor.
- Não selecionar o fornecedor apenas por parâmetros de URL fornecidos pelo visitante.
- A mudança de prestador não copia automaticamente cartões guardados nem transfere contratos, referências, histórico ou saldo.
- A conta antiga só pode ser desligada depois de reconciliar pagamentos pendentes, reembolsos e eventos ainda esperados.

### Credenciais, cartões e contas bancárias

- Nunca guardar PAN ou CVC na aplicação.
- Guardar apenas tokens e identificadores externos estritamente necessários.
- Não tratar um cartão de pagamento de cliente como conta de recebimentos do comerciante.
- Não guardar IBAN completo sem necessidade e base legal; preferir configuração alojada no prestador.
- Segredos de API e webhooks não devem ficar no Git, nos logs ou expostos no browser.
- Credenciais devem ficar em variáveis de ambiente/cofre de segredos ou armazenamento cifrado com chave independente.
- Exigir autenticação forte e confirmação reforçada para operações de reembolso, alteração de credenciais ou destino de recebimentos.
- Um prestador pode exigir que a conta bancária seja titulada pela entidade/beneficiário verificado. O software não pode contornar verificações KYC/AML ou regras contratuais.

## Plano de testes obrigatório por prestador

1. Credenciais de sandbox/teste e ambiente corretamente identificado.
2. Pagamento aprovado, recusado, cancelado e em processamento.
3. Autenticação reforçada/3-D Secure, quando aplicável.
4. Webhook válido, assinatura inválida e payload malformado.
5. Entrega duplicada do mesmo evento e repetição após timeout.
6. Pagamento concluído enquanto o utilizador fecha o browser.
7. Reembolso total e parcial e respetiva reconciliação.
8. Alteração de prestador com transações pendentes no anterior.
9. Cartões guardados, consentimento, revogação e limites do fornecedor.
10. Reconciliação dos relatórios do nosso site com o painel/ficheiro do prestador.

Só depois de passar os testes de sandbox e de a clínica completar a verificação da conta se deve planear um piloto real, com valor reduzido e autorização expressa. Nunca usar cartões reais de terceiros em testes simulados nem fazer cobranças reais sem autorização do titular e da clínica.

## Critérios de aceitação da migração

- Backup restaurável confirmado.
- Mapeamento aprovado pela clínica.
- Dry-run sem erros críticos.
- Nenhum duplicado clínico ambíguo importado automaticamente.
- Totais financeiros reconciliados e diferenças documentadas.
- Documentos e imagens acessíveis apenas por perfis autorizados.
- Logs de importação sem dados de saúde ou credenciais desnecessárias.
- Plano de reversão e responsável pelo corte definidos.
- Aprovação final assinada pelos responsáveis da clínica.

## Implementação inicial já disponível no projeto

A página `admin/importar-dados.php` disponibiliza uma pré-visualização read-only para administradores. Aceita CSV até 5 MB e 10 000 registos, deteta delimitadores e codificações UTF-8/UTF-16/Windows-1252, e mostra uma amostra limitada.

O sistema já sugere o mapeamento de cabeçalhos para nome, email, telemóvel, data de nascimento e número SNS e permite corrigir manualmente cada associação. Faz validação básica dos campos, sinaliza possíveis duplicados no próprio ficheiro e compara email, telefone normalizado e nome+data de nascimento com registos existentes. Estas chaves são apenas pistas de revisão — um telefone pode ser partilhado e nomes/datas podem coincidir — e nunca provocam fusões automáticas.

A simulação apresenta registos válidos/inválidos, correspondências e potenciais novos sem correspondência pelas regras testadas, sem afirmar que estes últimos são definitivamente novos. A revisão individual classifica até 50 linhas por pedido como inválidas, possíveis duplicados no ficheiro, possíveis correspondências existentes ou potenciais novos; apresenta apenas número da linha e motivo, sem valores pessoais. Os totais abrangem todos os registos. Estas classificações são pistas e não decisões de importação. A amostra mascara nomes, emails, telefones, datas completas, identificadores e colunas clínicas/notas reconhecidas.

A ferramenta não grava pacientes, utilizadores, consultas ou pagamentos e não guarda o CSV depois do pedido. A revisão individual preliminar já está disponível, mas ainda não permite escolher, resolver ou confirmar conflitos nem guardar decisões. Continuam por desenvolver a importação idempotente em lotes, o relatório de reconciliação, a importação de relações/documentos e eventuais conectores externos. Também não lê XLS/XLSX, XML ou backups SQL. Existem 29 testes automatizados em `tests/test_importacao_csv.php`, incluindo a comparação de email, telefone e nome/data e a revisão individual com uma base SQLite isolada. A ligação ao sistema de origem continua dependente da descoberta e documentação desse sistema.

## Informação que falta para avançar para a integração real

- Software atual, fabricante e versão.
- Fornecedor de pagamentos e adquirente/terminal usado.
- Software de faturação/contabilidade.
- Documentação/API/contratos dos fornecedores.
- Exportação de amostra anonimizada e esquema dos dados.
- Módulos e procedimentos que têm de continuar a funcionar exatamente como hoje.
