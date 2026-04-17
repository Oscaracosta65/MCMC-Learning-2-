<?php
// ==============================================================================
// MCMC LOTTERY FORECASTING SYSTEM — UPGRADED v2.0.0
// ==============================================================================
// ARCHITECTURE SUMMARY (Deliverable 1)
//
// Layer 1 — Base Engines
//   Engine A: Lag-1 Markov transition model (weighted by recency)
//   Engine B: Co-occurrence model (which numbers appear together)
//   Engine C: Decayed popularity (raw frequency with exponential decay)
//
// Layer 2 — Ensemble Combiner
//   Adaptive weighted blend of Engines A, B, C.
//   Weights are stored per-game per-regime in #__mcmc_learning_state.
//   Weights are clamped [MIN_WEIGHT, MAX_WEIGHT] and normalized to sum=1.
//
// Layer 3 — Meta-Learning
//   Persistent DB tables store: learned params, weights, calibration maps,
//   rolling metrics, confidence scores, stability scores, audit trail.
//   Regime detection produces a deterministic string key from statistical
//   properties of the draw history.
//
// Layer 4 — UX Intelligence
//   Recommendation panel explains why settings are recommended.
//   Confidence badge, stability badge, regime badge rendered in page.
//   Calibration summary card and learning state card shown to user.
//
// UPGRADE INSERTION MAP (in-place baseline-safe upgrade)
// - UNCHANGED sections:
//   SECTION 2  Joomla bootstrap
//   SECTION 13 Input resolution and auth gate
//   SECTION 14 Config JSON loading
//   SECTION 16 Historical draw fetching
//   Prediction run flow, backtest flow, optimizer flow, diagnostics, charts
//   remain intact and use the same execution path.
// - MODIFIED sections:
//   SECTION 1  Central config expanded with deterministic defaults and bounds.
//   SECTION 15 Save prediction hardened with server-side clamping and
//              backward-compatible fallback insert strategy for pre-migration DBs.
//   SECTION 18 JS config expanded with learning state, recommendation payload,
//              and lottery numeric id to prevent client-side trust issues.
// - NEW sections:
//   SECTION 4  JSON safety helpers
//   SECTION 5  Regime detection helpers
//   SECTION 6  Learning state manager
//   SECTION 7  Audit logger
//   SECTION 8  Calibration helpers
//   SECTION 9  Stability helpers
//   SECTION 10 Recommendation builder
//   SECTION 11 SQL DDL migration helper
//   SECTION 12 Migration endpoint
//   SECTION 12B Learning-state persistence endpoint
// - REQUIRED DB migrations:
//   Run all SQL in sql/2026-04-17_mcmc_learning_upgrade.sql
//   and/or trigger SECTION 12 migration endpoint as superuser.
// - EXACT insertion points:
//   New helper sections are inserted before SECTION 13 so all downstream flows
//   can call them without changing preserved route/layout behavior.
//   New endpoints are inserted before SECTION 13 to process action posts early.
//
// ==============================================================================
// SECTION 0: SECURITY GATE
// ==============================================================================
defined('_JEXEC') or die('Restricted access');

// ==============================================================================
// SECTION 1 [MODIFIED]: CENTRAL ALGORITHM CONFIGURATION BLOCK
// All tunable defaults live here. Change ONLY this block to retune behavior.
// Each constant is commented with its range and effect.
// Why modified: centralizes deterministic defaults for calibration, stability,
// confidence, and adaptive weighting so behavior is explicit and auditable.
// Backward-compatibility risk addressed: avoids hidden per-function defaults
// drifting from prior behavior by enforcing one source of truth.
// ==============================================================================

// Model identity — bump on behavior changes for versioned learning state
if (!defined('MCMC_MODEL_VERSION'))     define('MCMC_MODEL_VERSION',     '2.0.0');

// --- Ensemble weight learning (adaptive blending) ---
if (!defined('MCMC_LEARNING_RATE'))     define('MCMC_LEARNING_RATE',     0.15);
// 0 = frozen weights, 1 = full overwrite from latest evidence
if (!defined('MCMC_MIN_WEIGHT'))        define('MCMC_MIN_WEIGHT',        0.05);
// Floor: no engine may fall below 5% contribution
if (!defined('MCMC_MAX_WEIGHT'))        define('MCMC_MAX_WEIGHT',        0.70);
// Ceiling: no engine may exceed 70% contribution
if (!defined('MCMC_WEIGHT_MOMENTUM'))   define('MCMC_WEIGHT_MOMENTUM',   0.60);
// EMA smoothing on weight updates: 0.6 * old + 0.4 * new
if (!defined('MCMC_MIN_SAMPLE_SIZE'))   define('MCMC_MIN_SAMPLE_SIZE',   30);
// Minimum backtest cases before trusting learned state

// --- Default ensemble component weights [lag1, cooccurrence, popularity] ---
if (!defined('MCMC_DEFAULT_W_LAG1'))    define('MCMC_DEFAULT_W_LAG1',    0.40);
if (!defined('MCMC_DEFAULT_W_COO'))     define('MCMC_DEFAULT_W_COO',     0.35);
if (!defined('MCMC_DEFAULT_W_POP'))     define('MCMC_DEFAULT_W_POP',     0.25);

// --- Composite final scoring formula ---
// final_score = W_pred*predictive + W_cal*calibration + W_stab*stability - W_cplx*complexity
// All four weights must sum to 1.0 when W_cplx is treated as subtracted.
if (!defined('MCMC_SCORE_W_PRED'))      define('MCMC_SCORE_W_PRED',      0.50);
if (!defined('MCMC_SCORE_W_CAL'))       define('MCMC_SCORE_W_CAL',       0.25);
if (!defined('MCMC_SCORE_W_STAB'))      define('MCMC_SCORE_W_STAB',      0.15);
if (!defined('MCMC_SCORE_W_CPLX'))      define('MCMC_SCORE_W_CPLX',      0.10);

// --- Confidence thresholds ---
if (!defined('MCMC_CONFIDENCE_THRESH')) define('MCMC_CONFIDENCE_THRESH', 0.60);
// Minimum confidence before applying learned defaults to settings
if (!defined('MCMC_STABILITY_THRESH'))  define('MCMC_STABILITY_THRESH',  0.50);
// Minimum stability to label regime as "stable"
if (!defined('MCMC_CONF_HIGH'))         define('MCMC_CONF_HIGH',         0.75);
if (!defined('MCMC_CONF_MEDIUM'))       define('MCMC_CONF_MEDIUM',       0.50);
if (!defined('MCMC_CONF_LOW'))          define('MCMC_CONF_LOW',          0.25);

// --- Regime detection thresholds ---
if (!defined('MCMC_REG_SHORT_HIST'))    define('MCMC_REG_SHORT_HIST',    200);
if (!defined('MCMC_REG_LONG_HIST'))     define('MCMC_REG_LONG_HIST',     1000);
if (!defined('MCMC_REG_SMALL_RANGE'))   define('MCMC_REG_SMALL_RANGE',   40);
if (!defined('MCMC_REG_LARGE_RANGE'))   define('MCMC_REG_LARGE_RANGE',   69);
if (!defined('MCMC_REG_HIGH_ENT'))      define('MCMC_REG_HIGH_ENT',      0.92);
if (!defined('MCMC_REG_LOW_ENT'))       define('MCMC_REG_LOW_ENT',       0.75);

// --- Calibration ---
if (!defined('MCMC_CALIB_BUCKETS'))     define('MCMC_CALIB_BUCKETS',     10);
if (!defined('MCMC_CALIB_MIN_CASES'))   define('MCMC_CALIB_MIN_CASES',   20);

// --- Reranker default feature weights (must sum to 1.0) ---
if (!defined('MCMC_RR_W_FREQ'))         define('MCMC_RR_W_FREQ',         0.25);
if (!defined('MCMC_RR_W_GAP'))          define('MCMC_RR_W_GAP',          0.20);
if (!defined('MCMC_RR_W_MOMENTUM'))     define('MCMC_RR_W_MOMENTUM',     0.15);
if (!defined('MCMC_RR_W_VOLATILITY'))   define('MCMC_RR_W_VOLATILITY',   0.10);
if (!defined('MCMC_RR_W_AGREE'))        define('MCMC_RR_W_AGREE',        0.30);

// --- Security: allowlisted event types and status values ---
if (!defined('MCMC_ALLOWED_EVENT_TYPES')) define('MCMC_ALLOWED_EVENT_TYPES', [
    'weight_update','regime_change','param_update',
    'calibration_update','stability_update','backtest_run','optimizer_run','prediction_run'
]);
if (!defined('MCMC_ALLOWED_STATUSES')) define('MCMC_ALLOWED_STATUSES', ['pending','running','completed','failed','cancelled']);

// --- Input parameter bounds (used server-side for clamping in SECTION 15)
// These must match MCMC_JS.PARAM_*_MIN/MAX in the JavaScript central config. ---
if (!defined('MCMC_PARAM_W_MIN'))   define('MCMC_PARAM_W_MIN',   100);
if (!defined('MCMC_PARAM_W_MAX'))   define('MCMC_PARAM_W_MAX',   40000);
if (!defined('MCMC_PARAM_B_MIN'))   define('MCMC_PARAM_B_MIN',   0);
if (!defined('MCMC_PARAM_B_MAX'))   define('MCMC_PARAM_B_MAX',   2000);
if (!defined('MCMC_PARAM_K_MIN'))   define('MCMC_PARAM_K_MIN',   0.0);
if (!defined('MCMC_PARAM_K_MAX'))   define('MCMC_PARAM_K_MAX',   20.0);
if (!defined('MCMC_PARAM_D_MIN'))   define('MCMC_PARAM_D_MIN',   0.001);
if (!defined('MCMC_PARAM_D_MAX'))   define('MCMC_PARAM_D_MAX',   1.0);
if (!defined('MCMC_PARAM_CL_MIN'))  define('MCMC_PARAM_CL_MIN',  100);
if (!defined('MCMC_PARAM_CL_MAX'))  define('MCMC_PARAM_CL_MAX',  10000);

// ==============================================================================
// SECTION 2: JOOMLA BOOTSTRAP (UNCHANGED)
// ==============================================================================
if (!defined('JPATH_BASE')) {
    define('JPATH_BASE', realpath(dirname(__FILE__) . '/../..'));
}
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

$app = JFactory::getApplication('site');
$db  = JFactory::getDbo();

// ==============================================================================
// SECTION 3: DB HELPERS (UNCHANGED + helpers for new tables)
// ==============================================================================

if (!function_exists('quoteTable')) {
    function quoteTable($db, $name) {
        $real = str_replace('#__', $db->getPrefix(), $name);
        return $db->quoteName($real);
    }
}

if (!function_exists('resolveGameIdDb')) {
    function resolveGameIdDb($db, $requestedGameId) {
        if (!$requestedGameId) return '';
        $q = $db->getQuery(true)
            ->select($db->quoteName('game_id'))
            ->from($db->quoteName('#__lotteries'))
            ->where('LOWER(' . $db->quoteName('game_id') . ') = LOWER(' . $db->quote($requestedGameId) . ')')
            ->setLimit(1);
        $db->setQuery($q);
        $res = $db->loadResult();
        return $res ?: $requestedGameId;
    }
}

if (!function_exists('getNextDrawDate')) {
    function getNextDrawDate($gameIdDb, $db, $tableName) {
        $q = $db->getQuery(true)
            ->select('MAX(' . $db->quoteName('next_draw_date') . ')')
            ->from(quoteTable($db, $tableName))
            ->where($db->quoteName('game_id') . ' = ' . $db->quote($gameIdDb));
        $db->setQuery($q);
        $res = $db->loadResult();
        return $res ? (string)$res : '';
    }
}

// ==============================================================================
// SECTION 4: JSON HELPERS (NEW)
// Purpose: Robust encode/decode with sanitization. All JSON stored in DB
// passes through these helpers to prevent malformed data and XSS via JSON.
// ==============================================================================

if (!function_exists('mcmcSafeJsonDecode')) {
    function mcmcSafeJsonDecode($str, $default = []) {
        if (!is_string($str) || $str === '' || $str === 'null') return $default;
        $str    = ltrim($str, "\xEF\xBB\xBF"); // strip UTF-8 BOM
        $result = json_decode($str, true, 64);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) return $default;
        return $result;
    }
}

if (!function_exists('mcmcSafeJsonEncode')) {
    function mcmcSafeJsonEncode($value, $default = '{}') {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $str   = json_encode($value, $flags);
        return ($str === false) ? $default : $str;
    }
}

if (!function_exists('mcmcSanitizeJson')) {
    // Round-trip to strip injected sequences before DB storage
    function mcmcSanitizeJson($str, $default = '{}') {
        $d = mcmcSafeJsonDecode($str, null);
        return ($d === null) ? $default : mcmcSafeJsonEncode($d, $default);
    }
}

// ==============================================================================
// SECTION 5: REGIME DETECTION (NEW)
// Purpose: Classify the current game state into a deterministic string key.
// The key is built from measurable statistics: history length, ball range,
// frequency entropy, frequency drift, format flags.
// Security: Pure computation on server-side DB data, no user input involved.
// ==============================================================================

if (!function_exists('mcmcComputeNormalizedEntropy')) {
    /**
     * Shannon entropy of main-ball frequency distribution, normalized to [0,1].
     * @param  array  $draws  2D array of draw sequences
     * @param  int    $maxN   Maximum ball number
     * @param  int    $pick   Pick size
     * @param  int    $window Last N draws to consider. 0 = use all draws.
     * Higher entropy value = more uniform distribution.
     */
    function mcmcComputeNormalizedEntropy(array $draws, $maxN, $pick, $window = 0) {
        if (empty($draws) || $maxN <= 0 || $pick <= 0) return 0.5;
        $slice = ($window > 0 && count($draws) > $window)
                 ? array_slice($draws, -$window) : $draws;
        $freq  = array_fill(0, $maxN, 0.0);
        $total = 0;
        foreach ($slice as $seq) {
            for ($i = 0; $i < $pick && $i < count($seq); $i++) {
                $idx = ((int)$seq[$i]) - 1;
                if ($idx >= 0 && $idx < $maxN) { $freq[$idx] += 1.0; $total++; }
            }
        }
        if ($total <= 0) return 0.5;
        $entropy = 0.0;
        foreach ($freq as $f) {
            if ($f <= 0) continue;
            $p = $f / $total;
            $entropy -= $p * log($p);
        }
        $maxEntropy = ($maxN > 1) ? log($maxN) : 1.0;
        return ($maxEntropy > 0) ? min(1.0, $entropy / $maxEntropy) : 0.5;
    }
}

if (!function_exists('mcmcComputeFreqDrift')) {
    /**
     * L1 distance between short-window and long-window frequency distributions.
     * High value = regime is shifting. Returns [0,1].
     */
    function mcmcComputeFreqDrift(array $draws, $maxN, $pick, $shortWin, $longWin) {
        if (empty($draws) || $maxN <= 0 || $pick <= 0 || count($draws) < 10) return 0.0;
        $n          = count($draws);
        $shortSlice = array_slice($draws, -min($shortWin, $n));
        $longSlice  = array_slice($draws, -min($longWin,  $n));
        $fShort     = array_fill(0, $maxN, 0.0);
        $fLong      = array_fill(0, $maxN, 0.0);
        $tsShort    = 0; $tsLong = 0;
        foreach ($shortSlice as $seq) {
            for ($i = 0; $i < $pick && $i < count($seq); $i++) {
                $idx = ((int)$seq[$i]) - 1;
                if ($idx >= 0 && $idx < $maxN) { $fShort[$idx]++; $tsShort++; }
            }
        }
        foreach ($longSlice as $seq) {
            for ($i = 0; $i < $pick && $i < count($seq); $i++) {
                $idx = ((int)$seq[$i]) - 1;
                if ($idx >= 0 && $idx < $maxN) { $fLong[$idx]++; $tsLong++; }
            }
        }
        if ($tsShort > 0) { foreach ($fShort as &$v) { $v /= $tsShort; } unset($v); }
        if ($tsLong  > 0) { foreach ($fLong  as &$v) { $v /= $tsLong;  } unset($v); }
        $l1 = 0.0;
        for ($i = 0; $i < $maxN; $i++) { $l1 += abs($fShort[$i] - $fLong[$i]); }
        return min(1.0, $l1 / 2.0); // max L1 = 2.0 for disjoint distributions
    }
}

if (!function_exists('mcmcComputeRegimeKey')) {
    /**
     * Build the deterministic regime key. Only alphanumeric + underscore chars.
     * Example: "long_history_large_range_stable_high_entropy_nodaily_extra"
     * Insertion point: called after draws are fetched, before learning state load.
     */
    function mcmcComputeRegimeKey(array $draws, array $lc) {
        $n     = count($draws);
        $maxN  = (int)($lc['max_main_ball_number'] ?? 0);
        $pick  = (int)($lc['num_main_balls_drawn']  ?? 0);
        $hasEx = !empty($lc['has_extra_ball']);

        // 1. History bucket
        if      ($n < MCMC_REG_SHORT_HIST) $histBucket = 'short_history';
        elseif  ($n < MCMC_REG_LONG_HIST)  $histBucket = 'medium_history';
        else                               $histBucket = 'long_history';

        // 2. Range bucket
        if      ($maxN <= MCMC_REG_SMALL_RANGE) $rangeBucket = 'small_range';
        elseif  ($maxN <= MCMC_REG_LARGE_RANGE) $rangeBucket = 'medium_range';
        else                                    $rangeBucket = 'large_range';

        // 3. Entropy bucket (last 100 draws for responsiveness)
        $ent = mcmcComputeNormalizedEntropy($draws, $maxN, $pick, 100);
        if      ($ent >= MCMC_REG_HIGH_ENT) $entBucket = 'high_entropy';
        elseif  ($ent >= MCMC_REG_LOW_ENT)  $entBucket = 'medium_entropy';
        else                               $entBucket = 'low_entropy';

        // 4. Drift bucket (short=50 vs long=200)
        $drift      = ($n >= 50) ? mcmcComputeFreqDrift($draws, $maxN, $pick, 50, 200) : 0.0;
        $driftBucket = ($drift > 0.10) ? 'shift' : 'stable';

        // 5. Format flags
        $dailyFlag = ($maxN <= 9 && !$hasEx) ? 'daily' : 'nodaily';
        $extraFlag = $hasEx ? 'extra' : 'noextra';

        $key = implode('_', [$histBucket, $rangeBucket, $driftBucket, $entBucket, $dailyFlag, $extraFlag]);
        return preg_replace('/[^a-z0-9_]/', '_', strtolower($key));
    }
}

// ==============================================================================
// SECTION 6: LEARNING STATE MANAGER (NEW)
// Purpose: Load/save the per-game per-regime learned state from DB.
// Security: All inputs validated; Joomla query builder prevents SQL injection.
// ==============================================================================

if (!function_exists('mcmcGetDefaultLearningState')) {
    function mcmcGetDefaultLearningState($gameIdDb, $regimeKey) {
        return [
            'game_id'                => (string)$gameIdDb,
            'regime_key'             => (string)$regimeKey,
            'model_version'          => MCMC_MODEL_VERSION,
            'best_params_json'       => mcmcSafeJsonEncode([
                'W' => 2700, 'B' => 70, 'K' => 1.0, 'D' => 0.02, 'CL' => 3000
            ]),
            'component_weights_json' => mcmcSafeJsonEncode([
                'main'  => [MCMC_DEFAULT_W_LAG1, MCMC_DEFAULT_W_COO, MCMC_DEFAULT_W_POP],
                'extra' => [MCMC_DEFAULT_W_LAG1, MCMC_DEFAULT_W_COO, MCMC_DEFAULT_W_POP],
            ]),
            'calibration_map_json'   => mcmcSafeJsonEncode([]),
            'rolling_metrics_json'   => mcmcSafeJsonEncode([
                'log_loss'    => null, 'brier_score' => null,
                'hit_rate'    => null, 'stability'   => null,
                'calibration' => null,
            ]),
            'confidence_score' => 0.0,
            'stability_score'  => 0.0,
            'sample_size'      => 0,
            'last_draw_count'  => 0,
            'is_source'        => 'default',
        ];
    }
}

if (!function_exists('mcmcLoadLearningState')) {
    function mcmcLoadLearningState($db, $gameIdDb, $regimeKey) {
        $gameIdDb  = (string)$gameIdDb;
        $regimeKey = (string)$regimeKey;
        if ($gameIdDb === '' || $regimeKey === '') {
            return mcmcGetDefaultLearningState($gameIdDb, $regimeKey);
        }
        try {
            $q = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__mcmc_learning_state'))
                ->where($db->quoteName('game_id')    . ' = ' . $db->quote($gameIdDb))
                ->where($db->quoteName('regime_key') . ' = ' . $db->quote($regimeKey))
                ->where($db->quoteName('is_active')  . ' = 1')
                ->setLimit(1);
            $db->setQuery($q);
            $row = $db->loadAssoc();
        } catch (\Exception $e) {
            return mcmcGetDefaultLearningState($gameIdDb, $regimeKey);
        }
        if (!$row) { return mcmcGetDefaultLearningState($gameIdDb, $regimeKey); }
        $row['is_source'] = 'db';
        return $row;
    }
}

if (!function_exists('mcmcSaveLearningState')) {
    /**
     * Upsert learning state. Tries UPDATE first, falls back to INSERT.
     * All JSON fields sanitized before storage.
     */
    function mcmcSaveLearningState($db, array $state) {
        $gameIdDb  = (string)($state['game_id']    ?? '');
        $regimeKey = (string)($state['regime_key'] ?? '');
        if ($gameIdDb === '' || $regimeKey === '') return false;
        $modelVersion = substr(preg_replace('/[^a-z0-9._\-]/i', '', MCMC_MODEL_VERSION), 0, 32);
        $bestParams  = mcmcSanitizeJson($state['best_params_json']        ?? '{}');
        $compWts     = mcmcSanitizeJson($state['component_weights_json']  ?? '{}');
        $calibMap    = mcmcSanitizeJson($state['calibration_map_json']    ?? '{}');
        $rolling     = mcmcSanitizeJson($state['rolling_metrics_json']    ?? '{}');
        $confidence  = min(1.0, max(0.0, (float)($state['confidence_score'] ?? 0.0)));
        $stability   = min(1.0, max(0.0, (float)($state['stability_score']  ?? 0.0)));
        $sampleSize  = max(0, (int)($state['sample_size']    ?? 0));
        $drawCount   = max(0, (int)($state['last_draw_count'] ?? 0));
        $now         = date('Y-m-d H:i:s');
        try {
            $upd = $db->getQuery(true)
                ->update($db->quoteName('#__mcmc_learning_state'))
                ->set($db->quoteName('best_params_json')       . '=' . $db->quote($bestParams))
                ->set($db->quoteName('component_weights_json') . '=' . $db->quote($compWts))
                ->set($db->quoteName('calibration_map_json')   . '=' . $db->quote($calibMap))
                ->set($db->quoteName('rolling_metrics_json')   . '=' . $db->quote($rolling))
                ->set($db->quoteName('confidence_score')       . '=' . $db->quote($confidence))
                ->set($db->quoteName('stability_score')        . '=' . $db->quote($stability))
                ->set($db->quoteName('sample_size')            . '=' . (int)$sampleSize)
                ->set($db->quoteName('last_draw_count')        . '=' . (int)$drawCount)
                ->set($db->quoteName('updated_at')             . '=' . $db->quote($now))
                ->where($db->quoteName('game_id')      . '=' . $db->quote($gameIdDb))
                ->where($db->quoteName('regime_key')   . '=' . $db->quote($regimeKey))
                ->where($db->quoteName('model_version'). '=' . $db->quote($modelVersion));
            $db->setQuery($upd);
            $db->execute();
            if ($db->getAffectedRows() > 0) return true;
            $ins = $db->getQuery(true)
                ->insert($db->quoteName('#__mcmc_learning_state'))
                ->columns(array_map([$db,'quoteName'], [
                    'game_id','regime_key','model_version',
                    'best_params_json','component_weights_json','calibration_map_json',
                    'rolling_metrics_json','confidence_score','stability_score',
                    'sample_size','last_draw_count','is_active','created_at','updated_at'
                ]))
                ->values(implode(',', [
                    $db->quote($gameIdDb), $db->quote($regimeKey), $db->quote($modelVersion),
                    $db->quote($bestParams), $db->quote($compWts), $db->quote($calibMap),
                    $db->quote($rolling), $db->quote($confidence), $db->quote($stability),
                    (int)$sampleSize, (int)$drawCount, 1, $db->quote($now), $db->quote($now),
                ]));
            $db->setQuery($ins);
            $db->execute();
            return true;
        } catch (\Exception $e) { return false; }
    }
}

// ==============================================================================
// SECTION 7: AUDIT HANDLER (NEW)
// Security: event_type strictly allowlisted before DB write.
// ==============================================================================

if (!function_exists('mcmcLogAudit')) {
    function mcmcLogAudit($db, $gameIdDb, $regimeKey, $eventType, array $payload) {
        if (!in_array($eventType, MCMC_ALLOWED_EVENT_TYPES, true)) return false;
        $gameIdDb = (string)$gameIdDb;
        if ($gameIdDb === '') return false;
        $payloadJson = mcmcSafeJsonEncode($payload);
        $now = date('Y-m-d H:i:s');
        try {
            $q = $db->getQuery(true)
                ->insert($db->quoteName('#__mcmc_model_audit'))
                ->columns(array_map([$db,'quoteName'], ['game_id','regime_key','event_type','event_payload_json','created_at']))
                ->values(implode(',', [
                    $db->quote((string)$gameIdDb),
                    $db->quote((string)$regimeKey),
                    $db->quote($eventType),
                    $db->quote($payloadJson),
                    $db->quote($now),
                ]));
            $db->setQuery($q);
            $db->execute();
            return true;
        } catch (\Exception $e) { return false; }
    }
}

// ==============================================================================
// SECTION 8: CALIBRATION HELPER (NEW — PHP side)
// ==============================================================================

if (!function_exists('mcmcComputeCalibrationScore')) {
    /**
     * ECE-based calibration score. Returns [0,1] where 1=perfect calibration.
     * @param array $predictions  Array of probability vectors (indexed by case)
     * @param array $actuals      Array of draw sequences (truth)
     * @param int   $pick         Main ball pick size
     * @param int   $maxN         Max ball number
     */
    function mcmcComputeCalibrationScore(array $predictions, array $actuals, $pick, $maxN) {
        if (empty($predictions) || empty($actuals) || $maxN <= 0 || $pick <= 0) return 0.5;
        $B   = MCMC_CALIB_BUCKETS;
        $bS  = array_fill(0, $B, 0.0); // bucket sum of predicted probs
        $bH  = array_fill(0, $B, 0.0); // bucket sum of actual hits
        $bC  = array_fill(0, $B, 0);   // bucket count
        foreach ($predictions as $idx => $pVec) {
            if (!isset($actuals[$idx]) || !is_array($pVec)) continue;
            $truthSet = [];
            foreach (array_slice($actuals[$idx], 0, $pick) as $n) $truthSet[(int)$n] = true;
            for ($n = 0; $n < $maxN; $n++) {
                $p = (float)($pVec[$n] ?? 0.0);
                $b = min($B - 1, (int)floor($p * $B));
                $bS[$b] += $p;
                $bH[$b] += isset($truthSet[$n + 1]) ? 1.0 : 0.0;
                $bC[$b]++;
            }
        }
        $ece   = 0.0;
        $total = count($predictions) * $maxN;
        if ($total <= 0) return 0.5;
        for ($b = 0; $b < $B; $b++) {
            if ($bC[$b] < MCMC_CALIB_MIN_CASES) continue;
            $ece += ($bC[$b] / $total) * abs($bS[$b] / $bC[$b] - $bH[$b] / $bC[$b]);
        }
        return max(0.0, 1.0 - $ece);
    }
}

