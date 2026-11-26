-- Migração 008: clientes Stripe e consentimento para cartões guardados.
-- Aditiva; não armazena números completos, CVC ou validade do cartão.

SET @tem_coluna_stripe_customer = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'utilizadores'
      AND column_name = 'stripe_customer_id'
);
SET @sql_stripe_customer = IF(
    @tem_coluna_stripe_customer = 0,
    'ALTER TABLE utilizadores ADD COLUMN stripe_customer_id VARCHAR(255) NULL AFTER email',
    'SELECT 1'
);
PREPARE stmt_stripe_customer FROM @sql_stripe_customer;
EXECUTE stmt_stripe_customer;
DEALLOCATE PREPARE stmt_stripe_customer;

SET @tem_indice_stripe_customer = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'utilizadores'
      AND index_name = 'uq_utilizadores_stripe_customer'
);
SET @sql_indice_stripe_customer = IF(
    @tem_indice_stripe_customer = 0,
    'ALTER TABLE utilizadores ADD UNIQUE KEY uq_utilizadores_stripe_customer (stripe_customer_id)',
    'SELECT 1'
);
PREPARE stmt_indice_stripe_customer FROM @sql_indice_stripe_customer;
EXECUTE stmt_indice_stripe_customer;
DEALLOCATE PREPARE stmt_indice_stripe_customer;

CREATE TABLE IF NOT EXISTS stripe_metodos_consentimentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilizador_id INT UNSIGNED NOT NULL,
    stripe_customer_id VARCHAR(255) NOT NULL,
    stripe_payment_method_id VARCHAR(255) NOT NULL,
    uso ENUM('on_session','off_session') NOT NULL DEFAULT 'on_session',
    termos_versao VARCHAR(40) NOT NULL,
    consentimento_texto VARCHAR(500) NOT NULL,
    consentido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revogado_em DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stripe_payment_method (stripe_payment_method_id),
    KEY idx_stripe_consent_utilizador (utilizador_id, revogado_em),
    CONSTRAINT fk_stripe_consent_utilizador
        FOREIGN KEY (utilizador_id) REFERENCES utilizadores (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
