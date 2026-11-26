
-- ATENÇÃO: esquema base estrutural, sem dados, gerado a partir da BD em 2026-11-18.
-- Contém DROP TABLE IF EXISTS e destina-se apenas a criar uma base NOVA/vazia.
-- NÃO executar sobre uma base de dados existente com dados.
-- Para alterações incrementais usar as migrações 001 a 008.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `artigos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `artigos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `titulo` varchar(200) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `resumo` varchar(300) DEFAULT NULL,
  `conteudo` mediumtext NOT NULL,
  `categoria` varchar(60) DEFAULT NULL,
  `visivel` tinyint(1) NOT NULL DEFAULT '1',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `autorizacoes_paciente`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `autorizacoes_paciente` (
  `utilizador_id` int unsigned NOT NULL,
  `paciente_id` int unsigned NOT NULL,
  `relacao` enum('proprio','filho','pai_mae','conjuge','outro') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'proprio',
  `pode_marcar` tinyint(1) NOT NULL DEFAULT '1',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`utilizador_id`,`paciente_id`),
  KEY `idx_autorizacoes_paciente` (`paciente_id`),
  CONSTRAINT `fk_aut_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aut_utilizador` FOREIGN KEY (`utilizador_id`) REFERENCES `utilizadores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `avaliacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `avaliacoes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `consulta_id` int unsigned NOT NULL,
  `paciente_id` int unsigned NOT NULL,
  `dentista_id` int unsigned NOT NULL,
  `classificacao` tinyint unsigned NOT NULL,
  `comentario` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `estado` enum('pendente','publicada','rejeitada') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `moderado_por` int unsigned DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_avaliacoes_consulta` (`consulta_id`),
  KEY `idx_avaliacoes_dentista` (`dentista_id`,`estado`),
  KEY `fk_av_paciente` (`paciente_id`),
  KEY `fk_av_moderador` (`moderado_por`),
  CONSTRAINT `fk_av_consulta` FOREIGN KEY (`consulta_id`) REFERENCES `consultas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_av_dentista` FOREIGN KEY (`dentista_id`) REFERENCES `dentistas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_av_moderador` FOREIGN KEY (`moderado_por`) REFERENCES `utilizadores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_av_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_av_classificacao` CHECK ((`classificacao` between 1 and 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `beneficios_paciente`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `beneficios_paciente` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int unsigned NOT NULL,
  `plano_id` int unsigned NOT NULL,
  `numero_apolice` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_beneficiario` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valido_de` date DEFAULT NULL,
  `valido_ate` date DEFAULT NULL,
  `estado_validacao` enum('registado','em_validacao','validado','rejeitado','expirado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'registado',
  `validado_em` datetime DEFAULT NULL,
  `validado_por` int unsigned DEFAULT NULL,
  `notas` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bp_paciente` (`paciente_id`),
  KEY `idx_bp_estado` (`estado_validacao`),
  KEY `fk_bp_plano` (`plano_id`),
  KEY `fk_bp_validador` (`validado_por`),
  CONSTRAINT `fk_bp_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bp_plano` FOREIGN KEY (`plano_id`) REFERENCES `planos_beneficio` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bp_validador` FOREIGN KEY (`validado_por`) REFERENCES `utilizadores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bloqueios_horario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bloqueios_horario` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `dentista_id` int unsigned DEFAULT NULL,
  `inicio` datetime NOT NULL,
  `fim` datetime NOT NULL,
  `motivo` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `criado_por` int unsigned DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bloqueios_dentista` (`dentista_id`,`inicio`,`fim`),
  KEY `fk_bloqueios_criador` (`criado_por`),
  CONSTRAINT `fk_bloqueios_criador` FOREIGN KEY (`criado_por`) REFERENCES `utilizadores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bloqueios_dentista` FOREIGN KEY (`dentista_id`) REFERENCES `dentistas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_bloqueios_datas` CHECK ((`fim` > `inicio`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `candidaturas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidaturas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `telemovel` varchar(20) DEFAULT NULL,
  `area` varchar(80) NOT NULL,
  `mensagem` text,
  `cv` varchar(255) DEFAULT NULL,
  `estado` enum('nova','em_analise','entrevista','rejeitada','contratada') NOT NULL DEFAULT 'nova',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `configuracao_notificacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracao_notificacoes` (
  `tipo` enum('confirmacao_marcacao','lembrete_consulta','alteracao_data','cancelamento','pagamento_pendente','pagamento_confirmado','estado_beneficio') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `canal` enum('email','sms') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `antecedencia_horas` smallint unsigned DEFAULT NULL,
  PRIMARY KEY (`tipo`,`canal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `configuracoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracoes` (
  `chave` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `consentimentos_cookies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consentimentos_cookies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sessao_id` varchar(64) NOT NULL,
  `utilizador_id` int DEFAULT NULL,
  `aceitou_analiticos` tinyint(1) NOT NULL DEFAULT '0',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sessao_id` (`sessao_id`),
  KEY `utilizador_id` (`utilizador_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `consultas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consultas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `marcacao_id` int unsigned NOT NULL,
  `paciente_id` int unsigned NOT NULL,
  `dentista_id` int unsigned NOT NULL,
  `inicio` datetime NOT NULL,
  `fim` datetime NOT NULL,
  `duracao_min` smallint unsigned NOT NULL,
  `estado` enum('pendente','confirmada','cancelada','concluida') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `preco_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `notas_clinicas` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_consultas_marcacao` (`marcacao_id`),
  KEY `idx_consultas_paciente` (`paciente_id`),
  KEY `idx_consultas_dentista_inicio` (`dentista_id`,`inicio`),
  KEY `idx_consultas_estado` (`estado`),
  CONSTRAINT `fk_consultas_dentista` FOREIGN KEY (`dentista_id`) REFERENCES `dentistas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_consultas_marcacao` FOREIGN KEY (`marcacao_id`) REFERENCES `marcacoes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_consultas_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ck_consultas_datas` CHECK ((`fim` > `inicio`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `consultas_tratamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consultas_tratamentos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `consulta_id` int unsigned NOT NULL,
  `tratamento_id` int unsigned NOT NULL,
  `beneficio_id` int unsigned DEFAULT NULL,
  `preco_original` decimal(10,2) NOT NULL,
  `desconto` decimal(10,2) NOT NULL DEFAULT '0.00',
  `preco_final` decimal(10,2) NOT NULL,
  `duracao_min` smallint unsigned NOT NULL,
  `preco_estimado` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_ct_consulta` (`consulta_id`),
  KEY `idx_ct_tratamento` (`tratamento_id`),
  KEY `fk_ct_beneficio` (`beneficio_id`),
  CONSTRAINT `fk_ct_beneficio` FOREIGN KEY (`beneficio_id`) REFERENCES `beneficios_paciente` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ct_consulta` FOREIGN KEY (`consulta_id`) REFERENCES `consultas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ct_tratamento` FOREIGN KEY (`tratamento_id`) REFERENCES `tratamentos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ck_ct_precos` CHECK (((`preco_original` >= 0) and (`desconto` >= 0) and (`preco_final` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `conteudo_site`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `conteudo_site` (
  `chave` varchar(100) NOT NULL,
  `valor` text NOT NULL,
  `grupo` varchar(50) NOT NULL DEFAULT 'geral',
  `tipo` enum('texto','textarea','html') NOT NULL DEFAULT 'texto',
  `rotulo` varchar(150) NOT NULL,
  `ajuda` varchar(255) DEFAULT NULL,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dentistas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dentistas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `utilizador_id` int unsigned DEFAULT NULL,
  `nome_completo` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cedula_profissional` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fotografia` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descricao` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dentistas_utilizador` (`utilizador_id`),
  CONSTRAINT `fk_dentistas_utilizador` FOREIGN KEY (`utilizador_id`) REFERENCES `utilizadores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dentistas_especialidades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dentistas_especialidades` (
  `dentista_id` int unsigned NOT NULL,
  `especialidade_id` int unsigned NOT NULL,
  PRIMARY KEY (`dentista_id`,`especialidade_id`),
  KEY `fk_de_especialidade` (`especialidade_id`),
  CONSTRAINT `fk_de_dentista` FOREIGN KEY (`dentista_id`) REFERENCES `dentistas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_de_especialidade` FOREIGN KEY (`especialidade_id`) REFERENCES `especialidades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dentistas_tratamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dentistas_tratamentos` (
  `dentista_id` int unsigned NOT NULL,
  `tratamento_id` int unsigned NOT NULL,
  PRIMARY KEY (`dentista_id`,`tratamento_id`),
  KEY `fk_dt_tratamento` (`tratamento_id`),
  CONSTRAINT `fk_dt_dentista` FOREIGN KEY (`dentista_id`) REFERENCES `dentistas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dt_tratamento` FOREIGN KEY (`tratamento_id`) REFERENCES `tratamentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `disponibilidades_dentista`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `disponibilidades_dentista` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `dentista_id` int unsigned NOT NULL,
  `dia_semana` tinyint unsigned NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fim` time NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dd_dentista_dia` (`dentista_id`,`dia_semana`),
  CONSTRAINT `fk_dd_dentista` FOREIGN KEY (`dentista_id`) REFERENCES `dentistas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_dd_dia` CHECK ((`dia_semana` between 1 and 7)),
  CONSTRAINT `ck_dd_horas` CHECK ((`hora_fim` > `hora_inicio`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `especialidades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `especialidades` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_especialidades_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `galeria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galeria` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tipo` enum('instalacoes','dentista','resultado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `caminho` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `legenda` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paciente_id` int unsigned DEFAULT NULL,
  `consentimento_confirmado` tinyint(1) NOT NULL DEFAULT '0',
  `consentimento_em` datetime DEFAULT NULL,
  `visivel` tinyint(1) NOT NULL DEFAULT '1',
  `ordem` smallint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_galeria_tipo` (`tipo`,`visivel`),
  KEY `fk_galeria_paciente` (`paciente_id`),
  CONSTRAINT `fk_galeria_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_galeria_consentimento` CHECK (((`tipo` <> _utf8mb4'resultado') or (`visivel` = 0) or (`consentimento_confirmado` = 1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hero_imagens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hero_imagens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `caminho` varchar(255) NOT NULL,
  `legenda` varchar(200) DEFAULT NULL,
  `ordem` int NOT NULL DEFAULT '0',
  `visivel` tinyint(1) NOT NULL DEFAULT '1',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `horarios_funcionamento`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `horarios_funcionamento` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `dia_semana` tinyint unsigned NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fim` time NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_hf_dia` (`dia_semana`),
  CONSTRAINT `ck_hf_dia` CHECK ((`dia_semana` between 1 and 7)),
  CONSTRAINT `ck_hf_horas` CHECK ((`hora_fim` > `hora_inicio`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `horarios_ocupados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `horarios_ocupados` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dentista_id` int unsigned NOT NULL,
  `inicio_bloco` datetime NOT NULL,
  `consulta_id` int unsigned DEFAULT NULL,
  `token_reserva` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expira_em` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ho_dentista_bloco` (`dentista_id`,`inicio_bloco`),
  KEY `idx_ho_consulta` (`consulta_id`),
  KEY `idx_ho_expira` (`expira_em`),
  KEY `idx_ho_token` (`token_reserva`),
  CONSTRAINT `fk_ho_consulta` FOREIGN KEY (`consulta_id`) REFERENCES `consultas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ho_dentista` FOREIGN KEY (`dentista_id`) REFERENCES `dentistas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lista_espera`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lista_espera` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `utilizador_id` int unsigned NOT NULL,
  `paciente_id` int unsigned NOT NULL,
  `dentista_id` int unsigned DEFAULT NULL,
  `tratamento_id` int unsigned DEFAULT NULL,
  `data_desde` date NOT NULL,
  `data_ate` date NOT NULL,
  `periodo` enum('manha','tarde','qualquer') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'qualquer',
  `estado` enum('ativa','notificada','concluida','cancelada') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ativa',
  `notificada_em` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_le_estado` (`estado`,`data_desde`,`data_ate`),
  KEY `fk_le_utilizador` (`utilizador_id`),
  KEY `fk_le_paciente` (`paciente_id`),
  KEY `fk_le_dentista` (`dentista_id`),
  KEY `fk_le_tratamento` (`tratamento_id`),
  CONSTRAINT `fk_le_dentista` FOREIGN KEY (`dentista_id`) REFERENCES `dentistas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_le_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_le_tratamento` FOREIGN KEY (`tratamento_id`) REFERENCES `tratamentos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_le_utilizador` FOREIGN KEY (`utilizador_id`) REFERENCES `utilizadores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_le_datas` CHECK ((`data_ate` >= `data_desde`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marcacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marcacoes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `referencia` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `utilizador_id` int unsigned NOT NULL,
  `estado` enum('pendente','confirmada','cancelada','concluida') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `total_previsto` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tem_preco_estimado` tinyint(1) NOT NULL DEFAULT '0',
  `observacoes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `motivo_cancelamento` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancelada_em` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_marcacoes_referencia` (`referencia`),
  KEY `idx_marcacoes_utilizador` (`utilizador_id`),
  KEY `idx_marcacoes_estado` (`estado`),
  CONSTRAINT `fk_marcacoes_utilizador` FOREIGN KEY (`utilizador_id`) REFERENCES `utilizadores` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mensagens_contacto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mensagens_contacto` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `telemovel` varchar(20) DEFAULT NULL,
  `assunto` varchar(200) NOT NULL,
  `mensagem` text NOT NULL,
  `lida` tinyint(1) NOT NULL DEFAULT '0',
  `respondida` tinyint(1) NOT NULL DEFAULT '0',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migracoes_aplicadas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migracoes_aplicadas` (
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `aplicada_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`nome`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notificacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notificacoes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `utilizador_id` int unsigned NOT NULL,
  `marcacao_id` int unsigned DEFAULT NULL,
  `tipo` enum('confirmacao_marcacao','lembrete_consulta','alteracao_data','cancelamento','pagamento_pendente','pagamento_confirmado','estado_beneficio') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `canal` enum('email','sms','interna') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'interna',
  `assunto` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mensagem` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` enum('pendente','enviada','falhada') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `lida` tinyint(1) NOT NULL DEFAULT '0',
  `agendada_para` datetime DEFAULT NULL,
  `enviada_em` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_utilizador` (`utilizador_id`,`lida`),
  KEY `idx_notif_envio` (`estado`,`agendada_para`),
  KEY `fk_notif_marcacao` (`marcacao_id`),
  CONSTRAINT `fk_notif_marcacao` FOREIGN KEY (`marcacao_id`) REFERENCES `marcacoes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notif_utilizador` FOREIGN KEY (`utilizador_id`) REFERENCES `utilizadores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pacientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pacientes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `utilizador_id` int unsigned DEFAULT NULL,
  `nome_completo` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_nascimento` date DEFAULT NULL,
  `telemovel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_utente_sns` varchar(9) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pacientes_utilizador` (`utilizador_id`),
  KEY `idx_pacientes_nome` (`nome_completo`),
  CONSTRAINT `fk_pacientes_utilizador` FOREIGN KEY (`utilizador_id`) REFERENCES `utilizadores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pagamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pagamentos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `marcacao_id` int unsigned NOT NULL,
  `consulta_id` int unsigned DEFAULT NULL,
  `metodo` enum('eletronico_antecipado','presencial','sinal','mbway','multibanco','cartao_online','outro') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `metodo_detalhe` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor` decimal(10,2) NOT NULL,
  `estado` enum('pendente','em_processamento','pago','parcialmente_pago','recusado','falhado','cancelado','reembolsado','parcialmente_reembolsado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `prestador` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referencia_transacao` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor_reembolsado` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pago_em` datetime DEFAULT NULL,
  `reembolsado_em` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `registado_por` int unsigned DEFAULT NULL,
  `data_pagamento` datetime DEFAULT NULL,
  `observacoes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_metodo` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idempotency_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `falhado_em` datetime DEFAULT NULL,
  `cancelado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pagamentos_transacao` (`prestador`,`referencia_transacao`),
  UNIQUE KEY `uq_pagamentos_idempotency` (`gateway`,`idempotency_key`),
  KEY `idx_pagamentos_marcacao` (`marcacao_id`),
  KEY `idx_pagamentos_estado` (`estado`),
  KEY `idx_pagamentos_pago_em` (`pago_em`),
  KEY `idx_pagamentos_consulta` (`consulta_id`),
  KEY `idx_pagamentos_registado_por` (`registado_por`),
  CONSTRAINT `fk_pagamentos_consulta` FOREIGN KEY (`consulta_id`) REFERENCES `consultas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pagamentos_marcacao` FOREIGN KEY (`marcacao_id`) REFERENCES `marcacoes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pagamentos_registado_por` FOREIGN KEY (`registado_por`) REFERENCES `utilizadores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_pagamentos_valor` CHECK (((`valor` >= 0) and (`valor_reembolsado` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `parceiros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parceiros` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) NOT NULL,
  `logo` varchar(255) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `visivel` tinyint(1) NOT NULL DEFAULT '1',
  `ordem` int NOT NULL DEFAULT '0',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pedidos_alteracao`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pedidos_alteracao` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `consulta_id` int unsigned NOT NULL,
  `utilizador_id` int unsigned NOT NULL,
  `novo_inicio` datetime NOT NULL,
  `motivo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` enum('pendente','aprovado','recusado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `decidido_por` int unsigned DEFAULT NULL,
  `decidido_em` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pa_consulta` (`consulta_id`),
  KEY `idx_pa_estado` (`estado`),
  KEY `fk_pa_utilizador` (`utilizador_id`),
  KEY `fk_pa_decisor` (`decidido_por`),
  CONSTRAINT `fk_pa_consulta` FOREIGN KEY (`consulta_id`) REFERENCES `consultas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_decisor` FOREIGN KEY (`decidido_por`) REFERENCES `utilizadores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pa_utilizador` FOREIGN KEY (`utilizador_id`) REFERENCES `utilizadores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `perguntas_frequentes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `perguntas_frequentes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pergunta` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `resposta` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordem` smallint NOT NULL DEFAULT '0',
  `visivel` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `planos_beneficio`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `planos_beneficio` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `seguradora_id` int unsigned NOT NULL,
  `nome` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `condicoes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planos_seguradora_nome` (`seguradora_id`,`nome`),
  CONSTRAINT `fk_planos_seguradora` FOREIGN KEY (`seguradora_id`) REFERENCES `seguradoras` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `planos_tratamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `planos_tratamentos` (
  `plano_id` int unsigned NOT NULL,
  `tratamento_id` int unsigned NOT NULL,
  `tipo_desconto` enum('percentagem','valor_fixo') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percentagem',
  `valor` decimal(10,2) NOT NULL,
  `preco_convencionado` decimal(10,2) DEFAULT NULL,
  `valor_max_comparticipado` decimal(10,2) DEFAULT NULL,
  `percentagem_comparticipacao` decimal(5,2) DEFAULT NULL,
  `condicoes_especificas` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`plano_id`,`tratamento_id`),
  KEY `fk_pt_tratamento` (`tratamento_id`),
  CONSTRAINT `fk_pt_plano` FOREIGN KEY (`plano_id`) REFERENCES `planos_beneficio` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pt_tratamento` FOREIGN KEY (`tratamento_id`) REFERENCES `tratamentos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_pt_valor` CHECK ((`valor` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `registo_admin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `registo_admin` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `utilizador_id` int unsigned DEFAULT NULL,
  `acao` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entidade` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entidade_id` int unsigned DEFAULT NULL,
  `detalhes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `motivo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `antes_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `depois_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_registo_utilizador` (`utilizador_id`),
  KEY `idx_registo_data` (`criado_em`),
  CONSTRAINT `fk_registo_utilizador` FOREIGN KEY (`utilizador_id`) REFERENCES `utilizadores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seguradoras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seguradoras` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` enum('seguro_saude','seguro_dentario','plano_saude','apoio_publico','desconto_comercial') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seguradoras_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_categorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_categorias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(80) NOT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_encomenda_linhas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_encomenda_linhas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `encomenda_id` int NOT NULL,
  `material_id` int NOT NULL,
  `quantidade` int NOT NULL,
  `preco_unitario` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_enc_linha` (`encomenda_id`),
  KEY `fk_enc_material` (`material_id`),
  CONSTRAINT `fk_enc_linha` FOREIGN KEY (`encomenda_id`) REFERENCES `stock_encomendas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_enc_material` FOREIGN KEY (`material_id`) REFERENCES `stock_materiais` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_encomendas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_encomendas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `referencia` varchar(30) NOT NULL,
  `fornecedor` varchar(120) DEFAULT NULL,
  `estado` enum('pendente','enviada','recebida','cancelada') NOT NULL DEFAULT 'pendente',
  `total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `notas` varchar(500) DEFAULT NULL,
  `criado_por` int DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recebida_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `referencia` (`referencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_materiais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_materiais` (
  `id` int NOT NULL AUTO_INCREMENT,
  `categoria_id` int DEFAULT NULL,
  `referencia` varchar(60) DEFAULT NULL,
  `nome` varchar(150) NOT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `unidade` varchar(20) NOT NULL DEFAULT 'un',
  `quantidade` int NOT NULL DEFAULT '0',
  `stock_minimo` int NOT NULL DEFAULT '0',
  `stock_maximo` int DEFAULT NULL,
  `preco_unitario` decimal(10,2) DEFAULT NULL,
  `fornecedor` varchar(120) DEFAULT NULL,
  `validade` date DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `referencia` (`referencia`),
  KEY `fk_stock_cat` (`categoria_id`),
  CONSTRAINT `fk_stock_cat` FOREIGN KEY (`categoria_id`) REFERENCES `stock_categorias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_movimentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_movimentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `material_id` int NOT NULL,
  `tipo` enum('entrada','saida','ajuste','perda') NOT NULL,
  `quantidade` int NOT NULL,
  `quantidade_antes` int NOT NULL,
  `quantidade_depois` int NOT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `utilizador_id` int DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_mov_material` (`material_id`),
  CONSTRAINT `fk_mov_material` FOREIGN KEY (`material_id`) REFERENCES `stock_materiais` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tentativas_login`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tentativas_login` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sucesso` tinyint(1) NOT NULL DEFAULT '0',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tentativas_email` (`email`,`criado_em`),
  KEY `idx_tentativas_ip` (`ip`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `testemunhos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `testemunhos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) NOT NULL,
  `texto` text NOT NULL,
  `local` varchar(80) DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `classificacao` tinyint DEFAULT NULL,
  `consentimento_confirmado` tinyint(1) NOT NULL DEFAULT '0',
  `visivel` tinyint(1) NOT NULL DEFAULT '1',
  `ordem` int NOT NULL DEFAULT '0',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tokens_conta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tokens_conta` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `utilizador_id` int unsigned NOT NULL,
  `tipo` enum('verificar_email','recuperar_password') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expira_em` datetime NOT NULL,
  `usado_em` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tokens_hash` (`token_hash`),
  KEY `idx_tokens_utilizador` (`utilizador_id`),
  CONSTRAINT `fk_tokens_utilizador` FOREIGN KEY (`utilizador_id`) REFERENCES `utilizadores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tratamentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tratamentos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `especialidade_id` int unsigned DEFAULT NULL,
  `nome` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `preco` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tipo_preco` enum('fixo','sujeito_avaliacao') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixo',
  `duracao_min` smallint unsigned NOT NULL DEFAULT '30',
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tratamentos_nome` (`nome`),
  KEY `idx_tratamentos_especialidade` (`especialidade_id`),
  CONSTRAINT `fk_tratamentos_especialidade` FOREIGN KEY (`especialidade_id`) REFERENCES `especialidades` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_tratamentos_duracao` CHECK ((`duracao_min` > 0)),
  CONSTRAINT `ck_tratamentos_preco` CHECK ((`preco` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `utilizadores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `utilizadores` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nome_completo` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_customer_id` varchar(255) DEFAULT NULL,
  `telemovel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `papel` enum('paciente','rececao','dentista','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paciente',
  `tema` enum('claro','escuro') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'claro',
  `email_verificado_em` datetime DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `ultimo_login` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_utilizadores_email` (`email`),
  UNIQUE KEY `uq_utilizadores_stripe_customer` (`stripe_customer_id`),
  KEY `idx_utilizadores_papel` (`papel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