// ==============================================================================
// SECTION 9: STABILITY ANALYZER (NEW — PHP side)
// ==============================================================================

if (!function_exists('mcmcComputeStabilityScore')) {
    /**
     * Stability = 1 - CoeffVar(scores). Rewards consistent performance.
     * Returns [0,1].
     */
    function mcmcComputeStabilityScore(array $scores) {
        $n = count($scores);
        if ($n < 2) return 0.5;
        $sum = 0.0; foreach ($scores as $s) { $sum += (float)$s; }
        $mean = $sum / $n;
        if ($mean <= 0) return 0.0;
        $varSum = 0.0; foreach ($scores as $s) { $varSum += ((float)$s - $mean) ** 2; }
        $cv = sqrt($varSum / $n) / $mean;
        return max(0.0, 1.0 - min(1.0, $cv));
    }
}

// ==============================================================================
// SECTION 10: RECOMMENDATION ENGINE (NEW)
// Purpose: Build structured recommendation object from learning state.
// ==============================================================================

if (!function_exists('mcmcBuildRecommendation')) {
    function mcmcBuildRecommendation(array $learningState, array $currentSettings) {
        $source       = $learningState['is_source']    ?? 'default';
        $regimeKey    = $learningState['regime_key']   ?? 'unknown';
        $confidence   = (float)($learningState['confidence_score'] ?? 0.0);
        $stability    = (float)($learningState['stability_score']  ?? 0.0);
        $sampleSize   = (int)($learningState['sample_size']        ?? 0);
        $learnedP     = mcmcSafeJsonDecode($learningState['best_params_json'] ?? '{}', []);
        $metrics      = mcmcSafeJsonDecode($learningState['rolling_metrics_json'] ?? '{}', []);
        $calScore     = isset($metrics['calibration']) ? (float)$metrics['calibration'] : null;

        // Calibration grade
        if ($calScore === null)         $calGrade = 'N/A';
        elseif ($calScore >= 0.80)     $calGrade = 'A';
        elseif ($calScore >= 0.65)     $calGrade = 'B';
        elseif ($calScore >= 0.50)     $calGrade = 'C';
        else                           $calGrade = 'D';

        // Confidence label
        if ($confidence >= MCMC_CONF_HIGH)       $confLabel = 'high';
        elseif ($confidence >= MCMC_CONF_MEDIUM) $confLabel = 'medium';
        elseif ($confidence >= MCMC_CONF_LOW)    $confLabel = 'low';
        else                                     $confLabel = 'none';

        // Check if learned params differ materially from current
        $differs = false;
        if (!empty($learnedP) && !empty($currentSettings)) {
            $wDiff = isset($learnedP['W'],  $currentSettings['W'])  && abs((int)$learnedP['W']  - (int)$currentSettings['W'])  > 200;
            $kDiff = isset($learnedP['K'],  $currentSettings['K'])  && abs((float)$learnedP['K'] - (float)$currentSettings['K']) > 0.5;
            $dDiff = isset($learnedP['D'],  $currentSettings['D'])  && abs((float)$learnedP['D'] - (float)$currentSettings['D']) > 0.005;
            $differs = ($wDiff || $kDiff || $dDiff);
        }

        // Explanation text
        $lines = [];
        if ($source === 'db') {
            $lines[] = 'Learned from ' . $sampleSize . ' cases in regime: ' . htmlspecialchars($regimeKey, ENT_QUOTES, 'UTF-8') . '.';
            $lines[] = 'Confidence: ' . ucfirst($confLabel) . ' (' . round($confidence * 100) . '%). Stability: ' . round($stability * 100) . '%.';
            if ($differs) { $lines[] = 'Current settings differ from learned optimum. Consider applying the recommended profile.'; }
            else           { $lines[] = 'Current settings are near the learned optimum for this regime.'; }
        } else {
            $lines[] = 'No learned state for regime: ' . htmlspecialchars($regimeKey, ENT_QUOTES, 'UTF-8') . '. Using safe defaults.';
            $lines[] = 'Run Optimizer and Backtest to accumulate learning data for this regime.';
        }

        return [
            'profile_name'      => ($source === 'db') ? 'Learned Profile v' . MCMC_MODEL_VERSION : 'Safe Default Profile',
            'params'            => !empty($learnedP) ? $learnedP : $currentSettings,
            'source'            => $source,
            'regime_key'        => $regimeKey,
            'confidence'        => $confidence,
            'confidence_label'  => $confLabel,
            'stability'         => $stability,
            'sample_size'       => $sampleSize,
            'calibration_grade' => $calGrade,
            'explanation'       => implode(' ', $lines),
            'differs_from_curr' => $differs,
            'learned_params'    => $learnedP,
        ];
    }
}

// ==============================================================================
// SECTION 11: SQL DDL MIGRATION HELPER (NEW — Deliverable 2)
// Returns CREATE TABLE IF NOT EXISTS statements for all 6 new tables.
// Executable from an admin migration endpoint (see SECTION 12).
// ==============================================================================

