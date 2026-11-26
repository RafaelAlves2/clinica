-- Migração 007: idempotência de eventos de gateway e histórico de reembolsos.
-- Aditiva: não elimina nem altera registos financeiros existentes.
-- Executar depois de fazer backup da base de dados.

CREATE TABLE IF NOT EXISTS pagamentos_gateway_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gateway VARCHAR(40) NOT NULL,
    evento_id VARCHAR(190) NOT NULL,
    tipo_evento VARCHAR(100) NULL,
    pagamento_id INT UNSIGNED NULL,
    recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pag_gateway_evento (gateway, evento_id),
    KEY idx_pag_gateway_evento_pagamento (pagamento_id),
    CONSTRAINT fk_pag_gateway_evento_pagamento
        FOREIGN KEY (pagamento_id) REFERENCES pagamentos (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrar a última chave de evento já guardada em cada pagamento (compatibilidade).
INSERT IGNORE INTO pagamentos_gateway_eventos
    (gateway, evento_id, tipo_evento, pagamento_id, recebido_em)
SELECT
    p.gateway,
    p.idempotency_key,
    'evento_historico',
    p.id,
    COALESCE(p.atualizado_em, p.criado_em)
FROM pagamentos p
WHERE p.gateway IS NOT NULL
  AND p.idempotency_key IS NOT NULL
  AND TRIM(p.idempotency_key) <> '';

CREATE TABLE IF NOT EXISTS pagamentos_reembolsos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pagamento_id INT UNSIGNED NOT NULL,
    gateway VARCHAR(40) NULL,
    evento_id VARCHAR(190) NULL,
    origem ENUM('administrativo','gateway','historico') NOT NULL DEFAULT 'administrativo',
    valor DECIMAL(10,2) NOT NULL,
    motivo VARCHAR(255) NULL,
    registado_por INT UNSIGNED NULL,
    reembolsado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pag_reembolso_evento (gateway, evento_id),
    KEY idx_pag_reembolso_data (reembolsado_em),
    KEY idx_pag_reembolso_pagamento (pagamento_id),
    CONSTRAINT fk_pag_reembolso_pagamento
        FOREIGN KEY (pagamento_id) REFERENCES pagamentos (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_pag_reembolso_staff
        FOREIGN KEY (registado_por) REFERENCES utilizadores (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preservar os montantes históricos já existentes como um movimento único.
-- A condição NOT EXISTS torna o backfill seguro para reexecução.
INSERT INTO pagamentos_reembolsos
    (pagamento_id, origem, valor, motivo, reembolsado_em)
SELECT
    p.id,
    'historico',
    p.valor_reembolsado,
    'Registo histórico migrado do campo pagamentos.valor_reembolsado',
    COALESCE(p.reembolsado_em, p.pago_em, p.criado_em)
FROM pagamentos p
WHERE p.valor_reembolsado > 0
  AND NOT EXISTS (
      SELECT 1
      FROM pagamentos_reembolsos pr
      WHERE pr.pagamento_id = p.id
        AND pr.origem = 'historico'
  );
