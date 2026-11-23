<?php
/**
 * Front controller
 * ================================
 * Single entry point: serves the API (JSON + SSE), the OIDC callback and the SPA.
 *
 * Works with `php -S 0.0.0.0:8501 index.php` (dev mode) and behind
 * Apache in production (see .htaccess).
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use Hermes\Auth;
use Hermes\Oidc;
use Hermes\OidcException;
use Hermes\Database;
use Hermes\Amphores;
use Hermes\Conversations;
use Hermes\Llm;
use Hermes\RequestAbortedException;
use Hermes\RateLimiter;
use Hermes\Upload;
use Hermes\UploadChunks;
use Hermes\Tools;
use Hermes\Config;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** One config per request. */
function getHermesConfig(): Config
{
    static $conf = null;
    if ($conf === null) {
        $conf = new Config();
    }
    return $conf;
}

/** Sends a JSON response and terminates. */
function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Sends a JSON error and terminates. */
function json_err(string $message, int $code = 400): never
{
    json_out(['erreur' => $message], $code);
}

/** Reads the JSON body of the request. */
function readJson(): array
{
    $raw = (string) file_get_contents('php://input');
    $dec = json_decode($raw, true);
    return is_array($dec) ? $dec : [];
}

/**
 * Security headers applied to every response (HTML, JSON, SSE, files).
 * The CSP allows inline styles only because the SPA injects style="…"
 * attributes via innerHTML; no inline scripts are used.
 */
function securityHeaders(): void
{
    header(
        'Content-Security-Policy: '
        . "default-src 'self'; script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; "
        . 'media-src \'self\' blob:; connect-src \'self\'; '
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
    );
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(self), geolocation=()');
}

/** Configured authentication mode (none / userpass). */
function authMode(Config $conf): string
{
    return strtolower($conf->get('auth', 'authentification', 'none'));
}

/** Conversation persistence active only in userpass mode. */
function persistenceActive(Config $conf): bool
{
    return authMode($conf) === 'userpass';
}

/**
 * Current user. In userpass mode, authentication is required
 * (401 otherwise). In none mode, returns null (anonymous).
 */
function userIfRequired(Config $conf): ?array
{
    if (!persistenceActive($conf)) {
        return null;
    }
    $u = Auth::currentUser();
    return $u ?? null;
}

/** Current user + admin (401/403 otherwise). */
function adminUserRequired(Config $conf): array
{
    $u = userIfRequired($conf);
    if ($u === null || !$u['is_admin']) {
        json_err('Réservé aux administrateurs.', 403);
    }
    return $u;
}

/** Default system prompt ([agent] section). */
function defaultSystemPrompt(Config $conf): string
{
    $p = trim($conf->get('agent', 'system_prompt', ''));
    return $p !== '' ? $p : 'Tu es Hermes, un assistant utile. Réponds en français.';
}

/** Splits an URL path into non-empty segments. */
function pathSegments(string $path): array
{
    return array_values(array_filter(explode('/', $path), fn ($p) => $p !== ''));
}

// --- Helpers SSE -----------------------------------------------------------

/** Headers for an SSE stream. */
function sseHeaders(): void
{
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    @ini_set('output_buffering', '0');
    ob_implicit_flush(true);
}

