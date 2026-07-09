/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: africode
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_logs`
--

DROP TABLE IF EXISTS `chat_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL,
  `message` text NOT NULL,
  `reply` text DEFAULT NULL,
  `tools_called` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tools_called`)),
  `gemini_requests` smallint(5) unsigned NOT NULL DEFAULT 0,
  `prompt_tokens` int(10) unsigned DEFAULT NULL,
  `completion_tokens` int(10) unsigned DEFAULT NULL,
  `total_tokens` int(10) unsigned DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'ok',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_logs_created_at_index` (`created_at`),
  KEY `chat_logs_session_id_index` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_logs`
--

LOCK TABLES `chat_logs` WRITE;
/*!40000 ALTER TABLE `chat_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fixtures`
--

DROP TABLE IF EXISTS `fixtures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fixtures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `league_id` bigint(20) unsigned NOT NULL,
  `season` varchar(9) NOT NULL,
  `matchday` smallint(5) unsigned DEFAULT NULL,
  `home_team_id` bigint(20) unsigned NOT NULL,
  `away_team_id` bigint(20) unsigned NOT NULL,
  `kickoff_utc` datetime NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'scheduled',
  `referee_id` bigint(20) unsigned DEFAULT NULL,
  `footballdata_match_id` bigint(20) unsigned DEFAULT NULL,
  `fbref_game_id` varchar(16) DEFAULT NULL,
  `home_goals` tinyint(3) unsigned DEFAULT NULL,
  `away_goals` tinyint(3) unsigned DEFAULT NULL,
  `is_derby` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fixtures_footballdata_match_id_unique` (`footballdata_match_id`),
  UNIQUE KEY `fixtures_fbref_game_id_unique` (`fbref_game_id`),
  KEY `fixtures_home_team_id_foreign` (`home_team_id`),
  KEY `fixtures_away_team_id_foreign` (`away_team_id`),
  KEY `fixtures_referee_id_foreign` (`referee_id`),
  KEY `fixtures_league_id_season_index` (`league_id`,`season`),
  KEY `fixtures_status_kickoff_utc_index` (`status`,`kickoff_utc`),
  CONSTRAINT `fixtures_away_team_id_foreign` FOREIGN KEY (`away_team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fixtures_home_team_id_foreign` FOREIGN KEY (`home_team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fixtures_league_id_foreign` FOREIGN KEY (`league_id`) REFERENCES `leagues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fixtures_referee_id_foreign` FOREIGN KEY (`referee_id`) REFERENCES `referees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fixtures`
--

LOCK TABLES `fixtures` WRITE;
/*!40000 ALTER TABLE `fixtures` DISABLE KEYS */;
/*!40000 ALTER TABLE `fixtures` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leagues`
--

DROP TABLE IF EXISTS `leagues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leagues` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(8) NOT NULL,
  `name` varchar(255) NOT NULL,
  `country` varchar(255) NOT NULL,
  `fbref_id` varchar(255) DEFAULT NULL,
  `apifootball_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leagues_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leagues`
--