if (!function_exists('mcmcGetMigrationSQL')) {
    function mcmcGetMigrationSQL() {
        // NOTE: Keep this migration list in sync with:
        // sql/2026-04-17_mcmc_learning_upgrade.sql (canonical standalone script).
        return [

/* Table 1 */
"CREATE TABLE IF NOT EXISTS `#__mcmc_learning_state` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* Table 2 */
"CREATE TABLE IF NOT EXISTS `#__mcmc_backtest_runs` (
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
  KEY `idx_bt_started`     (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* Table 3 */
"CREATE TABLE IF NOT EXISTS `#__mcmc_optimizer_runs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* Table 4 */
"CREATE TABLE IF NOT EXISTS `#__mcmc_param_history` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* Table 5 */
"CREATE TABLE IF NOT EXISTS `#__mcmc_number_features` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `game_id`      VARCHAR(64) NOT NULL,
  `draw_date`    DATE NOT NULL,
  `number_value` INT NOT NULL,
  `ball_type`    VARCHAR(16) NOT NULL,
  `feature_json` LONGTEXT NOT NULL,
  `created_at`   DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nf_game_date_num_type` (`game_id`,`draw_date`,`number_value`,`ball_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* Table 6 */
"CREATE TABLE IF NOT EXISTS `#__mcmc_model_audit` (
  `id`                  INT NOT NULL AUTO_INCREMENT,
  `game_id`             VARCHAR(64) NOT NULL,
  `regime_key`          VARCHAR(128) NOT NULL,
  `event_type`          VARCHAR(64) NOT NULL,
  `event_payload_json`  LONGTEXT NOT NULL,
  `created_at`          DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ma_game_type`  (`game_id`,`event_type`),
  KEY `idx_ma_created`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ===========================================================================
   Table 7 — REQUIRED DB MIGRATION: extend #__user_saved_numbers
   These are individual ADD COLUMN statements; each may fail silently (IGNORE)
   if the column already exists in a re-run. The migration endpoint wraps
   every statement in try/catch, so idempotency is guaranteed.
   Insertion point: appended to mcmcGetMigrationSQL() return array.
   Why: SECTION 15 (save prediction) now inserts 8 new structured metadata
   columns that were not present in the original 15-column schema.
   Backward compat risk addressed: if the migration has NOT been run yet the
   save handler will gracefully fall back to the original 15-column insert
   (see the try/catch fallback in SECTION 15).
   =========================================================================== */
"ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `draws_analyzed`       INT NOT NULL DEFAULT 0",
"ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `prediction_family`    VARCHAR(16) NOT NULL DEFAULT 'regular'",
"ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `prediction_type`      VARCHAR(64) NOT NULL DEFAULT ''",
"ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `render_mode`          VARCHAR(32) NOT NULL DEFAULT 'regular_main_extra'",
"ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `settings_json`        LONGTEXT NULL",
"ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `save_schema_version`  VARCHAR(16) NOT NULL DEFAULT 'v1'",
"ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `target_game_id`       VARCHAR(64) NOT NULL DEFAULT ''",
"ALTER TABLE `#__user_saved_numbers` ADD COLUMN IF NOT EXISTS `target_lottery_id`    INT NOT NULL DEFAULT 0",

        ];
    }
}

// ==============================================================================
// SECTION 11B [NEW]: FIRST-RUN SCHEMA MANAGEMENT
// Purpose:
//   Ensure required MCMC tables, columns, and indexes exist before any dependent
//   read/write paths execute.
// Rules:
//   - Changes are attempted only by super users.
//   - Non-super users get a safe setup-required message when schema is missing.
//   - Operations are idempotent and additive only (no drops, no destructive alters).
// ==============================================================================
if (!function_exists('mcmcResolveTableName')) {
    function mcmcResolveTableName($db, $name) {
        return str_replace('#__', $db->getPrefix(), $name);
    }
}

if (!function_exists('mcmcTableExists')) {
    function mcmcTableExists($db, $tableName) {
        try {
            $real = mcmcResolveTableName($db, $tableName);
            $q = 'SHOW TABLES LIKE ' . $db->quote($real);
            $db->setQuery($q);
            return (bool)$db->loadResult();
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('mcmcGetExistingColumns')) {
    function mcmcGetExistingColumns($db, $tableName) {
        $out = [];
        try {
            $real = mcmcResolveTableName($db, $tableName);
            $cols = $db->getTableColumns($real, false);
            if (is_array($cols)) {
                foreach ($cols as $k => $v) {
                    $out[strtolower((string)$k)] = true;
                }
            }
        } catch (\Exception $e) {
            $out = [];
        }
        return $out;
    }
}

if (!function_exists('mcmcGetExistingIndexes')) {
    function mcmcGetExistingIndexes($db, $tableName) {
        $out = [];
        try {
            $real = mcmcResolveTableName($db, $tableName);
            $q = 'SHOW INDEX FROM ' . $db->quoteName($real);
            $db->setQuery($q);
            $rows = $db->loadAssocList();
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (!empty($r['Key_name'])) {
                        $out[strtolower((string)$r['Key_name'])] = true;
                    }
                }
            }
        } catch (\Exception $e) {
            $out = [];
        }
        return $out;
    }
}

if (!function_exists('mcmcSchemaDefinitionMap')) {
    function mcmcSchemaDefinitionMap() {
        return [
            '#__mcmc_learning_state' => [
                'create_sql' => "CREATE TABLE IF NOT EXISTS `#__mcmc_learning_state` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `game_id` VARCHAR(64) NOT NULL,
  `regime_key` VARCHAR(128) NOT NULL,
  `model_version` VARCHAR(32) NOT NULL,
  `best_params_json` LONGTEXT NOT NULL,
  `component_weights_json` LONGTEXT NOT NULL,
  `calibration_map_json` LONGTEXT NOT NULL,
  `rolling_metrics_json` LONGTEXT NOT NULL,
  `confidence_score` DECIMAL(10,6) NOT NULL DEFAULT 0,
  `stability_score` DECIMAL(10,6) NOT NULL DEFAULT 0,
  `sample_size` INT NOT NULL DEFAULT 0,
  `last_backtest_end` DATE NULL,
  `last_draw_count` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_game_regime_ver` (`game_id`,`regime_key`,`model_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                'columns' => [
                    'id' => "INT NOT NULL AUTO_INCREMENT",
                    'game_id' => "VARCHAR(64) NOT NULL",
                    'regime_key' => "VARCHAR(128) NOT NULL",
                    'model_version' => "VARCHAR(32) NOT NULL",
                    'best_params_json' => "LONGTEXT NOT NULL",
                    'component_weights_json' => "LONGTEXT NOT NULL",
                    'calibration_map_json' => "LONGTEXT NOT NULL",
                    'rolling_metrics_json' => "LONGTEXT NOT NULL",
                    'confidence_score' => "DECIMAL(10,6) NOT NULL DEFAULT 0",
                    'stability_score' => "DECIMAL(10,6) NOT NULL DEFAULT 0",
                    'sample_size' => "INT NOT NULL DEFAULT 0",
                    'last_backtest_end' => "DATE NULL",
                    'last_draw_count' => "INT NOT NULL DEFAULT 0",
                    'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
                    'created_at' => "DATETIME NOT NULL",
                    'updated_at' => "DATETIME NOT NULL",
                ],
                'indexes' => [
                    'uq_game_regime_ver' => "UNIQUE KEY `uq_game_regime_ver` (`game_id`,`regime_key`,`model_version`)",
                ],
            ],
            '#__mcmc_backtest_runs' => [
                'create_sql' => "CREATE TABLE IF NOT EXISTS `#__mcmc_backtest_runs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `game_id` VARCHAR(64) NOT NULL,
  `regime_key` VARCHAR(128) NOT NULL,
  `model_version` VARCHAR(32) NOT NULL,
  `window_mode` VARCHAR(32) NOT NULL,
  `window_size` INT NOT NULL DEFAULT 0,
  `top_n` INT NOT NULL DEFAULT 20,
  `settings_json` LONGTEXT NOT NULL,
  `summary_json` LONGTEXT NOT NULL,
  `score_overall` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `score_recent_10` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `score_recent_25` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `log_loss` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `brier_score` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `hit_rate` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `stability_score` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `cases_tested` INT NOT NULL DEFAULT 0,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `idx_bt_game_regime` (`game_id`,`regime_key`),
  KEY `idx_bt_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                'columns' => [
                    'id' => "INT NOT NULL AUTO_INCREMENT",
                    'game_id' => "VARCHAR(64) NOT NULL",
                    'regime_key' => "VARCHAR(128) NOT NULL",
                    'model_version' => "VARCHAR(32) NOT NULL",
                    'window_mode' => "VARCHAR(32) NOT NULL",
                    'window_size' => "INT NOT NULL DEFAULT 0",
                    'top_n' => "INT NOT NULL DEFAULT 20",
                    'settings_json' => "LONGTEXT NOT NULL",
                    'summary_json' => "LONGTEXT NOT NULL",
                    'score_overall' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'score_recent_10' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'score_recent_25' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'log_loss' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'brier_score' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'hit_rate' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'stability_score' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'cases_tested' => "INT NOT NULL DEFAULT 0",
                    'started_at' => "DATETIME NOT NULL",
                    'finished_at' => "DATETIME NULL",
                    'status' => "VARCHAR(16) NOT NULL DEFAULT 'pending'",
                ],
                'indexes' => [
                    'idx_bt_game_regime' => "KEY `idx_bt_game_regime` (`game_id`,`regime_key`)",
                    'idx_bt_started' => "KEY `idx_bt_started` (`started_at`)",
                ],
            ],
            '#__mcmc_optimizer_runs' => [
                'create_sql' => "CREATE TABLE IF NOT EXISTS `#__mcmc_optimizer_runs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `game_id` VARCHAR(64) NOT NULL,
  `regime_key` VARCHAR(128) NOT NULL,
  `model_version` VARCHAR(32) NOT NULL,
  `search_space_json` LONGTEXT NOT NULL,
  `best_result_json` LONGTEXT NOT NULL,
  `leaderboard_json` LONGTEXT NOT NULL,
  `heatmap_json` LONGTEXT NOT NULL,
  `cases_evaluated` INT NOT NULL DEFAULT 0,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `idx_opt_game_regime` (`game_id`,`regime_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                'columns' => [
                    'id' => "INT NOT NULL AUTO_INCREMENT",
                    'game_id' => "VARCHAR(64) NOT NULL",
                    'regime_key' => "VARCHAR(128) NOT NULL",
                    'model_version' => "VARCHAR(32) NOT NULL",
                    'search_space_json' => "LONGTEXT NOT NULL",
                    'best_result_json' => "LONGTEXT NOT NULL",
                    'leaderboard_json' => "LONGTEXT NOT NULL",
                    'heatmap_json' => "LONGTEXT NOT NULL",
                    'cases_evaluated' => "INT NOT NULL DEFAULT 0",
                    'started_at' => "DATETIME NOT NULL",
                    'finished_at' => "DATETIME NULL",
                    'status' => "VARCHAR(16) NOT NULL DEFAULT 'pending'",
                ],
                'indexes' => [
                    'idx_opt_game_regime' => "KEY `idx_opt_game_regime` (`game_id`,`regime_key`)",
                ],
            ],
            '#__mcmc_param_history' => [
                'create_sql' => "CREATE TABLE IF NOT EXISTS `#__mcmc_param_history` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `game_id` VARCHAR(64) NOT NULL,
  `regime_key` VARCHAR(128) NOT NULL,
  `model_version` VARCHAR(32) NOT NULL,
  `walks` INT NOT NULL,
  `burn_in` INT NOT NULL,
  `chain_len` INT NOT NULL,
  `laplace_k` DECIMAL(12,6) NOT NULL,
  `decay` DECIMAL(12,6) NOT NULL,
  `ensemble_weights_json` LONGTEXT NOT NULL,
  `score` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `log_loss` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `brier_score` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `hit_rate` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `stability_score` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `calibration_score` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `complexity_penalty` DECIMAL(14,8) NOT NULL DEFAULT 0,
  `cases_tested` INT NOT NULL DEFAULT 0,
  `tested_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ph_game_regime_date` (`game_id`,`regime_key`,`tested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                'columns' => [
                    'id' => "INT NOT NULL AUTO_INCREMENT",
                    'game_id' => "VARCHAR(64) NOT NULL",
                    'regime_key' => "VARCHAR(128) NOT NULL",
                    'model_version' => "VARCHAR(32) NOT NULL",
                    'walks' => "INT NOT NULL",
                    'burn_in' => "INT NOT NULL",
                    'chain_len' => "INT NOT NULL",
                    'laplace_k' => "DECIMAL(12,6) NOT NULL",
                    'decay' => "DECIMAL(12,6) NOT NULL",
                    'ensemble_weights_json' => "LONGTEXT NOT NULL",
                    'score' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'log_loss' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'brier_score' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'hit_rate' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'stability_score' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'calibration_score' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'complexity_penalty' => "DECIMAL(14,8) NOT NULL DEFAULT 0",
                    'cases_tested' => "INT NOT NULL DEFAULT 0",
                    'tested_at' => "DATETIME NOT NULL",
                ],
                'indexes' => [
                    'idx_ph_game_regime_date' => "KEY `idx_ph_game_regime_date` (`game_id`,`regime_key`,`tested_at`)",
                ],
            ],
            '#__mcmc_number_features' => [
                'create_sql' => "CREATE TABLE IF NOT EXISTS `#__mcmc_number_features` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `game_id` VARCHAR(64) NOT NULL,
  `draw_date` DATE NOT NULL,
  `number_value` INT NOT NULL,
  `ball_type` VARCHAR(16) NOT NULL,
  `feature_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nf_game_date_num_type` (`game_id`,`draw_date`,`number_value`,`ball_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                'columns' => [
                    'id' => "INT NOT NULL AUTO_INCREMENT",
                    'game_id' => "VARCHAR(64) NOT NULL",
                    'draw_date' => "DATE NOT NULL",
                    'number_value' => "INT NOT NULL",
                    'ball_type' => "VARCHAR(16) NOT NULL",
                    'feature_json' => "LONGTEXT NOT NULL",
                    'created_at' => "DATETIME NOT NULL",
                ],
                'indexes' => [
                    'uq_nf_game_date_num_type' => "UNIQUE KEY `uq_nf_game_date_num_type` (`game_id`,`draw_date`,`number_value`,`ball_type`)",
                ],
            ],
            '#__mcmc_model_audit' => [
                'create_sql' => "CREATE TABLE IF NOT EXISTS `#__mcmc_model_audit` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `game_id` VARCHAR(64) NOT NULL,
  `regime_key` VARCHAR(128) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `event_payload_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ma_game_type` (`game_id`,`event_type`),
  KEY `idx_ma_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                'columns' => [
                    'id' => "INT NOT NULL AUTO_INCREMENT",
                    'game_id' => "VARCHAR(64) NOT NULL",
                    'regime_key' => "VARCHAR(128) NOT NULL",
                    'event_type' => "VARCHAR(64) NOT NULL",
                    'event_payload_json' => "LONGTEXT NOT NULL",
                    'created_at' => "DATETIME NOT NULL",
                ],
                'indexes' => [
                    'idx_ma_game_type' => "KEY `idx_ma_game_type` (`game_id`,`event_type`)",
                    'idx_ma_created' => "KEY `idx_ma_created` (`created_at`)",
                ],
            ],
            '#__user_saved_numbers' => [
                'create_sql' => '',
                'columns' => [
                    'draws_analyzed' => "INT NOT NULL DEFAULT 0",
                    'prediction_family' => "VARCHAR(16) NOT NULL DEFAULT 'regular'",
                    'prediction_type' => "VARCHAR(64) NOT NULL DEFAULT ''",
                    'render_mode' => "VARCHAR(32) NOT NULL DEFAULT 'regular_main_extra'",
                    'settings_json' => "LONGTEXT NULL",
                    'save_schema_version' => "VARCHAR(16) NOT NULL DEFAULT 'v1'",
                    'target_game_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
                    'target_lottery_id' => "INT NOT NULL DEFAULT 0",
                ],
                'indexes' => [],
            ],
        ];
    }
}

if (!function_exists('ensureMcmcSchema')) {
    function ensureMcmcSchema($db, $currentUser) {
        $result = [
            'success' => true,
            'setup_required' => false,
            'actions' => [],
            'errors' => [],
        ];
        $isSuper = false;
        try {
            $isSuper = (bool)$currentUser->authorise('core.admin');
        } catch (\Exception $e) {
            $isSuper = false;
        }
        $cacheKey = 'mcmc.schema.ok.' . MCMC_MODEL_VERSION;
        try {
            $appLocal = JFactory::getApplication();
            $cachedOk = (int)$appLocal->getUserState($cacheKey, 0);
            if ($cachedOk === 1) {
                return $result;
            }
        } catch (\Exception $e) {
            /* Continue without cache if state access fails. */
        }

        $schemaMap = mcmcSchemaDefinitionMap();
        $pending = [];

        foreach ($schemaMap as $tableName => $def) {
            $exists = mcmcTableExists($db, $tableName);
            if (!$exists) {
                if (!empty($def['create_sql'])) {
                    $pending[] = ['type' => 'create_table', 'table' => $tableName, 'sql' => $def['create_sql']];
                } else {
                    $result['errors'][] = 'Required table missing: ' . $tableName;
                }
                continue;
            }

            $existingCols = mcmcGetExistingColumns($db, $tableName);
            foreach ($def['columns'] as $colName => $colDef) {
                if (!isset($existingCols[strtolower($colName)])) {
                    $real = mcmcResolveTableName($db, $tableName);
                    $sql = 'ALTER TABLE ' . $db->quoteName($real)
                         . ' ADD COLUMN ' . $db->quoteName($colName) . ' ' . $colDef;
                    $pending[] = ['type' => 'add_column', 'table' => $tableName, 'column' => $colName, 'sql' => $sql];
                }
            }

            if (!empty($def['indexes'])) {
                $existingIdx = mcmcGetExistingIndexes($db, $tableName);
                foreach ($def['indexes'] as $idxName => $idxDef) {
                    if (!isset($existingIdx[strtolower($idxName)])) {
                        $real = mcmcResolveTableName($db, $tableName);
                        $sql = 'ALTER TABLE ' . $db->quoteName($real) . ' ADD ' . $idxDef;
                        $pending[] = ['type' => 'add_index', 'table' => $tableName, 'index' => $idxName, 'sql' => $sql];
                    }
                }
            }
        }

        if (!empty($result['errors'])) {
            $result['success'] = false;
            return $result;
        }

        if (empty($pending)) {
            try { JFactory::getApplication()->setUserState($cacheKey, 1); } catch (\Exception $e) {}
            return $result;
        }

        if (!$isSuper) {
            $result['success'] = false;
            $result['setup_required'] = true;
            $result['errors'][] = 'Schema update required and can only be run by a super user.';
            return $result;
        }

        foreach ($pending as $op) {
            $rawSql = (string)$op['sql'];
            $sql = str_replace('`#__', '`' . $db->getPrefix(), $rawSql);
            try {
                $db->setQuery($sql);
                $db->execute();
                $result['actions'][] = [
                    'type' => $op['type'],
                    'table' => $op['table'],
                    'target' => $op['column'] ?? ($op['index'] ?? ''),
                ];
            } catch (\Exception $e) {
                $result['errors'][] = 'Schema operation failed for table ' . $op['table'];
            }
        }

        if (!empty($result['errors'])) {
            $result['success'] = false;
        } else {
            try { JFactory::getApplication()->setUserState($cacheKey, 1); } catch (\Exception $e) {}
        }
        return $result;
    }
}

// SECTION 11C [NEW]: SCHEMA ENFORCEMENT CALL SITE
// Insertion point: immediately after schema helper definitions and before any
// read/write paths (migration endpoint, save flow, draw reads, learning reads).
$schemaStatus = ensureMcmcSchema($db, JFactory::getUser());
if (!$schemaStatus['success']) {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '[[div class="mcmc-container"]]'
       . '[[div class="mcmc-alert alert-warning"]]'
       . 'Database setup required. Please have an administrator run this page once.'
       . '[[/div]]'
       . '[[/div]]';
    if (defined('JDEBUG') && JDEBUG) {
        $dbg = 'schema_actions=' . count($schemaStatus['actions']) . '; schema_errors=' . count($schemaStatus['errors']);
        echo '<!-- ' . htmlspecialchars($dbg, ENT_QUOTES, 'UTF-8') . ' -->';
    }
    return;
}

// ==============================================================================
// SECTION 12: MIGRATION ENDPOINT (NEW)
// POST with action=mcmc_migrate + Joomla CSRF token + superuser auth
// executes all DDL statements. Returns JSON result.
// ==============================================================================
{
    $input2  = JFactory::getApplication()->input;
    $action2 = $input2->getString('action', '');
    if ($action2 === 'mcmc_migrate' && $_SERVER['REQUEST_METHOD'] === 'POST' && JSession::checkToken()) {
        $currUser2 = JFactory::getUser();
        if ($currUser2->authorise('core.admin')) {
            $sqlList = mcmcGetMigrationSQL();
            $res2    = [];
            foreach ($sqlList as $rawSql) {
                $realSql = str_replace('`#__', '`' . $db->getPrefix(), $rawSql);
                try {
                    $db->setQuery($realSql);
                    $db->execute();
                    $res2[] = ['ok' => true,  'hint' => substr($rawSql, 0, 80)];
                } catch (\Exception $ex) {
                    $res2[] = ['ok' => false, 'hint' => substr($rawSql, 0, 80), 'err' => $ex->getMessage()];
                }
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['migration' => $res2]);
            exit;
        }
    }
}

// ==============================================================================
// SECTION 12B: AJAX LEARNING STATE PERSISTENCE ENDPOINT (NEW -- C-1 fix)
// Purpose: Accept JSON POST from JS after backtest/optimizer completes and
//          persist computed metrics, weights, and calibration to DB.
// Auth:    Requires CSRF token and logged-in user (same as save prediction).
// URL:     POST with action=mcmc_save_learning
// ==============================================================================
{
    $inputLs = JFactory::getApplication()->input;
    $actionLs = $inputLs->getString('action', '');
    if ($actionLs === 'mcmc_save_learning' && $_SERVER['REQUEST_METHOD'] === 'POST' && JSession::checkToken()) {
        $currUserLs = JFactory::getUser();
        if (!$currUserLs->guest) {
            header('Content-Type: application/json; charset=utf-8');
            /* Read JSON body: try Joomla JInput first (stream-safe in Joomla 5.x),
               fall back to direct php://input read if JInput returns nothing. */
            $rawBody = '';
            try {
                $rawBody = (string)(JFactory::getApplication()->input->json->getRaw());
            } catch (\Exception $_e) { $rawBody = ''; }
            if ($rawBody === '') {
                $rawBody = (string)@file_get_contents('php://input');
            }
            $payload     = mcmcSafeJsonDecode($rawBody, null);
            if ($payload === null) {
                echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
                exit;
            }
            /* Validate required fields */
            $lsGameId    = trim((string)($payload['game_id']    ?? ''));
            $lsRegimeKey = trim((string)($payload['regime_key'] ?? ''));
            if ($lsGameId === '' || $lsRegimeKey === '') {
                echo json_encode(['ok' => false, 'error' => 'Missing game_id or regime_key']);
                exit;
            }
            /* Sanitize regime key to safe chars only */
            $lsRegimeKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($lsRegimeKey));
            /* Build state object from payload */
            $lsState = [
                'game_id'                => $lsGameId,
                'regime_key'             => $lsRegimeKey,
                'best_params_json'       => mcmcSanitizeJson($payload['best_params_json']       ?? '{}'),
                'component_weights_json' => mcmcSanitizeJson($payload['component_weights_json'] ?? '{}'),
                'calibration_map_json'   => mcmcSanitizeJson($payload['calibration_map_json']   ?? '{}'),
                'rolling_metrics_json'   => mcmcSanitizeJson($payload['rolling_metrics_json']   ?? '{}'),
                'confidence_score'       => (float)($payload['confidence_score'] ?? 0.0),
                'stability_score'        => (float)($payload['stability_score']  ?? 0.0),
                'sample_size'            => (int)($payload['sample_size']         ?? 0),
                'last_draw_count'        => (int)($payload['last_draw_count']     ?? 0),
            ];
            $saved = mcmcSaveLearningState($db, $lsState);
            /* Optionally persist backtest run summary */
            $runType = (string)($payload['run_type'] ?? '');
            if ($runType === 'backtest' && $saved) {
                mcmcLogAudit($db, $lsGameId, $lsRegimeKey, 'backtest_run', [
                    'cases'         => $lsState['sample_size'],
                    'confidence'    => $lsState['confidence_score'],
                    'stability'     => $lsState['stability_score'],
                    'model_version' => MCMC_MODEL_VERSION,
                ]);
            } elseif ($runType === 'optimizer' && $saved) {
                mcmcLogAudit($db, $lsGameId, $lsRegimeKey, 'optimizer_run', [
                    'cases'         => $lsState['sample_size'],
                    'confidence'    => $lsState['confidence_score'],
                    'model_version' => MCMC_MODEL_VERSION,
                ]);
            }
            echo json_encode(['ok' => $saved]);
            exit;
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
            exit;
        }
    }
}

// ==============================================================================
// SECTION 13: INPUT RESOLUTION & AUTH (UNCHANGED)
// ==============================================================================
$input = JFactory::getApplication()->input;

$rawGameId = $input->getString('gameId')
           ?: $input->getString('game_id')
           ?: (isset($_GET['gameId'])   ? $_GET['gameId']   : null)
           ?: (isset($_GET['game_id'])  ? $_GET['game_id']  : null);

if (!$rawGameId && !empty($_SERVER['REQUEST_URI'])) {
    $uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    $uriPath = rtrim($uriPath, '/');
    if (preg_match('~[?&](game_id|gameId)=([A-Za-z0-9_-]+)~', $_SERVER['REQUEST_URI'], $m)) {
        $rawGameId = $m[2];
    } elseif (preg_match('~/([A-Za-z0-9_-]{2,})$~', $uriPath, $m)) {
        $rawGameId = $m[1];
    }
}
$requestedId = trim((string)$rawGameId);

include '../joomla-auth.php';

$currUser      = JFactory::getUser();
$userGroups    = isset($currUser->groups) ? $currUser->groups : [];
$allowedGroups = [14];
$userIsInAllowedGroup = ($currUser->guest && in_array(1, $allowedGroups))
    ? true
    : !empty(array_intersect($allowedGroups, $userGroups));

// ==============================================================================
// SECTION 14: LOAD CONFIG JSON (UNCHANGED)
// ==============================================================================
$jsonFile = '/home/oscara/web/lottoexpert.net/public_html/lottery_skip_config.json';
if (!is_readable($jsonFile)) { echo "<!-- ERROR: Config not readable -->"; die('Config file not found.'); }
$cfgAll = json_decode(file_get_contents($jsonFile), true);
if (json_last_error()) { echo "<!-- JSON ERROR -->"; die('JSON error.'); }
$lotteries = (isset($cfgAll['lotteries']) && is_array($cfgAll['lotteries'])) ? $cfgAll['lotteries'] : [];
$allKeys   = array_keys($lotteries);

$gameId = '';
if ($requestedId !== '' && isset($lotteries[$requestedId]))                { $gameId = $requestedId; }
elseif ($requestedId !== '' && isset($lotteries[strtoupper($requestedId)])){ $gameId = strtoupper($requestedId); }
elseif ($requestedId !== '' && isset($lotteries[strtolower($requestedId)])){ $gameId = strtolower($requestedId); }

$DEFAULT_GAME_ID = '101';
if ($gameId === '') {
    if      (isset($lotteries[$DEFAULT_GAME_ID])) { $gameId = $DEFAULT_GAME_ID; echo "<!-- DEFAULTED: {$DEFAULT_GAME_ID} -->"; }
    elseif  (!empty($allKeys))                    { $gameId = $allKeys[0];       echo "<!-- DEFAULTED: first available {$gameId} -->"; }
    else { die('No lotteries defined.'); }
}

if (defined('JDEBUG') && JDEBUG) {
    echo "<!-- DEBUG requestedId: "   . htmlspecialchars($requestedId === '' ? 'NULL/empty' : $requestedId, ENT_QUOTES, 'UTF-8') . " -->";
    echo "<!-- DEBUG resolved gameId: " . htmlspecialchars($gameId, ENT_QUOTES, 'UTF-8') . " -->";
    echo "<!-- DEBUG available keys: "  . htmlspecialchars(implode(', ', $allKeys), ENT_QUOTES, 'UTF-8') . " -->";
}
$gameIdDb = resolveGameIdDb($db, $gameId);
if (defined('JDEBUG') && JDEBUG) {
    echo "<!-- DEBUG gameIdDb: " . htmlspecialchars($gameIdDb, ENT_QUOTES, 'UTF-8') . " -->";
}

if (!isset($lotteries[$gameId])) {
    echo '[[div class="mcmc-container"]][[h2]]Select a Lottery[[/h2]][[ul]]';
    foreach ($allKeys as $k) {
        $href = htmlspecialchars(JUri::getInstance()->toString(), ENT_QUOTES, 'UTF-8');
        $sep  = (strpos($href, '?') === false) ? '?' : '&';
        echo '[[li]][[a href="' . $href . $sep . 'game_id=' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '"]]'
           . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '[[/a]][[/li]]';
    }
    echo '[[/ul]][[/div]]';
    return;
}

$spec      = $lotteries[$gameId];
$tableName = $spec['dbCol'] ?? '';
$lc        = $spec['lotteryConfig'] ?? [];

$mainCols = [];
if (!empty($lc['main_ball_columns'])) {
    $mainCols = is_array($lc['main_ball_columns']) ? $lc['main_ball_columns'] : [$lc['main_ball_columns']];
}
$extraCols = [];
if (!empty($lc['extra_ball_columns'])) {
    $extraCols = is_array($lc['extra_ball_columns']) ? $lc['extra_ball_columns'] : [$lc['extra_ball_columns']];
} elseif (!empty($lc['extra_ball_column'])) {
    $extraCols = is_array($lc['extra_ball_column']) ? $lc['extra_ball_column'] : [$lc['extra_ball_column']];
}
$cols = array_values(array_filter(array_merge($mainCols, $extraCols), function($c){ return is_string($c) && $c !== ''; }));
if (empty($tableName) || empty($cols)) { die('Lottery config missing table name or ball columns.'); }

// ==============================================================================
// SECTION 15 [MODIFIED]: SAVE PREDICTION (POST)
// Why modified: adds strict server-side bounds, schema-aware insert, and
// fallback to original column set when migrations are not yet applied.
// Backward-compatibility risk addressed: preserves save flow on old DB schemas
// while enabling upgraded metadata persistence on migrated schemas.
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && JSession::checkToken() && isset($_POST['save_set'])) {
    $lottery = (int)($_POST['lottery_id'] ?? 0);
    if ($lottery <= 0) { die("Invalid or missing lottery_id."); }
    $src      = $db->quote(strip_tags(trim($_POST['source']             ?? '')));
    $lbl      = $db->quote(strip_tags(trim($_POST['label']              ?? '')));
    $mainNums = $db->quote(strip_tags(trim($_POST['main_numbers']       ?? '')));
    $extraNums= $db->quote(strip_tags(trim($_POST['extra_ball_numbers'] ?? '')));
    $genAt    = $db->quote(strip_tags(trim($_POST['generated_at']       ?? '')));
    $saved    = $db->quote(date('Y-m-d H:i:s'));
    // [SECURITY] Clamp all numeric params server-side — never trust client values.
    // Bounds are defined in SECTION 1 central config block for single-source-of-truth.
    $W  = min(MCMC_PARAM_W_MAX,  max(MCMC_PARAM_W_MIN,  (int)($_POST['walks']     ?? 1500)));
    $B  = min(MCMC_PARAM_B_MAX,  max(MCMC_PARAM_B_MIN,  (int)($_POST['burn_in']   ?? 70)));
    $K  = min(MCMC_PARAM_K_MAX,  max(MCMC_PARAM_K_MIN,  (float)($_POST['laplace_k'] ?? 1.0)));
    $D  = min(MCMC_PARAM_D_MAX,  max(MCMC_PARAM_D_MIN,  (float)($_POST['decay']   ?? 0.02)));
    $CL = min(MCMC_PARAM_CL_MAX, max(MCMC_PARAM_CL_MIN, (int)($_POST['chain_len'] ?? 3000)));

    $q = $db->getQuery(true)
        ->select('l.game_id')
        ->from($db->quoteName('#__lotteries', 'l'))
        ->where($db->quoteName('l.lottery_id') . '=' . $db->quote($lottery));
    $db->setQuery($q); $gameDbId = $db->loadResult();
    $nextDraw = getNextDrawDate($gameDbId, $db, $tableName);
    /* ---------------------------------------------------------------
       Schema-confirmed columns from #__user_saved_numbers.
       Derived fields computed server-side to ensure canonical correctness.
       Column names quoted with quoteName() per Joomla best practice (H-2).
       --------------------------------------------------------------- */

    /* prediction_family: 'regular' for standard lotteries, 'daily' for pick3/4/5.
       $isDailyGame is defined in Section 16 (after this POST handler exits via redirect),
       so we compute the same expression inline from $lc which IS available at this point. */
    $predFamilyIsDaily = ((int)($lc['max_main_ball_number'] ?? 0) <= 9
                          && empty($lc['has_extra_ball']));
    $predFamily = $predFamilyIsDaily ? 'daily' : 'regular';

    /* render_mode: determines front-end renderer */
    $renderMode  = ($predFamily === 'daily') ? 'daily_combos' : 'regular_main_extra';

    /* prediction_type: taken from lotteryConfig or defaulted from game_id */
    $predType    = trim((string)($lc['prediction_type'] ?? $gameId));

    /* draws_analyzed: posted by JS so user sees the count that was used */
    $drawsAnalyzed = max(0, (int)($_POST['draws_analyzed'] ?? 0));

    /* settings_json: store MCMC params as structured JSON for reproducibility */
    $settingsArr = [
        'source'     => 'mcmc_v2',
        'walks'      => $W, 'burn_in' => $B, 'laplace_k' => $K,
        'decay'      => $D, 'chain_len' => $CL,
        'model_version' => MCMC_MODEL_VERSION,
    ];
    $settingsJson = $db->quote(mcmcSafeJsonEncode($settingsArr));

    /* save_schema_version: version marker for structured display fields */
    $saveSchemaVer = $db->quote('mcmc_v2');

    /* Canonical target fields bound at save time */
    $targetGameId     = $db->quote((string)($gameDbId ?? $gameId));
    $targetLotteryId  = (int)$lottery;  /* already validated > 0 above */

    /* -----------------------------------------------------------------------
       BACKWARD COMPAT STRATEGY: Try the extended v2 insert first (includes
       the 8 new columns added by the ALTER TABLE migration). If the columns
       don't exist yet (migration not run), MySQL throws a "Unknown column"
       error and we fall back to the original 15-column insert so saves keep
       working on un-migrated databases.
       ----------------------------------------------------------------------- */
    $ins = $db->getQuery(true)
        ->insert($db->quoteName('#__user_saved_numbers'))
        ->columns(array_map([$db,'quoteName'], [
            'user_id', 'lottery_id', 'main_numbers', 'extra_ball_numbers',
            'source', 'label', 'generated_at', 'date_saved', 'next_draw_date',
            'walks', 'burn_in', 'laplace_k', 'decay', 'chain_len',
            'draws_analyzed',
            'prediction_family', 'prediction_type', 'render_mode',
            'settings_json', 'save_schema_version',
            'target_game_id', 'target_lottery_id',
        ]))
        ->values(implode(',', [
            (int)$currUser->id,
            $targetLotteryId,
            $mainNums,
            $extraNums,
            $src,
            $lbl,
            $genAt,
            $saved,
            ($nextDraw !== '' ? $db->quote($nextDraw) : 'NULL'),
            $W, $B, $K, $D, $CL,
            $drawsAnalyzed,
            $db->quote($predFamily),
            $db->quote($predType),
            $db->quote($renderMode),
            $settingsJson,
            $saveSchemaVer,
            $targetGameId,
            $targetLotteryId,
        ]));
    try {
        $db->setQuery($ins);
        $db->execute();
        $app->enqueueMessage('Prediction set saved successfully.');
        $app->redirect('/my-dashboard');
        exit;
    } catch (\Exception $eV2) {
        /* Detect "Unknown column" error indicating new columns not yet migrated */
        $dbErrMsg = method_exists($eV2, 'getMessage') ? $eV2->getMessage() : '';
        $isColErr = (stripos($dbErrMsg, 'Unknown column') !== false
                  || stripos($dbErrMsg, "doesn't exist")  !== false);
        if ($isColErr) {
            /* FALLBACK: original 15-column insert (pre-migration schema) */
            $insFallback = $db->getQuery(true)
                ->insert($db->quoteName('#__user_saved_numbers'))
                ->columns(array_map([$db,'quoteName'], [
                    'user_id','lottery_id','main_numbers','extra_ball_numbers',
                    'source','label','generated_at','date_saved','next_draw_date',
                    'walks','burn_in','laplace_k','decay','chain_len'
                ]))
                ->values(implode(',', [
                    (int)$currUser->id,
                    $targetLotteryId,
                    $mainNums,
                    $extraNums,
                    $src,
                    $lbl,
                    $genAt,
                    $saved,
                    ($nextDraw !== '' ? $db->quote($nextDraw) : 'NULL'),
                    $W, $B, $K, $D, $CL,
                ]));
            try {
                $db->setQuery($insFallback);
                $db->execute();
                /* Warn that DB migration is needed for full metadata storage */
                $app->enqueueMessage('Prediction saved (run DB migration for extended metadata).', 'notice');
                $app->redirect('/my-dashboard');
                exit;
            } catch (\Exception $eFb) {
                $app->enqueueMessage('Error saving prediction: ' . $eFb->getMessage(), 'error');
            }
        } else {
            $app->enqueueMessage('Error saving prediction: ' . $eV2->getMessage(), 'error');
        }
    }
}

// ==============================================================================
// SECTION 16: FETCH HISTORICAL DRAWS (UNCHANGED)
// ==============================================================================
$query = $db->getQuery(true)
    ->select(array_merge(array_map([$db,'quoteName'], $cols), [$db->quoteName('draw_date')]))
    ->from(quoteTable($db, $tableName))
    ->where($db->quoteName('game_id').'='.$db->quote($gameIdDb))
    ->order($db->quoteName('draw_date').' ASC');
$db->setQuery($query);
try { $rows = $db->loadAssocList(); } catch (\RuntimeException $e) { die('Database query failed.'); }

$draws     = [];
$drawDates = [];
$isDailyGame = ((int)($lc['max_main_ball_number'] ?? 0) <= 9 && empty($lc['has_extra_ball']));
foreach ($rows as $r) {
    $seq = [];
    foreach ($cols as $c) {
        $val = isset($r[$c]) ? (int)$r[$c] : 0;
        if ($val === 0 && !$isDailyGame) continue;
        $seq[] = $val;
    }
    if (!empty($seq)) {
        $draws[]     = $seq;
        $dt          = isset($r['draw_date']) ? (string)$r['draw_date'] : '';
        $drawDates[] = ($dt !== '') ? date('Y-m-d', strtotime($dt)) : '';
    }
}

// ==============================================================================
// SECTION 17: REGIME DETECTION & LEARNING STATE (NEW)
// Runs after draws loaded. Results passed to JS as window globals.
// ==============================================================================
$pickSize    = (int)($lc['num_main_balls_drawn']  ?? 0);
$maxMainNum  = (int)($lc['max_main_ball_number']  ?? 0);
$hasExtraBall = !empty($lc['has_extra_ball']);

$regimeKey = (!empty($draws) && $pickSize > 0 && $maxMainNum > 0)
    ? mcmcComputeRegimeKey($draws, $lc)
    : 'unknown_unknown_stable_medium_entropy_nodaily_noextra';

$learningState = mcmcLoadLearningState($db, $gameIdDb, $regimeKey);
$learnedParams = mcmcSafeJsonDecode($learningState['best_params_json'] ?? '{}', []);
$currentSettingsForRec = ['W' => 2700, 'B' => 70, 'K' => 1.0, 'D' => 0.02, 'CL' => 3000];
$recommendation = mcmcBuildRecommendation($learningState, $currentSettingsForRec);

// ==============================================================================
// SECTION 18 [MODIFIED]: OUTPUT JS CONFIG
// Why modified: injects deterministic learning state and recommendation payloads
// required by the new UX and meta-learning layers.
// Backward-compatibility risk addressed: keeps existing globals and extends them
// without removing legacy fields used by current prediction, backtest, and charts.
// ==============================================================================
echo '[[script]]';
echo 'window.lottoDraws = '      . json_encode($draws,     JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';';
echo 'window.lottoDrawDates = '  . json_encode($drawDates, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';';
echo 'window.lottoConfig = '     . json_encode([
    'pickSize'          => $lc['num_main_balls_drawn']  ?? null,
    'hasExtra'          => $lc['has_extra_ball']         ?? false,
    'extraCount'        => $lc['num_extra_balls_drawn']  ?? 0,
    'rangeMain'         => isset($lc['max_main_ball_number'])  ? [1,(int)$lc['max_main_ball_number']]  : [],
    'rangeExtra'        => isset($lc['max_extra_ball_number']) ? [1,(int)$lc['max_extra_ball_number']] : [],
    'lotteryName'       => $spec['lotteryName'] ?? '',
    'scoreWMain'        => isset($lc['score_weight_main'])  ? (int)$lc['score_weight_main']  : 5,
    'scoreWExtra'       => isset($lc['score_weight_extra']) ? (int)$lc['score_weight_extra'] : 2,
    'gameId'            => $gameIdDb,
    'csrfTokenName'     => JSession::getFormToken(),
    // lotteryNumericId: the integer lottery_id from #__lotteries for save-form use.
    // Resolved server-side to avoid client-side spoofing.
    'lotteryNumericId'  => (function() use ($db, $gameIdDb) {
        try {
            $q2 = $db->getQuery(true)
                ->select($db->quoteName('lottery_id'))
                ->from($db->quoteName('#__lotteries'))
                ->where($db->quoteName('game_id') . '=' . $db->quote($gameIdDb))
                ->setLimit(1);
            $db->setQuery($q2);
            $val = $db->loadResult();
            return $val ? (int)$val : 0;
        } catch (\Exception $e) { return 0; }
    })(),
], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';';
echo 'window.mcmcLearningState = ' . json_encode([
    'source'        => $learningState['is_source']  ?? 'default',
    'regimeKey'     => $regimeKey,
    'learnedParams' => $learnedParams,
    'confidence'    => (float)($learningState['confidence_score'] ?? 0.0),
    'stability'     => (float)($learningState['stability_score']  ?? 0.0),
    'sampleSize'    => (int)($learningState['sample_size']        ?? 0),
    'weights'       => mcmcSafeJsonDecode($learningState['component_weights_json'] ?? '{}', []),
    'rollingMetrics'=> mcmcSafeJsonDecode($learningState['rolling_metrics_json']   ?? '{}', []),
], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';';
echo 'window.mcmcRecommendation = ' . json_encode($recommendation, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';';
echo 'window.MCMC_CFG = ' . json_encode([
    'MODEL_VERSION'   => MCMC_MODEL_VERSION,
    'LEARNING_RATE'   => MCMC_LEARNING_RATE,
    'MIN_WEIGHT'      => MCMC_MIN_WEIGHT,
    'MAX_WEIGHT'      => MCMC_MAX_WEIGHT,
    'WEIGHT_MOMENTUM' => MCMC_WEIGHT_MOMENTUM,
    'SCORE_W_PRED'    => MCMC_SCORE_W_PRED,
    'SCORE_W_CAL'     => MCMC_SCORE_W_CAL,
    'SCORE_W_STAB'    => MCMC_SCORE_W_STAB,
    'SCORE_W_CPLX'    => MCMC_SCORE_W_CPLX,
    'CONF_HIGH'       => MCMC_CONF_HIGH,
    'CONF_MEDIUM'     => MCMC_CONF_MEDIUM,
    'CONF_LOW'        => MCMC_CONF_LOW,
    'DEFAULT_W_LAG1'  => MCMC_DEFAULT_W_LAG1,
    'DEFAULT_W_COO'   => MCMC_DEFAULT_W_COO,
    'DEFAULT_W_POP'   => MCMC_DEFAULT_W_POP,
    'RR_W_FREQ'       => MCMC_RR_W_FREQ,
    'RR_W_GAP'        => MCMC_RR_W_GAP,
    'RR_W_MOMENTUM'   => MCMC_RR_W_MOMENTUM,
    'RR_W_VOLATILITY' => MCMC_RR_W_VOLATILITY,
    'RR_W_AGREE'      => MCMC_RR_W_AGREE,
    /* M-1: Regime thresholds mirrored to JS for single-source-of-truth */
    'REG_SHORT_HIST'  => MCMC_REG_SHORT_HIST,
    'REG_LONG_HIST'   => MCMC_REG_LONG_HIST,
    'REG_SMALL_RANGE' => MCMC_REG_SMALL_RANGE,
    'REG_LARGE_RANGE' => MCMC_REG_LARGE_RANGE,
    'REG_HIGH_ENT'    => MCMC_REG_HIGH_ENT,
    'REG_LOW_ENT'     => MCMC_REG_LOW_ENT,
], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';';
echo '[[/script]]';
?>

[[!DOCTYPE html]]
[[html lang="en"]]
[[head]]
[[meta charset="UTF-8"]]
[[meta name="viewport" content="width=device-width, initial-scale=1.0"]]
[[title]]<?php echo htmlspecialchars(($spec['lotteryName'] ?? 'MCMC Lottery'), ENT_QUOTES, 'UTF-8'); ?> - MCMC Prediction[[/title]]
[[script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"]][[/script]]
[[style]]
/* ============================================================
   MCMC LOTTERY FORECASTING -- EMBEDDED CSS (v2.1.0 SKAI Brand)
   Deliverable 5: Full CSS — SKAI brand palette applied throughout.

   SKAI Palette:
     Blue:          #1C66FF
     Deep Navy:     #0A1A33
     Sky Gray:      #EFEFF5
     Soft Slate:    #7F8DAA
     Success Green: #20C997
     Caution Amber: #F5A623

   SKAI Gradients:
     Deep Horizon:  linear-gradient(135deg, #0A1A33 0%, #1C66FF 100%)
     Radiant Blue:  linear-gradient(135deg, #1C66FF 0%, #7F8DAA 100%)
     Slate Mist:    linear-gradient(180deg, #EFEFF5 0%, #FFFFFF 100%) [light surfaces]
     Success:       linear-gradient(135deg, #20C997 0%, #0A1A33 100%)
     Ember Warning: linear-gradient(135deg, #F5A623 0%, #0A1A33 100%)

   WCAG AA notes:
     Text on Deep Navy (#0A1A33): use #EFEFF5 (primary) or #A8BFDA (secondary).
     #20C997 on #0A1A33: contrast ~8.2:1 -- passes AA for all text.
     #F5A623 on #0A1A33: contrast ~6.2 -- passes AA for all text.
     #1C66FF on #0A1A33: contrast ~3.0 -- use only for interactive elements.
     For button text on #1C66FF bg: use #FFFFFF (contrast ~4.2, AA large text).
   ============================================================ */

/* --- Reset and base --- */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Inter, Helvetica, Arial, sans-serif;
    background: #0A1A33; /* SKAI Deep Navy */
    color: #EFEFF5;      /* SKAI Sky Gray -- primary text */
    font-size: 14px;
    line-height: 1.6;
}
a { color: #7AADFF; text-decoration: none; } /* lightened blue for AA on navy */
a:hover { text-decoration: underline; color: #A8C8FF; }

/* --- Layout container --- */
.mcmc-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px 16px;
}

/* --- Cards --- */
.mcmc-card {
    background: #0D2147;   /* slightly lighter than Deep Navy */
    border: 1px solid rgba(28, 102, 255, 0.22); /* subtle SKAI Blue border */
    border-radius: 10px;
    padding: 18px 22px;
    margin-bottom: 18px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.30);
}
.mcmc-card h2, .mcmc-card h3 {
    font-size: 15px;
    font-weight: 700;
    color: #EFEFF5;
    margin-bottom: 10px;
    border-bottom: 1px solid rgba(28, 102, 255, 0.18);
    padding-bottom: 7px;
    letter-spacing: 0.2px;
}
.mcmc-card p { margin-bottom: 8px; color: #7F8DAA; font-size: 13px; }

/* --- Buttons --- */
.mcmc-btn {
    display: inline-block;
    padding: 7px 16px;
    border: 1px solid rgba(28, 102, 255, 0.30);
    border-radius: 7px;
    background: #152A50;  /* mid navy */
    color: #EFEFF5;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s, box-shadow 0.15s;
    margin-right: 6px;
    margin-top: 4px;
    letter-spacing: 0.1px;
}
.mcmc-btn:hover {
    background: #1A3560;
    border-color: rgba(28, 102, 255, 0.55);
    box-shadow: 0 0 0 2px rgba(28, 102, 255, 0.15);
    color: #EFEFF5;
}
/* Primary: SKAI Blue */
.mcmc-btn-primary { background: #1C66FF; border-color: #1C66FF; color: #FFFFFF; }
.mcmc-btn-primary:hover { background: #4D80FF; border-color: #4D80FF; box-shadow: 0 0 0 3px rgba(28,102,255,0.25); }
/* Success: SKAI Success Green */
.mcmc-btn-success { background: #20C997; border-color: #20C997; color: #0A1A33; }
.mcmc-btn-success:hover { background: #3DDAAF; border-color: #3DDAAF; }
/* Warning: SKAI Caution Amber */
.mcmc-btn-warning { background: #F5A623; border-color: #F5A623; color: #0A1A33; }
.mcmc-btn-warning:hover { background: #F7B84B; border-color: #F7B84B; }
/* Danger: keep legible red */
.mcmc-btn-danger  { background: #C0392B; border-color: #C0392B; color: #FFFFFF; }
.mcmc-btn-danger:hover  { background: #E74C3C; border-color: #E74C3C; }
.mcmc-btn-sm { padding: 4px 10px; font-size: 12px; }

/* --- Tables --- */
.mcmc-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
.mcmc-table th {
    background: #152A50; color: #7F8DAA; font-weight: 700;
    padding: 7px 10px; border-bottom: 1px solid rgba(28,102,255,0.18); text-align: left;
    font-size: 12px; letter-spacing: 0.3px; text-transform: uppercase;
}
.mcmc-table td {
    padding: 6px 10px; border-bottom: 1px solid rgba(28,102,255,0.10); color: #EFEFF5;
    vertical-align: top;
}
.mcmc-table tr:last-child td { border-bottom: none; }
.mcmc-table tr:hover td { background: rgba(28,102,255,0.06); }

/* --- Badges --- */
.mcmc-badge {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.3px;
    vertical-align: middle;
}
/* High/success -- SKAI Success Green */
.badge-high    { background: rgba(32,201,151,0.15); color: #20C997; border: 1px solid rgba(32,201,151,0.40); }
/* Medium/warning -- SKAI Caution Amber */
.badge-medium  { background: rgba(245,166,35,0.12); color: #F5A623; border: 1px solid rgba(245,166,35,0.35); }
/* Low/error */
.badge-low     { background: rgba(192,57,43,0.12);  color: #FF6B6B; border: 1px solid rgba(192,57,43,0.35); }
/* None/muted */
.badge-none    { background: rgba(127,141,170,0.10); color: #7F8DAA; border: 1px solid rgba(127,141,170,0.25); }
/* Stable -- SKAI Blue */
.badge-stable  { background: rgba(28,102,255,0.12); color: #7AADFF; border: 1px solid rgba(28,102,255,0.35); }
/* Shifting -- amber warning */
.badge-shifting{ background: rgba(245,166,35,0.12); color: #F5A623; border: 1px solid rgba(245,166,35,0.35); }
/* Calibration grades */
.badge-grade-A { background: rgba(32,201,151,0.15); color: #20C997; border: 1px solid rgba(32,201,151,0.40); }
.badge-grade-B { background: rgba(28,102,255,0.12); color: #7AADFF; border: 1px solid rgba(28,102,255,0.35); }
.badge-grade-C { background: rgba(245,166,35,0.12); color: #F5A623; border: 1px solid rgba(245,166,35,0.35); }
.badge-grade-D { background: rgba(192,57,43,0.12);  color: #FF6B6B; border: 1px solid rgba(192,57,43,0.35); }
.badge-grade-NA{ background: rgba(127,141,170,0.10); color: #7F8DAA; border: 1px solid rgba(127,141,170,0.25); }
/* Regime key badge -- monospace, SKAI Blue tint */
.badge-regime  {
    background: rgba(28,102,255,0.08); color: #7AADFF;
    border: 1px solid rgba(28,102,255,0.30); font-size: 10px;
    letter-spacing: 0.5px; font-family: monospace;
}

/* --- Progress bars --- */
.mcmc-progress-wrap { background: #152A50; border-radius: 4px; height: 8px; overflow: hidden; margin: 4px 0; }
.mcmc-progress-bar  { height: 100%; border-radius: 4px; transition: width 0.3s; }
/* Use SKAI palette colors for bar fills */
.pb-green  { background: #20C997; }         /* SKAI Success Green */
.pb-blue   { background: #1C66FF; }          /* SKAI Blue */
.pb-yellow { background: #F5A623; }          /* SKAI Caution Amber */
.pb-red    { background: #C0392B; }

/* --- Form inputs --- */
.mcmc-input {
    background: #0A1A33; border: 1px solid rgba(28,102,255,0.25);
    color: #EFEFF5; padding: 7px 10px; border-radius: 7px; font-size: 13px; width: 100%;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.mcmc-input:focus {
    border-color: #1C66FF; outline: none;
    box-shadow: 0 0 0 3px rgba(28,102,255,0.20);
}
.mcmc-select {
    background: #0A1A33; border: 1px solid rgba(28,102,255,0.25);
    color: #EFEFF5; padding: 6px 8px; border-radius: 7px; font-size: 13px;
}
.form-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px; align-items: flex-end; }
.form-group { flex: 1 1 140px; }
.form-group label { display: block; font-size: 12px; color: #7F8DAA; margin-bottom: 4px; font-weight: 500; letter-spacing: 0.2px; }
.param-note { font-size: 11px; color: #4E6A8A; margin-top: 3px; }

/* --- Charts --- */
.chart-container { position: relative; height: 260px; margin: 10px 0; }

/* --- Sections (collapsibles) --- */
.mcmc-section-toggle {
    cursor: pointer;
    -webkit-user-select: none; /* Safari */
    user-select: none;
    display: flex; align-items: center; justify-content: space-between;
}
.mcmc-section-toggle .toggle-icon { font-size: 12px; color: #7F8DAA; transition: transform 0.2s; }
.mcmc-section-body { overflow: hidden; }

/* --- Alerts / callouts --- */
.mcmc-alert {
    padding: 11px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 12px;
    border-left: 3px solid;
}
.alert-info    { background: rgba(28,102,255,0.10);  border-color: #1C66FF; color: #7AADFF; }
.alert-success { background: rgba(32,201,151,0.10);  border-color: #20C997; color: #20C997; }
.alert-warning { background: rgba(245,166,35,0.10);  border-color: #F5A623; color: #F5A623; }
.alert-error   { background: rgba(192,57,43,0.10);   border-color: #C0392B; color: #FF6B6B; }

/* --- Spinner --- */
.mcmc-spinner {
    display: inline-block; width: 14px; height: 14px;
    border: 2px solid rgba(28,102,255,0.25); border-top-color: #1C66FF;
    border-radius: 50%; animation: spin 0.7s linear infinite;
    vertical-align: middle; margin-right: 6px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ============================================================
   NEW ADDITIONS -- Recommendation panel, health cards,
   learning state card, calibration summary, comparison table.
   All using SKAI brand palette.
   ============================================================ */

/* --- Recommendation panel: SKAI Deep Horizon gradient ---
   This is a premium hero surface: uses the primary brand gradient.
   Text on gradient must pass WCAG AA: #EFEFF5 on darkest part of gradient passes. */
#mcmcRecommendPanel {
    background: linear-gradient(135deg, #0A1A33 0%, #1C2E5A 60%, #0D2147 100%);
    border: 1px solid rgba(28,102,255,0.40);
    border-radius: 10px;
    padding: 18px 22px;
    margin-bottom: 18px;
    box-shadow: 0 4px 20px rgba(28,102,255,0.12);
}
#mcmcRecommendPanel h2 {
    font-size: 15px; font-weight: 800; color: #7AADFF;
    margin-bottom: 10px;
    border-bottom: 1px solid rgba(28,102,255,0.25);
    padding-bottom: 7px;
    display: flex; align-items: center; gap: 8px;
    letter-spacing: 0.2px;
}
.rec-profile-row {
    display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 10px;
}
.rec-profile-name { font-weight: 700; color: #EFEFF5; font-size: 14px; letter-spacing: 0.1px; }
.rec-explanation  { color: #A8BFDA; font-size: 13px; line-height: 1.7; margin-bottom: 12px; }
.rec-params-grid  {
    /* Fallback flex for IE11-class browsers */
    display: -webkit-flex;
    display: flex;
    flex-wrap: wrap;
    /* Modern grid */
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px,1fr));
    gap: 8px; margin-bottom: 12px;
}
.rec-param-item {
    background: rgba(10,26,51,0.70);
    border: 1px solid rgba(28,102,255,0.22);
    border-radius: 8px; padding: 8px 10px; text-align: center;
    backdrop-filter: blur(4px);
}
.rec-param-label { font-size: 10px; color: #7F8DAA; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 3px; }
.rec-param-value { font-size: 17px; font-weight: 800; color: #7AADFF; }
.rec-param-hint  { font-size: 10px; color: #4E6A8A; margin-top: 2px; }
.rec-actions     { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
/* Diff warning: SKAI Ember Warning gradient on left border */
.rec-diff-warn {
    background: rgba(245,166,35,0.08);
    border: 1px solid rgba(245,166,35,0.30);
    border-left: 3px solid #F5A623;
    border-radius: 7px;
    padding: 7px 12px; color: #F5A623; font-size: 12px; margin-top: 8px;
}

/* --- Model health card: SKAI Success Green accent --- */
#mcmcHealthCard { border-color: rgba(32,201,151,0.35); }
#mcmcHealthCard h3 { color: #20C997; }
.health-metrics-grid {
    display: -webkit-flex; display: flex; flex-wrap: wrap;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap: 10px;
}
.health-metric {
    background: rgba(10,26,51,0.60);
    border: 1px solid rgba(28,102,255,0.15);
    border-radius: 8px; padding: 10px 12px;
}
.health-metric-label {
    font-size: 11px; color: #7F8DAA; text-transform: uppercase;
    letter-spacing: 0.5px; margin-bottom: 5px; font-weight: 600;
}
.health-metric-value { font-size: 22px; font-weight: 800; color: #EFEFF5; }
.health-metric-sub   { font-size: 11px; color: #4E6A8A; margin-top: 3px; }

/* --- Calibration card: SKAI Blue accent --- */
#mcmcCalibCard { border-color: rgba(28,102,255,0.35); }
#mcmcCalibCard h3 { color: #7AADFF; }
.calib-buckets { display: flex; gap: 4px; align-items: flex-end; height: 60px; margin: 8px 0; }
.calib-bucket-bar {
    flex: 1; background: #1C66FF; border-radius: 2px 2px 0 0; min-height: 4px;
}
.calib-bucket-bar.over  { background: #FF6B6B; }
.calib-bucket-bar.under { background: #F5A623; }
.calib-ideal-line       { border-top: 1px dashed #7F8DAA; }

/* --- Learning state card: SKAI Blue accent --- */
#mcmcLearnStateCard { border-color: rgba(28,102,255,0.35); }
#mcmcLearnStateCard h3 { color: #7AADFF; }
.ls-row {
    display: flex; justify-content: space-between;
    padding: 5px 0; border-bottom: 1px solid rgba(28,102,255,0.10); font-size: 13px;
}
.ls-row:last-child { border-bottom: none; }
.ls-label { color: #7F8DAA; }
.ls-value { color: #EFEFF5; font-weight: 700; }

/* --- Comparison table --- */
#mcmcComparePanel { display: none; }
.compare-table th { font-size: 12px; }
.compare-match  { color: #20C997; font-weight: 600; }  /* SKAI Success Green */
.compare-differ { color: #F5A623; font-weight: 800; }  /* SKAI Caution Amber */

/* --- Regime display --- */
.regime-display {
    background: rgba(28,102,255,0.08);
    border: 1px solid rgba(28,102,255,0.30);
    border-radius: 7px; padding: 6px 12px;
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 12px; color: #7AADFF;
    font-family: monospace; letter-spacing: 0.3px; margin: 4px 0;
}
.regime-icon { color: #1C66FF; font-size: 11px; }

/* --- Performance trend area --- */
#mcmcPerfTrend { margin: 8px 0; }
.trend-up   { color: #20C997; font-weight: 600; }  /* SKAI Success Green */
.trend-down { color: #FF6B6B; font-weight: 600; }
.trend-flat { color: #7F8DAA; }                     /* SKAI Soft Slate */

/* --- Audit trail --- */
#mcmcAuditTrail { max-height: 200px; overflow-y: auto; }
.audit-row {
    padding: 5px 0; border-bottom: 1px solid rgba(28,102,255,0.10);
    font-size: 12px; color: #7F8DAA;
}
.audit-event-type { color: #7AADFF; font-weight: 700; margin-right: 8px; }
.audit-time       { color: #4E6A8A; font-size: 11px; }

/* --- Score display --- */
.score-pill {
    display: inline-block; padding: 2px 8px; border-radius: 10px;
    font-size: 12px; font-weight: 700;
}
.score-high   { background: rgba(32,201,151,0.15); color: #20C997; }   /* SKAI Success */
.score-medium { background: rgba(245,166,35,0.12); color: #F5A623; }   /* SKAI Amber */
.score-low    { background: rgba(192,57,43,0.12);  color: #FF6B6B; }

/* --- Enhanced backtest results area --- */
#backtestResultsV2 { margin-top: 10px; }
.bt-summary-box {
    background: rgba(10,26,51,0.60);
    border: 1px solid rgba(28,102,255,0.18);
    border-radius: 8px; padding: 14px 18px; margin-bottom: 10px;
}
.bt-summary-grid {
    display: -webkit-flex; display: flex; flex-wrap: wrap;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(110px,1fr)); gap: 10px;
}
.bt-metric-item { text-align: center; }
.bt-metric-num  { font-size: 20px; font-weight: 800; color: #7AADFF; }  /* SKAI Blue tint */
.bt-metric-label{ font-size: 11px; color: #7F8DAA; margin-top: 3px; letter-spacing: 0.3px; }

/* --- Optimizer leaderboard --- */
#optimizerLeaderboard { margin-top: 10px; }
/* Best-by-score card: SKAI Success Gradient accent */
.lb-best-score {
    background: linear-gradient(135deg, rgba(32,201,151,0.08) 0%, rgba(10,26,51,0.80) 100%);
    border-color: rgba(32,201,151,0.35);
}
/* Best-by-stability card: SKAI Deep Horizon accent */
.lb-best-stab {
    background: linear-gradient(135deg, rgba(28,102,255,0.08) 0%, rgba(10,26,51,0.80) 100%);
    border-color: rgba(28,102,255,0.35);
}

/* --- Ensemble weight bars --- */
.weight-bar-row { margin: 7px 0; }
.weight-bar-label { font-size: 12px; color: #7F8DAA; margin-bottom: 3px; }
.weight-bar-fill  { height: 10px; border-radius: 4px; background: #1C66FF; transition: width 0.4s; }

/* --- Existing styles preserved --- */
.numbers-display { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
.number-ball {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 13px; border: 2px solid transparent;
    box-shadow: 0 1px 4px rgba(0,0,0,0.25);
}
.ball-main  { background: #1C66FF; border-color: #4D80FF; color: #FFFFFF; }   /* SKAI Blue */
.ball-extra { background: #F5A623; border-color: #F7B84B; color: #0A1A33; }   /* SKAI Amber */
.ball-predicted { opacity: 0.88; }
.ball-hit   { border-color: #20C997; box-shadow: 0 0 0 3px rgba(32,201,151,0.25); } /* SKAI Success */

/* --- Collapsible panels --- */
.collapsible-header {
    cursor: pointer; padding: 13px 18px;
    background: #0D2147; /* card-level navy */
    border: 1px solid rgba(28,102,255,0.18);
    border-radius: 8px; margin-bottom: 8px;
    display: flex; justify-content: space-between; align-items: center;
    transition: background 0.15s, border-color 0.15s;
}
.collapsible-header:hover {
    background: #152A50;
    border-color: rgba(28,102,255,0.35);
}
.collapsible-body { padding: 0 4px 4px; }

/* --- Responsive adjustments --- */
@media (max-width: 640px) {
    .rec-params-grid     { grid-template-columns: repeat(2, 1fr); }
    .health-metrics-grid { grid-template-columns: repeat(2, 1fr); }
    .form-row { flex-direction: column; }
    .form-group { flex: 1 1 100%; }
    .bt-summary-grid { grid-template-columns: repeat(3, 1fr); }
}
[[/style]]
[[/head]]
[[body]]
[[div class="mcmc-container"]]

<!-- Page header: lottery name (filled by JS from window.lottoConfig.lotteryName) -->
[[div class="mcmc-card" style="background:linear-gradient(135deg,#0A1A33 0%,#1C66FF 100%);margin-bottom:16px;"]]
  [[h1 id="lotteryTitle" aria-live="polite" style="font-size:22px;font-weight:700;color:#EFEFF5;margin:0 0 4px;"]]Loading...[[/h1]]
  [[div id="lotteryDetails" style="font-size:13px;color:#7F8DAA;"]]Loading configuration...[[/div]]
[[/div]]

<!-- ==================================================================
     NEW SECTION: RECOMMENDATION PANEL (Deliverable 3 UI, Layer 4)
     Insertion point: top of page, before existing advanced settings.
     Explains why settings are recommended, shows regime/confidence/
     stability badges, provides apply/revert/compare buttons.
     Populated entirely by renderRecommendPanel() JS on DOMContentLoaded.
     ================================================================== -->
[[div id="mcmcRecommendPanel" class="mcmc-card"]]
  [[h2]]
    [[span]]Recommendation Panel[[/span]]
    [[span id="recRegimeBadge" class="mcmc-badge badge-regime"]]...[[/span]]
  [[/h2]]
  [[div id="recContent"]]
    [[p]]Loading recommendation...[[/p]]
  [[/div]]
[[/div]]

<!-- ==================================================================
     NEW SECTION: MODEL HEALTH & LEARNING STATE CARD
     Shows confidence score, stability score, sample size, last updated.
     ================================================================== -->
[[div style="display:flex;gap:14px;flex-wrap:wrap;"]]

  [[div id="mcmcHealthCard" class="mcmc-card" style="flex:1;min-width:260px;"]]
    [[h3]]Model Health[[/h3]]
    [[div id="healthMetrics" class="health-metrics-grid"]]
      [[div class="health-metric"]]
        [[div class="health-metric-label"]]Confidence[[/div]]
        [[div class="health-metric-value" id="healthConfVal"]]--[[/div]]
        [[div class="health-metric-sub" id="healthConfLabel"]]loading[[/div]]
      [[/div]]
      [[div class="health-metric"]]
        [[div class="health-metric-label"]]Stability[[/div]]
        [[div class="health-metric-value" id="healthStabVal"]]--[[/div]]
        [[div class="health-metric-sub" id="healthStabLabel"]]loading[[/div]]
      [[/div]]
      [[div class="health-metric"]]
        [[div class="health-metric-label"]]Sample Size[[/div]]
        [[div class="health-metric-value" id="healthSampleVal"]]--[[/div]]
        [[div class="health-metric-sub"]]backtest cases[[/div]]
      [[/div]]
      [[div class="health-metric"]]
        [[div class="health-metric-label"]]Calibration[[/div]]
        [[div class="health-metric-value" id="healthCalibVal"]]--[[/div]]
        [[div class="health-metric-sub"]]grade[[/div]]
      [[/div]]
    [[/div]]
    <!-- Ensemble weight display -->
    [[div style="margin-top:10px;"]]
      [[div style="font-size:12px;color:#7F8DAA;margin-bottom:6px;"]]Ensemble Component Weights[[/div]]
      [[div id="ensembleWeightBars"]][[/div]]
    [[/div]]
  [[/div]]

  [[div id="mcmcLearnStateCard" class="mcmc-card" style="flex:1;min-width:260px;"]]
    [[h3]]Learning State[[/h3]]
    [[div id="learnStateRows"]]
      [[div class="ls-row"]][[span class="ls-label"]]Source[[/span]][[span class="ls-value" id="lsSource"]]--[[/span]][[/div]]
      [[div class="ls-row"]][[span class="ls-label"]]Regime[[/span]][[span class="ls-value" id="lsRegime"]]--[[/span]][[/div]]
      [[div class="ls-row"]][[span class="ls-label"]]Model Version[[/span]][[span class="ls-value"]]v2.0.0[[/span]][[/div]]
      [[div class="ls-row"]][[span class="ls-label"]]Log Loss[[/span]][[span class="ls-value" id="lsLogLoss"]]--[[/span]][[/div]]
      [[div class="ls-row"]][[span class="ls-label"]]Brier Score[[/span]][[span class="ls-value" id="lsBrier"]]--[[/span]][[/div]]
      [[div class="ls-row"]][[span class="ls-label"]]Hit Rate[[/span]][[span class="ls-value" id="lsHitRate"]]--[[/span]][[/div]]
    [[/div]]
  [[/div]]

[[/div]]

<!-- ==================================================================
     NEW SECTION: CURRENT vs LEARNED COMPARISON TABLE (hidden by default)
     ================================================================== -->
[[div id="mcmcComparePanel" class="mcmc-card"]]
  [[h3]]Current Settings vs Learned Optimum[[/h3]]
  [[table class="mcmc-table" id="compareTable"]]
    [[thead]][[tr]]
      [[th]]Parameter[[/th]][[th]]Current Value[[/th]][[th]]Learned Value[[/th]][[th]]Status[[/th]]
    [[/tr]][[/thead]]
    [[tbody id="compareTableBody"]][[/tbody]]
  [[/table]]
[[/div]]

<!-- ==================================================================
     EXISTING SECTION: ADVANCED SETTINGS (UNCHANGED — begin)
     ================================================================== -->
[[div class="collapsible-header" onclick="toggleSection('advancedSettings')"]]
  [[span]]Advanced Settings[[/span]]
  [[span class="toggle-icon" id="advancedSettingsIcon"]]&#9660;[[/span]]
[[/div]]
[[div id="advancedSettings" class="mcmc-card collapsible-body"]]
  [[div class="form-row"]]
    [[div class="form-group"]]
      [[label for="walksInput"]]Walks (W)[[/label]]
      [[input type="number" id="walksInput" class="mcmc-input" value="2700" min="100" max="40000" step="100"]]
      [[div class="param-note"]]Markov chain samples per prediction run[[/div]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="burnInInput"]]Burn-in (B)[[/label]]
      [[input type="number" id="burnInInput" class="mcmc-input" value="70" min="0" max="2000" step="10"]]
      [[div class="param-note"]]Initial samples discarded as warm-up[[/div]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="laplaceKInput"]]Laplace K[[/label]]
      [[input type="number" id="laplaceKInput" class="mcmc-input" value="1.0" min="0" max="20" step="0.1"]]
      [[div class="param-note"]]Smoothing constant (0=no smooth)[[/div]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="decayInput"]]Decay (D)[[/label]]
      [[input type="number" id="decayInput" class="mcmc-input" value="0.02" min="0.001" max="1.0" step="0.001"]]
      [[div class="param-note"]]Per-draw exponential weight decay[[/div]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="chainLenInput"]]Chain Length (CL)[[/label]]
      [[input type="number" id="chainLenInput" class="mcmc-input" value="3000" min="100" max="10000" step="100"]]
      [[div class="param-note"]]Total chain length after burn-in[[/div]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="topNInput"]]Top-N Picks[[/label]]
      [[input type="number" id="topNInput" class="mcmc-input" value="20" min="1" max="60" step="1"]]
      [[div class="param-note"]]Number of prediction candidates[[/div]]
    [[/div]]
  [[/div]]

  <!-- NEW: Ensemble weight controls -->
  [[div style="margin-top:12px;"]]
    [[div style="font-size:13px;font-weight:600;color:#EFEFF5;margin-bottom:8px;"]]Ensemble Component Weights[[/div]]
    [[div class="form-row"]]
      [[div class="form-group"]]
        [[label for="wLag1Input"]]Lag-1 Transition Weight[[/label]]
        [[input type="number" id="wLag1Input" class="mcmc-input" value="0.40" min="0.05" max="0.70" step="0.05"]]
      [[/div]]
      [[div class="form-group"]]
        [[label for="wCooInput"]]Co-occurrence Weight[[/label]]
        [[input type="number" id="wCooInput" class="mcmc-input" value="0.35" min="0.05" max="0.70" step="0.05"]]
      [[/div]]
      [[div class="form-group"]]
        [[label for="wPopInput"]]Popularity Weight[[/label]]
        [[input type="number" id="wPopInput" class="mcmc-input" value="0.25" min="0.05" max="0.70" step="0.05"]]
      [[/div]]
    [[/div]]
  [[/div]]

  <!-- NEW: Reranker controls -->
  [[div style="margin-top:12px;"]]
    [[div style="font-size:13px;font-weight:600;color:#EFEFF5;margin-bottom:8px;"]]Feature Reranker[[/div]]
    [[div class="form-row"]]
      [[div class="form-group"]]
        [[label for="useRerankerInput"]]Enable Reranker[[/label]]
        [[select id="useRerankerInput" class="mcmc-select"]]
          [[option value="1"]]Enabled[[/option]]
          [[option value="0"]]Disabled (base probs only)[[/option]]
        [[/select]]
      [[/div]]
      [[div class="form-group"]]
        [[label for="useCalibInput"]]Apply Calibration Map[[/label]]
        [[select id="useCalibInput" class="mcmc-select"]]
          [[option value="1"]]Enabled[[/option]]
          [[option value="0"]]Disabled[[/option]]
        [[/select]]
      [[/div]]
    [[/div]]
  [[/div]]
[[/div]]

<!-- ==================================================================
     EXISTING SECTION: RUN PREDICTION (UNCHANGED start)
     ================================================================== -->
[[div class="mcmc-card"]]
  [[h2]]Run Prediction[[/h2]]
  [[div class="form-row"]]
    [[div class="form-group"]]
      [[label for="labelInput"]]Prediction Label[[/label]]
      [[input type="text" id="labelInput" class="mcmc-input" placeholder="e.g. Saturday draw"]]
    [[/div]]
  [[/div]]
  [[button class="mcmc-btn mcmc-btn-primary" onclick="runPrediction()"]]
    Run MCMC Prediction
  [[/button]]
  [[div id="predictionStatus" style="margin-top:10px;"]][[/div]]
  [[div id="predictionResult" style="margin-top:10px;"]][[/div]]
[[/div]]

<!-- ==================================================================
     EXISTING SECTION: BACKTEST (UPGRADED — enhanced metrics in JS)
     ================================================================== -->
[[div class="collapsible-header" onclick="toggleSection('backtestSection')"]]
  [[span]]Walk-Forward Backtest[[/span]]
  [[span class="toggle-icon" id="backtestSectionIcon"]]&#9660;[[/span]]
[[/div]]
[[div id="backtestSection" class="mcmc-card collapsible-body"]]
  [[div class="form-row"]]
    [[div class="form-group"]]
      [[label for="btWindowMode"]]Window Mode[[/label]]
      [[select id="btWindowMode" class="mcmc-select"]]
        [[option value="expanding"]]Expanding Window[[/option]]
        [[option value="sliding"]]Sliding Window[[/option]]
      [[/select]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="btWindowSize"]]Window Size (sliding)[[/label]]
      [[input type="number" id="btWindowSize" class="mcmc-input" value="300" min="50" max="2000" step="50"]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="btStartIdx"]]Start Index[[/label]]
      [[input type="number" id="btStartIdx" class="mcmc-input" value="200" min="20" max="5000" step="10"]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="btStepSize"]]Step Size[[/label]]
      [[input type="number" id="btStepSize" class="mcmc-input" value="1" min="1" max="20" step="1"]]
    [[/div]]
  [[/div]]
  [[button class="mcmc-btn mcmc-btn-primary" onclick="runBacktest()"]]Run Backtest[[/button]]
  [[button class="mcmc-btn mcmc-btn-danger" id="btCancelBtn" onclick="cancelBacktest()" style="display:none"]]Cancel[[/button]]
  [[div id="btProgress" style="margin-top:8px;display:none;"]]
    [[div class="mcmc-progress-wrap"]][[div class="mcmc-progress-bar pb-blue" id="btProgressBar" style="width:0%"]][[/div]][[/div]]
    [[div id="btProgressText" style="font-size:12px;color:#7F8DAA;margin-top:4px;"]]Initializing...[[/div]]
  [[/div]]
  [[div id="backtestResultsV2"]][[/div]]
[[/div]]

<!-- ==================================================================
     EXISTING SECTION: OPTIMIZER (UPGRADED — stability-aware in JS)
     ================================================================== -->
[[div class="collapsible-header" onclick="toggleSection('optimizerSection')"]]
  [[span]]Parameter Optimizer[[/span]]
  [[span class="toggle-icon" id="optimizerSectionIcon"]]&#9660;[[/span]]
[[/div]]
[[div id="optimizerSection" class="mcmc-card collapsible-body"]]
  [[div class="form-row"]]
    [[div class="form-group"]]
      [[label for="optWalksVals"]]Walks grid (csv)[[/label]]
      [[input type="text" id="optWalksVals" class="mcmc-input" value="1500,2700,5000"]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="optKVals"]]Laplace K grid (csv)[[/label]]
      [[input type="text" id="optKVals" class="mcmc-input" value="0.5,1.0,2.0,4.0"]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="optDecayVals"]]Decay grid (csv)[[/label]]
      [[input type="text" id="optDecayVals" class="mcmc-input" value="0.005,0.02,0.05,0.10"]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="optBtStart"]]Backtest start idx[[/label]]
      [[input type="number" id="optBtStart" class="mcmc-input" value="200" min="20"]]
    [[/div]]
    [[div class="form-group"]]
      [[label for="optBtCases"]]Cases per candidate[[/label]]
      [[input type="number" id="optBtCases" class="mcmc-input" value="50" min="5" max="300"]]
    [[/div]]
  [[/div]]
  [[button class="mcmc-btn mcmc-btn-primary" onclick="runOptimizer()"]]Run Optimizer[[/button]]
  [[button class="mcmc-btn mcmc-btn-danger" id="optCancelBtn" onclick="cancelOptimizer()" style="display:none"]]Cancel[[/button]]
  [[div id="optProgress" style="margin-top:8px;display:none;"]]
    [[div class="mcmc-progress-wrap"]][[div class="mcmc-progress-bar pb-blue" id="optProgressBar" style="width:0%"]][[/div]][[/div]]
    [[div id="optProgressText" style="font-size:12px;color:#7F8DAA;margin-top:4px;"]]Initializing...[[/div]]
  [[/div]]
  [[div id="optimizerResults"]][[/div]]
  [[div id="optimizerLeaderboard"]][[/div]]
[[/div]]

<!-- ==================================================================
     EXISTING SECTION: DIAGNOSTICS AND CHARTS
     ================================================================== -->
[[div class="collapsible-header" onclick="toggleSection('diagnosticsSection')"]]
  [[span]]Diagnostics and Charts[[/span]]
  [[span class="toggle-icon" id="diagnosticsSectionIcon"]]&#9660;[[/span]]
[[/div]]
[[div id="diagnosticsSection" class="mcmc-card collapsible-body"]]
  [[div class="chart-container"]]
    [[canvas id="freqChart"]][[/canvas]]
  [[/div]]
  [[div class="chart-container"]]
    [[canvas id="transChart"]][[/canvas]]
  [[/div]]
  [[div id="diagnosticsText"]][[/div]]
[[/div]]

<!-- ==================================================================
     NEW SECTION: AUDIT TRAIL SUMMARY
     ================================================================== -->
[[div class="collapsible-header" onclick="toggleSection('auditSection')"]]
  [[span]]Model Audit Trail[[/span]]
  [[span class="toggle-icon" id="auditSectionIcon"]]&#9660;[[/span]]
[[/div]]
[[div id="auditSection" class="mcmc-card collapsible-body"]]
  [[div id="mcmcAuditTrail"]]
    [[div class="audit-row"]][[span class="audit-event-type"]]system[[/span]]Page loaded. Audit trail shows learning events once backtest/optimizer runs.[[/div]]
  [[/div]]
[[/div]]

[[/div]][[!-- end .mcmc-container --]]

[[script]]
/* ============================================================
   MCMC LOTTERY FORECASTING - JavaScript (ES5 compliant)
   Deliverable 4: Full ES5 JS additions and changes

   No let/const/arrow/class/template-literals/optional-chaining/
   nullish-coalescing/async-await/Map/Set/destructuring/spread/
   Promise.allSettled/modules used anywhere in this file.

   JS-1  : Central JS constants
   JS-2  : Utility helpers
   JS-3  : Probability engine builders (Layer 1)
   JS-4  : Ensemble combiner (Layer 2)
   JS-5  : Feature extraction
   JS-6  : Regime detection
   JS-7  : Calibration layer
   JS-8  : Stability analyzer
   JS-9  : Reranker
   JS-10 : Adaptive ensemble weight learning
   JS-11 : Confidence model
   JS-12 : Recommendation panel renderer (Layer 4)
   JS-13 : Backtest engine (upgraded)
   JS-14 : Optimizer (upgraded, stability-aware)
   JS-15 : Prediction run
   JS-16 : Diagnostics and charts
   JS-17 : UI helpers
   JS-18 : Init on DOMContentLoaded
   ============================================================ */

/* ============================================================
   JS-1: CENTRAL JS CONSTANTS
   Mirrors PHP central config block. window.MCMC_CFG injected by PHP.
   ============================================================ */

var MCMC_JS = (function () {
    var cfg = window.MCMC_CFG || {};
    return {
        MODEL_VERSION   : cfg.MODEL_VERSION   || '2.0.0',
        LEARNING_RATE   : cfg.LEARNING_RATE   || 0.15,
        MIN_WEIGHT      : cfg.MIN_WEIGHT      || 0.05,
        MAX_WEIGHT      : cfg.MAX_WEIGHT      || 0.70,
        WEIGHT_MOMENTUM : cfg.WEIGHT_MOMENTUM || 0.60,
        SCORE_W_PRED    : cfg.SCORE_W_PRED    || 0.50,
        SCORE_W_CAL     : cfg.SCORE_W_CAL     || 0.25,
        SCORE_W_STAB    : cfg.SCORE_W_STAB    || 0.15,
        SCORE_W_CPLX    : cfg.SCORE_W_CPLX    || 0.10,
        CONF_HIGH       : cfg.CONF_HIGH       || 0.75,
        CONF_MEDIUM     : cfg.CONF_MEDIUM     || 0.50,
        CONF_LOW        : cfg.CONF_LOW        || 0.25,
        DEFAULT_W_LAG1  : cfg.DEFAULT_W_LAG1  || 0.40,
        DEFAULT_W_COO   : cfg.DEFAULT_W_COO   || 0.35,
        DEFAULT_W_POP   : cfg.DEFAULT_W_POP   || 0.25,
        RR_W_FREQ       : cfg.RR_W_FREQ       || 0.25,
        RR_W_GAP        : cfg.RR_W_GAP        || 0.20,
        RR_W_MOMENTUM   : cfg.RR_W_MOMENTUM   || 0.15,
        RR_W_VOLATILITY : cfg.RR_W_VOLATILITY || 0.10,
        RR_W_AGREE      : cfg.RR_W_AGREE      || 0.30,
        /* Parameter bounds — must match PHP central config block server-side clamping */
        PARAM_W_MIN     : 100,
        PARAM_W_MAX     : 40000,
        PARAM_B_MIN     : 0,
        PARAM_B_MAX     : 2000,
        PARAM_K_MIN     : 0.0,
        PARAM_K_MAX     : 20.0,
        PARAM_D_MIN     : 0.001,
        PARAM_D_MAX     : 1.0,
        PARAM_CL_MIN    : 100,
        PARAM_CL_MAX    : 10000,
        /* M-1: Regime thresholds read from MCMC_CFG */
        REG_SHORT_HIST  : cfg.REG_SHORT_HIST  || 200,
        REG_LONG_HIST   : cfg.REG_LONG_HIST   || 1000,
        REG_SMALL_RANGE : cfg.REG_SMALL_RANGE || 40,
        REG_LARGE_RANGE : cfg.REG_LARGE_RANGE || 69,
        REG_HIGH_ENT    : cfg.REG_HIGH_ENT    || 0.92,
        REG_LOW_ENT     : cfg.REG_LOW_ENT     || 0.75
    };
}());

var MCMC_AUDIT     = [];  // In-page audit trail
var MCMC_BT_CANCEL  = false;  // Backtest cancellation flag
var MCMC_OPT_CANCEL = false;  // Optimizer cancellation flag

/* ============================================================
   JS-2: UTILITY HELPERS
   ============================================================ */

function sumArr(arr) {
    var s = 0;
    for (var i = 0; i < arr.length; i++) { s += arr[i]; }
    return s;
}

function clamp(v, lo, hi) {
    return (v < lo) ? lo : ((v > hi) ? hi : v);
}

function maxArr(arr) {
    var m = -Infinity;
    for (var i = 0; i < arr.length; i++) { if (arr[i] > m) m = arr[i]; }
    return m;
}

function minArr(arr) {
    var m = Infinity;
    for (var i = 0; i < arr.length; i++) { if (arr[i] < m) m = arr[i]; }
    return m;
}

/* Normalize array to sum=1. Returns uniform distribution if sum<=0. */
function normalizeVec(arr) {
    var s = sumArr(arr);
    var out = [];
    if (s <= 0) {
        var u = 1.0 / Math.max(1, arr.length);
        for (var i = 0; i < arr.length; i++) { out.push(u); }
        return out;
    }
    for (var i = 0; i < arr.length; i++) { out.push(arr[i] / s); }
    return out;
}

/* Deep copy using JSON round-trip. Returns fallback on failure. */
function deepCopy(obj, fallback) {
    try { return JSON.parse(JSON.stringify(obj)); }
    catch (e) { return (fallback !== undefined) ? fallback : null; }
}

/* Safe JSON parse with fallback */
function safeParseJSON(str, fallback) {
    if (fallback === undefined) fallback = null;
    try {
        var r = JSON.parse(str);
        return (r !== null && r !== undefined) ? r : fallback;
    } catch (e) { return fallback; }
}

/* Format float to N decimal places */
function fmt(v, n) {
    if (n === undefined) n = 4;
    if (v === null || v === undefined || isNaN(v)) return '--';
    return parseFloat(v).toFixed(n);
}

/* Escape HTML to prevent XSS in innerHTML assignments. */
function escHtml(str) {
    if (str === null || str === undefined) return '';
    str = String(str);
    var map = { '&': '\x26amp;', '<': '\x26lt;', '>': '\x26gt;', '"': '\x26quot;', "'": '\x26#39;', '`': '\x26#96;' };
    return str.replace(/[&<>"'`]/g, function (ch) { return map[ch] || ch; });
}

/* Resolve Joomla CSRF token field name from server-injected config first,
   then fallback to hidden-field scan for backward compatibility. */
function getCsrfTokenName() {
    var cfg = window.lottoConfig || {};
    if (cfg.csrfTokenName) {
        return String(cfg.csrfTokenName);
    }
    var tokenEls = document.querySelectorAll('input[type=hidden]');
    for (var t = 0; t < tokenEls.length; t++) {
        var tel = tokenEls[t];
        if (tel.name && tel.name.length === 32 && /^[0-9a-f]{32}$/.test(tel.name)) {
            return tel.name;
        }
    }
    return '';
}

/* Build plain object as hash-set from array */
function buildSet(arr) {
    var s = {};
    for (var i = 0; i < arr.length; i++) { s[arr[i]] = true; }
    return s;
}

/* Read float from input element, return fallback if missing/NaN */
function inputFloat(id, fallback) {
    var el = document.getElementById(id);
    if (!el) return fallback;
    var v = parseFloat(el.value);
    return isNaN(v) ? fallback : v;
}

/* Read int from input element, return fallback if missing/NaN */
function inputInt(id, fallback) {
    var el = document.getElementById(id);
    if (!el) return fallback;
    var v = parseInt(el.value, 10);
    return isNaN(v) ? fallback : v;
}

/* Set innerHTML of element by ID. No-op if element missing. */
function setInner(id, html) {
    var el = document.getElementById(id);
    if (el) el.innerHTML = html;
}

/* Set textContent (XSS safe) */
function setText(id, text) {
    var el = document.getElementById(id);
    if (el) el.textContent = String(text);
}

/* Show/hide helpers */
function showEl(id) { var e = document.getElementById(id); if (e) e.style.display = ''; }
function hideEl(id) { var e = document.getElementById(id); if (e) e.style.display = 'none'; }

/* Toggle collapsible section */
function toggleSection(id) {
    var body = document.getElementById(id);
    var icon = document.getElementById(id + 'Icon');
    if (!body) return;
    if (body.style.display === 'none') {
        body.style.display = '';
        if (icon) icon.innerHTML = '&#9660;';
    } else {
        body.style.display = 'none';
        if (icon) icon.innerHTML = '&#9654;';
    }
}

/* Add entry to in-page audit trail */
function auditLog(eventType, payload) {
    MCMC_AUDIT.push({
        eventType : eventType,
        payload   : payload || {},
        time      : new Date().toISOString()
    });
    renderAuditTrail();
}

/* Render the last 20 audit events to #mcmcAuditTrail */
function renderAuditTrail() {
    var el = document.getElementById('mcmcAuditTrail');
    if (!el) return;
    var html = '';
    var start = Math.max(0, MCMC_AUDIT.length - 20);
    for (var i = MCMC_AUDIT.length - 1; i >= start; i--) {
        var ev = MCMC_AUDIT[i];
        var payloadStr = '';
        try { payloadStr = JSON.stringify(ev.payload).slice(0, 100); }
        catch (e) { payloadStr = '(unparseable)'; }
        html += '<div class="audit-row">'
              + '<span class="audit-event-type">' + escHtml(ev.eventType) + '</span>'
              + escHtml(payloadStr)
              + '<span class="audit-time"> &bull; ' + escHtml(ev.time.replace('T',' ').slice(0,19)) + '</span>'
              + '</div>';
    }
    el.innerHTML = html || '<div class="audit-row">No events recorded.</div>';
}

/* Build HTML badge */
function buildBadge(text, cls) {
    return '<span class="mcmc-badge ' + escHtml(cls) + '">' + escHtml(String(text)) + '</span>';
}

/* Build score pill */
function scorePill(val) {
    var cls = (val >= 0.6) ? 'score-high' : ((val >= 0.35) ? 'score-medium' : 'score-low');
    return '<span class="score-pill ' + cls + '">' + fmt(val, 4) + '</span>';
}

/* ============================================================
   JS-3: PROBABILITY ENGINE BUILDERS (Layer 1 — Base Engines)

   Each engine returns a normalized probability vector of length maxN
   for ball numbers [1..maxN]. Index 0 = ball 1, index maxN-1 = ball maxN.

   ENGINE A: Lag-1 Markov transition model
   ENGINE B: Co-occurrence model
   ENGINE C: Decayed frequency popularity model
   ============================================================ */

/**
 * ENGINE A: Lag-1 transition probability vector.
 * Builds transition matrix T[from][to]. Predicts by weighting
 * recent appearances with exponential decay.
 * @param  {Array}  draws  2D array of draw sequences
 * @param  {number} pick   Main balls per draw
 * @param  {number} maxN   Max ball number
 * @param  {number} decay  Per-draw recency decay (e.g. 0.02)
 * @param  {number} k      Laplace smoothing (e.g. 1.0)
 * @return {Array}  Normalized probability vector length maxN
 */
function buildLag1TransitionProbs(draws, pick, maxN, decay, k) {
    if (!k) k = 1.0;
    /* Initialize transition matrix with Laplace counts */
    var trans = [];
    var i, j, b, prev;
    for (i = 0; i <= maxN; i++) {
        trans.push([]);
        for (j = 0; j <= maxN; j++) { trans[i].push(k); }
    }
    var n = draws.length;
    /* Fill transitions with recency-weighted counts */
    for (var d = 0; d < n; d++) {
        var w   = Math.exp(-decay * (n - 1 - d));
        var seq = draws[d];
        for (var a = 0; a < pick - 1 && a < seq.length - 1; a++) {
            var from = seq[a];
            var to   = seq[a + 1];
            if (from >= 1 && from <= maxN && to >= 1 && to <= maxN) {
                trans[from][to] += w;
            }
        }
    }
    /* Predict: for each ball b, sum all T[prev][b] */
    var probs = [];
    for (b = 1; b <= maxN; b++) {
        var score = 0;
        for (prev = 1; prev <= maxN; prev++) { score += trans[prev][b]; }
        probs.push(score);
    }
    return normalizeVec(probs);
}

/**
 * ENGINE B: Co-occurrence probability vector.
 * High co-occurrence with frequently drawn numbers = stronger signal.
 * @param  {Array}  draws  2D array
 * @param  {number} pick   Balls per draw
 * @param  {number} maxN   Max ball number
 * @param  {number} decay  Recency decay
 * @param  {number} k      Laplace smoothing
 * @return {Array}  Normalized probability vector length maxN
 */
function buildCoOccurrenceProbs(draws, pick, maxN, decay, k) {
    if (!k) k = 1.0;
    var co = [];
    var i, j;
    for (i = 0; i <= maxN; i++) {
        co.push([]);
        for (j = 0; j <= maxN; j++) { co[i].push(k); }
    }
    var n = draws.length;
    for (var d = 0; d < n; d++) {
        var w    = Math.exp(-decay * (n - 1 - d));
        var seq  = draws[d];
        var seen = [];
        for (var a = 0; a < pick && a < seq.length; a++) {
            var v = seq[a];
            if (v >= 1 && v <= maxN) seen.push(v);
        }
        for (var x = 0; x < seen.length; x++) {
            for (var y = x + 1; y < seen.length; y++) {
                co[seen[x]][seen[y]] += w;
                co[seen[y]][seen[x]] += w;
            }
        }
    }
    var probs = [];
    for (var b = 1; b <= maxN; b++) {
        var score = 0;
        for (var other = 1; other <= maxN; other++) {
            if (other !== b) score += co[b][other];
        }
        probs.push(score);
    }
    return normalizeVec(probs);
}

/**
 * ENGINE C: Decayed frequency popularity.
 * Simple recency-weighted frequency. Robust baseline.
 * @param  {Array}  draws  2D array
 * @param  {number} pick   Balls per draw
 * @param  {number} maxN   Max ball number
 * @param  {number} decay  Recency decay
 * @param  {number} k      Laplace smoothing
 * @return {Array}  Normalized probability vector length maxN
 */
function buildDecayedPopularityProbs(draws, pick, maxN, decay, k) {
    if (!k) k = 1.0;
    var freq = [];
    for (var i = 0; i < maxN; i++) { freq.push(k); }
    var n = draws.length;
    for (var d = 0; d < n; d++) {
        var w   = Math.exp(-decay * (n - 1 - d));
        var seq = draws[d];
        for (var a = 0; a < pick && a < seq.length; a++) {
            var v = seq[a];
            if (v >= 1 && v <= maxN) { freq[v - 1] += w; }
        }
    }
    return normalizeVec(freq);
}

/* ============================================================
   JS-4: ENSEMBLE COMBINER (Layer 2)
   Blends three probability vectors with clamped, normalized weights.
   ============================================================ */

/**
 * Blend three probability vectors with weights [wA, wB, wC].
 * Clamps to [MIN_WEIGHT, MAX_WEIGHT] and normalizes sum=1 before blending.
 */
function blendEnsemble(probsA, probsB, probsC, weights) {
    var wA = clamp(weights[0], MCMC_JS.MIN_WEIGHT, MCMC_JS.MAX_WEIGHT);
    var wB = clamp(weights[1], MCMC_JS.MIN_WEIGHT, MCMC_JS.MAX_WEIGHT);
    var wC = clamp(weights[2], MCMC_JS.MIN_WEIGHT, MCMC_JS.MAX_WEIGHT);
    var s  = wA + wB + wC;
    if (s <= 0) {
        wA = MCMC_JS.DEFAULT_W_LAG1;
        wB = MCMC_JS.DEFAULT_W_COO;
        wC = MCMC_JS.DEFAULT_W_POP;
        s  = 1.0;
    }
    wA /= s; wB /= s; wC /= s;

    var len     = probsA.length;
    var blended = [];
    for (var i = 0; i < len; i++) {
        blended.push(
            wA * (probsA[i] || 0) +
            wB * (probsB[i] || 0) +
            wC * (probsC[i] || 0)
        );
    }
    return normalizeVec(blended);
}

/** Read ensemble weights from page controls and normalize */
function getCurrentEnsembleWeights() {
    var wA = clamp(inputFloat('wLag1Input', MCMC_JS.DEFAULT_W_LAG1), MCMC_JS.MIN_WEIGHT, MCMC_JS.MAX_WEIGHT);
    var wB = clamp(inputFloat('wCooInput',  MCMC_JS.DEFAULT_W_COO),  MCMC_JS.MIN_WEIGHT, MCMC_JS.MAX_WEIGHT);
    var wC = clamp(inputFloat('wPopInput',  MCMC_JS.DEFAULT_W_POP),  MCMC_JS.MIN_WEIGHT, MCMC_JS.MAX_WEIGHT);
    var s  = wA + wB + wC;
    if (s <= 0) { return [MCMC_JS.DEFAULT_W_LAG1, MCMC_JS.DEFAULT_W_COO, MCMC_JS.DEFAULT_W_POP]; }
    return [wA / s, wB / s, wC / s];
}

/**
 * Full prediction pipeline for a slice of draws.
 * Runs all three engines, blends them, optionally applies calibration map.
 * @param  {Array}  drawsSlice  History to use
 * @param  {number} pick        Main ball pick size
 * @param  {number} maxN        Max ball number
 * @param  {Object} params      {W, B, K, D, CL, weights}
 * @param  {Object} calibMap    Calibration map (may be null)
 * @return {Array}  Blended normalized probability vector length maxN
 */
function runEnsemblePrediction(drawsSlice, pick, maxN, params, calibMap) {
    var K     = params.K    || 1.0;
    var D     = params.D    || 0.02;
    var wts   = params.weights || [MCMC_JS.DEFAULT_W_LAG1, MCMC_JS.DEFAULT_W_COO, MCMC_JS.DEFAULT_W_POP];

    var probsA = buildLag1TransitionProbs(drawsSlice, pick, maxN, D, K);
    var probsB = buildCoOccurrenceProbs(drawsSlice,  pick, maxN, D, K);
    var probsC = buildDecayedPopularityProbs(drawsSlice, pick, maxN, D, K);

    var blended = blendEnsemble(probsA, probsB, probsC, wts);

    /* Optionally apply calibration map (piecewise monotonic remapping) */
    if (calibMap && (document.getElementById('useCalibInput') ? document.getElementById('useCalibInput').value !== '0' : true)) {
        blended = applyCalibrationMap(blended, calibMap);
    }

    return blended;
}

/* ============================================================
   JS-5: FEATURE EXTRACTION (Layer 3 support)
   Computes per-number features from draw history.
   Used by the reranker and regime detection.
   ============================================================ */

/**
 * Compute decayed frequency for one ball number (1-indexed).
 * @param  {Array}  draws   2D array of full draw history
 * @param  {number} numIdx  1-indexed ball number
 * @param  {number} pick    Pick size
 * @param  {number} maxN    Max ball number
 * @param  {number} halfLife Decay half-life in draws
 * @return {number} Decayed frequency score (unnormalized)
 */
function computeDecayedFrequency(draws, numIdx, pick, maxN, halfLife) {
    if (!halfLife || halfLife <= 0) halfLife = 50;
    var decay = Math.log(2) / halfLife;
    var n     = draws.length;
    var score = 0;
    for (var d = 0; d < n; d++) {
        var w   = Math.exp(-decay * (n - 1 - d));
        var seq = draws[d];
        for (var a = 0; a < pick && a < seq.length; a++) {
            if (seq[a] === numIdx) { score += w; break; }
        }
    }
    return score;
}

/**
 * Compute recent absence gap for one ball (draws since last appearance).
 * @param  {Array}  draws   Full draw history (ascending by date)
 * @param  {number} numIdx  1-indexed ball number
 * @param  {number} pick    Pick size
 * @return {number} Gap in draws (0 = appeared in last draw, large = long absence)
 */
function computeRecentAbsenceGap(draws, numIdx, pick) {
    var n = draws.length;
    for (var d = n - 1; d >= 0; d--) {
        var seq = draws[d];
        for (var a = 0; a < pick && a < seq.length; a++) {
            if (seq[a] === numIdx) return (n - 1 - d);
        }
    }
    return n; // Never appeared
}

/**
 * Compute rolling rank momentum: rank in short window vs long window.
 * Positive = moving up in rank. Normalized to [-1, +1].
 * @param  {Array}  draws    Full draw history
 * @param  {number} numIdx   1-indexed ball number
 * @param  {number} pick     Pick size
 * @param  {number} maxN     Max ball number
 * @param  {number} shortWin Short window size
 * @param  {number} longWin  Long window size
 * @return {number} Momentum in [-1, +1]
 */
function computeRankMomentum(draws, numIdx, pick, maxN, shortWin, longWin) {
    var n     = draws.length;
    shortWin  = Math.min(shortWin || 50,  n);
    longWin   = Math.min(longWin  || 200, n);

    /* Compute frequency rank in short and long windows */
    function rankInWindow(slice) {
        var freq = [];
        var i;
        for (i = 0; i < maxN; i++) { freq.push(0); }
        for (var d = 0; d < slice.length; d++) {
            var seq = slice[d];
            for (var a = 0; a < pick && a < seq.length; a++) {
                var v = seq[a];
                if (v >= 1 && v <= maxN) freq[v - 1]++;
            }
        }
        /* Sort indices by frequency descending */
        var indices = [];
        for (i = 0; i < maxN; i++) { indices.push(i); }
        indices.sort(function (a, b) { return freq[b] - freq[a]; });
        /* Return 1-indexed rank of numIdx */
        for (i = 0; i < indices.length; i++) {
            if (indices[i] === numIdx - 1) return i + 1;
        }
        return maxN;
    }

    var shortSlice = draws.slice(Math.max(0, n - shortWin));
    var longSlice  = draws.slice(Math.max(0, n - longWin));
    var shortRank  = rankInWindow(shortSlice);
    var longRank   = rankInWindow(longSlice);

    /* Higher rank number = worse. Momentum = improved if shortRank < longRank */
    return (longRank - shortRank) / maxN;  // Normalized to [-1,+1]
}

/**
 * Compute volatility: standard deviation of appearance indicator over rolling windows.
 * High volatility = erratic number behavior.
 */
function computeVolatility(draws, numIdx, pick, windowSize) {
    var n   = draws.length;
    windowSize = Math.min(windowSize || 20, n);
    var rates = [];
    for (var start = 0; start + windowSize <= n; start++) {
        var count = 0;
        for (var d = start; d < start + windowSize; d++) {
            var seq = draws[d];
            for (var a = 0; a < pick && a < seq.length; a++) {
                if (seq[a] === numIdx) { count++; break; }
            }
        }
        rates.push(count / windowSize);
    }
    if (rates.length < 2) return 0;
    var mean = sumArr(rates) / rates.length;
    var varSum = 0;
    for (var i = 0; i < rates.length; i++) { varSum += (rates[i] - mean) * (rates[i] - mean); }
    return Math.sqrt(varSum / rates.length);
}

/**
 * Extract all features for all ball numbers into a 2D array.
 * features[i] = {freq, gap, momentum, volatility} for ball i+1
 * @param  {Array}  draws  Full draw history
 * @param  {number} pick   Pick size
 * @param  {number} maxN   Max ball number
 * @return {Array}  Array of feature objects length maxN
 */
function extractAllFeatures(draws, pick, maxN) {
    var features = [];
    for (var b = 1; b <= maxN; b++) {
        features.push({
            freq       : computeDecayedFrequency(draws, b, pick, maxN, 50),
            gap        : computeRecentAbsenceGap(draws, b, pick),
            momentum   : computeRankMomentum(draws, b, pick, maxN, 30, 150),
            volatility : computeVolatility(draws, b, pick, 20)
        });
    }
    return features;
}

/* ============================================================
   JS-6: REGIME DETECTION (JS version, mirrors PHP)
   Used by recommendation panel to show active regime on client side.
   ============================================================ */

/**
 * Compute normalized Shannon entropy of frequency distribution.
 * @param  {Array}  draws   Draw history (2D)
 * @param  {number} maxN    Max ball number
 * @param  {number} pick    Pick size
 * @param  {number} winSize  Last N draws to consider (0=all)
 * @return {number} Normalized entropy [0,1]
 */
function computeNormEntropy(draws, maxN, pick, winSize) {
    if (!draws.length || !maxN || !pick) return 0.5;
    var slice = (winSize > 0 && draws.length > winSize) ? draws.slice(-winSize) : draws;
    var freq  = [];
    var total = 0;
    var i;
    for (i = 0; i < maxN; i++) { freq.push(0); }
    for (var d = 0; d < slice.length; d++) {
        var seq = slice[d];
        for (var a = 0; a < pick && a < seq.length; a++) {
            var idx = seq[a] - 1;
            if (idx >= 0 && idx < maxN) { freq[idx]++; total++; }
        }
    }
    if (total <= 0) return 0.5;
    var entropy = 0;
    for (i = 0; i < maxN; i++) {
        if (freq[i] <= 0) continue;
        var p = freq[i] / total;
        entropy -= p * Math.log(p);
    }
    var maxEntropy = (maxN > 1) ? Math.log(maxN) : 1;
    return (maxEntropy > 0) ? Math.min(1, entropy / maxEntropy) : 0.5;
}

/**
 * Compute frequency drift (L1 distance between short and long windows).
 * @return {number} Drift [0,1]
 */
function computeFreqDrift(draws, maxN, pick, shortWin, longWin) {
    if (!draws.length || !maxN || !pick || draws.length < 10) return 0;
    var n     = draws.length;
    var sSlice = draws.slice(Math.max(0, n - shortWin));
    var lSlice = draws.slice(Math.max(0, n - longWin));

    function freqVec(slice) {
        var f = [], total = 0, i;
        for (i = 0; i < maxN; i++) { f.push(0); }
        for (var d = 0; d < slice.length; d++) {
            var seq = slice[d];
            for (var a = 0; a < pick && a < seq.length; a++) {
                var idx = seq[a] - 1;
                if (idx >= 0 && idx < maxN) { f[idx]++; total++; }
            }
        }
        if (total > 0) { for (i = 0; i < maxN; i++) { f[i] /= total; } }
        return f;
    }

    var fS = freqVec(sSlice);
    var fL = freqVec(lSlice);
    var l1 = 0;
    for (var i = 0; i < maxN; i++) { l1 += Math.abs(fS[i] - fL[i]); }
    return Math.min(1, l1 / 2);
}

/**
 * Build the regime key string from draws + config.
 * Returns a string like "long_history_large_range_stable_high_entropy_nodaily_extra"
 */
function buildRegimeKey(draws, lottoConfig) {
    var n    = draws.length;
    var maxN = (lottoConfig.rangeMain && lottoConfig.rangeMain[1]) || 0;
    var pick = lottoConfig.pickSize || 0;
    var hasEx = lottoConfig.hasExtra || false;

    var histBucket, rangeBucket, entBucket, driftBucket, dailyFlag, extraFlag;

    /* M-1: Read thresholds from MCMC_CFG (injected by PHP) for single-source-of-truth */
    var regShortHist  = MCMC_JS.REG_SHORT_HIST  || 200;
    var regLongHist   = MCMC_JS.REG_LONG_HIST   || 1000;
    var regSmallRange = MCMC_JS.REG_SMALL_RANGE || 40;
    var regLargeRange = MCMC_JS.REG_LARGE_RANGE || 69;
    var regHighEnt    = MCMC_JS.REG_HIGH_ENT    || 0.92;
    var regLowEnt     = MCMC_JS.REG_LOW_ENT     || 0.75;

    /* 1. History bucket */
    if      (n < regShortHist)  histBucket = 'short_history';
    else if (n < regLongHist)   histBucket = 'medium_history';
    else                        histBucket = 'long_history';

    /* 2. Range bucket */
    if      (maxN <= regSmallRange) rangeBucket = 'small_range';
    else if (maxN <= regLargeRange) rangeBucket = 'medium_range';
    else                            rangeBucket = 'large_range';

    /* 3. Entropy bucket (last 100 draws) */
    var ent = computeNormEntropy(draws, maxN, pick, 100);
    if      (ent >= regHighEnt) entBucket = 'high_entropy';
    else if (ent >= regLowEnt)  entBucket = 'medium_entropy';
    else                        entBucket = 'low_entropy';

    /* 4. Drift bucket (short=50 vs long=200) */
    var drift = (n >= 50) ? computeFreqDrift(draws, maxN, pick, 50, 200) : 0;
    driftBucket = (drift > 0.10) ? 'shift' : 'stable';

    /* 5. Format flags */
    dailyFlag = (maxN <= 9 && !hasEx) ? 'daily' : 'nodaily';
    extraFlag = hasEx ? 'extra' : 'noextra';

    return [histBucket, rangeBucket, driftBucket, entBucket, dailyFlag, extraFlag].join('_');
}

/* ============================================================
   JS-7: CALIBRATION LAYER
   Multi-label calibration metrics computed in pure JS.
   Used by backtest evaluator and optimizer scorer.
   ============================================================ */

/**
 * Compute multi-label log loss for a set of predictions vs actuals.
 * predictions[i] = probability vector (length maxN)
 * actuals[i]     = array of drawn numbers (1-indexed, length pick)
 * Returns average log loss per draw (lower = better).
 *
 * Formula: -(1/N) * sum_i sum_b [ y_ib * log(p_ib + eps) ]
 */
function computeLogLoss(predictions, actuals, pick, maxN) {
    var eps  = 1e-7;
    var n    = predictions.length;
    if (n === 0) return 9999;
    var totalLoss = 0;
    for (var i = 0; i < n; i++) {
        var pVec    = predictions[i];
        var truth   = actuals[i] || [];
        var truthSet = buildSet(truth.slice(0, pick));
        for (var b = 1; b <= maxN; b++) {
            var p = (pVec && pVec[b - 1] !== undefined) ? pVec[b - 1] : 0;
            var y = truthSet[b] ? 1 : 0;
            /* Log loss contribution for this ball */
            totalLoss -= y * Math.log(p + eps) + (1 - y) * Math.log(1 - p + eps);
        }
    }
    return totalLoss / (n * maxN);
}

/**
 * Compute Brier score (mean squared error of probability predictions).
 * Returns average Brier score per draw [0,1], lower = better.
 *
 * Formula: (1/N) * sum_i (1/M) * sum_b (p_ib - y_ib)^2
 */
function computeBrierScore(predictions, actuals, pick, maxN) {
    var n = predictions.length;
    if (n === 0) return 1.0;
    var total = 0;
    for (var i = 0; i < n; i++) {
        var pVec     = predictions[i];
        var truth    = actuals[i] || [];
        var truthSet = buildSet(truth.slice(0, pick));
        var rowSum   = 0;
        for (var b = 1; b <= maxN; b++) {
            var p = (pVec && pVec[b - 1] !== undefined) ? pVec[b - 1] : 0;
            var y = truthSet[b] ? 1 : 0;
            var d = p - y;
            rowSum += d * d;
        }
        total += rowSum / maxN;
    }
    return total / n;
}

/**
 * Compute reliability buckets for calibration curve.
 * Returns array of {avgPred, avgActual, count} for numBuckets equal-width bins.
 */
function computeReliabilityBuckets(predictions, actuals, pick, maxN, numBuckets) {
    if (!numBuckets) numBuckets = 10;
    var bSum  = [], bHit  = [], bCnt  = [], b;
    for (b = 0; b < numBuckets; b++) { bSum.push(0); bHit.push(0); bCnt.push(0); }

    for (var i = 0; i < predictions.length; i++) {
        var pVec     = predictions[i];
        var truth    = actuals[i] || [];
        var truthSet = buildSet(truth.slice(0, pick));
        for (var num = 1; num <= maxN; num++) {
            var p   = (pVec && pVec[num - 1] !== undefined) ? pVec[num - 1] : 0;
            var y   = truthSet[num] ? 1 : 0;
            var bi  = Math.min(numBuckets - 1, Math.floor(p * numBuckets));
            bSum[bi] += p;
            bHit[bi] += y;
            bCnt[bi]++;
        }
    }

    var buckets = [];
    for (b = 0; b < numBuckets; b++) {
        if (bCnt[b] > 0) {
            buckets.push({
                avgPred   : bSum[b] / bCnt[b],
                avgActual : bHit[b] / bCnt[b],
                count     : bCnt[b]
            });
        } else {
            buckets.push({ avgPred: (b + 0.5) / numBuckets, avgActual: 0, count: 0 });
        }
    }
    return buckets;
}

/**
 * Compute Expected Calibration Error from reliability buckets.
 * ECE = sum_b (count_b/total) * |avgPred_b - avgActual_b|
 */
function computeECE(buckets, totalCases) {
    if (!totalCases || totalCases <= 0) return 1.0;
    var ece = 0;
    for (var b = 0; b < buckets.length; b++) {
        if (buckets[b].count < 5) continue;
        ece += (buckets[b].count / totalCases) * Math.abs(buckets[b].avgPred - buckets[b].avgActual);
    }
    return ece;
}

/**
 * Apply calibration map to a probability vector.
 * Calibration map is an array of {inputLo, inputHi, outputMid} bucket remappings.
 * Simple piecewise linear interpolation (monotonic).
 * @param  {Array}  probs     Input probability vector
 * @param  {Array}  calibMap  Array of bucket objects {inputMid, outputMid}
 * @return {Array}  Remapped probability vector (renormalized)
 */
function applyCalibrationMap(probs, calibMap) {
    if (!calibMap || !calibMap.length) return probs;
    var out = [];
    for (var i = 0; i < probs.length; i++) {
        var p    = probs[i];
        /* Find nearest calibration bucket and interpolate */
        var best = p;
        var bestDist = Infinity;
        for (var j = 0; j < calibMap.length; j++) {
            var d = Math.abs(p - calibMap[j].inputMid);
            if (d < bestDist) { bestDist = d; best = calibMap[j].outputMid; }
        }
        out.push(Math.max(0, Math.min(1, best)));
    }
    return normalizeVec(out);
}

/**
 * Build calibration map from reliability buckets.
 * Each bucket becomes a {inputMid, outputMid} remap entry.
 */
function buildCalibMapFromBuckets(buckets) {
    var map = [];
    for (var b = 0; b < buckets.length; b++) {
        if (buckets[b].count < 5) continue;
        map.push({
            inputMid  : buckets[b].avgPred,
            outputMid : buckets[b].avgActual
        });
    }
    return map;
}

/* ============================================================
   JS-8: STABILITY ANALYZER
   Computes stability metrics from score histories.
   ============================================================ */

/**
 * Compute stability score from rolling window scores.
 * Stability = 1 - CoeffVar. Penalizes highly variable performance.
 * @param  {Array}  scores  Array of numeric scores
 * @return {number} Stability in [0,1]
 */
function computeStabilityScore(scores) {
    var n = scores.length;
    if (n < 2) return 0.5;
    var sum = sumArr(scores);
    var mean = sum / n;
    if (mean <= 0) return 0;
    var varSum = 0;
    for (var i = 0; i < n; i++) {
        var d = scores[i] - mean;
        varSum += d * d;
    }
    var cv = Math.sqrt(varSum / n) / mean;
    return Math.max(0, 1 - Math.min(1, cv));
}

/**
 * Compute rank overlap (Jaccard) between two top-K lists.
 * @param  {Array}  listA  Array of ball numbers (top-K predicted)
 * @param  {Array}  listB  Array of ball numbers (top-K predicted)
 * @return {number} Overlap ratio [0,1]
 */
function computeRankOverlap(listA, listB) {
    if (!listA.length || !listB.length) return 0;
    var setA = buildSet(listA);
    var setB = buildSet(listB);
    var union = 0, inter = 0;
    var key;
    for (key in setA) { union++; if (setB[key]) inter++; }
    for (key in setB) { if (!setA[key]) union++; }
    return (union > 0) ? inter / union : 0;
}

/**
 * Compute complexity penalty for large walks/chainLen when gains are marginal.
 * Penalty = W_cplx * normalized(walks * chainLen / maxComplexity)
 * @param  {number} walks      Walk count
 * @param  {number} chainLen   Chain length
 * @return {number} Penalty in [0,1]
 */
function computeComplexityPenalty(walks, chainLen) {
    /* maxComplexity: product of the maximum allowed walks and maximum allowed
       chain length, read from MCMC_JS central config (single-source-of-truth
       that mirrors the PHP MCMC_PARAM_W_MAX / MCMC_PARAM_CL_MAX constants). */
    var maxWalks    = MCMC_JS.PARAM_W_MAX  || 40000;
    var maxChainLen = MCMC_JS.PARAM_CL_MAX || 10000;
    var maxComplexity = maxWalks * maxChainLen;
    var complexity = Math.max(0, walks * chainLen);
    var raw = complexity / maxComplexity;
    /* Log scale: penalizes very high complexity without over-penalizing mid-range.
       At max complexity: penalty=1.0.
       At typical (walks=2700, CL=3000): raw=0.02, penalty~0.17 — very mild. */
    return Math.min(1, Math.log(1 + raw * 100) / Math.log(101));
}

/**
 * Compute the final composite optimizer score.
 * final_score = W_pred*predictive + W_cal*calibration + W_stab*stability - W_cplx*complexity
 * All weights from MCMC_JS central config.
 */
function computeFinalScore(predictive, calibration, stability, complexityPenalty) {
    return MCMC_JS.SCORE_W_PRED * predictive
         + MCMC_JS.SCORE_W_CAL  * calibration
         + MCMC_JS.SCORE_W_STAB * stability
         - MCMC_JS.SCORE_W_CPLX * complexityPenalty;
}

/* ============================================================
   JS-9: RERANKER (Layer 3)
   Applies a transparent weighted feature scoring on top of base probs.
   Does NOT replace base probabilities; adjusts rank order only.
   ============================================================ */

/**
 * Rerank probability vector using feature scores.
 * Output is the base prob multiplied by a feature-weighted modifier.
 * The modifier is always >= 0 and the result is renormalized.
 *
 * Formula: finalProb[i] = baseProb[i] * (1 + featureScore[i] * alpha)
 * where alpha controls reranker influence (0=pure base, 1=strong reranking).
 *
 * @param  {Array}  baseProbs  Blended probability vector from ensemble
 * @param  {Array}  features   Feature array from extractAllFeatures()
 * @param  {number} maxN       Max ball number
 * @param  {Object} weights    Reranker weights {freq, gap, momentum, volatility, agree}
 * @param  {number} alpha      Reranker influence [0,1]
 * @return {Array}  Renormalized probability vector
 */
function rerankByFeatures(baseProbs, features, maxN, weights, alpha) {
    if (!alpha) alpha = 0.3;
    if (!weights) {
        weights = {
            freq       : MCMC_JS.RR_W_FREQ,
            gap        : MCMC_JS.RR_W_GAP,
            momentum   : MCMC_JS.RR_W_MOMENTUM,
            volatility : MCMC_JS.RR_W_VOLATILITY,
            agree      : MCMC_JS.RR_W_AGREE
        };
    }

    /* Normalize each feature dimension across all balls */
    var freqArr = [], gapArr = [], momArr = [], volArr = [];
    for (var i = 0; i < features.length; i++) {
        freqArr.push(features[i].freq);
        gapArr.push(features[i].gap);
        momArr.push(features[i].momentum);
        volArr.push(features[i].volatility);
    }
    var freqMax = Math.max(maxArr(freqArr), 1e-9);
    var gapMax  = Math.max(maxArr(gapArr),  1e-9);
    var volMax  = Math.max(maxArr(volArr),  1e-9);

    /* Agreement feature: high base prob = high agreement across models */
    var probMax = Math.max(maxArr(baseProbs), 1e-9);
    var GAP_PARABOLA_SCALE = 4;

    var modified = [];
    for (var b = 0; b < maxN; b++) {
        var f     = features[b] || { freq: 0, gap: 0, momentum: 0, volatility: 0 };
        /* Normalize: freq high = good, gap high = due/overdue (moderate positive) */
        var normFreq = f.freq       / freqMax;               // [0,1] higher=better
        var normGap  = Math.min(1, f.gap / gapMax);          // [0,1] moderate gap = positive
        var normMom  = (f.momentum + 1) / 2;                 // [-1,1] -> [0,1]
        var normVol  = 1 - Math.min(1, f.volatility / volMax); // low vol = better
        var normAgree = baseProbs[b] / probMax;              // [0,1] agreement
        /* Gap scoring: A parabolic (quadratic) function is used because:
           - normGap=0 (appeared just now) should NOT get a strong boost (too fresh)
           - normGap=0.5 (moderately absent) gets maximum boost = 1.0
           - normGap=1.0 (never appeared or extremely old) gets no boost (stale/cold)
           The formula normGap*(1-normGap)*4 is the unit parabola scaled to [0,1],
           peaking at normGap=0.5. The factor 4 ensures the peak value equals 1.0.
           This is a transparent heuristic for reranking, not a proven causal law. */
        var gapScore = normGap * (1 - normGap) * GAP_PARABOLA_SCALE;
        var featureScore = weights.freq       * normFreq
                         + weights.gap        * gapScore
                         + weights.momentum   * normMom
                         + weights.volatility * normVol
                         + weights.agree      * normAgree;
        var baseP = baseProbs[b] || 0;
        modified.push(baseP * (1 + alpha * featureScore));
    }
    return normalizeVec(modified);
}

/* ============================================================
   JS-10: ADAPTIVE ENSEMBLE WEIGHT LEARNING
   Updates ensemble weights from per-component backtest evidence.
   Implements smoothing, clamping, and normalization.
   ============================================================ */

/**
 * Update ensemble weights from per-component performance scores.
 * Uses EMA-like blending: newWeights = momentum*old + (1-momentum)*provisional
 *
 * @param  {Array}  priorWeights      Current weights [wA, wB, wC]
 * @param  {Array}  componentScores   Raw performance of each engine [sA, sB, sC]
 * @param  {number} learningRate      Step size (0=frozen, 1=overwrite)
 * @param  {number} momentum          EMA momentum (0=no memory, 1=no update)
 * @return {Array}  Updated weights [wA, wB, wC]
 */
function updateEnsembleWeights(priorWeights, componentScores, learningRate, momentum) {
    if (!learningRate) learningRate = MCMC_JS.LEARNING_RATE;
    if (!momentum)     momentum     = MCMC_JS.WEIGHT_MOMENTUM;

    /* Step 1: Convert relative performance to provisional weights */
    var minScore = minArr(componentScores);
    var shifted  = [];
    for (var i = 0; i < componentScores.length; i++) {
        /* Shift to ensure all positive before normalization */
        shifted.push(Math.max(0, componentScores[i] - minScore + 1e-9));
    }
    var provisional = normalizeVec(shifted);

    /* Step 2: EMA blend: prior * momentum + provisional * (1-momentum) */
    var blended = [];
    for (var j = 0; j < priorWeights.length; j++) {
        blended.push(
            momentum * (priorWeights[j] || MCMC_JS.DEFAULT_W_LAG1) +
            (1 - momentum) * (provisional[j] || 0)
        );
    }

    /* Step 3: Apply learning rate: prior + rate * (blended - prior) */
    var updated = [];
    for (var k = 0; k < priorWeights.length; k++) {
        var prior = priorWeights[k] || MCMC_JS.DEFAULT_W_LAG1;
        updated.push(prior + learningRate * (blended[k] - prior));
    }

    /* Step 4: Clamp to [MIN_WEIGHT, MAX_WEIGHT] */
    for (var m = 0; m < updated.length; m++) {
        updated[m] = clamp(updated[m], MCMC_JS.MIN_WEIGHT, MCMC_JS.MAX_WEIGHT);
    }

    /* Step 5: Normalize to sum=1 */
    return normalizeVec(updated);
}

/* ============================================================
   JS-11: CONFIDENCE MODEL
   Computes composite confidence score from multiple quality signals.
   ============================================================ */

/**
 * Compute confidence score [0,1] from quality signals.
 * Combines calibration quality, stability, sample size sufficiency,
 * regime consistency (based on drift), and ensemble agreement.
 *
 * @param  {Object} signals  {calibration, stability, sampleSize, drift, entropyUniformity}
 * @return {Object} {score, label, explanation}
 */
function computeConfidenceScore(signals) {
    var cal     = clamp(signals.calibration      || 0, 0, 1);
    var stab    = clamp(signals.stability        || 0, 0, 1);
    var sample  = signals.sampleSize             || 0;
    var drift   = clamp(signals.drift            || 0, 0, 1);
    var entropy = clamp(signals.entropyUniformity|| 0, 0, 1);

    /* Sample sufficiency: sigmoid-like scaling */
    var sampleSuff = Math.min(1, sample / 200);  // 200 cases = full sufficiency

    /* Regime consistency: low drift = consistent = higher confidence */
    var regimeConsist = 1 - drift;

    /* Composite score: weighted average of signals */
    var score = 0.30 * cal
              + 0.25 * stab
              + 0.20 * sampleSuff
              + 0.15 * regimeConsist
              + 0.10 * entropy;

    /* Confidence label */
    var label;
    if      (score >= MCMC_JS.CONF_HIGH)   label = 'high';
    else if (score >= MCMC_JS.CONF_MEDIUM) label = 'medium';
    else if (score >= MCMC_JS.CONF_LOW)    label = 'low';
    else                                   label = 'none';

    /* Explanation text */
    var parts = [];
    if (cal < 0.5)    parts.push('Calibration is weak (' + fmt(cal, 2) + ')');
    if (stab < 0.5)   parts.push('Stability is low (' + fmt(stab, 2) + ')');
    if (sample < 30)  parts.push('Insufficient backtest cases (' + sample + ')');
    if (drift > 0.10) parts.push('Regime is shifting (drift=' + fmt(drift, 2) + ')');
    var explanation = parts.length ? parts.join('. ') + '.' : 'All quality signals are satisfactory.';

    return { score: score, label: label, explanation: explanation };
}

/* ============================================================
   JS-11B: LEARNING STATE PERSISTENCE (C-1 fix)
   Sends computed learning state back to server after backtest/optimizer.
   Uses synchronous-style XMLHttpRequest wrapped in a try/catch.
   ES5 compliant: no fetch, no Promise, no arrow functions.
   ============================================================ */

/**
 * Persist the computed learning state to the server via AJAX POST.
 * Called after backtest or optimizer completes.
 *
 * @param {string} runType      'backtest' or 'optimizer'
 * @param {Object} statePayload Serializable learning state object
 */
function persistLearningState(runType, statePayload) {
    /* Require game_id and regime_key at minimum */
    if (!statePayload || !statePayload.game_id || !statePayload.regime_key) {
        auditLog('weight_update', { action: 'persist_skipped', reason: 'missing_ids' });
        return;
    }

    /* Resolve Joomla CSRF token field name */
    var tokenName = getCsrfTokenName();
    var token = tokenName ? (tokenName + '=1') : '';
    /* Abort silently if no CSRF token found — the server would reject the request
       anyway. Log to console to aid debugging if Joomla changes its token format. */
    if (!token) {
        auditLog('weight_update', { action: 'persist_skipped', reason: 'no_csrf_token' });
        if (typeof console !== 'undefined' && console.warn) {
            console.warn('[MCMC] persistLearningState: CSRF token not found — persist aborted.');
        }
        return;
    }

    statePayload.run_type = runType;
    var bodyStr = '';
    try { bodyStr = JSON.stringify(statePayload); } catch (e) { return; }

    /* Build URL with CSRF token and action */
    var url = window.location.href.split('?')[0] + '?action=mcmc_save_learning&' + token;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true); /* async */
    xhr.setRequestHeader('Content-Type', 'application/json; charset=UTF-8');
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        if (xhr.status === 200) {
            var resp = null;
            try { resp = JSON.parse(xhr.responseText); } catch (e) { resp = null; }
            if (resp && resp.ok) {
                auditLog('weight_update', { action: 'persist_success', runType: runType });
            } else {
                auditLog('weight_update', {
                    action  : 'persist_failed',
                    runType : runType,
                    error   : (resp && resp.error) ? String(resp.error) : 'unknown'
                });
            }
        }
    };
    try { xhr.send(bodyStr); } catch (e) {
        auditLog('weight_update', { action: 'persist_xhr_error', error: String(e.message || e) });
    }
}

/**
 * Build a learning state payload from current backtest/optimizer results.
 * Called by renderBacktestResults and renderOptimizerResults.
 *
 * @param {string}  runType     'backtest' or 'optimizer'
 * @param {Object}  metrics     {avgScore, logLoss, brier, stability, calibScore}
 * @param {Array}   newWeights  Updated ensemble weights [wA, wB, wC]
 * @param {Object}  bestParams  Best parameters found {W, K, D, CL, B}
 * @param {number}  cases       Number of test cases
 * @param {Array}   calibMap    Calibration map array
 * @return {Object} Payload ready to POST
 */
function buildPersistPayload(runType, metrics, newWeights, bestParams, cases, calibMap) {
    var ls  = window.mcmcLearningState  || {};
    var cfg = window.lottoConfig        || {};
    var gameId    = cfg.gameId   || '';
    var regimeKey = buildRegimeKey(window.lottoDraws || [], cfg);

    var weightsObj = {
        main  : newWeights || [MCMC_JS.DEFAULT_W_LAG1, MCMC_JS.DEFAULT_W_COO, MCMC_JS.DEFAULT_W_POP],
        extra : [MCMC_JS.DEFAULT_W_LAG1, MCMC_JS.DEFAULT_W_COO, MCMC_JS.DEFAULT_W_POP]
    };

    var rollingMetrics = {
        log_loss    : metrics.logLoss    || null,
        brier_score : metrics.brier      || null,
        hit_rate    : (metrics.hitRate   !== undefined) ? metrics.hitRate : null,
        stability   : metrics.stability  || null,
        calibration : metrics.calibScore || null
    };

    var bestParamsJson = JSON.stringify(bestParams || {});
    var compWtsJson    = JSON.stringify(weightsObj);
    var calibMapJson   = JSON.stringify(calibMap || []);
    var rollingJson    = JSON.stringify(rollingMetrics);

    return {
        game_id                 : gameId,
        regime_key              : regimeKey,
        best_params_json        : bestParamsJson,
        component_weights_json  : compWtsJson,
        calibration_map_json    : calibMapJson,
        rolling_metrics_json    : rollingJson,
        confidence_score        : metrics.confidence || 0,
        stability_score         : metrics.stability  || 0,
        sample_size             : cases || 0,
        last_draw_count         : (window.lottoDraws || []).length
    };
}

/* ============================================================
   JS-12: RECOMMENDATION PANEL RENDERER (Layer 4)
   Reads from window.mcmcLearningState and window.mcmcRecommendation
   (injected by PHP) and renders the recommendation panel cards.
   ============================================================ */

/**
 * Render the recommendation panel using data from window.mcmcRecommendation.
 * Reads current settings from form inputs to detect drift.
 */
function renderRecommendPanel() {
    var rec = window.mcmcRecommendation || {};
    var ls  = window.mcmcLearningState  || {};
    var el  = document.getElementById('recContent');
    if (!el) return;

    var html = '';

    /* Profile name + regime badge */
    html += '<div class="rec-profile-row">'
          + '<span class="rec-profile-name">' + escHtml(rec.profile_name || 'Default Profile') + '</span>'
          + buildBadge(rec.confidence_label || 'none', 'mcmc-badge ' + 'badge-' + (rec.confidence_label || 'none'))
          + '&nbsp;'
          + buildBadge((rec.stability >= 0.5) ? 'stable' : 'shifting', 'mcmc-badge ' + ((rec.stability >= 0.5) ? 'badge-stable' : 'badge-shifting'))
          + '&nbsp;Calibration:&nbsp;'
          + buildBadge(rec.calibration_grade || 'N/A', 'mcmc-badge badge-grade-' + (rec.calibration_grade || 'NA').replace('/', '').replace(' ',''))
          + '</div>';

    /* Regime key display */
    var regimeKey = escHtml(ls.regimeKey || rec.regime_key || 'unknown');
    document.getElementById('recRegimeBadge').textContent = regimeKey;

    /* Explanation text */
    html += '<div class="rec-explanation">' + escHtml(rec.explanation || '') + '</div>';

    /* Learned parameter display */
    var params = rec.learned_params || rec.params || {};
    if (params && typeof params === 'object') {
        html += '<div class="rec-params-grid">';
        var paramDefs = [
            { key: 'W',  label: 'Walks',       hint: 'chain samples' },
            { key: 'B',  label: 'Burn-in',      hint: 'warmup discards' },
            { key: 'K',  label: 'Laplace K',    hint: 'smoothing' },
            { key: 'D',  label: 'Decay',        hint: 'per-draw decay' },
            { key: 'CL', label: 'Chain Len',    hint: 'total chain' }
        ];
        for (var p = 0; p < paramDefs.length; p++) {
            var pd  = paramDefs[p];
            var val = params[pd.key];
            if (val === undefined || val === null) val = '--';
            html += '<div class="rec-param-item">'
                  + '<div class="rec-param-label">' + escHtml(pd.label) + '</div>'
                  + '<div class="rec-param-value">' + escHtml(String(val)) + '</div>'
                  + '<div class="rec-param-hint">'  + escHtml(pd.hint)   + '</div>'
                  + '</div>';
        }
        html += '</div>';
    }

    /* Action buttons */
    html += '<div class="rec-actions">'
          + '<button class="mcmc-btn mcmc-btn-primary mcmc-btn-sm" onclick="applyLearnedDefaults()">Apply Learned Defaults</button>'
          + '<button class="mcmc-btn mcmc-btn-sm" onclick="applyHardDefaults()">Revert to Safe Defaults</button>'
          + '<button class="mcmc-btn mcmc-btn-sm" onclick="toggleComparePanel()">Compare Current vs Learned</button>'
          + '</div>';

    /* Drift warning */
    if (rec.differs_from_curr) {
        html += '<div class="rec-diff-warn">Your current settings differ from the learned optimum for this regime. Click "Apply Learned Defaults" to align them.</div>';
    }

    el.innerHTML = html;

    /* Update health card */
    updateHealthCard(rec, ls);
    /* Update learning state card */
    updateLearnStateCard(ls);
    /* Render ensemble weight bars */
    renderEnsembleWeightBars(ls.weights || {});
}

/** Update the model health card */
function updateHealthCard(rec, ls) {
    var confPct  = Math.round((rec.confidence || 0) * 100);
    var stabPct  = Math.round((rec.stability  || 0) * 100);
    var sample   = ls.sampleSize || 0;
    var calGrade = rec.calibration_grade || 'N/A';

    /* Color-code the values -- SKAI palette */
    var confColor = (rec.confidence >= 0.75) ? '#20C997' : ((rec.confidence >= 0.50) ? '#F5A623' : '#FF6B6B');
    var stabColor = (rec.stability  >= 0.50) ? '#20C997' : '#FF6B6B';

    setInner('healthConfVal',   '<span style="color:' + confColor + '">' + confPct + '%</span>');
    setInner('healthConfLabel', rec.confidence_label || 'none');
    setInner('healthStabVal',   '<span style="color:' + stabColor + '">' + stabPct + '%</span>');
    setInner('healthStabLabel', (rec.stability >= 0.5) ? 'stable' : 'shifting');
    setText('healthSampleVal', String(sample));
    var calClass = 'badge-grade-' + (calGrade === 'N/A' ? 'NA' : calGrade);
    setInner('healthCalibVal',  buildBadge(calGrade, 'mcmc-badge ' + calClass));
}

/** Update the learning state card */
function updateLearnStateCard(ls) {
    setText('lsSource',  ls.source   || 'default');
    setText('lsRegime',  ls.regimeKey|| 'unknown');
    var metrics = ls.rollingMetrics || {};
    setText('lsLogLoss', (metrics.log_loss   !== null && metrics.log_loss   !== undefined) ? fmt(metrics.log_loss,   4) : '--');
    setText('lsBrier',   (metrics.brier_score !== null && metrics.brier_score!== undefined) ? fmt(metrics.brier_score,4) : '--');
    setText('lsHitRate', (metrics.hit_rate    !== null && metrics.hit_rate   !== undefined) ? fmt(metrics.hit_rate,  4) : '--');
}

/** Render ensemble weight bars in the health card */
function renderEnsembleWeightBars(weights) {
    var el = document.getElementById('ensembleWeightBars');
    if (!el) return;
    var mainWeights = (weights.main && weights.main.length) ? weights.main : [MCMC_JS.DEFAULT_W_LAG1, MCMC_JS.DEFAULT_W_COO, MCMC_JS.DEFAULT_W_POP];
    var labels = ['Lag-1 Transition', 'Co-occurrence', 'Popularity'];
    var html = '';
    for (var i = 0; i < 3; i++) {
        var w   = mainWeights[i] || 0;
        var pct = Math.round(w * 100);
        html += '<div class="weight-bar-row">'
              + '<div class="weight-bar-label">' + escHtml(labels[i]) + ': ' + pct + '%</div>'
              + '<div class="mcmc-progress-wrap"><div class="mcmc-progress-bar pb-blue weight-bar-fill" style="width:' + pct + '%"></div></div>'
              + '</div>';
    }
    el.innerHTML = html;
}

/** Render the comparison table (current form values vs learned params) */
function renderCompareTable() {
    var rec = window.mcmcRecommendation || {};
    var learned = rec.learned_params || {};
    var current = {
        W  : inputInt(  'walksInput',   2700),
        B  : inputInt(  'burnInInput',  70),
        K  : inputFloat('laplaceKInput',1.0),
        D  : inputFloat('decayInput',   0.02),
        CL : inputInt(  'chainLenInput',3000)
    };
    var tbody = document.getElementById('compareTableBody');
    if (!tbody) return;
    var rows = [
        { key: 'W',  label: 'Walks',       unit: '' },
        { key: 'B',  label: 'Burn-in',      unit: '' },
        { key: 'K',  label: 'Laplace K',    unit: '' },
        { key: 'D',  label: 'Decay',        unit: '' },
        { key: 'CL', label: 'Chain Length', unit: '' }
    ];
    var html = '';
    for (var i = 0; i < rows.length; i++) {
        var r     = rows[i];
        var cur   = current[r.key];
        var learn = learned[r.key];
        var match = (learn !== undefined && learn !== null);
        var differs = match && (Math.abs(parseFloat(cur) - parseFloat(learn)) > 0.0001);
        html += '<tr>'
              + '<td>' + escHtml(r.label) + '</td>'
              + '<td>' + escHtml(String(cur)) + '</td>'
              + '<td>' + (match ? escHtml(String(learn)) : '<em>--</em>') + '</td>'
              + '<td class="' + (differs ? 'compare-differ' : 'compare-match') + '">'
              + (differs ? 'DIFFERS' : (match ? 'OK' : '--'))
              + '</td></tr>';
    }
    tbody.innerHTML = html;
}

/** Toggle compare panel visibility */
function toggleComparePanel() {
    var el = document.getElementById('mcmcComparePanel');
    if (!el) return;
    /* Use 'block' as the canonical shown state.
       The initial CSS rule sets display:none so el.style.display starts as ''.
       Checking !== 'block' correctly handles both the CSS-hidden initial state
       and any subsequent hide (set to 'none'). */
    if (el.style.display !== 'block') {
        el.style.display = 'block';
        renderCompareTable();
    } else {
        el.style.display = 'none';
    }
}

/** Apply learned defaults from recommendation to form inputs */
function applyLearnedDefaults() {
    var rec    = window.mcmcRecommendation || {};
    var params = rec.learned_params || rec.params || {};
    if (!params || !Object.keys(params).length) {
        alert('No learned defaults available for this regime yet.');
        return;
    }
    var mapping = {
        W  : 'walksInput',
        B  : 'burnInInput',
        K  : 'laplaceKInput',
        D  : 'decayInput',
        CL : 'chainLenInput'
    };
    for (var key in mapping) {
        if (params[key] !== undefined) {
            var el = document.getElementById(mapping[key]);
            if (el) el.value = params[key];
        }
    }
    /* Apply ensemble weights if present */
    var weights = window.mcmcLearningState && window.mcmcLearningState.weights;
    if (weights && weights.main) {
        var el0 = document.getElementById('wLag1Input');
        var el1 = document.getElementById('wCooInput');
        var el2 = document.getElementById('wPopInput');
        if (el0) el0.value = parseFloat(weights.main[0] || MCMC_JS.DEFAULT_W_LAG1).toFixed(2);
        if (el1) el1.value = parseFloat(weights.main[1] || MCMC_JS.DEFAULT_W_COO).toFixed(2);
        if (el2) el2.value = parseFloat(weights.main[2] || MCMC_JS.DEFAULT_W_POP).toFixed(2);
    }
    auditLog('param_update', { action: 'apply_learned_defaults', params: params });
    alert('Learned defaults applied to settings.');
}

/** Revert all inputs to safe hard-coded defaults */
function applyHardDefaults() {
    var defaults = { walksInput: 2700, burnInInput: 70, laplaceKInput: 1.0, decayInput: 0.02, chainLenInput: 3000 };
    for (var id in defaults) {
        var el = document.getElementById(id);
        if (el) el.value = defaults[id];
    }
    var e0 = document.getElementById('wLag1Input');
    var e1 = document.getElementById('wCooInput');
    var e2 = document.getElementById('wPopInput');
    if (e0) e0.value = MCMC_JS.DEFAULT_W_LAG1;
    if (e1) e1.value = MCMC_JS.DEFAULT_W_COO;
    if (e2) e2.value = MCMC_JS.DEFAULT_W_POP;
    auditLog('param_update', { action: 'reverted_to_hard_defaults' });
}

/* ============================================================
   JS-13: BACKTEST ENGINE (UPGRADED)
   Walk-forward backtest with enhanced metrics:
   - Log-loss, Brier score, hit rate, stability
   - Component-specific performance for weight learning
   - Recent-10% and recent-25% performance
   - Progress bar with cancel support
   - Calibration map building from results
   ============================================================ */

/**
 * Evaluate predictions against one draw.
 * Returns {topNHit, mainScore, componentScores, prob}
 * @param  {Array}  probs   Probability vector (length maxN)
 * @param  {Array}  actual  Drawn numbers (1-indexed)
 * @param  {number} topN    How many to pick
 * @param  {number} pick    Official pick size
 * @param  {number} maxN    Max ball number
 * @return {Object} Evaluation result
 */
function evaluatePrediction(probs, actual, topN, pick, maxN) {
    /* Build sorted indices (descending prob) */
    var idx = [];
    for (var i = 0; i < maxN; i++) { idx.push(i); }
    idx.sort(function (a, b) { return probs[b] - probs[a]; });

    var topPick = idx.slice(0, topN).map(function (i) { return i + 1; });
    var truthSet = buildSet(actual.slice(0, pick));
    var hits = 0;
    for (var t = 0; t < topPick.length; t++) {
        if (truthSet[topPick[t]]) hits++;
    }
    /* Main score = fraction of actual balls that appear in top-N */
    var mainScore = hits / pick;

    /* Probability assigned to actual balls (for log-loss) */
    var probActual = 0;
    for (var a = 0; a < pick && a < actual.length; a++) {
        var ballNum = actual[a];
        if (ballNum >= 1 && ballNum <= maxN) {
            probActual += probs[ballNum - 1];
        }
    }

    return {
        hits        : hits,
        topN        : topN,
        mainScore   : mainScore,
        probActual  : probActual,
        topPick     : topPick,
        pick        : pick,
        truthSet    : truthSet
    };
}

/**
 * Run a full walk-forward backtest.
 * Uses setTimeout chunks to avoid freezing the browser.
 *
 * @param {Object} params  Backtest parameters
 * @param {Function} onDone Callback(results)
 */
function runBacktest() {
    var draws       = window.lottoDraws    || [];
    var lottoConfig = window.lottoConfig   || {};
    var pick        = lottoConfig.pickSize || 6;
    var maxN        = (lottoConfig.rangeMain && lottoConfig.rangeMain[1]) || 49;
    var windowMode  = document.getElementById('btWindowMode')  ? document.getElementById('btWindowMode').value  : 'expanding';
    var windowSize  = inputInt('btWindowSize', 300);
    var startIdx    = inputInt('btStartIdx',   200);
    var stepSize    = inputInt('btStepSize',   1);
    var topN        = inputInt('topNInput',    20);
    var walks       = inputInt('walksInput',   2700);
    var burnIn      = inputInt('burnInInput',  70);
    var K           = inputFloat('laplaceKInput', 1.0);
    var D           = inputFloat('decayInput',    0.02);
    var CL          = inputInt('chainLenInput', 3000);
    var weights     = getCurrentEnsembleWeights();
    var useReranker = document.getElementById('useRerankerInput') && document.getElementById('useRerankerInput').value === '1';

    if (!draws.length) { setInner('backtestResultsV2', '<div class="mcmc-alert alert-error">No draw data loaded.</div>'); return; }

    startIdx  = clamp(startIdx,  20, draws.length - 2);
    topN      = clamp(topN,      1,  maxN);
    stepSize  = clamp(stepSize,  1,  20);

    MCMC_BT_CANCEL = false;
    showEl('btProgress');
    showEl('btCancelBtn');
    setInner('backtestResultsV2', '');

    var params       = { K: K, D: D, weights: weights };
    var caseIdx      = startIdx;
    var results      = [];
    var predVecs     = [];  // For calibration computation
    var actualVecs   = [];

    /* Per-component standalone tracking for weight learning */
    var scoresByComponent = [[], [], []];  // [lag1, coo, pop]

    function processNextBatch() {
        if (MCMC_BT_CANCEL) {
            hideEl('btProgress');
            hideEl('btCancelBtn');
            setInner('backtestResultsV2', '<div class="mcmc-alert alert-warning">Backtest cancelled.</div>');
            auditLog('backtest_run', { status: 'cancelled', cases: results.length });
            return;
        }

        var batchEnd = Math.min(caseIdx + 20, draws.length - 1);

        for (; caseIdx < batchEnd; caseIdx += stepSize) {
            var histEnd   = (windowMode === 'sliding') ? Math.max(10, caseIdx - windowSize) : 0;
            var drawSlice = draws.slice(histEnd, caseIdx);
            if (drawSlice.length < 10) continue;

            var actual = draws[caseIdx];

            /* Build ensemble prediction */
            var blended = runEnsemblePrediction(drawSlice, pick, maxN, params, null);

            /* Optionally rerank */
            var finalProbs = blended;
            if (useReranker && drawSlice.length >= 20) {
                var feats   = extractAllFeatures(drawSlice, pick, maxN);
                finalProbs  = rerankByFeatures(blended, feats, maxN, null, 0.3);
            }

            /* Evaluate */
            var evalResult = evaluatePrediction(finalProbs, actual, topN, pick, maxN);

            /* Store prediction vector + actual for calibration */
            predVecs.push(finalProbs);
            actualVecs.push(actual);

            /* Per-component scores for weight learning */
            var probsA  = buildLag1TransitionProbs(drawSlice, pick, maxN, D, K);
            var probsB  = buildCoOccurrenceProbs(drawSlice,  pick, maxN, D, K);
            var probsC  = buildDecayedPopularityProbs(drawSlice, pick, maxN, D, K);
            var evalA   = evaluatePrediction(probsA, actual, topN, pick, maxN);
            var evalB   = evaluatePrediction(probsB, actual, topN, pick, maxN);
            var evalC   = evaluatePrediction(probsC, actual, topN, pick, maxN);
            scoresByComponent[0].push(evalA.mainScore);
            scoresByComponent[1].push(evalB.mainScore);
            scoresByComponent[2].push(evalC.mainScore);

            results.push({
                idx       : caseIdx,
                mainScore : evalResult.mainScore,
                hits      : evalResult.hits,
                topPick   : evalResult.topPick,
                actual    : actual,
                prob      : evalResult.probActual
            });
        }

        /* Update progress bar */
        var progress = Math.round(((caseIdx - startIdx) / (draws.length - 1 - startIdx)) * 100);
        var barEl = document.getElementById('btProgressBar');
        if (barEl) barEl.style.width = Math.min(100, progress) + '%';
        var txtEl = document.getElementById('btProgressText');
        if (txtEl) txtEl.textContent = 'Case ' + caseIdx + ' / ' + (draws.length - 1) + ' (' + Math.min(100, progress) + '%)';

        if (caseIdx < draws.length - 1) {
            setTimeout(processNextBatch, 0);
        } else {
            /* Backtest complete */
            hideEl('btProgress');
            hideEl('btCancelBtn');
            renderBacktestResults(results, predVecs, actualVecs, pick, maxN, topN, scoresByComponent, weights);
            auditLog('backtest_run', { status: 'completed', cases: results.length, windowMode: windowMode });
        }
    }

    setTimeout(processNextBatch, 10);
}

/** Cancel the running backtest */
function cancelBacktest() {
    MCMC_BT_CANCEL = true;
}

/**
 * Render backtest results to #backtestResultsV2.
 * Computes log-loss, Brier score, hit rate, stability, recent-10%, recent-25%.
 */
function renderBacktestResults(results, predVecs, actualVecs, pick, maxN, topN, scoresByComponent, priorWeights) {
    if (!results.length) {
        setInner('backtestResultsV2', '<div class="mcmc-alert alert-warning">No backtest cases generated.</div>');
        return;
    }

    var n = results.length;

    /* Overall scores */
    var scores     = results.map(function (r) { return r.mainScore; });
    var avgScore   = sumArr(scores) / n;
    var hitCount   = 0;
    for (var i = 0; i < results.length; i++) { if (results[i].hits > 0) hitCount++; }
    var hitRate    = hitCount / n;

    /* Recent 25% and 10% */
    var n25        = Math.max(1, Math.floor(n * 0.25));
    var n10        = Math.max(1, Math.floor(n * 0.10));
    var recent25   = results.slice(-n25).map(function (r) { return r.mainScore; });
    var recent10   = results.slice(-n10).map(function (r) { return r.mainScore; });
    var score25    = sumArr(recent25) / recent25.length;
    var score10    = sumArr(recent10) / recent10.length;

    /* Log-loss and Brier score */
    var logLoss    = computeLogLoss(predVecs, actualVecs, pick, maxN);
    var brier      = computeBrierScore(predVecs, actualVecs, pick, maxN);

    /* Stability */
    var stability  = computeStabilityScore(scores);

    /* Calibration */
    var buckets    = computeReliabilityBuckets(predVecs, actualVecs, pick, maxN, 10);
    var ece        = computeECE(buckets, predVecs.length * maxN);
    var calibScore = Math.max(0, 1 - ece);

    /* Complexity penalty */
    var walks   = inputInt('walksInput',   2700);
    var chainLen= inputInt('chainLenInput',3000);
    var cplx    = computeComplexityPenalty(walks, chainLen);

    /* Final composite score */
    var finalScore = computeFinalScore(avgScore, calibScore, stability, cplx);

    /* Trend: is performance improving or worsening? */
    var trendIcon = '';
    if (score10 > score25 * 1.05)      trendIcon = '<span class="trend-up">  improving</span>';
    else if (score10 < score25 * 0.95) trendIcon = '<span class="trend-down">  weakening</span>';
    else                               trendIcon = '<span class="trend-flat">  flat</span>';

    /* Build summary HTML */
    var html = '<div class="bt-summary-box">'
             + '<div style="font-size:14px;font-weight:700;color:#EFEFF5;margin-bottom:10px;">'
             + 'Backtest Summary &mdash; ' + n + ' cases' + trendIcon
             + '</div>'
             + '<div class="bt-summary-grid">'
             + '<div class="bt-metric-item"><div class="bt-metric-num">' + fmt(avgScore, 4)  + '</div><div class="bt-metric-label">Avg Score</div></div>'
             + '<div class="bt-metric-item"><div class="bt-metric-num">' + fmt(score25, 4)   + '</div><div class="bt-metric-label">Recent 25%</div></div>'
             + '<div class="bt-metric-item"><div class="bt-metric-num">' + fmt(score10, 4)   + '</div><div class="bt-metric-label">Recent 10%</div></div>'
             + '<div class="bt-metric-item"><div class="bt-metric-num">' + fmt(hitRate, 4)   + '</div><div class="bt-metric-label">Hit Rate</div></div>'
             + '<div class="bt-metric-item"><div class="bt-metric-num">' + fmt(logLoss, 4)   + '</div><div class="bt-metric-label">Log-Loss</div></div>'
             + '<div class="bt-metric-item"><div class="bt-metric-num">' + fmt(brier, 4)     + '</div><div class="bt-metric-label">Brier Score</div></div>'
             + '<div class="bt-metric-item"><div class="bt-metric-num">' + fmt(stability, 4) + '</div><div class="bt-metric-label">Stability</div></div>'
             + '<div class="bt-metric-item"><div class="bt-metric-num">' + fmt(calibScore, 4)+ '</div><div class="bt-metric-label">Calibration</div></div>'
             + '<div class="bt-metric-item"><div class="bt-metric-num">' + fmt(finalScore, 4)+ '</div><div class="bt-metric-label">Final Score</div></div>'
             + '</div></div>';

    /* Adaptive weight update suggestion */
    if (scoresByComponent[0].length >= 10) {
        var avgA = sumArr(scoresByComponent[0]) / scoresByComponent[0].length;
        var avgB = sumArr(scoresByComponent[1]) / scoresByComponent[1].length;
        var avgC = sumArr(scoresByComponent[2]) / scoresByComponent[2].length;
        var newWeights = updateEnsembleWeights(priorWeights, [avgA, avgB, avgC], MCMC_JS.LEARNING_RATE, MCMC_JS.WEIGHT_MOMENTUM);
        html += '<div class="mcmc-alert alert-info" style="margin-top:8px;">'
              + '<strong>Adaptive Weight Update:</strong> Based on ' + n + ' cases, suggested weights: '
              + 'Lag-1=' + fmt(newWeights[0], 3) + ', Co-occ=' + fmt(newWeights[1], 3) + ', Pop=' + fmt(newWeights[2], 3)
              + ' &mdash; <a href="#" onclick="applyAdaptiveWeights(' + JSON.stringify(newWeights) + ');return false;">Apply</a>'
              + '</div>';
        auditLog('weight_update', { action: 'adaptive_suggestion', weights: newWeights, cases: n });

        /* C-1: Persist learning state to server */
        var confSignalsBt = {
            calibration       : calibScore,
            stability         : stability,
            sampleSize        : n,
            drift             : (window.lottoDraws && window.lottoDraws.length >= 50)
                                    ? computeFreqDrift(window.lottoDraws, maxN, pick, 50, 200) : 0,
            entropyUniformity : computeNormEntropy(window.lottoDraws || [], maxN, pick, 0)
        };
        var confResultBt = computeConfidenceScore(confSignalsBt);
        var bestParamsBt = {
            W  : inputInt(  'walksInput',    2700),
            B  : inputInt(  'burnInInput',   70),
            K  : inputFloat('laplaceKInput', 1.0),
            D  : inputFloat('decayInput',    0.02),
            CL : inputInt(  'chainLenInput', 3000)
        };
        var calibMapBt   = buildCalibMapFromBuckets(buckets);
        var payloadBt    = buildPersistPayload('backtest', {
            logLoss    : logLoss,
            brier      : brier,
            hitRate    : hitRate,
            stability  : stability,
            calibScore : calibScore,
            confidence : confResultBt.score
        }, newWeights, bestParamsBt, n, calibMapBt);
        persistLearningState('backtest', payloadBt);
    }

    /* Results table (last 20 cases) */
    html += '<div style="margin-top:10px;font-size:13px;font-weight:600;color:#7F8DAA;">Last ' + Math.min(20, n) + ' cases:</div>';
    html += '<table class="mcmc-table"><thead><tr><th>Case#</th><th>Score</th><th>Hits</th><th>Actual</th><th>Top-3 Picks</th></tr></thead><tbody>';
    var showFrom = Math.max(0, n - 20);
    for (var r = showFrom; r < n; r++) {
        var row  = results[r];
        var sc   = row.mainScore;
        var scCls = (sc >= 0.3) ? 'score-high' : ((sc >= 0.1) ? 'score-medium' : 'score-low');
        html += '<tr>'
              + '<td>' + escHtml(String(row.idx)) + '</td>'
              + '<td><span class="score-pill ' + scCls + '">' + fmt(sc, 3) + '</span></td>'
              + '<td>' + escHtml(String(row.hits)) + '/' + pick + '</td>'
              + '<td>' + escHtml((row.actual || []).slice(0, 5).join(', ')) + '</td>'
              + '<td>' + escHtml((row.topPick || []).slice(0, 3).join(', ')) + '</td>'
              + '</tr>';
    }
    html += '</tbody></table>';

    setInner('backtestResultsV2', html);
}

/** Apply adaptively suggested weights to form inputs */
function applyAdaptiveWeights(weights) {
    if (!weights || weights.length < 3) return;
    var e0 = document.getElementById('wLag1Input');
    var e1 = document.getElementById('wCooInput');
    var e2 = document.getElementById('wPopInput');
    if (e0) e0.value = parseFloat(weights[0]).toFixed(3);
    if (e1) e1.value = parseFloat(weights[1]).toFixed(3);
    if (e2) e2.value = parseFloat(weights[2]).toFixed(3);
    auditLog('weight_update', { action: 'adaptive_applied', weights: weights });
}

/* ============================================================
   JS-14: OPTIMIZER (UPGRADED — stability-aware, final score)
   Grid search over param space.
   Two phases: preview (coarse) then refine (neighbors of best).
   Scores by final composite score, not raw predictive score alone.
   ============================================================ */

function runOptimizer() {
    var draws       = window.lottoDraws  || [];
    var lottoConfig = window.lottoConfig || {};
    var pick        = lottoConfig.pickSize || 6;
    var maxN        = (lottoConfig.rangeMain && lottoConfig.rangeMain[1]) || 49;

    if (!draws.length) { setInner('optimizerResults', '<div class="mcmc-alert alert-error">No draw data loaded.</div>'); return; }

    /* Parse grid from inputs */
    function parseCSV(id, fallback) {
        var el = document.getElementById(id);
        if (!el || !el.value.trim()) return fallback;
        return el.value.split(',').map(function (s) { return parseFloat(s.trim()); }).filter(function (v) { return !isNaN(v); });
    }

    var walksGrid   = parseCSV('optWalksVals', [1500, 2700, 5000]);
    var kGrid       = parseCSV('optKVals',     [0.5, 1.0, 2.0, 4.0]);
    var decayGrid   = parseCSV('optDecayVals', [0.005, 0.02, 0.05, 0.10]);
    var btStart     = inputInt('optBtStart',   200);
    var btCases     = inputInt('optBtCases',   50);
    var topN        = inputInt('topNInput',    20);
    var weights     = getCurrentEnsembleWeights();
    /* Read chainLen once here to avoid repeated DOM queries inside processNextCandidate */
    var optChainLen = inputInt('chainLenInput', 3000);

    btStart  = clamp(btStart, 20, draws.length - btCases - 1);
    btCases  = clamp(btCases, 5, 300);

    /* Build candidate list */
    var candidates = [];
    for (var wi = 0; wi < walksGrid.length; wi++) {
        for (var ki = 0; ki < kGrid.length; ki++) {
            for (var di = 0; di < decayGrid.length; di++) {
                candidates.push({
                    W : walksGrid[wi],
                    K : kGrid[ki],
                    D : decayGrid[di]
                });
            }
        }
    }

    MCMC_OPT_CANCEL = false;
    showEl('optProgress');
    showEl('optCancelBtn');
    setInner('optimizerResults', '');
    setInner('optimizerLeaderboard', '');

    var totalCandidates = candidates.length;
    var candidateIdx    = 0;
    var leaderboard     = [];

    function processNextCandidate() {
        if (MCMC_OPT_CANCEL) {
            hideEl('optProgress');
            hideEl('optCancelBtn');
            renderOptimizerResults(leaderboard, 'cancelled');
            auditLog('optimizer_run', { status: 'cancelled', evaluated: leaderboard.length });
            return;
        }
        if (candidateIdx >= totalCandidates) {
            hideEl('optProgress');
            hideEl('optCancelBtn');
            renderOptimizerResults(leaderboard, 'completed');
            auditLog('optimizer_run', { status: 'completed', evaluated: leaderboard.length, best: leaderboard[0] });
            return;
        }

        var cand   = candidates[candidateIdx];
        var params = { K: cand.K, D: cand.D, weights: weights };

        /* Run mini-backtest over btCases consecutive draws */
        var testScores = [], testPreds = [], testActuals = [];
        var compScores = [[], [], []];

        for (var ci = 0; ci < btCases; ci++) {
            var testIdx = btStart + ci;
            if (testIdx >= draws.length - 1) break;
            var slice   = draws.slice(0, testIdx);
            if (slice.length < 10) continue;
            var actual  = draws[testIdx];
            var blended = runEnsemblePrediction(slice, pick, maxN, params, null);
            testPreds.push(blended);
            testActuals.push(actual);
            var evalR = evaluatePrediction(blended, actual, topN, pick, maxN);
            testScores.push(evalR.mainScore);

            /* Component scores */
            var pA = buildLag1TransitionProbs(slice, pick, maxN, cand.D, cand.K);
            var pB = buildCoOccurrenceProbs(slice,   pick, maxN, cand.D, cand.K);
            var pC = buildDecayedPopularityProbs(slice, pick, maxN, cand.D, cand.K);
            compScores[0].push(evaluatePrediction(pA, actual, topN, pick, maxN).mainScore);
            compScores[1].push(evaluatePrediction(pB, actual, topN, pick, maxN).mainScore);
            compScores[2].push(evaluatePrediction(pC, actual, topN, pick, maxN).mainScore);
        }

        if (!testScores.length) { candidateIdx++; setTimeout(processNextCandidate, 0); return; }

        /* Compute metrics */
        var avgScore   = sumArr(testScores) / testScores.length;
        var logLoss    = computeLogLoss(testPreds, testActuals, pick, maxN);
        var brier      = computeBrierScore(testPreds, testActuals, pick, maxN);
        var stability  = computeStabilityScore(testScores);
        var buckets    = computeReliabilityBuckets(testPreds, testActuals, pick, maxN, 10);
        var ece        = computeECE(buckets, testPreds.length * maxN);
        var calibScore = Math.max(0, 1 - ece);
        var cplx       = computeComplexityPenalty(cand.W, optChainLen);
        var finalScore = computeFinalScore(avgScore, calibScore, stability, cplx);

        leaderboard.push({
            W          : cand.W,
            K          : cand.K,
            D          : cand.D,
            avgScore   : avgScore,
            logLoss    : logLoss,
            brier      : brier,
            stability  : stability,
            calibScore : calibScore,
            cplx       : cplx,
            finalScore : finalScore,
            cases      : testScores.length
        });

        /* Update progress */
        var pct = Math.round((candidateIdx / totalCandidates) * 100);
        var barEl = document.getElementById('optProgressBar');
        if (barEl) barEl.style.width = pct + '%';
        var txtEl = document.getElementById('optProgressText');
        if (txtEl) txtEl.textContent = 'Candidate ' + (candidateIdx + 1) + '/' + totalCandidates + ' (W=' + cand.W + ', K=' + cand.K + ', D=' + cand.D + ')';

        candidateIdx++;
        setTimeout(processNextCandidate, 0);
    }

    setTimeout(processNextCandidate, 10);
}

/** Cancel running optimizer */
function cancelOptimizer() {
    MCMC_OPT_CANCEL = true;
}

/** Render optimizer results with leaderboard */
function renderOptimizerResults(leaderboard, status) {
    if (!leaderboard.length) {
        setInner('optimizerResults', '<div class="mcmc-alert alert-warning">No candidates evaluated.</div>');
        return;
    }

    /* Sort by finalScore descending */
    leaderboard.sort(function (a, b) { return b.finalScore - a.finalScore; });

    /* Find best by stability separately */
    var bestByStab = leaderboard.slice().sort(function (a, b) { return b.stability - a.stability; })[0];
    var bestByScore = leaderboard[0];

    var html = '<div class="mcmc-alert alert-' + (status === 'completed' ? 'success' : 'warning') + '">'
             + 'Optimizer ' + escHtml(status) + '. ' + leaderboard.length + ' candidates evaluated.'
             + '</div>';

    /* Best by score card */
    html += '<div class="mcmc-card lb-best-score" style="margin-bottom:8px;">'
          + '<strong>Best by Final Score:</strong> W=' + escHtml(String(bestByScore.W))
          + ', K=' + escHtml(String(bestByScore.K))
          + ', D=' + escHtml(String(bestByScore.D))
          + ' &mdash; Final=' + fmt(bestByScore.finalScore, 4)
          + ', Pred=' + fmt(bestByScore.avgScore, 4)
          + ', Cal=' + fmt(bestByScore.calibScore, 4)
          + ', Stab=' + fmt(bestByScore.stability, 4)
          + '<br><button class="mcmc-btn mcmc-btn-success mcmc-btn-sm" style="margin-top:6px;" onclick="applyOptimizerResult('
          + JSON.stringify({W: bestByScore.W, K: bestByScore.K, D: bestByScore.D})
          + ')">Apply These Params</button>'
          + '</div>';

    /* Best by stability card (only if different from best by score) */
    if (bestByStab.W !== bestByScore.W || bestByStab.K !== bestByScore.K || bestByStab.D !== bestByScore.D) {
        html += '<div class="mcmc-card lb-best-stab" style="margin-bottom:8px;">'
              + '<strong>Best by Stability:</strong> W=' + escHtml(String(bestByStab.W))
              + ', K=' + escHtml(String(bestByStab.K))
              + ', D=' + escHtml(String(bestByStab.D))
              + ' &mdash; Stab=' + fmt(bestByStab.stability, 4)
              + ', Final=' + fmt(bestByStab.finalScore, 4)
              + '<br><button class="mcmc-btn mcmc-btn-primary mcmc-btn-sm" style="margin-top:6px;" onclick="applyOptimizerResult('
              + JSON.stringify({W: bestByStab.W, K: bestByStab.K, D: bestByStab.D})
              + ')">Apply Stable Params</button>'
              + '</div>';
    }

    /* Top-20 leaderboard table */
    setInner('optimizerResults', html);

    var lbHtml = '<table class="mcmc-table">'
               + '<thead><tr><th>#</th><th>W</th><th>K</th><th>D</th><th>Final</th><th>Pred</th><th>Cal</th><th>Stab</th><th>LogLoss</th><th>Brier</th></tr></thead>'
               + '<tbody>';
    var showCount = Math.min(20, leaderboard.length);
    for (var i = 0; i < showCount; i++) {
        var e  = leaderboard[i];
        var cls = (i === 0) ? ' style="background:rgba(32,201,151,0.10)"' : ''; /* SKAI Success tint */
        lbHtml += '<tr' + cls + '>'
                + '<td>' + (i + 1) + '</td>'
                + '<td>' + escHtml(String(e.W)) + '</td>'
                + '<td>' + escHtml(String(e.K)) + '</td>'
                + '<td>' + escHtml(String(e.D)) + '</td>'
                + '<td>' + scorePill(e.finalScore) + '</td>'
                + '<td>' + fmt(e.avgScore,   4) + '</td>'
                + '<td>' + fmt(e.calibScore, 4) + '</td>'
                + '<td>' + fmt(e.stability,  4) + '</td>'
                + '<td>' + fmt(e.logLoss,    4) + '</td>'
                + '<td>' + fmt(e.brier,      4) + '</td>'
                + '</tr>';
    }
    lbHtml += '</tbody></table>';
    setInner('optimizerLeaderboard', lbHtml);

    /* C-1: Persist optimizer best result to server learning state DB */
    if (status === 'completed' && leaderboard.length > 0) {
        var bestOpt   = leaderboard[0];
        var drawsOpt  = window.lottoDraws   || [];
        var cfgOpt    = window.lottoConfig  || {};
        var pickOpt   = cfgOpt.pickSize || 6;
        var maxNOpt   = (cfgOpt.rangeMain && cfgOpt.rangeMain[1]) || 49;
        var confSigOpt = {
            calibration       : bestOpt.calibScore,
            stability         : bestOpt.stability,
            sampleSize        : bestOpt.cases,
            drift             : (drawsOpt.length >= 50) ? computeFreqDrift(drawsOpt, maxNOpt, pickOpt, 50, 200) : 0,
            entropyUniformity : computeNormEntropy(drawsOpt, maxNOpt, pickOpt, 0)
        };
        var confResOpt    = computeConfidenceScore(confSigOpt);
        var bestPOpt      = {
            W  : bestOpt.W,
            K  : bestOpt.K,
            D  : bestOpt.D,
            B  : inputInt(  'burnInInput',   70),
            CL : inputInt(  'chainLenInput', 3000)
        };
        var payOpt = buildPersistPayload('optimizer', {
            logLoss    : bestOpt.logLoss,
            brier      : bestOpt.brier,
            hitRate    : null,
            stability  : bestOpt.stability,
            calibScore : bestOpt.calibScore,
            confidence : confResOpt.score
        }, getCurrentEnsembleWeights(), bestPOpt, bestOpt.cases, []);
        persistLearningState('optimizer', payOpt);
    }
}

/** Apply optimizer result params to form inputs */
function applyOptimizerResult(params) {
    if (!params) return;
    if (params.W !== undefined) { var e = document.getElementById('walksInput');    if (e) e.value = params.W; }
    if (params.K !== undefined) { var f = document.getElementById('laplaceKInput'); if (f) f.value = params.K; }
    if (params.D !== undefined) { var g = document.getElementById('decayInput');    if (g) g.value = params.D; }
    auditLog('param_update', { action: 'applied_optimizer_result', params: params });
}

/* ============================================================
   JS-15: PREDICTION RUN (EXISTING — refactored to ES5, uses new engine)
   ============================================================ */

function runPrediction() {
    var draws       = window.lottoDraws  || [];
    var lottoConfig = window.lottoConfig || {};
    var pick        = lottoConfig.pickSize  || 6;
    var maxN        = (lottoConfig.rangeMain && lottoConfig.rangeMain[1]) || 49;
    var hasExtra    = lottoConfig.hasExtra  || false;
    var extraCount  = lottoConfig.extraCount || 0;
    var maxExtra    = (lottoConfig.rangeExtra && lottoConfig.rangeExtra[1]) || 0;

    if (!draws.length) {
        setInner('predictionStatus', '<div class="mcmc-alert alert-error">No draw data loaded.</div>');
        return;
    }

    var K        = inputFloat('laplaceKInput', 1.0);
    var D        = inputFloat('decayInput',    0.02);
    var topN     = inputInt(  'topNInput',     20);
    var weights  = getCurrentEnsembleWeights();
    var useReranker = document.getElementById('useRerankerInput') && document.getElementById('useRerankerInput').value === '1';

    setInner('predictionStatus', '<div class="mcmc-alert alert-info"><span class="mcmc-spinner"></span>Running prediction...</div>');

    /* Run in next tick to allow status to render */
    setTimeout(function () {
        try {
            var params  = { K: K, D: D, weights: weights };
            var calibMap = null;

            /* Try to get calibration map from learning state */
            var ls = window.mcmcLearningState || {};
            if (ls.weights && ls.rollingMetrics) {
                /* Build calibration map if available from stored state */
                calibMap = null; // Will be populated from stored buckets if available
            }

            var blended = runEnsemblePrediction(draws, pick, maxN, params, calibMap);

            /* Optionally rerank */
            if (useReranker && draws.length >= 20) {
                var feats   = extractAllFeatures(draws, pick, maxN);
                blended     = rerankByFeatures(blended, feats, maxN, null, 0.3);
            }

            /* Get top-N indices */
            var idx = [];
            for (var i = 0; i < maxN; i++) { idx.push(i); }
            idx.sort(function (a, b) { return blended[b] - blended[a]; });
            var topPicks = idx.slice(0, topN).map(function (i) { return i + 1; });

            /* Extra ball prediction (if game has extra) */
            var extraPicks = [];
            if (hasExtra && extraCount > 0 && maxExtra > 0) {
                var exDraws = draws;
                var exProbs = buildDecayedPopularityProbs(exDraws, extraCount, maxExtra, D, K);
                var exIdx   = [];
                for (var ei = 0; ei < maxExtra; ei++) { exIdx.push(ei); }
                exIdx.sort(function (a, b) { return exProbs[b] - exProbs[a]; });
                extraPicks = exIdx.slice(0, extraCount).map(function (i) { return i + 1; });
            }

            /* Entropy and sharpness analysis */
            var entropy = computeNormEntropy(draws, maxN, pick, 0);
            var maxProb = blended[idx[0]];
            var minTopProb = blended[idx[topN - 1]];
            var sharpness = (maxProb > 0) ? (maxProb - 1/maxN) / (maxProb) : 0;

            /* Render result */
            var html = '<div class="mcmc-card" style="margin-top:10px;">'
                     + '<h3>Prediction Result</h3>';

            /* Confidence badge based on entropy */
            var confSignals = {
                calibration: (ls.rollingMetrics && ls.rollingMetrics.calibration) || 0.5,
                stability:   (ls.stability || 0),
                sampleSize:  (ls.sampleSize || 0),
                drift:       0,
                entropyUniformity: entropy
            };
            var conf = computeConfidenceScore(confSignals);
            html += '<div style="margin-bottom:10px;">'
                  + buildBadge('Confidence: ' + conf.label + ' (' + fmt(conf.score, 2) + ')', 'mcmc-badge badge-' + conf.label)
                  + '&nbsp;'
                  + buildBadge(sharpness > 0.3 ? 'Sharp distribution' : 'Flat distribution', 'mcmc-badge badge-' + (sharpness > 0.3 ? 'stable' : 'medium'))
                  + '<div style="font-size:11px;color:#7F8DAA;margin-top:4px;">' + escHtml(conf.explanation) + '</div>'
                  + '</div>';

            /* Main balls display */
            html += '<div style="font-size:13px;color:#7F8DAA;margin-bottom:6px;">Main Balls (top-' + topN + '):</div>';
            html += '<div class="numbers-display">';
            for (var t = 0; t < topPicks.length; t++) {
                var prob = blended[topPicks[t] - 1];
                var probStr = (prob * 100).toFixed(2) + '%';
                html += '<div class="number-ball ball-main ball-predicted" title="p=' + probStr + '">' + escHtml(String(topPicks[t])) + '</div>';
            }
            html += '</div>';

            /* Extra balls display */
            if (extraPicks.length) {
                html += '<div style="font-size:13px;color:#7F8DAA;margin:10px 0 6px;">Extra Balls:</div>';
                html += '<div class="numbers-display">';
                for (var ep = 0; ep < extraPicks.length; ep++) {
                    html += '<div class="number-ball ball-extra ball-predicted">' + escHtml(String(extraPicks[ep])) + '</div>';
                }
                html += '</div>';
            }

            /* Analysis summary */
            html += '<div style="margin-top:10px;font-size:12px;color:#7F8DAA;">'
                  + 'Regime: ' + buildBadge(buildRegimeKey(draws, lottoConfig), 'mcmc-badge badge-regime')
                  + ' | Entropy: ' + fmt(entropy, 3)
                  + ' | Sharpness: ' + fmt(sharpness, 3)
                  + '</div>';

            html += '</div>';

            setInner('predictionStatus', '');
            setInner('predictionResult', html);
            auditLog('prediction_run', { action: 'prediction_run', topN: topN, picks: topPicks.slice(0, 5) });

        } catch (err) {
            setInner('predictionStatus', '<div class="mcmc-alert alert-error">Error: ' + escHtml(String(err.message || err)) + '</div>');
        }
    }, 20);
}

/* ============================================================
   JS-16: DIAGNOSTICS AND CHARTS (EXISTING — preserved)
   Uses Chart.js (loaded via script tag in head).
   ============================================================ */

var _freqChart  = null;
var _transChart = null;

function renderDiagnostics() {
    var draws       = window.lottoDraws  || [];
    var lottoConfig = window.lottoConfig || {};
    var pick        = lottoConfig.pickSize || 6;
    var maxN        = (lottoConfig.rangeMain && lottoConfig.rangeMain[1]) || 49;
    var D           = inputFloat('decayInput', 0.02);
    var K           = inputFloat('laplaceKInput', 1.0);

    if (!draws.length || !maxN) return;

    /* Frequency chart */
    var freqProbs = buildDecayedPopularityProbs(draws, pick, maxN, D, K);
    var labels    = [];
    for (var i = 1; i <= maxN; i++) { labels.push(String(i)); }

    var freqCtx = document.getElementById('freqChart');
    if (freqCtx) {
        if (_freqChart) { _freqChart.destroy(); _freqChart = null; }
        _freqChart = new Chart(freqCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Decayed Frequency',
                    data: freqProbs,
                    backgroundColor: 'rgba(28,102,255,0.55)',
                    borderColor: '#1C66FF',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#7F8DAA' } } },
                scales: {
                    x: { ticks: { color: '#7F8DAA', font: { size: 10 } }, grid: { color: 'rgba(28,102,255,0.12)' } },
                    y: { ticks: { color: '#7F8DAA' }, grid: { color: 'rgba(28,102,255,0.12)' } }
                }
            }
        });
    }

    /* Transition chart: top-10 numbers by lag-1 prob */
    var transProbs = buildLag1TransitionProbs(draws, pick, maxN, D, K);
    var idx        = [];
    for (var j = 0; j < maxN; j++) { idx.push(j); }
    idx.sort(function (a, b) { return transProbs[b] - transProbs[a]; });
    var top10idx   = idx.slice(0, 10);
    var top10labels = top10idx.map(function (i) { return String(i + 1); });
    var top10data   = top10idx.map(function (i) { return transProbs[i]; });

    var transCtx = document.getElementById('transChart');
    if (transCtx) {
        if (_transChart) { _transChart.destroy(); _transChart = null; }
        _transChart = new Chart(transCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: top10labels,
                datasets: [{
                    label: 'Lag-1 Transition Probability (Top 10)',
                    data: top10data,
                    backgroundColor: 'rgba(122,173,255,0.55)',
                    borderColor: '#7AADFF',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#7F8DAA' } } },
                scales: {
                    x: { ticks: { color: '#7F8DAA' }, grid: { color: 'rgba(28,102,255,0.12)' } },
                    y: { ticks: { color: '#7F8DAA' }, grid: { color: 'rgba(28,102,255,0.12)' } }
                }
            }
        });
    }

    /* Diagnostics text */
    var entropy = computeNormEntropy(draws, maxN, pick, 0);
    var drift   = (draws.length >= 50) ? computeFreqDrift(draws, maxN, pick, 50, 200) : 0;
    var regime  = buildRegimeKey(draws, lottoConfig);
    var ls      = window.mcmcLearningState || {};

    var diagHtml = '<div style="font-size:12px;line-height:1.8;color:#7F8DAA;margin-top:8px;">'
                 + '<strong style="color:#EFEFF5;">Diagnostic Summary</strong><br>'
                 + 'Draws loaded: '         + draws.length + '<br>'
                 + 'Max ball number: '      + maxN + '<br>'
                 + 'Pick size: '            + pick + '<br>'
                 + 'Normalized entropy: '   + fmt(entropy, 4) + '<br>'
                 + 'Frequency drift: '      + fmt(drift, 4) + '<br>'
                 + 'Active regime: '        + escHtml(regime) + '<br>'
                 + 'Learning source: '      + escHtml(ls.source || 'default') + '<br>'
                 + 'Sample size: '          + (ls.sampleSize || 0) + '<br>'
                 + '</div>';
    setInner('diagnosticsText', diagHtml);
}

/* ============================================================
   JS-17: UI HELPERS
   ============================================================ */

/** Save prediction form submission */
function savePrediction(mainNums, extraNums, label, generatedAt, walks, burnIn, laplaceK, decay, chainLen) {
    /* This submits a standard POST form to the PHP save handler */
    var form = document.createElement('form');
    form.method = 'post';
    form.action = window.location.href;

    function addHidden(name, val) {
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = name;
        inp.value = String(val);
        form.appendChild(inp);
    }

    /* Joomla CSRF token from server-injected token name with backward-compatible
       hidden input scan fallback. */
    var tokenFound = false;
    var tokenName = getCsrfTokenName();
    if (tokenName) {
        addHidden(tokenName, '1');
        tokenFound = true;
    }
    if (!tokenFound) {
        alert('Cannot save: security token not found. Please reload the page and try again.');
        return;
    }

    addHidden('save_set',       '1');
    /* lottery_id: must be numeric DB lottery_id from #__lotteries.lottery_id.
       No string fallback is allowed because the server expects an integer id. */
    addHidden('lottery_id',         (window.lottoConfig && window.lottoConfig.lotteryNumericId) || 0);
    addHidden('main_numbers',        mainNums);
    addHidden('extra_ball_numbers',  extraNums || '');
    addHidden('source',              'mcmc_v2');
    addHidden('label',               label || '');
    addHidden('generated_at',        generatedAt || new Date().toISOString());
    addHidden('walks',               walks    || 2700);
    addHidden('burn_in',             burnIn   || 70);
    addHidden('laplace_k',           laplaceK || 1.0);
    addHidden('decay',               decay    || 0.02);
    addHidden('chain_len',           chainLen || 3000);
    /* Schema-confirmed columns: draws_analyzed tells the server how many draws
       were loaded at prediction time, stored in #__user_saved_numbers.draws_analyzed */
    addHidden('draws_analyzed',      String((window.lottoDraws || []).length));

    /* M-2: Guard against zero/missing lotteryNumericId before submitting */
    var lottNumId = (window.lottoConfig && window.lottoConfig.lotteryNumericId) || 0;
    if (!lottNumId || lottNumId <= 0) {
        alert('Cannot save: lottery ID not resolved. Contact admin.');
        return;
    }
    document.body.appendChild(form);
    form.submit();
}

/* ============================================================
   JS-18: INIT ON DOMContentLoaded
   ============================================================ */

document.addEventListener('DOMContentLoaded', function () {
    /* Populate lottery name heading and details from window.lottoConfig */
    var cfg         = window.lottoConfig   || {};
    var draws       = window.lottoDraws    || [];
    var lotteryName = String(cfg.lotteryName || 'MCMC Lottery');
    var pickSize    = cfg.pickSize || 0;
    var maxMain     = (cfg.rangeMain && cfg.rangeMain[1]) || 0;
    var hasExtra    = cfg.hasExtra || false;
    var extraCount  = cfg.extraCount || 0;
    var maxExtra    = (cfg.rangeExtra && cfg.rangeExtra[1]) || 0;
    var totalDraws  = draws.length;

    /* Set page title */
    document.title = (lotteryName ? lotteryName : 'MCMC Lottery') + ' - MCMC Prediction';

    /* Fill lottery name heading */
    var hTitle = document.getElementById('lotteryTitle');
    if (hTitle) { hTitle.textContent = lotteryName; }

    /* Fill lottery details */
    var detEl = document.getElementById('lotteryDetails');
    if (detEl) {
        var det = 'Pick ' + pickSize + ' from 1-' + maxMain
                + (hasExtra ? (' | Bonus: ' + extraCount + ' from 1-' + maxExtra) : '')
                + ' | ' + totalDraws + ' historical draws loaded';
        if (!maxMain || totalDraws === 0) {
            det = 'Invalid configuration or no historical draws found.';
        }
        detEl.textContent = det;
    }

    /* Render recommendation panel from PHP-injected data */
    if (window.mcmcRecommendation) {
        renderRecommendPanel();
    }

    /* If learning state has params with high confidence, optionally prefill inputs */
    var ls  = window.mcmcLearningState || {};
    var rec = window.mcmcRecommendation || {};
    if (ls.source === 'db' && rec.confidence >= 0.60 && rec.learned_params) {
        /* Do not auto-apply; just show diff warning and let user decide */
        if (rec.differs_from_curr) {
            auditLog('regime_change', { regime: ls.regimeKey, note: 'Learned params available, differ from defaults' });
        }
    }

    /* Render diagnostics when diagnostics section is opened */
    var diagHeader = document.querySelector('[onclick*="diagnosticsSection"]');
    if (diagHeader) {
        diagHeader.addEventListener('click', function () {
            setTimeout(renderDiagnostics, 50);
        });
    }

    /* Initial audit entry */
    auditLog('regime_change', {
        regime    : (ls.regimeKey || 'unknown'),
        draws     : totalDraws,
        source    : (ls.source || 'default')
    });
});
[[/script]]
[[/body]]
[[/html]]
