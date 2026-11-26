-- Migração 009: gestão de unidades/localizações da clínica.
-- A migração é aditiva e pode ser executada mais do que uma vez.

CREATE TABLE IF NOT EXISTS unidades (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(150) NOT NULL,
    slug VARCHAR(170) NOT NULL,
    descricao VARCHAR(500) DEFAULT NULL,
    endereco VARCHAR(255) NOT NULL,
    codigo_postal VARCHAR(20) DEFAULT NULL,
    cidade VARCHAR(100) NOT NULL,
    distrito VARCHAR(100) DEFAULT NULL,
    pais VARCHAR(100) NOT NULL DEFAULT 'Portugal',
    telefone VARCHAR(40) DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    horario VARCHAR(255) DEFAULT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(11,7) DEFAULT NULL,
    principal TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem SMALLINT NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_unidades_slug (slug),
    KEY idx_unidades_publicas (ativo, principal, ordem),
    KEY idx_unidades_coordenadas (latitude, longitude),
    CONSTRAINT ck_unidades_latitude CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
    CONSTRAINT ck_unidades_longitude CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
    CONSTRAINT ck_unidades_coordenadas CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cria a unidade atualmente configurada no site, apenas se a tabela estiver vazia.
-- As coordenadas iniciais apontam para a zona da morada atual e podem ser corrigidas
-- no seletor de mapa da administração.
INSERT INTO unidades (
    nome, slug, descricao, endereco, codigo_postal, cidade, distrito, pais,
    telefone, email, latitude, longitude, principal, ativo, ordem
)
SELECT
    COALESCE(NULLIF((SELECT valor FROM configuracoes WHERE chave = 'clinica_nome' LIMIT 1), ''), 'Clínica Dentária'),
    'clinica-principal',
    'Unidade principal da clínica.',
    'Rua Conselheiro Bivar, 1',
    '8000-255',
    'Faro',
    'Faro',
    'Portugal',
    NULLIF((SELECT valor FROM configuracoes WHERE chave = 'contacto_telefone' LIMIT 1), ''),
    NULLIF((SELECT valor FROM configuracoes WHERE chave = 'contacto_email' LIMIT 1), ''),
    37.0172197,
    -7.9359366,
    1,
    1,
    0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM unidades);

INSERT INTO migracoes_aplicadas (nome)
SELECT '009_unidades_globo'
WHERE NOT EXISTS (
    SELECT 1 FROM migracoes_aplicadas WHERE nome = '009_unidades_globo'
);