LOCK TABLES `leagues` WRITE;
/*!40000 ALTER TABLE `leagues` DISABLE KEYS */;
INSERT INTO `leagues` VALUES
(1,'PL','Premier League','England','9',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(2,'PD','La Liga','Spain','12',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(3,'SA','Serie A','Italy','11',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(4,'BL1','Bundesliga','Germany','20',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(5,'FL1','Ligue 1','France','13',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09');
/*!40000 ALTER TABLE `leagues` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `match_stats`
--

DROP TABLE IF EXISTS `match_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `match_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fixture_id` bigint(20) unsigned NOT NULL,
  `team_id` bigint(20) unsigned NOT NULL,
  `is_home` tinyint(1) NOT NULL,
  `goals` tinyint(3) unsigned DEFAULT NULL,
  `xg` decimal(5,2) DEFAULT NULL,
  `xga` decimal(5,2) DEFAULT NULL,
  `shots` smallint(5) unsigned DEFAULT NULL,
  `shots_on_target` smallint(5) unsigned DEFAULT NULL,
  `shots_on_target_against` smallint(5) unsigned DEFAULT NULL,
  `corners_for` smallint(5) unsigned DEFAULT NULL,
  `corners_against` smallint(5) unsigned DEFAULT NULL,
  `crosses` smallint(5) unsigned DEFAULT NULL,
  `fouls_committed` smallint(5) unsigned DEFAULT NULL,
  `fouls_drawn` smallint(5) unsigned DEFAULT NULL,
  `yellows` tinyint(3) unsigned DEFAULT NULL,
  `reds` tinyint(3) unsigned DEFAULT NULL,
  `possession` decimal(5,2) DEFAULT NULL,
  `source` varchar(16) NOT NULL DEFAULT 'fbref',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `match_stats_fixture_id_team_id_unique` (`fixture_id`,`team_id`),
  KEY `match_stats_team_id_foreign` (`team_id`),
  CONSTRAINT `match_stats_fixture_id_foreign` FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`id`) ON DELETE CASCADE,
  CONSTRAINT `match_stats_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `match_stats`
--

LOCK TABLES `match_stats` WRITE;
/*!40000 ALTER TABLE `match_stats` DISABLE KEYS */;
/*!40000 ALTER TABLE `match_stats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES
(1,'0001_01_01_000000_create_users_table',1),
(2,'0001_01_01_000001_create_cache_table',1),
(3,'0001_01_01_000002_create_jobs_table',1),
(4,'2026_07_08_000001_create_leagues_table',1),
(5,'2026_07_08_000002_create_teams_table',1),
(6,'2026_07_08_000003_create_referees_table',1),
(7,'2026_07_08_000004_create_rivalries_table',1),
(8,'2026_07_08_000005_create_fixtures_table',1),
(9,'2026_07_08_000006_create_match_stats_table',1),
(10,'2026_07_08_000007_create_team_profiles_table',1),
(11,'2026_07_08_000008_create_predictions_table',1),
(12,'2026_07_08_000009_create_prediction_markets_table',1),
(13,'2026_07_08_000010_create_model_accuracy_table',1),
(14,'2026_07_08_000011_create_pipeline_runs_table',1),
(15,'2026_07_09_000001_create_chat_logs_table',1),
(16,'2026_07_09_000002_add_fbref_game_id_to_fixtures',1),
(17,'2026_07_09_000003_create_players_table',1),
(18,'2026_07_09_000004_create_player_match_stats_table',1),
(19,'2026_07_09_000005_create_player_scrape_progress_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_accuracy`
--

DROP TABLE IF EXISTS `model_accuracy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_accuracy` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `market` varchar(32) NOT NULL,
  `line_bucket` varchar(16) NOT NULL,
  `total_settled` int(10) unsigned NOT NULL DEFAULT 0,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  `hit_rate` decimal(5,4) DEFAULT NULL,
  `avg_probability` decimal(5,4) DEFAULT NULL,
  `calibration_gap` decimal(6,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `model_accuracy_market_line_bucket_unique` (`market`,`line_bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_accuracy`
--

LOCK TABLES `model_accuracy` WRITE;
/*!40000 ALTER TABLE `model_accuracy` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_accuracy` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pipeline_runs`
--

DROP TABLE IF EXISTS `pipeline_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pipeline_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_name` varchar(64) NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'running',
  `error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pipeline_runs_job_name_started_at_index` (`job_name`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pipeline_runs`
--

LOCK TABLES `pipeline_runs` WRITE;
/*!40000 ALTER TABLE `pipeline_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `pipeline_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `player_match_stats`
--

DROP TABLE IF EXISTS `player_match_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `player_match_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `player_id` bigint(20) unsigned NOT NULL,
  `fixture_id` bigint(20) unsigned NOT NULL,
  `team_id` bigint(20) unsigned NOT NULL,
  `minutes` tinyint(3) unsigned DEFAULT NULL,
  `goals` tinyint(3) unsigned DEFAULT NULL,
  `assists` tinyint(3) unsigned DEFAULT NULL,
  `shots` tinyint(3) unsigned DEFAULT NULL,
  `shots_on_target` tinyint(3) unsigned DEFAULT NULL,
  `yellows` tinyint(3) unsigned DEFAULT NULL,
  `reds` tinyint(3) unsigned DEFAULT NULL,
  `xg` decimal(4,2) DEFAULT NULL,
  `xa` decimal(4,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `player_match_stats_player_id_fixture_id_unique` (`player_id`,`fixture_id`),
  KEY `player_match_stats_fixture_id_foreign` (`fixture_id`),
  KEY `player_match_stats_team_id_fixture_id_index` (`team_id`,`fixture_id`),
  CONSTRAINT `player_match_stats_fixture_id_foreign` FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`id`) ON DELETE CASCADE,
  CONSTRAINT `player_match_stats_player_id_foreign` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE,
  CONSTRAINT `player_match_stats_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `player_match_stats`
--

LOCK TABLES `player_match_stats` WRITE;
/*!40000 ALTER TABLE `player_match_stats` DISABLE KEYS */;
/*!40000 ALTER TABLE `player_match_stats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `player_scrape_progress`
--

DROP TABLE IF EXISTS `player_scrape_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `player_scrape_progress` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fixture_id` bigint(20) unsigned NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `scraped_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `player_scrape_progress_fixture_id_unique` (`fixture_id`),
  KEY `player_scrape_progress_status_attempts_index` (`status`,`attempts`),
  CONSTRAINT `player_scrape_progress_fixture_id_foreign` FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `player_scrape_progress`
--

LOCK TABLES `player_scrape_progress` WRITE;
/*!40000 ALTER TABLE `player_scrape_progress` DISABLE KEYS */;
/*!40000 ALTER TABLE `player_scrape_progress` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `players`
--

DROP TABLE IF EXISTS `players`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `players` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `team_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `fbref_id` varchar(16) DEFAULT NULL,
  `position` varchar(16) DEFAULT NULL,
  `nationality` varchar(8) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `players_name_nationality_unique` (`name`,`nationality`),
  UNIQUE KEY `players_fbref_id_unique` (`fbref_id`),
  KEY `players_team_id_foreign` (`team_id`),
  KEY `players_name_index` (`name`),
  CONSTRAINT `players_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `players`
--

LOCK TABLES `players` WRITE;
/*!40000 ALTER TABLE `players` DISABLE KEYS */;
/*!40000 ALTER TABLE `players` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `prediction_markets`
--

DROP TABLE IF EXISTS `prediction_markets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `prediction_markets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `prediction_id` bigint(20) unsigned NOT NULL,
  `market` varchar(32) NOT NULL,
  `line` decimal(4,1) DEFAULT NULL,
  `direction` varchar(8) NOT NULL,
  `probability` decimal(5,4) NOT NULL,
  `confidence_margin` decimal(5,4) NOT NULL,
  `outcome` varchar(8) NOT NULL DEFAULT 'pending',
  `settled_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `prediction_markets_market_outcome_index` (`market`,`outcome`),
  KEY `prediction_markets_prediction_id_market_index` (`prediction_id`,`market`),
  CONSTRAINT `prediction_markets_prediction_id_foreign` FOREIGN KEY (`prediction_id`) REFERENCES `predictions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `prediction_markets`
--

LOCK TABLES `prediction_markets` WRITE;
/*!40000 ALTER TABLE `prediction_markets` DISABLE KEYS */;
/*!40000 ALTER TABLE `prediction_markets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `predictions`
--

DROP TABLE IF EXISTS `predictions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `predictions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fixture_id` bigint(20) unsigned NOT NULL,
  `generated_at` datetime NOT NULL,
  `model_version` varchar(32) NOT NULL,
  `best_bet_market` varchar(32) NOT NULL,
  `best_bet_line` decimal(4,1) DEFAULT NULL,
  `best_bet_direction` varchar(8) NOT NULL,
  `best_bet_probability` decimal(5,4) NOT NULL,
  `headline_text` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `predictions_fixture_id_generated_at_index` (`fixture_id`,`generated_at`),
  CONSTRAINT `predictions_fixture_id_foreign` FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `predictions`
--

LOCK TABLES `predictions` WRITE;
/*!40000 ALTER TABLE `predictions` DISABLE KEYS */;
/*!40000 ALTER TABLE `predictions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `referees`
--

DROP TABLE IF EXISTS `referees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `referees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `matches_officiated` smallint(5) unsigned NOT NULL DEFAULT 0,
  `avg_yellows_per_match` decimal(5,2) DEFAULT NULL,
  `avg_reds_per_match` decimal(5,2) DEFAULT NULL,
  `avg_fouls_per_match` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `referees_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `referees`
--

LOCK TABLES `referees` WRITE;
/*!40000 ALTER TABLE `referees` DISABLE KEYS */;
/*!40000 ALTER TABLE `referees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rivalries`
--

DROP TABLE IF EXISTS `rivalries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rivalries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `league_id` bigint(20) unsigned NOT NULL,
  `team1_id` bigint(20) unsigned NOT NULL,
  `team2_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rivalries_team1_id_team2_id_unique` (`team1_id`,`team2_id`),
  KEY `rivalries_league_id_foreign` (`league_id`),
  KEY `rivalries_team2_id_foreign` (`team2_id`),
  CONSTRAINT `rivalries_league_id_foreign` FOREIGN KEY (`league_id`) REFERENCES `leagues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rivalries_team1_id_foreign` FOREIGN KEY (`team1_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rivalries_team2_id_foreign` FOREIGN KEY (`team2_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rivalries`
--

LOCK TABLES `rivalries` WRITE;
/*!40000 ALTER TABLE `rivalries` DISABLE KEYS */;
INSERT INTO `rivalries` VALUES
(1,1,1,18,'North London Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(2,1,12,9,'Merseyside Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(3,1,12,14,'North-West Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(4,1,13,14,'Manchester Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(5,1,15,17,'Tyne-Wear Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(6,1,11,14,'Roses Rivalry','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(7,1,8,5,'M23 Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(8,1,7,1,'London Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(9,1,7,18,'London Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(10,1,19,18,'London Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(11,1,7,10,'West London Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(12,1,10,4,'West London Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(13,2,35,24,'El Clásico','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(14,2,35,23,'Madrid Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(15,2,34,23,'Madrid Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(16,2,34,35,'Madrid Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(17,2,38,25,'Seville Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(18,2,22,37,'Basque Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(19,2,24,28,'Barcelona Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(20,2,39,31,'Valencia Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(21,2,39,40,'Comunitat Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(22,3,49,53,'Derby della Madonnina','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(23,3,57,51,'Derby della Capitale','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(24,3,50,59,'Derby della Mole','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(25,3,49,50,'Derby d\'Italia','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(26,3,57,54,'Derby del Sole','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(27,3,42,46,'Derby dell\'Appennino','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(28,4,63,64,'Der Klassiker','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(29,4,68,74,'Hamburg Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(30,4,68,77,'Nordderby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(31,4,71,65,'Rheinderby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(32,4,71,62,'Rhein Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(33,4,66,72,'Rhein-Main Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(34,5,93,87,'Le Classique','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(35,5,86,87,'Choc des Olympiques','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(36,5,83,84,'Derby du Nord','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(37,5,90,94,'Derby de l\'Ouest','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(38,5,91,89,'Derby de la Côte d\'Azur','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(39,5,93,92,'Paris Derby','2026-07-09 13:57:09','2026-07-09 13:57:09'),
(40,5,81,94,'Derby Breton','2026-07-09 13:57:09','2026-07-09 13:57:09');
/*!40000 ALTER TABLE `rivalries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `team_profiles`
--

DROP TABLE IF EXISTS `team_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `team_id` bigint(20) unsigned NOT NULL,
  `season` varchar(9) NOT NULL,
  `matches_played` smallint(5) unsigned NOT NULL DEFAULT 0,
  `attack_strength` decimal(8,4) DEFAULT NULL,
  `defence_strength` decimal(8,4) DEFAULT NULL,
  `xg_for_avg` decimal(6,3) DEFAULT NULL,
  `xg_against_avg` decimal(6,3) DEFAULT NULL,
  `corners_for_avg` decimal(6,3) DEFAULT NULL,
  `corners_against_avg` decimal(6,3) DEFAULT NULL,
  `crosses_avg` decimal(6,3) DEFAULT NULL,
  `cards_avg` decimal(6,3) DEFAULT NULL,
  `fouls_committed_avg` decimal(6,3) DEFAULT NULL,
  `fouls_drawn_avg` decimal(6,3) DEFAULT NULL,
  `sot_for_avg` decimal(6,3) DEFAULT NULL,
  `sot_against_avg` decimal(6,3) DEFAULT NULL,
  `home_advantage_factor` decimal(8,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `team_profiles_team_id_season_unique` (`team_id`,`season`),
  CONSTRAINT `team_profiles_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `team_profiles`
--

LOCK TABLES `team_profiles` WRITE;
/*!40000 ALTER TABLE `team_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `team_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teams`
--

DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `teams` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `league_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `fbref_name` varchar(255) NOT NULL,
  `footballdata_id` int(10) unsigned DEFAULT NULL,
  `apifootball_id` int(10) unsigned DEFAULT NULL,
  `short_name` varchar(32) NOT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teams_league_id_name_unique` (`league_id`,`name`),
  UNIQUE KEY `teams_footballdata_id_unique` (`footballdata_id`),
  CONSTRAINT `teams_league_id_foreign` FOREIGN KEY (`league_id`) REFERENCES `leagues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=97 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teams`
--

LOCK TABLES `teams` WRITE;
/*!40000 ALTER TABLE `teams` DISABLE KEYS */;
INSERT INTO `teams` VALUES
(1,1,'Arsenal','Arsenal',NULL,NULL,'ARS',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(2,1,'Aston Villa','Aston Villa',NULL,NULL,'AVL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(3,1,'AFC Bournemouth','Bournemouth',NULL,NULL,'BOU',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(4,1,'Brentford','Brentford',NULL,NULL,'BRE',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(5,1,'Brighton & Hove Albion','Brighton',NULL,NULL,'BHA',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(6,1,'Burnley','Burnley',NULL,NULL,'BUR',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(7,1,'Chelsea','Chelsea',NULL,NULL,'CHE',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(8,1,'Crystal Palace','Crystal Palace',NULL,NULL,'CRY',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(9,1,'Everton','Everton',NULL,NULL,'EVE',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(10,1,'Fulham','Fulham',NULL,NULL,'FUL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(11,1,'Leeds United','Leeds United',NULL,NULL,'LEE',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(12,1,'Liverpool','Liverpool',NULL,NULL,'LIV',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(13,1,'Manchester City','Manchester City',NULL,NULL,'MCI',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(14,1,'Manchester United','Manchester Utd',NULL,NULL,'MUN',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(15,1,'Newcastle United','Newcastle Utd',NULL,NULL,'NEW',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(16,1,'Nottingham Forest','Nott\'ham Forest',NULL,NULL,'NFO',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(17,1,'Sunderland','Sunderland',NULL,NULL,'SUN',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(18,1,'Tottenham Hotspur','Tottenham',NULL,NULL,'TOT',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(19,1,'West Ham United','West Ham',NULL,NULL,'WHU',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(20,1,'Wolverhampton Wanderers','Wolves',NULL,NULL,'WOL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(21,2,'Deportivo Alavés','Alavés',NULL,NULL,'ALA',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(22,2,'Athletic Club','Athletic Club',NULL,NULL,'ATH',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(23,2,'Atlético Madrid','Atlético Madrid',NULL,NULL,'ATM',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(24,2,'FC Barcelona','Barcelona',NULL,NULL,'BAR',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(25,2,'Real Betis','Betis',NULL,NULL,'BET',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(26,2,'Celta Vigo','Celta Vigo',NULL,NULL,'CEL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(27,2,'Elche','Elche',NULL,NULL,'ELC',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(28,2,'Espanyol','Espanyol',NULL,NULL,'ESP',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(29,2,'Getafe','Getafe',NULL,NULL,'GET',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(30,2,'Girona','Girona',NULL,NULL,'GIR',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(31,2,'Levante','Levante',NULL,NULL,'LEV',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(32,2,'RCD Mallorca','Mallorca',NULL,NULL,'MLL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(33,2,'Osasuna','Osasuna',NULL,NULL,'OSA',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(34,2,'Rayo Vallecano','Rayo Vallecano',NULL,NULL,'RAY',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(35,2,'Real Madrid','Real Madrid',NULL,NULL,'RMA',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(36,2,'Real Oviedo','Oviedo',NULL,NULL,'OVI',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(37,2,'Real Sociedad','Real Sociedad',NULL,NULL,'RSO',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(38,2,'Sevilla','Sevilla',NULL,NULL,'SEV',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(39,2,'Valencia','Valencia',NULL,NULL,'VAL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(40,2,'Villarreal','Villarreal',NULL,NULL,'VIL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(41,3,'Atalanta','Atalanta',NULL,NULL,'ATA',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(42,3,'Bologna','Bologna',NULL,NULL,'BOL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(43,3,'Cagliari','Cagliari',NULL,NULL,'CAG',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(44,3,'Como','Como',NULL,NULL,'COM',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(45,3,'Cremonese','Cremonese',NULL,NULL,'CRE',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(46,3,'Fiorentina','Fiorentina',NULL,NULL,'FIO',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(47,3,'Genoa','Genoa',NULL,NULL,'GEN',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(48,3,'Hellas Verona','Hellas Verona',NULL,NULL,'VER',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(49,3,'Inter Milan','Inter',NULL,NULL,'INT',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(50,3,'Juventus','Juventus',NULL,NULL,'JUV',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(51,3,'Lazio','Lazio',NULL,NULL,'LAZ',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(52,3,'Lecce','Lecce',NULL,NULL,'LEC',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(53,3,'AC Milan','Milan',NULL,NULL,'MIL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(54,3,'Napoli','Napoli',NULL,NULL,'NAP',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(55,3,'Parma','Parma',NULL,NULL,'PAR',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(56,3,'Pisa','Pisa',NULL,NULL,'PIS',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(57,3,'AS Roma','Roma',NULL,NULL,'ROM',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(58,3,'Sassuolo','Sassuolo',NULL,NULL,'SAS',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(59,3,'Torino','Torino',NULL,NULL,'TOR',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(60,3,'Udinese','Udinese',NULL,NULL,'UDI',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(61,4,'FC Augsburg','Augsburg',NULL,NULL,'FCA',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(62,4,'Bayer Leverkusen','Leverkusen',NULL,NULL,'B04',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(63,4,'Bayern Munich','Bayern Munich',NULL,NULL,'FCB',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(64,4,'Borussia Dortmund','Dortmund',NULL,NULL,'BVB',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(65,4,'Borussia Mönchengladbach','Gladbach',NULL,NULL,'BMG',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(66,4,'Eintracht Frankfurt','Eint Frankfurt',NULL,NULL,'SGE',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(67,4,'SC Freiburg','Freiburg',NULL,NULL,'SCF',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(68,4,'Hamburger SV','Hamburger SV',NULL,NULL,'HSV',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(69,4,'1. FC Heidenheim','Heidenheim',NULL,NULL,'HDH',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(70,4,'TSG Hoffenheim','Hoffenheim',NULL,NULL,'TSG',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(71,4,'1. FC Köln','Köln',NULL,NULL,'KOE',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(72,4,'Mainz 05','Mainz 05',NULL,NULL,'M05',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(73,4,'RB Leipzig','RB Leipzig',NULL,NULL,'RBL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(74,4,'FC St. Pauli','St. Pauli',NULL,NULL,'STP',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(75,4,'VfB Stuttgart','Stuttgart',NULL,NULL,'VFB',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(76,4,'Union Berlin','Union Berlin',NULL,NULL,'FCU',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(77,4,'Werder Bremen','Werder Bremen',NULL,NULL,'SVW',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(78,4,'VfL Wolfsburg','Wolfsburg',NULL,NULL,'WOB',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(79,5,'Angers SCO','Angers',NULL,NULL,'ANG',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(80,5,'AJ Auxerre','Auxerre',NULL,NULL,'AJA',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(81,5,'Stade Brestois','Brest',NULL,NULL,'BRE',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(82,5,'Le Havre','Le Havre',NULL,NULL,'HAC',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(83,5,'RC Lens','Lens',NULL,NULL,'RCL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(84,5,'Lille OSC','Lille',NULL,NULL,'LIL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(85,5,'FC Lorient','Lorient',NULL,NULL,'FCL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(86,5,'Olympique Lyonnais','Lyon',NULL,NULL,'OL',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(87,5,'Olympique de Marseille','Marseille',NULL,NULL,'OM',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(88,5,'FC Metz','Metz',NULL,NULL,'MET',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(89,5,'AS Monaco','Monaco',NULL,NULL,'ASM',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(90,5,'FC Nantes','Nantes',NULL,NULL,'NAN',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(91,5,'OGC Nice','Nice',NULL,NULL,'OGC',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(92,5,'Paris FC','Paris FC',NULL,NULL,'PFC',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(93,5,'Paris Saint-Germain','Paris S-G',NULL,NULL,'PSG',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(94,5,'Stade Rennais','Rennes',NULL,NULL,'REN',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(95,5,'RC Strasbourg','Strasbourg',NULL,NULL,'RCS',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09'),
(96,5,'Toulouse FC','Toulouse',NULL,NULL,'TFC',NULL,'2026-07-09 13:57:09','2026-07-09 13:57:09');
/*!40000 ALTER TABLE `teams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-09 13:57:21
