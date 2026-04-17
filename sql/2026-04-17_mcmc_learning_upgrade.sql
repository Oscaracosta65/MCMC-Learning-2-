CREATE TABLE IF NOT EXISTS `#__mcmc_learning_state` (
  `id`                      INT NOT NULL AUTO_INCREMENT,
  `game_id`                 VARCHAR(64) NOT NULL,
  `regime_key`              VARCHAR(128) NOT NULL,
  `model_version`           VARCHAR(32) NOT NULL,
  `best_params_json`        LONGTEXT NOT NULL,
  `component_weights_json`  LONGTEXT NOT NULL,
  `calibration_map_json`    LONGTEXT NOT NULL,
  `rolling_metrics_json`    LONGTEXT NOT NULL,
  `confidence_score`        DECIMAL(10,6) NOT NULL DEFAULT 0,
  `stability_score`         DECIMAL(10,6) NOT NULL DEFAULT 0,
  `sample_size`             INT NOT NULL DEFAULT 0,
  `last_backtest_end`       DATE NULL,
  `last_draw_count`         INT NOT NULL DEFAULT 0,
  `is_active`               TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`              DATETIME NOT NULL,
  `updated_at`              DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_game_regime_ver` (`game_id`,`regime_key`,`model_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcmc_backtest_runs` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `game_id`         VARCHAR(64) NOT NULL,
  `regime_key`      VARCHAR(128) NOT NULL,
  `model_version`   VARCHAR(32) NOT NULL,
  `window_mode`     VARCHAR(32) NOT NULL,
  `window_size`     INT NOT NULL DEFAULT 0,
  `top_n`           INT NOT NULL DEFAULT 20,
  `settings_json`   LONGTEXT NOT NULL,
  `summary_json`    LONGTEXT NOT NULL,
  `score_overall`   DECIMAL(14,8) NOT NULL DEFAULT 0,
  `score_recent_10` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `score_recent_25` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `log_loss`        DECIMAL(14,8) NOT NULL DEFAULT 0,
  `brier_score`     DECIMAL(14,8) NOT NULL DEFAULT 0,
  `hit_rate`        DECIMAL(14,8) NOT NULL DEFAULT 0,
  `stability_score` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `cases_tested`    INT NOT NULL DEFAULT 0,
  `started_at`      DATETIME NOT NULL,
  `finished_at`     DATETIME NULL,
  `status`          VARCHAR(16) NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `idx_bt_game_regime` (`game_id`,`regime_key`),
  KEY `idx_bt_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcmc_optimizer_runs` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `game_id`           VARCHAR(64) NOT NULL,
  `regime_key`        VARCHAR(128) NOT NULL,
  `model_version`     VARCHAR(32) NOT NULL,
  `search_space_json` LONGTEXT NOT NULL,
  `best_result_json`  LONGTEXT NOT NULL,
  `leaderboard_json`  LONGTEXT NOT NULL,
  `heatmap_json`      LONGTEXT NOT NULL,
  `cases_evaluated`   INT NOT NULL DEFAULT 0,
  `started_at`        DATETIME NOT NULL,
  `finished_at`       DATETIME NULL,
  `status`            VARCHAR(16) NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `idx_opt_game_regime` (`game_id`,`regime_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcmc_param_history` (
  `id`                    INT NOT NULL AUTO_INCREMENT,
  `game_id`               VARCHAR(64) NOT NULL,
  `regime_key`            VARCHAR(128) NOT NULL,
  `model_version`         VARCHAR(32) NOT NULL,
  `walks`                 INT NOT NULL,
  `burn_in`               INT NOT NULL,
  `chain_len`             INT NOT NULL,
  `laplace_k`             DECIMAL(12,6) NOT NULL,
  `decay`                 DECIMAL(12,6) NOT NULL,
  `ensemble_weights_json` LONGTEXT NOT NULL,
  `score`                 DECIMAL(14,8) NOT NULL DEFAULT 0,
  `log_loss`              DECIMAL(14,8) NOT NULL DEFAULT 0,
  `brier_score`           DECIMAL(14,8) NOT NULL DEFAULT 0,
  `hit_rate`              DECIMAL(14,8) NOT NULL DEFAULT 0,
  `stability_score`       DECIMAL(14,8) NOT NULL DEFAULT 0,
  `calibration_score`     DECIMAL(14,8) NOT NULL DEFAULT 0,
  `complexity_penalty`    DECIMAL(14,8) NOT NULL DEFAULT 0,
  `cases_tested`          INT NOT NULL DEFAULT 0,
  `tested_at`             DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ph_game_regime_date` (`game_id`,`regime_key`,`tested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcmc_number_features` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `game_id`      VARCHAR(64) NOT NULL,
  `draw_date`    DATE NOT NULL,
  `number_value` INT NOT NULL,
  `ball_type`    VARCHAR(16) NOT NULL,
  `feature_json` LONGTEXT NOT NULL,
  `created_at`   DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nf_game_date_num_type` (`game_id`,`draw_date`,`number_value`,`ball_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcmc_model_audit` (
  `id`                  INT NOT NULL AUTO_INCREMENT,
  `game_id`             VARCHAR(64) NOT NULL,
  `regime_key`          VARCHAR(128) NOT NULL,
  `event_type`          VARCHAR(64) NOT NULL,
  `event_payload_json`  LONGTEXT NOT NULL,
  `created_at`          DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ma_game_type` (`game_id`,`event_type`),
  KEY `idx_ma_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `draws_analyzed`      INT NOT NULL DEFAULT 0;
ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `prediction_family`   VARCHAR(16) NOT NULL DEFAULT 'regular';
ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `prediction_type`     VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `render_mode`         VARCHAR(32) NOT NULL DEFAULT 'regular_main_extra';
ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `settings_json`       LONGTEXT NULL;
ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `save_schema_version` VARCHAR(16) NOT NULL DEFAULT 'v1';
ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `target_game_id`      VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `target_lottery_id`   INT NOT NULL DEFAULT 0;
