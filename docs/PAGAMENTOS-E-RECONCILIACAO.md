# Pagamentos e reconciliação financeira

## Formas de pagamento

- **Na clínica:** a marcação fica confirmada e a receção regista cada pagamento presencial. O serviço valida o montante em dívida e guarda método, data, colaborador e observações.
- **Sinal online:** quando o gateway Stripe está disponível, o paciente paga a percentagem definida em `sinal_percentagem` (20% na configuração atual). O sinal é guardado como movimento separado, confirmado pelo webhook e abatido ao saldo da marcação. O restante pode ser pago posteriormente na clínica ou através do checkout do valor em dívida.
- **Pagamento online total:** o checkout calcula sempre o saldo a partir da base de dados. O valor enviado pelo browser não determina a cobrança.

## Configuração Stripe

As chaves devem existir apenas no ficheiro `.env` local ou em variáveis de ambiente, nunca no Git:

- `STRIPE_SECRET_KEY`
- `STRIPE_PUBLISHABLE_KEY`
- `STRIPE_WEBHOOK_SECRET`

As chaves pública e secreta têm de pertencer ao mesmo ambiente: `pk_test_` com `sk_test_`, ou `pk_live_` com `sk_live_`. O sistema oculta/desativa o checkout se os ambientes não corresponderem.

O endpoint de notificações é:

`https://<dominio-da-clinica>/meu-projeto/clinica/pagamento-webhook.php`

No Stripe, configurar pelo menos os eventos `payment_intent.succeeded`, `payment_intent.processing`, `payment_intent.payment_failed`, `payment_intent.canceled` e `charge.refunded`. O segredo de assinatura fornecido pelo endpoint deve ser colocado em `STRIPE_WEBHOOK_SECRET`. A confirmação de pagamento não é feita pelo URL de regresso do browser: só uma notificação autenticada pode confirmar o pagamento.

Para publicação, configurar também `site_base_url` com o URL público HTTPS da clínica e testar primeiro em modo de teste.

## Reembolsos

Existem duas origens distintas:

- **Administrativo:** a receção regista no sistema um reembolso que já foi tratado externamente. Esta ação atualiza a contabilidade e o histórico, mas não envia dinheiro ao prestador.
- **Stripe:** quando a Stripe comunica `charge.refunded`, o webhook valida a assinatura, identifica o pagamento, regista o movimento e atualiza o total reembolsado. O valor comunicado pela Stripe é acumulado; o sistema regista apenas a diferença ainda não contabilizada e ignora eventos duplicados.

O histórico detalhado fica em `pagamentos_reembolsos`. Os identificadores de eventos recebidos ficam em `pagamentos_gateway_eventos`. A migração 007 cria ambas as tabelas e importa os reembolsos e identificadores antigos, sem eliminar pagamentos.

## Cartões guardados

- Cada conta de paciente pode ter um Customer Stripe próprio, identificado localmente por `utilizadores.stripe_customer_id`.
- A página `cartoes.php` pede consentimento explícito antes de iniciar um SetupIntent. O SetupIntent usa `usage=on_session`: o cartão pode ser apresentado quando o paciente está autenticado, mas não autoriza cobranças automáticas quando está ausente.
- O Payment Element recebe uma Customer Session com redisplay de métodos guardados ativo. O paciente escolhe o cartão no checkout e confirma cada pagamento.
- A clínica guarda apenas o ID Stripe do Customer, o ID Stripe do PaymentMethod e o registo do consentimento. A Stripe processa o número completo, validade e CVC.
- A remoção desassocia o PaymentMethod do Customer Stripe e revoga o consentimento local. A migração 008 cria a coluna e a tabela necessárias, sem mexer nos pagamentos anteriores.

## Relatórios

- O relatório financeiro usa a data do recebimento para entradas e a data do movimento para saídas/reembolsos.
- O valor líquido do período é o recebido bruto no período menos os reembolsos efetivamente registados nesse período. Um reembolso feito num mês posterior aparece nesse mês, não no mês do pagamento original.
- O painel de pagamentos calcula os totais sobre todos os registos que correspondem aos filtros. A tabela apresenta até 600 movimentos mais recentes e informa quando existem mais resultados.

## Estado verificado em 23/11/2026

- Base de dados local: migrações 007 e 008 aplicadas.
- Cartões guardados: migração 008, Customer Stripe, SetupIntent em modo `on_session`, Customer Session e registo do consentimento implementados. Ainda não foi adicionado/testado um cartão real ou de teste através da interface.
- As chaves locais estão no ambiente de teste e o endpoint Stripe respondeu à consulta de conta.
- A resposta da Stripe indicou `charges_enabled=false` e `details_submitted=false`. A integração de código e a validação local da assinatura foram testadas, mas **não foi confirmada uma cobrança de cartão de ponta a ponta**. A conta Stripe ainda precisa de ser ativada/completada no painel do prestador e o webhook público tem de ser configurado e testado.

## Backup e reversão

Antes das alterações, foi criado o backup SQL:

`C:\Users\HP\Documents\Backups\clinica_dentaria-pre-correcoes-20261122-234856.sql`

O commit anterior às correções financeiras está identificado como `8e85249`. Antes da integração visual/cartões existe ainda a tag `checkpoint/checkout-cartoes-antes` a apontar para `19b33e1`. O backup anterior a esta integração está em:

`C:\Users\HP\Documents\Backups\clinica_dentaria-antes-cartoes-20261123-000623.sql`

Para desfazer apenas a integração visual/cartões depois de criado o seu commit, usar `git revert <hash-do-commit>`. As migrações 007 e 008 são aditivas; não remover as respetivas tabelas/colunas sem primeiro avaliar os dados.