/** Emits an SSE event. */
function sseSend(string $evenement, array $data): void
{
    echo 'event: ' . $evenement . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

// --- Helpers: request stop (stop button) -----------------------------

/** Marker added to the saved text when the generation is stopped. */
const MARQUEUR_ARRET = '> ⏹ *Génération arrêtée.*';

/** Stop files directory (one .lock per in-flight request). */
function stopDir(): string
{
    $dir = __DIR__ . '/data/stop';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/** Stop file path for a request identifier. */
function cheminFichierStop(string $idDemande): string
{
    return stopDir() . '/' . $idDemande . '.lock';
}

/**
 * Stop request detection: file created by POST /api/stop
 * (stop button, handled by another worker) or client connection dropped
 * (closed tab).
 */
function demandeArretee(string $idDemande): bool
{
    if ($idDemande !== '' && is_file(cheminFichierStop($idDemande))) {
        return true;
    }
    return (bool) @connection_aborted();
}

/** Removes orphan stop files (older than $maxAge). */
function nettoyerFichiersStop(int $maxAge = 3600): void
{
    foreach (glob(stopDir() . '/*.lock') ?: [] as $fichier) {
        if (time() - filemtime($fichier) > $maxAge) {
            @unlink($fichier);
        }
    }
}

// ---------------------------------------------------------------------------
// API — Authentification
// ---------------------------------------------------------------------------

function routeLogin(Config $conf, array $in): never
{
    $user = Auth::verifyCredentials((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''));
    if ($user === null) {
        json_err('Identifiants invalides.', 401);
    }
    $token = Auth::createSession($user['id']);
    Auth::setCookie($token);
    json_out(['ok' => true, 'user' => $user]);
}

function routeRegister(Config $conf, array $in): never
{
    if (!$conf->getbool('auth', 'register', false)) {
        json_err('La création de compte est désactivée.', 403);
    }
    [$ok, $msg] = Auth::createAccount((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''));
    if (!$ok) {
        json_err($msg, 400);
    }
    json_out(['ok' => true, 'message' => $msg]);
}

function routeLogout(Config $conf): never
{
    $token = (string) ($_COOKIE[Auth::COOKIE_NAME] ?? '');
    if ($token !== '') {
        Auth::revokeSession($token);
    }
    Auth::removeCookie();
    json_out(['ok' => true]);
}

function routeMe(Config $conf): never
{
    $u = Auth::currentUser();
    if ($u === null) {
        json_out(['authentifie' => false], 401);
    }
    json_out(['authentifie' => true, 'user' => $u]);
}

function routePassword(Config $conf, array $in): never
{
    $u = userIfRequired($conf);
    if ($u === null) {
        json_err('Non authentifié.', 401);
    }
    [$ok, $msg] = Auth::changePassword(
        $u['id'],
        (string) ($in['actuel'] ?? ''),
        (string) ($in['nouveau'] ?? ''),
    );
    if (!$ok) {
        json_err($msg, 400);
    }
    json_out(['ok' => true, 'message' => $msg]);
}

function routeProfile(Config $conf, array $in): never
{
    $u = userIfRequired($conf);
    if ($u === null) {
        json_err('Non authentifié.', 401);
    }
    $sets = [];
    $params = [];
    if (isset($in['theme'])) {
        $theme = $in['theme'] === 'dark' ? 'dark' : 'light';
        $sets[] = 'theme = ?';
        $params[] = $theme;
    }
    if (empty($sets)) {
        json_err('Aucune donnée à mettre à jour.', 400);
    }
    $params[] = $u['id'];
    Database::query('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params, 'exec');
    json_out(['ok' => true]);
}

function routeOidcStart(Config $conf): never
{
    if (!Oidc::isActive($conf)) {
        json_err('OIDC désactivé.', 400);
    }
    $url = Oidc::buildAuthorizationUrl($conf);
    if ($url === null) {
        json_err("Impossible de construire l'URL d'autorisation OIDC.", 500);
    }
    header('Location: ' . $url);
    exit;
}

/** OIDC callback (outside /api): exchanges the code, creates the session, redirects. */
function routeOidcCallback(Config $conf): never
{
    $code = isset($_GET['code']) ? (string) $_GET['code'] : null;
    $state = isset($_GET['state']) ? (string) $_GET['state'] : null;
    try {
        $claims = Oidc::processCallback($conf, $code, $state);
        $sub = (string) ($claims['sub'] ?? '');
        $pref = (string) ($claims['preferred_username'] ?? ($claims['email'] ?? ''));
        $user = Auth::resolveOrCreateOidcUser($sub, $pref);
        $token = Auth::createSession($user['id']);
        Auth::setCookie($token);
        header('Location: /');
    } catch (OidcException $e) {
        header('Location: /?erreur=' . rawurlencode($e->getMessage()));
    }
    exit;
}

// ---------------------------------------------------------------------------
// API — Config
// ---------------------------------------------------------------------------

function routeConfig(Config $conf, ?array $user): never
{
    $authMode = authMode($conf);
    $theme = 'light';
    if ($user !== null) {
        try {
            $row = Database::query(
                'SELECT theme FROM users WHERE id = ?',
                [$user['id']],
                'fetch'
            );
            if ($row && in_array($row['theme'], ['light', 'dark'], true)) {
                $theme = $row['theme'];
            }
        } catch (\Throwable) {
            // ignore
        }
    }
    json_out([
        'page_title' => $conf->get('web', 'page_title', 'Hermes'),
        'page_icon' => $conf->get('web', 'page_icon', '📜'),
        'header' => $conf->get('web', 'header', 'Hermes'),
        'auth_mode' => $authMode,
        'register_enabled' => $conf->getbool('auth', 'register', false),
        'oidc_enabled' => $authMode === 'userpass' && Oidc::isActive($conf),
        'tts_enabled' => $conf->getbool('audio', 'tts', true),
        'dictation_enabled' => $conf->getbool('whisper', 'dictation', true),
        'amphores_mode' => $conf->get('amphores', 'mode', 'disabled'),
        'reasoning_mode' => strtolower($conf->get('llm', 'raisonnement', '')),
        'reasoning_levels' => Llm::LEVELS,
        'model' => $conf->get('llm', 'model', ''),
        'theme' => $theme,
        'authentifie' => $user !== null,
        'seuil_upload_chunk' => $conf->getint('web', 'seuil_upload_chunk', 5242880),
        'taille_chunk' => $conf->getint('web', 'taille_chunk', 1048576),
    ]);
}

// ---------------------------------------------------------------------------
// API — System config (admin only)
// ---------------------------------------------------------------------------

function routeConfigAdminGet(Config $conf): never
{
    $configPath = __DIR__ . '/data/config.php';
    if (!is_file($configPath)) {
        json_err('Fichier de configuration introuvable.', 404);
    }
    $config = include $configPath;
    json_out($config);
}

function routeConfigAdminSave(array $in): never
{
    $configPath = __DIR__ . '/data/config.php';
    $backupPath = $configPath . '.' . date('Ymd_His') . '.bak';

    // Backup
    if (is_file($configPath)) {
        copy($configPath, $backupPath);
    }

    // Validation: check that it is an array
    $newConfig = $in['config'] ?? null;
    if (!is_array($newConfig)) {
        json_err('Config invalide.', 400);
    }

    // Generate the PHP
    $exporte = var_export($newConfig, true);
    $contenu = "<?php\nreturn $exporte;\n";

    // Check the syntax in an isolated context
    $testFile = sys_get_temp_dir() . '/hermes_config_test_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($testFile, '<?php' . "\n" . '$c = ' . $exporte . ";\nreturn \$c;");
    try {
        $testConfig = include $testFile;
        if (!is_array($testConfig)) {
            throw new \RuntimeException('Le fichier inclus ne retourne pas un tableau.');
        }
    } catch (\Throwable $e) {
        $debug = file_get_contents($testFile);
        error_log('Config save error: ' . $e->getMessage() . ' | Test file: ' . $debug);
        @unlink($testFile);
        @unlink($backupPath);
        json_err('Syntaxe PHP invalide : ' . $e->getMessage(), 400);
    }
    @unlink($testFile);

    // Atomic write
    $tempFile = $configPath . '.tmp';
    if (file_put_contents($tempFile, $contenu) === false) {
        json_err('Impossible d\'écrire le fichier.', 500);
    }
    if (!rename($tempFile, $configPath)) {
        json_err('Erreur lors de la sauvegarde.', 500);
    }

    json_out(['ok' => true, 'message' => 'Config sauvegardée.']);
}

// ---------------------------------------------------------------------------
// API — Conversations
// ---------------------------------------------------------------------------

function routeConversationsList(Config $conf, ?array $user): never
{
    if ($user === null) {
        json_out([]);
    }
    json_out(Conversations::list($user['id']));
}

function routeConversationsCreate(Config $conf, ?array $user, array $in): never
{
    if ($user === null) {
        json_out(['id' => 0]);
    }
    $id = Conversations::create($user['id'], 'Nouvelle conversation', (string) ($in['amphore_id'] ?? ''));
    json_out(['id' => $id]);
}

function routeConversationsLoad(Config $conf, ?array $user, int $id): never
{
    if ($user === null) {
        json_out(['id' => $id, 'titre' => '', 'amphore_id' => '', 'messages' => []]);
    }
    $conv = Conversations::load($id, $user['id']);
    if ($conv === null) {
        json_err('Conversation introuvable.', 404);
    }
    json_out($conv);
}

function routeConversationsUpdate(Config $conf, ?array $user, int $id, array $in): never
{
    if ($user === null) {
        json_out(['ok' => true]);
    }
    if (isset($in['amphore_id'])) {
        [$ok, $msg] = Conversations::changeAmphora($id, $user['id'], (string) ($in['amphore_id'] ?? ''));
        if (!$ok) {
            json_err($msg, 400);
        }
        json_out(['ok' => true, 'message' => $msg]);
    }
    if (isset($in['titre'])) {
        [$ok, $msg] = Conversations::changeTitle($id, $user['id'], (string) $in['titre']);
        if (!$ok) {
            json_err($msg, 400);
        }
        json_out(['ok' => true, 'message' => $msg]);
    }
    json_out(['ok' => true]);
}

function routeConversationsDelete(Config $conf, ?array $user, int $id): never
{
    if ($user === null) {
        json_out(['ok' => true]);
    }
    [$ok, $msg] = Conversations::delete($id, $user['id']);
    if (!$ok) {
        json_err($msg, 404);
    }
    json_out(['ok' => true, 'message' => $msg]);
}

// ---------------------------------------------------------------------------
// API — Amphores
// ---------------------------------------------------------------------------

function routeAmphoresList(Config $conf, ?array $user): never
{
    if ($user === null) {
        // none mode: only global amphorae are visible.
        $con = Database::pdo();
        $globales = $con->query(
            "SELECT id, nom, description, system_prompt, created_at
             FROM amphores_global ORDER BY nom ASC"
        )->fetchAll();
        json_out(array_map(fn ($a) => [
            'id' => $a['id'], 'nom' => $a['nom'], 'description' => $a['description'] ?? '',
            'system_prompt' => $a['system_prompt'], 'niveau' => 'global',
            'created_at' => (int) $a['created_at'],
        ], $globales));
    }
    json_out(Amphores::listFor($user['id']));
}

function routeAmphoresCreate(Config $conf, ?array $user, array $in): never
{
    $estGlobal = !empty($in['global']);
    if ($estGlobal) {
        $admin = adminUserRequired($conf);
        $id = Amphores::create(
            (string) ($in['nom'] ?? ''),
            (string) ($in['system_prompt'] ?? ''),
            (string) ($in['description'] ?? ''),
            $admin['id'],
            true,
        )['id'];
        json_out(['id' => $id]);
    }
    if ($user === null) {
        json_err('Non authentifié.', 401);
    }
    $id = Amphores::create(
        (string) ($in['nom'] ?? ''),
        (string) ($in['system_prompt'] ?? ''),
        (string) ($in['description'] ?? ''),
        $user['id'],
        false,
    )['id'];
    json_out(['id' => $id]);
}

function routeAmphoresUpdate(Config $conf, string $id, array $in): never
{
    // A user updates their personal amphorae; an admin can modify
    // any amphora (global or personal).
    $u = userIfRequired($conf);
    if ($u === null) {
        json_err('Non authentifié.', 401);
    }
    [$ok, $msg] = Amphores::update(
        $id,
        (string) ($in['nom'] ?? ''),
        (string) ($in['system_prompt'] ?? ''),
        (string) ($in['description'] ?? ''),
        $u['id'],
        $u['is_admin'],
    );
    if (!$ok) {
        json_err($msg, 400);
    }
    json_out(['ok' => true, 'message' => $msg]);
}

function routeAmphoresDelete(Config $conf, string $id): never
{
    // A user deletes their personal amphorae; an admin can delete
    // any amphora (global or personal).
    $u = userIfRequired($conf);
    if ($u === null) {
        json_err('Non authentifié.', 401);
    }
    [$ok, $msg] = Amphores::delete($id, $u['id'], $u['is_admin']);
    if (!$ok) {
        json_err($msg, 400);
    }
    json_out(['ok' => true, 'message' => $msg]);
}

// ---------------------------------------------------------------------------
// API — Users (admin)
// ---------------------------------------------------------------------------

function routeUsersList(Config $conf): never
{
    adminUserRequired($conf);
    json_out(Auth::listUsers());
}

function routeUsersCreate(Config $conf, array $in): never
{
    adminUserRequired($conf);
    [$ok, $msg] = Auth::createAccount((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''));
    if (!$ok) {
        json_err($msg, 400);
    }
    json_out(['ok' => true, 'message' => $msg]);
}

function routeUsersUpdate(Config $conf, int $id, array $in): never
{
    $admin = adminUserRequired($conf);

    if (array_key_exists('is_admin', $in)) {
        [$ok, $msg] = Auth::setAdmin($id, (bool) $in['is_admin']);
        if (!$ok) {
            json_err($msg, 400);
        }
    }
    if (array_key_exists('is_active', $in)) {
        [$ok, $msg] = Auth::setActive($id, (bool) $in['is_active'], $admin['id']);
        if (!$ok) {
            json_err($msg, 400);
        }
    }
    if (!empty($in['password'])) {
        [$ok, $msg] = Auth::resetPassword($id, (string) $in['password']);
        if (!$ok) {
            json_err($msg, 400);
        }
    }
    json_out(['ok' => true]);
}

// ---------------------------------------------------------------------------
// API — TTS
// ---------------------------------------------------------------------------

function routeTts(Config $conf, array $in): never
{
    $text = (string) ($in['texte'] ?? '');
    $msgId = (int) ($in['message_id'] ?? 0);
    if (trim($text) === '') {
        json_err('Texte vide.', 400);
    }
    try {
        [$data, $mime] = Tools::generateTts($text, $conf);
    } catch (\Throwable $e) {
        json_err('Erreur TTS : ' . $e->getMessage(), 502);
    }
    if ($msgId > 0) {
        $audioB64 = base64_encode($data);
        try {
            $con = Database::pdo();
            $stmt = $con->prepare("UPDATE messages SET audio_b64 = ? WHERE id = ?");
            $stmt->execute([$audioB64, $msgId]);
        } catch (\Throwable) {
            // Silent: a DB error must not block TTS
        }
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: inline; filename="hermes-tts"');
    echo $data;
    exit;
}

// ---------------------------------------------------------------------------
// API — Voice dictation (micro recording -> whisper.cpp)
// ---------------------------------------------------------------------------

/**
 * Route: POST /api/dictation — Transcribes a micro recording sent as
 * multipart (the “fichier” field). The browser sends webm/opus or
 * mp4/aac depending on the engine (MediaRecorder); the ffmpeg normalization
 * and the transcription reuse the existing Tools::transcribeAudio() pipeline.
 */
function routeDictation(Config $conf): never
{
    // Dictation is governed only by whisper.dictation; the
    // tools.enable_transcrire_audio tool stays independent (transcription of
    // audio/video files attached to the conversation).
    if (!$conf->getbool('whisper', 'dictation', true)) {
        json_err('La dictée vocale est désactivée dans la configuration.', 403);
    }

    if (empty($_FILES['fichier']) || ($_FILES['fichier']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $codeErreur = (int) ($_FILES['fichier']['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($codeErreur, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            json_err('Enregistrement trop volumineux (limite d\'upload du serveur PHP atteinte).', 413);
        }
        json_err('Enregistrement audio manquant.', 400);
    }
    $f = $_FILES['fichier'];
    $taille = (int) ($f['size'] ?? 0);
    if ($taille <= 0) {
        json_err('Enregistrement vide.', 400);
    }
    // 25 MB: a 5 min recording weighs ~1 MB in Opus, the margin is
    // there to block abnormal uploads.
    $maxTaille = 25 * 1024 * 1024;
    if ($taille > $maxTaille) {
        json_err('Enregistrement trop volumineux (25 Mo maximum).', 413);
    }
    $tmpName = (string) ($f['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        json_err('Fichier temporaire introuvable.', 500);
    }
    // Maximum duration: 10 min (the client stops at 5 min).
    if (Tools::fileDurationSeconds($tmpName) > 600) {
        json_err('Enregistrement trop long (10 minutes maximum).', 413);
    }

    $donnees = @file_get_contents($tmpName);
    if ($donnees === false || $donnees === '') {
        json_err('Impossible de lire l\'enregistrement.', 500);
    }
    $nom = (string) ($f['name'] ?? '') ?: 'enregistrement.webm';

    $texte = Tools::transcribeAudio($donnees, $nom, $conf);
    if (str_starts_with($texte, '⚠️')) {
        json_err($texte, 502);
    }
    json_out(['texte' => $texte]);
}

// ---------------------------------------------------------------------------
// API — Chat (boucle LLM + outils, streaming SSE)
// ---------------------------------------------------------------------------

/**
 * Deterministic tool_choice detection (like the Python version):
 *  - URL of a whitelisted video domain -> transcribeVideoUrl
 *  - any other URL -> toolFetchPage
 * Returns a "function" dict to force, or null.
 */
function detectToolChoiceUrl(string $prompt, Config $conf, array $nomsOutils): ?array
{
    if (!preg_match_all('#https?://[^\s<>"\']+#', $prompt, $m)) {
        return null;
    }
    $urls = $m[0];
    if (in_array('transcribeVideoUrl', $nomsOutils, true)) {
        foreach ($urls as $u) {
            if (Tools::allowedVideoUrl($u, $conf)) {
                return ['type' => 'function', 'function' => ['name' => 'transcribeVideoUrl']];
            }
        }
    }
    if (in_array('toolFetchPage', $nomsOutils, true)) {
        return ['type' => 'function', 'function' => ['name' => 'toolFetchPage']];
    }
    return null;
}

/**
 * Deterministic detection of a request for a downloadable file generation.
 * Forces the generateFile tool so the file is actually created on the
 * server (and thus comes with a download link), even for
 * markdown or text the model would otherwise produce in plain form.
 * Returns a "function" dict to force, or null.
 */
function detectToolChoiceFichier(string $prompt, array $nomsOutils): ?array
{
    if (!in_array('generateFile', $nomsOutils, true)) {
        return null;
    }
    $forcer = fn () => ['type' => 'function', 'function' => ['name' => 'generateFile']];
    // 1. Explicit file extension: .md, .csv, .xlsx, .pptx, .odt, .txt, ...
    if (preg_match('/\.(md|markdown|csv|xlsx|xls|ods|txt|docx|odt|pdf|pptx|odp|ppt|html|htm|json|tsv|xml)\b/i', $prompt)) {
        return $forcer();
    }
    // 2. Explicit format keyword (markdown, excel, xlsx, csv, pdf, docx,
    //    odt, ods, odp, libreoffice, powerpoint, présentation...) in a
    //    file context:
    //    « un excel », « en csv », « fichier markdown », « un rapport pdf »,
    //    « convertis en xlsx », « une présentation pptx », « en odt »...
    if (preg_match('/\b(un|une|en|dans|vers|sous|de|pour|des|mon|ma|mes|ton|ta|tes|notre|format|fichier|document|rapport|tableur)\s+(markdown|excel|xlsx|csv|pdf|docx|odt|ods|odp|libreoffice|openoffice|txt|pptx|powerpoint|presentation|présentation|diaporama|slides|spreadsheet)\b/i', $prompt)
        || preg_match('/\b(markdown|excel|xlsx|csv|pdf|docx|odt|ods|odp|libreoffice|openoffice|txt|pptx|powerpoint|presentation|présentation|diaporama|slides|spreadsheet)\s+(fichier|document|tableau|rapport|de|des|avec|contenant|listant|format)\b/i', $prompt)
        || preg_match('/(^|\n)\s*(markdown|excel|xlsx|csv|pdf|docx|odt|ods|odp|libreoffice|openoffice|txt|pptx|powerpoint|presentation|présentation|diaporama|slides|spreadsheet)\b/i', $prompt)) {
        return $forcer();
    }
    // 3. Request to put into file form: « dans un fichier », « en fichier »,
    //    « sous forme de document »...
    if (preg_match('/\b(dans|en)\s+(?:(un|une)\s+)?(fichier|document)\b/i', $prompt)
        || preg_match('/\bsous forme de\s+(?:(un|une)\s+)?(fichier|document)\b/i', $prompt)) {
        return $forcer();
    }
    // 4. Creation / export verb + fichier, document, rapport,
    //    présentation, export...
    if (preg_match(
        '/\b(générer|generer|génère|genere|generate|créer|creer|crée|cree|create|création|creation|exporter|exporte|export|produire|produis|produit|fabriquer|fabrique|rédiger|rediger|rédige|redige|préparer|preparer|prépare|prepare|établir|etablir|établis|etabli|composer|compose|dresser|dresse|faire|fais|donner|donne|donnes|fournir|fournis|veux|veut|voudrais|voudra|aimerais|aimerait)\b[^\n]{0,80}\b(fichier|document|rapport|export|exportation|file|spreadsheet|presentation|présentation|diaporama|powerpoint|pptx|odt|ods|odp|libreoffice)\b/i',
        $prompt,
    )) {
        return $forcer();
    }
    return null;
}

/**
 * Reads the chat request (JSON or multipart if an attached file or chunked upload).
 * @return array{message:string, conversation_id:string, reasoning:string, amphore_id:string, ephemeral:bool, fichier:?array{contenu:string,nom:string,mime:string}, upload_id:string, demande_id:string}
 */
function readChatRequest(): array
{
    $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($ct, 'multipart/form-data') !== false) {
        $fichier = null;
        $uploadId = (string) ($_POST['upload_id'] ?? '');
        if (!empty($_FILES['fichier']) && ($_FILES['fichier']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $fichier = [
                'contenu' => (string) file_get_contents($_FILES['fichier']['tmp_name']),
                'nom' => (string) $_FILES['fichier']['name'],
                'mime' => (string) $_FILES['fichier']['type'],
            ];
        }
        return [
            'message' => (string) ($_POST['message'] ?? ''),
            'conversation_id' => (string) ($_POST['conversation_id'] ?? ''),
            'reasoning' => (string) ($_POST['reasoning'] ?? ''),
            'amphore_id' => (string) ($_POST['amphore_id'] ?? ''),
            'ephemeral' => !empty($_POST['ephemeral']),
            'fichier' => $fichier,
            'upload_id' => $uploadId,
            'demande_id' => (string) ($_POST['demande_id'] ?? ''),
        ];
    }
    $d = readJson();
    return [
        'message' => (string) ($d['message'] ?? ''),
        'conversation_id' => (string) ($d['conversation_id'] ?? ''),
        'reasoning' => (string) ($d['reasoning'] ?? ''),
        'amphore_id' => (string) ($d['amphore_id'] ?? ''),
        'ephemeral' => !empty($d['ephemeral']),
        'debut' => (string) ($d['debut'] ?? ''),
        'fichier' => null,
        'upload_id' => (string) ($d['upload_id'] ?? ''),
        'demande_id' => (string) ($d['demande_id'] ?? ''),
    ];
}

/**
 * Conversation loop: LLM calls + tool execution, SSE streaming.
 * Events emitted: conversation, etape, outil, outil_resultat, delta,
 * pieces_jointes, erreur, fin.
 */
function routeChat(Config $conf, ?array $user): void
{
    $req = readChatRequest();
    $message = trim($req['message']);
    if ($message === '') {
        json_err('Message vide.', 400);
    }

    $ephemeral = !empty($req['ephemeral']);
    $persistent = persistenceActive($conf) && !$ephemeral;
    $userId = $user['id'] ?? 0;
    $userLevel = $req['reasoning'] !== '' ? $req['reasoning'] : null;
    $requestStart = !empty($req['debut']) ? (int) $req['debut'] : 0;

    // Request identifier: capability of the stop button (POST /api/stop
    // creates data/stop/<id>.lock, checked at each control point below).
    $idDemande = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $req['demande_id']);
    nettoyerFichiersStop();

    $llm = new Llm($conf, fn () => demandeArretee($idDemande));
    $outils = Tools::activeTools($conf);
    $nomsOutils = array_map(fn ($o) => (string) ($o['function']['name'] ?? ''), $outils);
    $icones = Tools::icons();

    sseHeaders();

    // tool_call tags hallucinated by some models (Qwen...).
    $tagOuverture = '<' . 'tool_call';
    $tagFermeture = '</' . 'tool_call>';

    try {
        // 1. Conversation (persistante seulement en mode userpass).
        $convId = 0;
        if ($persistent) {
            $demande = (int) $req['conversation_id'];
            if ($demande > 0) {
                $existant = Conversations::load($demande, $userId);
                if ($existant === null) {
                    sseSend('erreur', ['message' => 'Conversation introuvable.']);
                    return;
                }
                if ($req['amphore_id'] !== '') {
                    Conversations::changeAmphora($demande, $userId, $req['amphore_id']);
                }
                $convId = $demande;
            } else {
                $convId = Conversations::create($userId, 'Nouvelle conversation', $req['amphore_id']);
            }
            sseSend('conversation', ['id' => $convId]);
        }

        // 2. Attached file (direct or chunked upload).
        $fileInfo = null;
        $activeAudio = null;
        $attachments = [];
        $uploadIdToClean = '';
        if ($req['fichier'] !== null) {
            $fileInfo = Upload::extract($req['fichier']['contenu'], $req['fichier']['nom'], $req['fichier']['mime']);
            $attachments = [[
                'nom' => $fileInfo['nom'],
                'type' => $fileInfo['type'],
                'taille' => strlen($req['fichier']['contenu']),
            ]];
            if ($fileInfo['type'] === 'audio') {
                $activeAudio = $fileInfo;
            }
        } elseif ($req['upload_id'] !== '') {
            $uploadIdToClean = $req['upload_id'];
            $assembly = UploadChunks::reassemble($uploadIdToClean);
            if ($assembly['status'] !== 'ok') {
                sseSend('erreur', ['message' => $assembly['message'] ?? 'Erreur d\'assemblage du fichier.']);
                return;
            }
            $fileInfo = Upload::extractFromFile($assembly['chemin'], $assembly['nom'], $assembly['mime'] ?? 'application/octet-stream');
            $attachments = [[
                'nom' => $assembly['nom'],
                'type' => $fileInfo['type'],
                'taille' => $assembly['taille'] ?? filesize($assembly['chemin']),
            ]];
            if ($fileInfo['type'] === 'audio') {
                $activeAudio = $fileInfo;
                $tempAudioPath = $assembly['chemin'];
            } else {
                @unlink($assembly['chemin']);
            }
        }
        $userMessageLlm = $fileInfo !== null
            ? Upload::messageWithFile($fileInfo, $message)
            : ['role' => 'user', 'content' => $message];

        // 3. History + system prompt (BEFORE adding the current message).
        $conv = $persistent ? Conversations::load($convId, $userId) : null;
        $amphoreId = (string) ($conv['amphore_id'] ?? ($req['amphore_id'] !== '' ? $req['amphore_id'] : ''));
        $systemPrompt = Amphores::systemPrompt($amphoreId, $userId);
        $systemPrompt = $systemPrompt ?? defaultSystemPrompt($conf);

        $llmMessages = [['role' => 'system', 'content' => $systemPrompt]];
        if ($conv !== null) {
            foreach ($conv['messages'] as $m) {
                $llmMessages[] = ['role' => (string) $m['role'], 'content' => (string) $m['content']];
            }
        }
        $llmMessages[] = $userMessageLlm;

        // 4. Save the user message.
        if ($persistent) {
            Conversations::addMessage($convId, $userId, 'user', $message, $attachments);
        }

        // 5. First call (non-streaming, with tools) to detect tool_calls.
        //    tool_choice can be forced: URL -> fetch tool,
        //    file request -> generateFile (guarantees the file creation
        //    and thus the download link, even for markdown).
        $choiceFichier = detectToolChoiceFichier($message, $nomsOutils);
        $demandeFichier = $choiceFichier !== null;
        $toolChoice = detectToolChoiceUrl($message, $conf, $nomsOutils) ?? $choiceFichier ?? 'auto';
        try {
            $aiMessage = $llm->completion($llmMessages, $outils, $toolChoice, $userLevel);
        } catch (RequestAbortedException $e) {
            throw $e; // Stop by the user: the outer catch handles persistence.
        } catch (\Throwable $e) {
            // Forced tool_choice rejected: retry in "auto".
            if (is_array($toolChoice)) {
                try {
                    $aiMessage = $llm->completion($llmMessages, $outils, 'auto', $userLevel);
                } catch (\Throwable $e2) {
                    sseSend('erreur', ['message' => 'Erreur de connexion au LLM : ' . $e2->getMessage()]);
                    return;
                }
            } else {
                sseSend('erreur', ['message' => 'Erreur de connexion au LLM : ' . $e->getMessage()]);
                return;
            }
        }

        if (!empty($aiMessage['tool_calls'])) {
            // --- Tool loop (at most 2 rounds) ---
            $responseParts = [];
            $fichiersGenere = [];
            $executerTourOutils = function (array $aiMessageTour) use (
                &$llmMessages, &$responseParts, &$fichiersGenere,
                $conf, $activeAudio, $userId, $convId, $icones, $idDemande,
            ): void {
                $llmMessages[] = $aiMessageTour;
                foreach ($aiMessageTour['tool_calls'] as $call) {
                    // Stop control point (a tool already in progress cannot
                    // be interrupted: the stop takes effect here).
                    if (demandeArretee($idDemande)) {
                        throw new RequestAbortedException('Génération arrêtée par l\'utilisateur.');
                    }
                    $toolName = (string) ($call['function']['name'] ?? '');
                    $decodedArgs = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                    $args = is_array($decodedArgs) ? $decodedArgs : [];
                    $label = $icones[$toolName] ?? ('🔧 ' . $toolName);

                    sseSend('outil', ['etape' => 'demarrage', 'nom' => $toolName, 'label' => $label, 'args' => $args]);

                    try {
                        $toolResult = Tools::executeTool($toolName, $args, $conf, $activeAudio, $userId, (string) $convId);
                    } catch (\Throwable $e) {
                        $toolResult = '⚠️ Erreur de l\'outil « ' . $toolName . ' » : ' . $e->getMessage();
                    }

                    $toolResultLlm = $toolResult;
                    if ($toolName === 'generateFile') {
                        $parsed = json_decode($toolResult, true);
                        if (is_array($parsed) && !empty($parsed['__fichier_genere__'])) {
                            // File saved on disk: the download link
                            // is added at the end of the final message (not in the tool block).
                            $fichiersGenere[] = [
                                'nom' => (string) ($parsed['nom'] ?? ''),
                                'url' => (string) ($parsed['telechargement_url'] ?? ''),
                            ];
                            $toolResultLlm = '✅ Fichier `' . ($parsed['nom'] ?? '') . '` généré avec succès '
                                . 'au format ' . strtoupper((string) ($parsed['format'] ?? '')) . '.';
                        }
                    } elseif ($toolName === 'generateImage') {
                        $parsed = json_decode($toolResult, true);
                        if (is_array($parsed) && !empty($parsed['__image_generee__'])) {
                            $responseParts[] = $parsed + ['type' => 'image'];
                            $toolResultLlm = '✅ Image `' . ($parsed['nom'] ?? '') . '` générée avec succès '
                                . 'et affichée à l\'utilisateur juste après cette réponse.';
                        }
                    }

                    sseSend('outil_resultat', ['nom' => $toolName, 'label' => $label, 'resultat' => $toolResultLlm]);

                    $llmMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($call['id'] ?? ''),
                        'name' => $toolName,
                        'content' => $toolResultLlm,
                    ];
                }
            };

            $executerTourOutils($aiMessage);

            // Second round: a file was requested but has not yet been
            // generated (e.g. the page fetch tool was forced first,
            // or the generateFile arguments were invalid). We give
            // the model a chance to call generateFile with the round 1 results.
            if ($demandeFichier && $fichiersGenere === []) {
                if (demandeArretee($idDemande)) {
                    throw new RequestAbortedException('Génération arrêtée par l\'utilisateur.');
                }
                sseSend('etape', ['texte' => 'Génération du fichier...']);
                try {
                    $aiMessageTour2 = $llm->completion($llmMessages, $outils, 'auto', $userLevel);
                } catch (RequestAbortedException $e) {
                    throw $e;
                } catch (\Throwable) {
                    $aiMessageTour2 = null;
                }
                if (!empty($aiMessageTour2['tool_calls'])) {
                    $executerTourOutils($aiMessageTour2);
                }
            }

            // Cleanup of the temporary audio/video file after tool execution
            if (isset($tempAudioPath) && is_file($tempAudioPath)) {
                @unlink($tempAudioPath);
                unset($tempAudioPath);
            }

            // --- Final synthesis (non-streaming, like the original) ---
            if (demandeArretee($idDemande)) {
                throw new RequestAbortedException('Génération arrêtée par l\'utilisateur.');
            }
            sseSend('etape', ['texte' => 'Rédaction de la réponse...']);
            try {
                $final = $llm->completion($llmMessages, null, null, $userLevel);
                $finalText = (string) ($final['content']
                    ?? $final['reasoning_content'] ?? '');
            } catch (RequestAbortedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $finalText = 'Erreur lors de la synthèse : ' . $e->getMessage();
            }

            // Anti-hallucination: some models (Qwen...) emit a
            // tool_call in plain text although the tool is already executed.
            if (str_contains($finalText, $tagOuverture)) {
                $messagesRetry = $llmMessages + [[
                    'role' => 'system',
                    'content' => 'Rappel : les outils nécessaires ont déjà été exécutés et leurs résultats '
                        . 'sont disponibles ci-dessus (messages de rôle "tool"). N\'appelle plus aucun '
                        . 'outil et ne génère surtout pas de balise tool_call. Réponds uniquement '
                        . 'en langage naturel, en te basant sur les résultats déjà obtenus.',
                ]];
                try {
                    $retryResult = $llm->completion($messagesRetry, null, null, $userLevel);
                    $retryText = (string) ($retryResult['content']
                        ?? $retryResult['reasoning_content'] ?? '');
                    if (trim($retryText) !== '' && !str_contains($retryText, $tagOuverture)) {
                        $finalText = $retryText;
                    } else {
                        // Second attempt: remove the tags from the original text
                        // and add a user message to force a response.
                        $finalText = trim((string) preg_replace('/' . preg_quote($tagOuverture, '/') . '.*?' . preg_quote($tagFermeture, '/') . '/s', '', $finalText));
                        if ($finalText === '') {
                            // The model produced nothing: force a simple response
                            $messagesForce = $llmMessages + [
                                ['role' => 'user', 'content' => 'Merci de confirmer que la tâche a été effectuée avec succès. Réponds en une phrase courte en langage naturel.'],
                            ];
                            try {
                                $forceResult = $llm->completion($messagesForce, null, null, $userLevel);
                                $forceText = (string) ($forceResult['content']
                                    ?? $forceResult['reasoning_content'] ?? '');
                                if (trim($forceText) !== '' && !str_contains($forceText, $tagOuverture)) {
                                    $finalText = $forceText;
                                }
                            } catch (\Throwable $e) {
                                $finalText = '⚠️ Le modèle n\'a pas réussi à formuler une réponse en langage naturel. (' . $e->getMessage() . ')';
                            }
                        }
                    }
                } catch (RequestAbortedException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $finalText = trim((string) preg_replace('/' . preg_quote($tagOuverture, '/') . '.*?' . preg_quote($tagFermeture, '/') . '/s', '', $finalText));
                    if ($finalText === '') {
                        $finalText = '⚠️ Le modèle n\'a pas réussi à formuler une réponse en langage naturel. (' . $e->getMessage() . ')';
                    }
                }
            }

            // --- Cleanup of the download links written by the model ---
            // The model does not know the real URL of the generated files (the
            // unique ID only exists after the synthesis): any /api/fichiers/
            // link it produces is hallucinated or expired (e.g. ID reused from
            // the conversation history). The line is removed; the
            // official link is added just below, once.
            $finalText = (string) preg_replace('/^[^\n]*\/api\/fichiers\/[^\n]*$/m', '', $finalText);
            $finalText = (string) preg_replace('/\n{3,}/', "\n\n", $finalText);
            $finalText = trim($finalText);

            // --- Download links of the generated files (at the end of the message) ---
            // Guarantees the link is present in the saved text (persists
            // on conversation reload), regardless of the model.
            foreach ($fichiersGenere as $fichierGenere) {
                $urlFichier = (string) ($fichierGenere['url'] ?? '');
                if ($urlFichier !== '' && !str_contains($finalText, $urlFichier)) {
                    $nomFichier = (string) ($fichierGenere['nom'] ?? 'fichier');
                    $finalText = rtrim($finalText)
                        . "\n\n📄 **Fichier généré :** [" . $nomFichier . "](" . $urlFichier . ")";
                }
            }

            sseSend('delta', ['texte' => $finalText]);
            if ($responseParts !== []) {
                sseSend('pieces_jointes', ['pieces' => $responseParts]);
            }
            $msgIdAsst = null;
            if ($persistent) {
                $duration = $requestStart > 0 ? round((microtime(true) * 1000 - $requestStart) / 1000, 1) : null;
                $metadata = json_encode(['duree_ms' => $duration, 'raisonnement' => $userLevel ?? ''], JSON_UNESCAPED_UNICODE);
                Conversations::addMessage($convId, $userId, 'assistant', $finalText, $responseParts, '', $metadata);
                $msgIdAsst = (int) Database::pdo()->lastInsertId();
            }
            $duration = $requestStart > 0 ? round((microtime(true) * 1000 - $requestStart) / 1000, 1) : null;
            sseSend('fin', ['conversation_id' => $convId, 'texte' => $finalText, 'message_id' => $msgIdAsst, 'duree_ms' => $duration]);
        } else {
            // --- Direct response (streaming) ---
            sseSend('etape', ['texte' => 'Génération...']);
            $text = '';
            $arrete = false;
            try {
                $text = $llm->completion_stream($llmMessages, function (string $delta) {
                    sseSend('delta', ['texte' => $delta]);
                }, $userLevel);
            } catch (RequestAbortedException $e) {
                // Stop by the user: keep the partial text.
                $text = $e->getPartiel();
                $arrete = true;
            } catch (\Throwable $e) {
                sseSend('erreur', ['message' => 'Erreur pendant le streaming : ' . $e->getMessage()]);
                $text = '*(erreur de génération)*';
            }
            if ($arrete) {
                // Partial text + marker (identical to the client display).
                $text = ($text === '' ? '' : $text . "\n\n") . MARQUEUR_ARRET;
            }
            $msgIdAsst = null;
            if ($persistent) {
                $duration = $requestStart > 0 ? round((microtime(true) * 1000 - $requestStart) / 1000, 1) : null;
                $metadata = json_encode(['duree_ms' => $duration, 'raisonnement' => $userLevel ?? ''], JSON_UNESCAPED_UNICODE);
                Conversations::addMessage($convId, $userId, 'assistant', $text, [], '', $metadata);
                $msgIdAsst = (int) Database::pdo()->lastInsertId();
            }
            if (!$arrete) {
                // On stop, the client is already gone: the fin event
                // would serve no one.
                $duration = $requestStart > 0 ? round((microtime(true) * 1000 - $requestStart) / 1000, 1) : null;
                sseSend('fin', ['conversation_id' => $convId, 'texte' => $text, 'message_id' => $msgIdAsst, 'duree_ms' => $duration]);
            }
        }
    } catch (RequestAbortedException $e) {
        // Stop during the tools / synthesis phase: no text has yet
        // been emitted. The marker is saved to keep the history
        // consistent with what the client displayed.
        if ($persistent) {
            Conversations::addMessage($convId, $userId, 'assistant', MARQUEUR_ARRET, [], '', '');
        }
    } catch (\Throwable $e) {
        sseSend('erreur', ['message' => $e->getMessage()]);
    }

    // Cleanup of the chunked upload after processing.
    if ($uploadIdToClean !== '') {
        UploadChunks::clean($uploadIdToClean);
    }

    // Removal of the stop file (in all cases).
    if ($idDemande !== '') {
        @unlink(cheminFichierStop($idDemande));
    }

    exit;
}

/**
 * Route: POST /api/stop — Requests the stop of an in-flight chat request.
 *
 * The client generates a random identifier on send (demande_id, sent
 * in the body of /api/chat); knowing it here is the stop capability.
 * The created file is seen by the worker handling the request (the workers
 * PHP share no memory). Idempotent: a double stop or a stop after
 * the end of the request has no effect (file cleaned up on the next chat).
 */
function routeStop(array $in): never
{
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($in['demande_id'] ?? ''));
    if ($id === '') {
        json_err('Identifiant de requête manquant.', 400);
    }
    // Format imposed by the client ('h_' + random base36): everything else
    // is rejected to avoid creating useless orphan files.
    if (!preg_match('/^h_[a-z0-9]{5,30}$/', $id)) {
        json_err('Identifiant de requête invalide.', 400);
    }
    $fichier = cheminFichierStop($id);
    if (!is_file($fichier)) {
        file_put_contents($fichier, (string) time(), LOCK_EX);
    }
    json_out(['ok' => true]);
}

// ---------------------------------------------------------------------------
// Routage API
// ---------------------------------------------------------------------------

function routeApi(Config $conf, string $methode, string $path): never
{
    $parts = pathSegments($path);
    $ressource = $parts[1] ?? '';
    $sous      = $parts[2] ?? '';
    // Full sub-path (multiple segments), needed for routes
    // of the /api/fichiers/{id}/{nom} type.
    $sousComplet = implode('/', array_slice($parts, 2));
    $in        = ($methode === 'POST' || $methode === 'PUT') ? readJson() : [];

    // Current user WITHOUT forcing authentication (null if anonymous or
    // not connected). The public routes below must stay accessible
    // without a session (otherwise the login page could not load).
    $user = Auth::currentUser();

    // --- Rate limiting (brute force) on login/register, per IP. ---
    // Note: REMOTE_ADDR is the reverse-proxy IP behind one (limits then
    // apply to all users); fine for a direct Apache deployment.
    if ($conf->getbool('rate_limit', 'enable', true)) {
        $limits = [
            'login'    => [10, 15 * 60], // 10 hits / 15 min
            'register' => [5, 3600],     // 5 hits / hour
        ];
        if (isset($limits[$ressource]) && $methode === 'POST') {
            [$max, $window] = $limits[$ressource];
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $result = RateLimiter::hit("{$ressource}:$ip", $max, $window);
            if (!$result['allowed']) {
                header('Retry-After: ' . $result['retryAfter']);
                json_err('Trop de tentatives, veuillez réessayer plus tard.', 429);
            }
        }
    }

    // --- Public routes (accessible without a session) ---
    if ($ressource === 'login' && $methode === 'POST') {
        routeLogin($conf, $in);
    }
    if ($ressource === 'register' && $methode === 'POST') {
        routeRegister($conf, $in);
    }
    if ($ressource === 'logout' && $methode === 'POST') {
        routeLogout($conf);
    }
    if ($ressource === 'me' && $methode === 'GET') {
        routeMe($conf);
    }
    if ($ressource === 'password' && $methode === 'POST') {
        routePassword($conf, $in);
    }
    if ($ressource === 'oidc' && $sous === 'start' && $methode === 'GET') {
        routeOidcStart($conf);
    }
    if ($ressource === 'config' && $methode === 'GET') {
        routeConfig($conf, $user);
    }

    // --- Protected routes: authentication required in userpass mode ---
    // (in none mode, returns null = anonymous and continues)
    $requiredUser = userIfRequired($conf);
    if ($requiredUser === null && authMode($conf) === 'userpass') {
        json_err('Authentification requise.', 401);
    }
    if ($ressource === 'profile' && $methode === 'PUT') {
        routeProfile($conf, $in);
    }

    // --- System config (admin only) ---
    if ($ressource === 'admin' && $sous === 'config') {
        if ($requiredUser === null || !$requiredUser['is_admin']) {
            json_err('Accès refusé.', 403);
        }
        if ($methode === 'GET') {
            routeConfigAdminGet($conf);
        }
        if ($methode === 'POST') {
            routeConfigAdminSave($in);
        }
    }

    // --- Protected routes: authentication required in userpass mode ---
    // (in none mode, returns null = anonymous and continues)
    $requiredUser = userIfRequired($conf);

    // --- Conversations ---
    if ($ressource === 'conversations') {
        if ($methode === 'GET' && $sous === '') {
            routeConversationsList($conf, $requiredUser);
        }
        if ($methode === 'POST' && $sous === '') {
            routeConversationsCreate($conf, $requiredUser, $in);
        }
        if ($methode === 'GET' && $sous !== '') {
            routeConversationsLoad($conf, $requiredUser, (int) $sous);
        }
        if ($methode === 'PUT' && $sous !== '') {
            routeConversationsUpdate($conf, $requiredUser, (int) $sous, $in);
        }
        if ($methode === 'DELETE' && $sous !== '') {
            routeConversationsDelete($conf, $requiredUser, (int) $sous);
        }
    }

    // --- Amphores ---
    if ($ressource === 'amphores') {
        if ($methode === 'GET' && $sous === '') {
            routeAmphoresList($conf, $requiredUser);
        }
        if ($methode === 'POST' && $sous === '') {
            routeAmphoresCreate($conf, $requiredUser, $in);
        }
        if ($methode === 'PUT' && $sous !== '') {
            routeAmphoresUpdate($conf, (string) $sous, $in);
        }
        if ($methode === 'DELETE' && $sous !== '') {
            routeAmphoresDelete($conf, (string) $sous);
        }
    }

    // --- Users (admin) ---
    if ($ressource === 'users') {
        if ($methode === 'GET' && $sous === '') {
            routeUsersList($conf);
        }
        if ($methode === 'POST' && $sous === '') {
            routeUsersCreate($conf, $in);
        }
        if ($methode === 'PUT' && $sous !== '') {
            routeUsersUpdate($conf, (int) $sous, $in);
        }
    }

    // --- Chat (SSE) — never return to the router (headers already sent) ---
    if ($ressource === 'chat' && $methode === 'POST') {
        routeChat($conf, $requiredUser);
        exit;
    }

    // --- Stop of an in-flight request (stop button) ---
    if ($ressource === 'stop' && $methode === 'POST') {
        routeStop($in);
    }

    // --- TTS ---
    if ($ressource === 'tts' && $methode === 'POST') {
        routeTts($conf, $in);
    }

    // --- Voice dictation (micro recording -> whisper.cpp) ---
    if ($ressource === 'dictation' && $methode === 'POST') {
        routeDictation($conf);
    }

    // --- Upload par chunks ---
    if ($ressource === 'upload') {
        if ($methode === 'POST' && $sous === 'chunk') {
            routeUploadChunk($conf, $in);
        }
        if ($methode === 'POST' && $sous === 'assemble') {
            routeUploadAssemble($conf, $in);
        }
        if ($methode === 'POST' && $sous === 'clean') {
            routeUploadClean($conf, $in);
        }
    }

    // --- Download of generated files ---
    if ($ressource === 'fichiers' && $methode === 'GET') {
        routeDownloadFichier($conf, $sousComplet);
    }

    json_err('Route inconnue : ' . $methode . ' ' . $path, 404);
}

// ---------------------------------------------------------------------------
// Routes d'upload par chunks
// ---------------------------------------------------------------------------

/** Route: POST /api/upload/chunk — Receives a file chunk. */
function routeUploadChunk(Config $conf, array $in): never
{
    // Clean up expired uploads
    UploadChunks::cleanExpired();

    // The parameters come from $_POST (filled automatically by PHP for multipart/form-data)
    $uploadId = (string) ($_POST['upload_id'] ?? '');
    $index = (int) ($_POST['index'] ?? -1);
    $nom = (string) ($_POST['nom'] ?? '');
    $mime = (string) ($_POST['mime'] ?? 'application/octet-stream');
    $taille = (int) ($_POST['taille'] ?? 0);

    if ($uploadId === '' || $index < 0 || $nom === '' || $taille <= 0) {
        json_err('Paramètres manquants : upload_id, index, nom, taille requis.', 400);
    }

    // Read the raw chunk (the multipart request body)
    $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($ct, 'multipart/form-data') !== false) {
        if (empty($_FILES['chunk']) || ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_err('Chunk manquant ou erreur d\'upload.', 400);
        }
        $tmpChunk = $_FILES['chunk']['tmp_name'];
        if (!is_string($tmpChunk) || $tmpChunk === '') {
            json_err('Chunk manquant ou erreur d\'upload.', 400);
        }
        if (!is_file($tmpChunk)) {
            json_err('Chunk temporaire introuvable.', 500);
        }
        $fpTmp = fopen($tmpChunk, 'rb');
        if ($fpTmp === false) {
            json_err('Impossible d\'ouvrir le chunk temporaire.', 500);
        }
        $donneesChunk = stream_get_contents($fpTmp);
        if ($donneesChunk === false) {
            fclose($fpTmp);
            json_err('Impossible de lire le chunk temporaire.', 500);
        }
        fclose($fpTmp);
    } else {
        $donneesChunk = file_get_contents('php://input');
    }

    $resultat = UploadChunks::receiveChunk($uploadId, $index, $nom, $mime, $taille, $donneesChunk);
    json_out($resultat);
}

/** Route: POST /api/upload/assemble — Reassembles the chunks into a file. */
function routeUploadAssemble(Config $conf, array $in): never
{
    $uploadId = (string) ($in['upload_id'] ?? '');
    if ($uploadId === '') {
        json_err('upload_id requis.', 400);
    }

    $resultat = UploadChunks::reassemble($uploadId);
    if ($resultat['status'] !== 'ok') {
        json_err($resultat['message'] ?? 'Erreur lors de l\'assemblage.', 400);
    }
    json_out($resultat);
}

/** Route: POST /api/upload/clean — Deletes an upload after processing. */
function routeUploadClean(Config $conf, array $in): never
{
    $uploadId = (string) ($in['upload_id'] ?? '');
    if ($uploadId === '') {
        json_err('upload_id requis.', 400);
    }

    UploadChunks::clean($uploadId);
    json_out(['message' => 'Upload supprimé.']);
}

/** Route: GET /api/fichiers/{idUnique}/{nom} — Serves a generated file with access control. */
function routeDownloadFichier(Config $conf, string $sous): never
{
    // Parse the URL: /{idUnique}/{nom} (segments percent-encoded when
    // the URL was generated → to decode before use)
    $parts = explode('/', trim($sous, '/'));
    if (count($parts) < 2) {
        json_err('URL de fichier invalide.', 400);
    }
    $idUnique = basename(rawurldecode((string) ($parts[0] ?? '')));
    $nomFichier = basename(rawurldecode((string) ($parts[1] ?? '')));

    if ($idUnique === '' || $nomFichier === '') {
        json_err('URL de fichier invalide.', 400);
    }

    $repertoireGen = __DIR__ . '/data/generated_files';
    $cheminFichier = $repertoireGen . '/' . $idUnique . '_' . $nomFichier;
    $cheminMeta = $cheminFichier . '.meta';

    if (!is_file($cheminFichier) || !is_file($cheminMeta)) {
        json_err('Fichier introuvable.', 404);
    }

    try {
        // Check authentication
        $user = Auth::currentUser();
        if ($user === null) {
            json_err('Authentification requise.', 401);
        }

        // Check the access metadata
        $meta = json_decode((string) file_get_contents($cheminMeta), true);
        if (!is_array($meta) || empty($meta['user_id']) || empty($meta['conversation_id'])) {
            json_err('Métadonnées du fichier introuvables.', 403);
        }

        // Check that the user is the owner
        if ((int) $meta['user_id'] !== (int) $user['id']) {
            json_err('Accès refusé : ce fichier ne vous appartient pas.', 403);
        }

        // Check that the conversation exists and belongs to the user
        $conv = Conversations::load((int) $meta['conversation_id'], (int) $user['id']);
        if ($conv === null) {
            json_err('Conversation associée introuvable.', 403);
        }
    } catch (\Throwable $e) {
        json_err('Erreur lors de la vérification d\'accès : ' . $e->getMessage(), 500);
    }

    // Serve the file
    $mime = $meta['mime'] ?? mime_content_type($cheminFichier);
    $taille = filesize($cheminFichier);

    header('Content-Type: ' . $mime);
    // ASCII fallback name + UTF-8 name (RFC 5987) for accents.
    $filenameAscii = preg_replace('/[^\x20-\x7E]/', '_', $nomFichier);
    header('Content-Disposition: attachment; filename="' . $filenameAscii . '"; filename*=UTF-8\'\'' . rawurlencode($nomFichier));
    header('Content-Length: ' . (string) $taille);
    header('Cache-Control: private');
    readfile($cheminFichier);
    exit;
}

// ---------------------------------------------------------------------------
// Static files + SPA serving
// ---------------------------------------------------------------------------

/** MIME types for the SPA assets. */
function staticMime(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'map' => 'application/json; charset=utf-8',
    ][$ext] ?? 'application/octet-stream';
}

/** Serves a file from public/ or the SPA (index.html) as a last resort. */
function serveSpa(string $path): never
{
    $public = __DIR__ . '/public';
    $publicReel = realpath($public) ?: $public;

    if ($path !== '/' && $path !== '/index.html') {
        $cible = realpath($public . $path);
        if ($cible !== false
            && str_starts_with($cible, $publicReel . DIRECTORY_SEPARATOR)
            && is_file($cible)
        ) {
            header('Content-Type: ' . staticMime($cible));
            header('Content-Length: ' . (string) filesize($cible));
            readfile($cible);
            exit;
        }
    }
    // SPA: all unknown routes fall back to index.html.
    header('Content-Type: text/html; charset=utf-8');
    readfile($publicReel . '/index.html');
    exit;
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

function main(): void
{
    securityHeaders();
    $conf = getHermesConfig();
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $methode = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    // Callback OIDC (hors /api).
    if ($path === '/oidc/callback') {
        routeOidcCallback($conf);
    }

    // API.
    if (str_starts_with($path, '/api/')) {
        routeApi($conf, $methode, $path);
    }

    // SPA / static files.
    serveSpa($path);
}

main();
