<?php
/**
 * Tools
 * ==============================
 * Mirrors the Python module hermes_tools.py: catalog of tools callable by the
 * LLM (weather, wiki, currency, web search, page reading, date, file
 * generation, image generation, audio/video transcription) + TTS.
 *
 * No external dependencies (no Composer):
 *  - HTTP via the in-house curl wrapper (Http);
 *  - DuckDuckGo search via the public HTML endpoint + DOM/regex parsing;
 *  - web page text extraction via DOMDocument;
 *  - transcription via whisper.cpp (API /inference) + system ffmpeg/ffprobe;
 *  - video download via system yt-dlp;
 *  - image / TTS via stablediffusion.cpp / audio.cpp servers (OpenAI API).
 */

declare(strict_types=1);

namespace Hermes;

class Tools
{
    /** Video extensions (converted to audio via ffmpeg before whisper.cpp). */
    private const VIDEO_EXTENSIONS = ['.mp4', '.mkv', '.mov', '.avi', '.webm'];

    /** Audio extensions accepted directly by the measured whisper.cpp server. */
    private const AUDIO_WHISPER_DIRECTES = ['.wav', '.mp3', '.ogg'];

    /** Browser User-Agent for reading web pages. */
    private const USER_AGENT_BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    // ------------------------------------------------------------------
    // Outils web
    // ------------------------------------------------------------------

    /** Full weather bulletin for a city (open-meteo). */
    public static function toolWeather(string $city): string
    {
        try {
            $geoUrl = 'https://geocoding-api.open-meteo.com/v1/search'
                . '?name=' . rawurlencode($city) . '&count=1&language=fr&format=json';
            [, $geo] = self::get_json($geoUrl, 5);
            if (!is_array($geo) || empty($geo['results'])) {
                return "Désolé, la ville « $city » est introuvable.";
            }
            $lieu = $geo['results'][0];
            $lat = $lieu['latitude'];
            $lon = $lieu['longitude'];

            $meteoUrl = 'https://api.open-meteo.com/v1/forecast'
                . '?latitude=' . $lat . '&longitude=' . $lon
                . '&current=temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m'
                . '&timezone=auto';
            [, $res] = self::get_json($meteoUrl, 5);
            if (!is_array($res) || empty($res['current'])) {
                return "⚠️ Impossible de récupérer la météo.";
            }
            $d = $res['current'];

            $translation = [
                0 => 'Ciel dégagé ☀️', 1 => 'Principalement dégagé 🌤️',
                2 => 'Partiellement nuageux ⛅', 3 => 'Couvert ☁️',
                45 => 'Brouillard 🌫️', 48 => 'Brouillard givrant 🌫️❄️',
                51 => 'Bruine légère 🌦️', 61 => 'Pluie légère 🌧️',
                63 => 'Pluie modérée 🌧️', 65 => 'Pluie forte 🌧️💧',
                71 => 'Chute de neige légère 🌨️', 95 => 'Orage ⛈️',
            ];
            $condition = $translation[$d['weather_code']] ?? 'Conditions variables';
            $pays = (string) ($lieu['country'] ?? '');

            return "Météo à {$lieu['name']} ($pays) : $condition\n"
                . "  🌡 Température : {$d['temperature_2m']}°C\n"
                . "  💧 Humidité    : {$d['relative_humidity_2m']}%\n"
                . "  💨 Vent        : {$d['wind_speed_10m']} km/h";
        } catch (\Throwable $e) {
            return "⚠️ Impossible de récupérer la météo ({$e->getMessage()}).";
        }
    }

    /** Wikipedia summary (REST API, French version). */
    public static function toolWiki(string $topic): string
    {
        $url = 'https://fr.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $topic));
        try {
            [, $res] = self::get_json($url, 5, ['User-Agent' => 'Linuxtricks/1.0']);
            $text = is_array($res) ? ($res['extract'] ?? '') : '';
            if (!$text) {
                return "Aucune page Wikipédia trouvée pour « $topic ».";
            }
            return $text;
        } catch (\Throwable $e) {
            return "⚠️ Erreur lors de la recherche Wikipédia ({$e->getMessage()}).";
        }
    }

    /** Full content of a Wikipedia page (Action API, plain text). */
    public static function toolWikiFull(string $topic): string
    {
        $url = 'https://fr.wikipedia.org/w/api.php?' . http_build_query([
            'action' => 'query', 'prop' => 'extracts', 'explaintext' => 1,
            'titles' => $topic, 'format' => 'json', 'redirects' => 1,
        ]);
        try {
            [, $data] = self::get_json($url, 5, ['User-Agent' => 'Linuxtricks/1.0']);
            $pages = $data['query']['pages'] ?? [];
            if (!is_array($pages) || $pages === []) {
                return "Aucune page Wikipédia trouvée pour « $topic ».";
            }
            $page = reset($pages);
            if (!is_array($page) || isset($page['missing'])) {
                return "Aucune page Wikipédia trouvée pour « $topic ».";
            }
            $text = $page['extract'] ?? '';
            if (!$text) {
                return "Le contenu de la page « $topic » est vide.";
            }
            return $text;
        } catch (\Throwable $e) {
            return "⚠️ Erreur lors de la recherche Wikipédia ({$e->getMessage()}).";
        }
    }

    /** Real-time currency conversion (open.er-api.com, no key). */
    public static function toolCurrency(float $amount, string $fromCurrency, string $toCurrency): string
    {
        $de = strtoupper($fromCurrency);
        $vers = strtoupper($toCurrency);
        try {
            [, $res] = self::get_json("https://open.er-api.com/v6/latest/$de", 5);
            if (!is_array($res) || ($res['result'] ?? '') !== 'success') {
                return "⚠️ Devise source « $fromCurrency » non reconnue.";
            }
            $rate = $res['rates'][$vers] ?? null;
            if ($rate === null) {
                return "⚠️ Devise cible « $toCurrency » non reconnue.";
            }
            $result = $amount * (float) $rate;
            return "💱 $amount $de = " . number_format($result, 2) . " $vers\n"
                . "  Taux : 1 $de = " . number_format((float) $rate, 4) . " $vers";
        } catch (\Throwable $e) {
            return "⚠️ Erreur de conversion monétaire ({$e->getMessage()}).";
        }
    }

    /** Web search via DuckDuckGo (public HTML endpoint, no dependency). */
    public static function toolDuckDuckGo(string $query, int $numResults = 5): string
    {
        $numResults = min(max(1, $numResults), 10);
        $url = 'https://html.duckduckgo.com/html/?q=' . rawurlencode($query);
        try {
            [, , $raw] = Http::request('GET', $url, null, 15, [
                'User-Agent' => self::USER_AGENT_BROWSER,
                'Accept' => 'text/html',
            ]);
            $results = self::ddgParser($raw);
            if ($results === []) {
                return 'Aucun résultat trouvé.';
            }
            $results = array_slice($results, 0, $numResults);
            $lines = ["**Résultats pour « $query »**\n"];
            foreach ($results as $i => $r) {
                $lines[] = ($i + 1) . ". **" . ($r['titre'] !== '' ? $r['titre'] : 'Sans titre') . "**\n"
                    . "   URL : {$r['url']}\n"
                    . "   {$r['extrait']}\n";
            }
            return implode("\n", $lines);
        } catch (\Throwable $e) {
            return "⚠️ Erreur lors de la recherche : {$e->getMessage()}";
        }
    }

    /**
     * Parses DuckDuckGo HTML into a list of results.
     * @return list<array{titre:string, url:string, extrait:string}>
     */
    private static function ddgParser(string $raw): array
    {
        $results = [];
        if (preg_match_all(
            '/<h2 class="result__title">\s*<a[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s',
            $raw, $mTitres, PREG_SET_ORDER
        ) > 0) {
            if (preg_match_all(
                '/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/s',
                $raw, $mExtraits
            ) > 0) {
                $extraits = $mExtraits[1];
            } else {
                $extraits = [];
            }
            foreach ($mTitres as $i => $m) {
                $results[] = [
                    'titre' => self::cleanHtml($m[2]),
                    'url' => self::ddgDecodeUrl($m[1]),
                    'extrait' => isset($extraits[$i]) ? self::cleanHtml($extraits[$i]) : '',
                ];
            }
        }
        return $results;
    }

    /** Decodes the real URL hidden in the DuckDuckGo redirect link. */
    private static function ddgDecodeUrl(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }
        if (str_contains($href, 'duckduckgo.com/l/')) {
            $query = (string) parse_url($href, PHP_URL_QUERY);
            parse_str($query, $params);
            return $params['uddg'] ?? '';
        }
        if (!preg_match('#^https?://#i', $href)) {
            $href = 'https://' . $href;
        }
        return $href;
    }

    /** Strips tags and decodes HTML entities from a fragment. */
    private static function cleanHtml(string $s): string
    {
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string) $s);
    }

    /** Downloads a web page and extracts its main text. */
    public static function toolFetchPage(string $url, int $maxChars = 1024000): string
    {
        $maxChars = min(max(1, $maxChars), 1024000);
        try {
            [, , $raw] = Http::request('GET', $url, null, 10, [
                'User-Agent' => self::USER_AGENT_BROWSER,
                'Accept' => 'text/html,application/xhtml+xml',
            ]);
            $text = self::extractTextHtml($raw);
            $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
            $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
            $text = trim($text);
            if (mb_strlen($text, 'UTF-8') > $maxChars) {
                $text = mb_substr($text, 0, $maxChars, 'UTF-8')
                    . "\n\n[… contenu tronqué à $maxChars caractères]";
            }
            return $text !== '' ? $text : 'Page vide ou contenu non extractible.';
        } catch (\Throwable $e) {
            return "⚠️ Erreur lors du chargement de la page : {$e->getMessage()}";
        }
    }

    /**
     * Extracts the readable text of an HTML document: removes irrelevant
     * elements (scripts, navigation, footers...) then rebuilds
     * the text with line breaks at block elements.
     */
    private static function extractTextHtml(string $html): string
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return '';
        }
        foreach (['script', 'style', 'nav', 'footer', 'header', 'aside', 'form', 'noscript', 'svg', 'iframe', 'button'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $el) {
                $el->parentNode?->removeChild($el);
            }
        }
        $corps = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        if ($corps === null) {
            return '';
        }
        return self::textHtml($corps);
    }

    /** Rebuilds the text of a DOM sub-tree with \n at block elements. */
    private static function textHtml(\DOMNode $node): string
    {
        $blockTags = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'tr', 'br',
            'section', 'article', 'header', 'table', 'ul', 'ol', 'blockquote',
            'pre', 'dd', 'dt', 'hr', 'figure', 'figcaption'];
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= $child->nodeValue;
            } elseif ($child instanceof \DOMElement) {
                $out .= self::textHtml($child);
                if (in_array(strtolower($child->tagName), $blockTags, true)) {
                    $out .= "\n";
                }
            }
        }
        return $out;
    }

    /** GET + JSON decoding. @return array{0:int,1:mixed} */
    private static function get_json(string $url, int $timeout, array $headers = []): array
    {
        $h = ['Accept' => 'application/json'];
        foreach ($headers as $k => $v) {
            $h[$k] = $v;
        }
        return Http::request('GET', $url, null, $timeout, $h);
    }

    // ------------------------------------------------------------------
    // Date / time
    // ------------------------------------------------------------------

    /** Local date and time formatted in French. */
    public static function toolDateTime(): string
    {
        $frDays = [
            'Monday' => 'lundi', 'Tuesday' => 'mardi', 'Wednesday' => 'mercredi',
            'Thursday' => 'jeudi', 'Friday' => 'vendredi', 'Saturday' => 'samedi',
            'Sunday' => 'dimanche',
        ];
        $months = [
            'January' => 'janvier', 'February' => 'février', 'March' => 'mars',
            'April' => 'avril', 'May' => 'mai', 'June' => 'juin', 'July' => 'juillet',
            'August' => 'août', 'September' => 'septembre', 'October' => 'octobre',
            'November' => 'novembre', 'December' => 'décembre',
        ];
        $dayName = $frDays[date('l')] ?? date('l');
        $m = $months[date('F')] ?? date('F');
        return "Date : $dayName " . date('d') . " $m " . date('Y') . " — Heure : " . date('H:i:s');
    }

    // ------------------------------------------------------------------
    // System command execution (ffmpeg, ffprobe, yt-dlp)
    // ------------------------------------------------------------------

    /**
     * Runs a system command with a timeout.
     * @param list<string> $cmd
     * @return array{0:int, 1:string, 2:string} [code, stdout, stderr]
     */
    private static function execCommand(array $cmd, int $timeout): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, null);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Impossible de lancer le processus.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        while (true) {
            $status = proc_get_status($proc);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return [$status['exitcode'], $stdout, $stderr];
            }
            if (microtime(true) - $start > $timeout) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                throw new \RuntimeException("timeout après $timeout s");
            }
            usleep(50000);
        }
    }

    /** Checks whether a binary is available in the PATH. */
    public static function binaryAvailable(string $nom): bool
    {
        static $cache = [];
        if (!isset($cache[$nom])) {
            $cache[$nom] = (bool) @exec('command -v ' . escapeshellarg($nom) . ' 2>/dev/null', $o, $rc);
        }
        return $cache[$nom];
    }

    // ------------------------------------------------------------------
    // Audio / video transcription (whisper.cpp + ffmpeg + yt-dlp)
    // ------------------------------------------------------------------

    /**
     * Extracts the audio track of a video file and converts it to mono 16 kHz
     * (whisper.cpp format) via ffmpeg. Removes silences and speeds it up slightly.
     * @return array{0:string, 1:string} [donnees_audio, extension]
     */
    private static function extractAudioFfmpeg(
        ?string $donnees = null,
        string $fileName = 'audio.mp4',
        float $ignoreStart = 0,
        float $ignoreEnd = 0,
        ?string $cheminIn = null,
        string $outputFormat = 'wav',
    ): array {
        $outputFormat = strtolower(trim($outputFormat)) ?: 'wav';
        if (!in_array($outputFormat, ['wav', 'ogg'], true)) {
            $outputFormat = 'wav';
        }

        // If $donnees is an existing file path, use it directly
        if ($cheminIn === null && is_string($donnees) && is_file($donnees)) {
            $cheminIn = $donnees;
        }

        $tempFile = $cheminIn === null;
        if ($cheminIn === null) {
            $suffixe = strtolower('.' . (pathinfo($fileName, PATHINFO_EXTENSION) ?: 'mp4'));
            $tmp = tempnam('/var/tmp', 'hermes_ina_');
            file_put_contents($tmp, (string) $donnees);
            $cheminIn = $tmp . $suffixe;
            rename($tmp, $cheminIn);
        }
        $cheminOut = $cheminIn . '.' . $outputFormat;

        try {
            $cmd = ['ffmpeg', '-y'];
            if ($ignoreStart > 0) {
                $cmd[] = '-ss';
                $cmd[] = (string) $ignoreStart;
            }
            $cmd[] = '-i';
            $cmd[] = $cheminIn;
            if ($ignoreEnd > 0) {
                $dureeTotale = self::fileDurationSeconds($cheminIn);
                $dureeUtile = max($dureeTotale - $ignoreStart - $ignoreEnd, 1);
                $cmd[] = '-t';
                $cmd[] = (string) $dureeUtile;
            }
            $cmd = array_merge($cmd, [
                '-vn', '-ac', '1', '-ar', '16000',
                '-af', 'silenceremove=stop_periods=-1:stop_duration=1:stop_threshold=-40dB,atempo=1.2',
            ]);
            if ($outputFormat === 'ogg') {
                $cmd = array_merge($cmd, ['-c:a', 'libvorbis', '-q:a', '4', '-f', 'ogg', $cheminOut]);
            } else {
                $cmd = array_merge($cmd, ['-f', 'wav', $cheminOut]);
            }

            try {
                [$code, , $stderr] = self::execCommand($cmd, 900);
            } catch (\Throwable $e) {
                if ($e->getMessage() === 'timeout après 900 s') {
                    throw new \RuntimeException('timeout ffmpeg');
                }
                throw $e;
            }
            if ($code !== 0) {
                $err = mb_substr($stderr, -500, null, 'UTF-8');
                throw new \RuntimeException("ffmpeg a échoué : $err");
            }
            $out = (string) file_get_contents($cheminOut);
            return [$out, $outputFormat];
        } finally {
            @unlink($cheminOut);
            if ($tempFile) {
                @unlink($cheminIn);
            }
        }
    }

    /** Duration (seconds) of an audio/video file via ffprobe (0 if unavailable). */
    public static function fileDurationSeconds(string $path): float
    {
        try {
            [, $stdout] = self::execCommand([
                'ffprobe', '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $path,
            ], 60);
            return (float) trim($stdout);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * Transcribes an audio file (or the audio track of a video) via whisper.cpp.
     * @param string $donnees binary content of the file
     */
    public static function transcribeAudio(
        string $donnees,
        string $fileName,
        Config $conf,
        float $ignoreStart = 0,
        float $ignoreEnd = 0,
    ): string {
        try {
            $ext = strtolower('.' . (pathinfo($fileName, PATHINFO_EXTENSION) ?: ''));
            // Conversion via ffmpeg if the file is a video, if a partial
            // excerpt is requested, or if the audio format is not accepted
            // directly by the whisper.cpp server (measured: only
            // .wav/.mp3/.ogg passes; .m4a, .flac, .aiff, .webm… must be
            // normalized).
            if (in_array($ext, self::VIDEO_EXTENSIONS, true) || $ignoreStart > 0 || $ignoreEnd > 0
                || !in_array($ext, self::AUDIO_WHISPER_DIRECTES, true)) {
                try {
                    $outputFormat = $conf->get('whisper', 'audio_format', 'wav');
                    [$donnees, $extSortie] = self::extractAudioFfmpeg(
                        $donnees, $fileName, $ignoreStart, $ignoreEnd, null, $outputFormat,
                    );
                    $fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.' . $extSortie;
                } catch (\Throwable $e) {
                    if ($e->getMessage() === 'timeout après 900 s') {
                        return "⚠️ Délai d'attente dépassé lors du traitement audio (ffmpeg).";
                    }
                    return "⚠️ Erreur lors du traitement audio (ffmpeg) : {$e->getMessage()}";
                }
            }

            $baseUrl = rtrim($conf->get('whisper', 'base_url', 'http://localhost:8081'), '/');
            $endpoint = $conf->get('whisper', 'endpoint', '/inference');
            $fmt = trim($conf->get('whisper', 'response_format', 'json')) ?: 'json';
            $language = trim($conf->get('whisper', 'language', ''));

            $url = $baseUrl . $endpoint;
            $mime = self::mimeType($fileName);
            $champs = ['response_format' => $fmt];
            if ($language !== '') {
                $champs['language'] = $language;
            }
            try {
                [$status, $decode, $raw] = Http::uploadMultipart(
                    $url, $champs, ['nom' => $fileName, 'contenu' => $donnees, 'mime' => $mime, 'fichier' => 'file'], 1200,
                );
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'Erreur réseau')) {
                    return "⚠️ Impossible de contacter le serveur whisper.cpp ";
                }
                throw $e;
            }
            if ($status >= 400) {
                return "⚠️ Erreur HTTP du serveur whisper.cpp : HTTP $status";
            }

            $text = '';
            if ($fmt === 'json') {
                if (is_array($decode)) {
                    $text = (string) ($decode['text'] ?? $decode['transcription'] ?? '');
                } else {
                    $text = $raw;
                }
            } else {
                $text = $raw;
            }
            $text = trim($text);
            return $text !== '' ? $text : "⚠️ Transcription vide (aucun texte détecté dans l'audio).";
        } catch (\Throwable $e) {
            return "⚠️ Erreur lors de la transcription audio : {$e->getMessage()}";
        }
    }

    /** MIME type of a file from its extension. */
    private static function mimeType(string $nom): string
    {
        static $map = [
            '.wav' => 'audio/wav', '.aiff' => 'audio/x-aiff', '.flac' => 'audio/flac',
            '.ogg' => 'audio/ogg', '.mp3' => 'audio/mpeg', '.m4a' => 'audio/mp4',
            '.mp4' => 'video/mp4', '.mkv' => 'video/x-matroska', '.mov' => 'video/quicktime',
            '.avi' => 'video/x-msvideo', '.webm' => 'video/webm',
        ];
        $ext = strtolower('.' . (pathinfo($nom, PATHINFO_EXTENSION) ?: ''));
        return $map[$ext] ?? 'application/octet-stream';
    }

    /** Checks the URL against the [video_url] domaines_autorises whitelist. */
    public static function allowedVideoUrl(string $url, Config $conf): bool
    {
        $raw = $conf->get('video_url', 'domaines_autorises', '');
        $domains = array_values(array_filter(array_map(
            fn ($d) => strtolower(trim($d)),
            explode(',', $raw)
        )));
        if (in_array('all', $domains, true) || in_array('*', $domains, true)) {
            return true;
        }
        if ($domains === []) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        $host = explode('@', $host)[0];
        foreach ($domains as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Transcribes an online video (YouTube, Twitch, Arte...) via yt-dlp + whisper.cpp.
     * Restricted to the domains authorized in the config.
     */
    public static function transcribeVideoUrl(
        string $url,
        Config $conf,
        float $ignoreStart = 0,
        float $ignoreEnd = 0,
    ): string {
        $url = trim($url);
        if ($url === '') {
            return "⚠️ Aucune URL fournie.";
        }
        if (!self::allowedVideoUrl($url, $conf)) {
            $domains = $conf->get('video_url', 'domaines_autorises', '');
            return "⚠️ Ce site n'est pas autorisé pour la transcription vidéo. "
                . "Domaines autorisés : " . ($domains !== '' ? $domains : '(aucun configuré)') . '.';
        }

        $tmpDir = '/var/tmp/hermes_ytdlp_' . bin2hex(random_bytes(6));
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true)) {
            return "⚠️ Impossible de créer un dossier temporaire.";
        }
        $outputPattern = $tmpDir . '/audio.%(ext)s';
        try {
            try {
                [$code, , $stderr] = self::execCommand([
                    'yt-dlp', '-f', 'bestaudio/best', '-o', $outputPattern, $url,
                ], 1800);
            } catch (\Throwable $e) {
                if ($e->getMessage() === 'timeout après 1800 s') {
                    return "⚠️ Délai d'attente dépassé lors du téléchargement de la vidéo (yt-dlp).";
                }
                if (str_contains($e->getMessage(), 'Impossible de lancer')) {
                    return "⚠️ yt-dlp n'est pas installé (ou introuvable dans le PATH).";
                }
                throw $e;
            }
            if ($code !== 0) {
                $err = mb_substr($stderr, -500, null, 'UTF-8');
                return "⚠️ Échec du téléchargement de la vidéo (yt-dlp) : $err";
            }

            $files = array_values(array_filter(glob($tmpDir . '/audio.*') ?: [], 'is_file'));
            if ($files === []) {
                return "⚠️ yt-dlp n'a produit aucun fichier audio exploitable.";
            }
            $cheminAudio = $files[0];
            $fileName = basename($cheminAudio);

            try {
                $outputFormat = $conf->get('whisper', 'audio_format', 'wav');
                [$donnees, $extSortie] = self::extractAudioFfmpeg(
                    null, $fileName, $ignoreStart, $ignoreEnd, $cheminAudio, $outputFormat,
                );
                $fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.' . $extSortie;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'ffmpeg a échoué') || str_contains($e->getMessage(), 'timeout')) {
                    return "⚠️ Erreur lors du traitement audio (ffmpeg) : {$e->getMessage()}";
                }
                return "⚠️ ffmpeg n'est pas installé (ou introuvable dans le PATH).";
            }

            // Audio already normalized/cut: retry without cutting.
            return self::transcribeAudio($donnees, $fileName, $conf);
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    // ------------------------------------------------------------------
    // Image generation (stablediffusion.cpp)
    // ------------------------------------------------------------------

    /**
     * Generates an image via stablediffusion.cpp (OpenAI API /v1/images/generations).
     * Returns a JSON string: {__image_generee__, b64, mime, nom, format}.
     */
    public static function generateImage(
        string $prompt,
        Config $conf,
        string $negativePrompt = '',
        string $size = '',
    ): string {
        try {
            $baseUrl = rtrim($conf->get('image', 'base_url', 'http://localhost:8082'), '/');
            $endpoint = $conf->get('image', 'endpoint', '/v1/images/generations');
            $size = $size !== '' ? $size : $conf->get('image', 'size', '512x512');
            $steps = trim($conf->get('image', 'steps', ''));

            $payload = [
                'prompt' => $prompt, 'size' => $size, 'n' => 1, 'response_format' => 'b64_json',
            ];
            if ($negativePrompt !== '') {
                $payload['negative_prompt'] = $negativePrompt;
            }
            if ($steps !== '') {
                $payload['steps'] = (int) $steps;
            }

            try {
                [$status, $decode, ] = Http::request('POST', $baseUrl . $endpoint, $payload, 600);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'Erreur réseau')) {
                    return "⚠️ Impossible de contacter le serveur stablediffusion.cpp.";
                }
                throw $e;
            }
            if ($status >= 400) {
                return "⚠️ Erreur HTTP du serveur stablediffusion.cpp : HTTP $status";
            }
            $images = is_array($decode) ? ($decode['data'] ?? []) : [];
            if ($images === [] || !isset($images[0]['b64_json'])) {
                return "⚠️ Le serveur de génération d'image n'a retourné aucune image.";
            }
            $b64 = (string) $images[0]['b64_json'];
            $nom = 'image_' . date('Ymd_His') . '.png';
            return json_encode([
                '__image_generee__' => true,
                'b64' => $b64, 'mime' => 'image/png', 'nom' => $nom, 'format' => 'png',
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return "⚠️ Erreur lors de la génération d'image : {$e->getMessage()}";
        }
    }

    // ------------------------------------------------------------------
    // Speech synthesis (TTS) — audio.cpp (OpenAI API /v1/audio/speech)
    // ------------------------------------------------------------------

    /**
     * Speech synthesis of a text. Not an LLM tool: called from the interface.
     * @return array{0:string, 1:string} [donnees_audio, mime]
     */
    public static function generateTts(string $text, Config $conf): array
    {
        $baseUrl = rtrim($conf->get('audio', 'base_url', 'http://localhost:8083'), '/');
        $endpoint = $conf->get('audio', 'endpoint', '/v1/audio/speech');
        $model = trim($conf->get('audio', 'model', ''));
        $voice = trim($conf->get('audio', 'voice', ''));
        $language = trim($conf->get('audio', 'language', ''));

        $payload = ['input' => $text];
        if ($model !== '') {
            $payload['model'] = $model;
        }
        if ($voice !== '') {
            $payload['voice'] = $voice;
        }
        if ($language !== '') {
            $payload['language'] = $language;
        }

        $url = $baseUrl . $endpoint;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 900,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            throw new \RuntimeException("Erreur réseau TTS : $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($status >= 400) {
            $msg = "Erreur HTTP TTS : HTTP $status";
            if (!empty($raw)) {
                $msg .= " - " . mb_substr(trim($raw), 0, 500, 'UTF-8');
            }
            throw new \RuntimeException($msg);
        }
        $mime = trim(explode(';', $mime)[0]) ?: 'audio/mpeg';
        return [(string) $raw, $mime];
    }
    // ------------------------------------------------------------------
    // File generation (txt, md, csv, pdf, docx, odt, xlsx, ods, pptx, odp)
    // ------------------------------------------------------------------

    /**
     * Generates a downloadable file. Returns a JSON string:
     * {__fichier_genere__, b64, mime, nom, format, telechargement_url}.
     * The file is saved on disk in data/generated_files/
     * with a unique identifier to avoid duplicates.
     * A {id}.meta metadata file stores user_id and conversation_id
     * to check download rights.
     */
    public static function generateFile(
        string $content,
        string $format,
        string $fileName,
        int $userId = 0,
        string $convId = '',
    ): string {
        $fmt = strtolower(ltrim($format, '.'));
        if (!str_contains($fileName, '.')) {
            $fileName .= '.' . $fmt;
        }
        try {
            if (in_array($fmt, ['txt', 'md', 'csv'], true)) {
                $data = $content;
                $mime = match ($fmt) {
                    'txt' => 'text/plain',
                    'md' => 'text/markdown',
                    default => 'text/csv',
                };
            } elseif ($fmt === 'pdf') {
                $data = DocumentGenerator::generate($content, 'pdf');
                $mime = 'application/pdf';
            } elseif ($fmt === 'docx') {
                $data = DocumentGenerator::generate($content, 'docx');
                $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            } elseif ($fmt === 'odt') {
                $data = DocumentGenerator::generate($content, 'odt');
                $mime = 'application/vnd.oasis.opendocument.text';
            } elseif ($fmt === 'xlsx') {
                $data = DocumentGenerator::generate($content, 'xlsx');
                $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } elseif ($fmt === 'ods') {
                $data = DocumentGenerator::generate($content, 'ods');
                $mime = 'application/vnd.oasis.opendocument.spreadsheet';
            } elseif ($fmt === 'pptx') {
                $data = DocumentGenerator::generate($content, 'pptx');
                $mime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
            } elseif ($fmt === 'odp') {
                $data = DocumentGenerator::generate($content, 'odp');
                $mime = 'application/vnd.oasis.opendocument.presentation';
            } else {
                return "⚠️ Format « $format » non supporté. Formats disponibles : txt, md, csv, pdf, docx, odt, xlsx, ods, pptx, odp";
            }
            $b64 = base64_encode($data);

            // Save on disk with a unique ID to avoid duplicates
            $telechargementUrl = '';
            $repertoireGen = __DIR__ . '/../data/generated_files';
            if (!is_dir($repertoireGen)) {
                @mkdir($repertoireGen, 0775, true);
            }
            if (is_dir($repertoireGen)) {
                $idUnique = bin2hex(random_bytes(8));
                if ($idUnique === '') {
                    return "⚠️ Impossible de générer un identifiant unique pour le fichier.";
                }
                $nomBase = basename($fileName);
                $cheminFichier = $repertoireGen . '/' . $idUnique . '_' . $nomBase;
                if (file_put_contents($cheminFichier, $data) !== false) {
                    // Store the access-control metadata
                    $meta = json_encode([
                        'user_id' => $userId,
                        'conversation_id' => $convId,
                        'nom_original' => $nomBase,
                        'mime' => $mime,
                        'format' => $fmt,
                    ], JSON_UNESCAPED_UNICODE);
                    if (file_put_contents($cheminFichier . '.meta', $meta) === false) {
                        return "⚠️ Erreur lors de l'écriture des métadonnées du fichier.";
                    }
                    $telechargementUrl = '/api/fichiers/' . rawurlencode($idUnique) . '/' . rawurlencode($nomBase);
                } else {
                    return "⚠️ Échec de l'écriture du fichier : $cheminFichier";
                }
            }

            return json_encode([
                '__fichier_genere__' => true,
                'b64' => $b64, 'mime' => $mime, 'nom' => $fileName, 'format' => $fmt,
                'telechargement_url' => $telechargementUrl,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return "⚠️ Erreur lors de la génération du fichier .$fmt : {$e->getMessage()}";
        }
    }

    // ------------------------------------------------------------------
    // Catalog (JSON schema for the LLM)
    // ------------------------------------------------------------------

    /** Tools catalog in OpenAI "tools" format. */
    public static function catalogue(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'toolWeather',
                    'description' => "Donne la météo complète et en temps réel d'une ville (température, humidité, vent, état du ciel).",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => ['type' => 'string', 'description' => 'Nom de la ville (ex : Paris, London, Tokyo).'],
                        ],
                        'required' => ['city'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'toolWiki',
                    'description' => 'Recherche et retourne un résumé Wikipédia sur n\'importe quel sujet.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'topic' => ['type' => 'string', 'description' => 'Sujet à rechercher sur Wikipédia (ex : Tour Eiffel, Albert Einstein).'],
                        ],
                        'required' => ['topic'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'toolCurrency',
                    'description' => 'Convertit un montant d\'une devise vers une autre avec les taux en temps réel.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'amount' => ['type' => 'number', 'description' => 'Valeur à convertir (ex : 100).'],
                            'fromCurrency' => ['type' => 'string', 'description' => 'Code ISO 4217 source (ex : EUR).'],
                            'toCurrency' => ['type' => 'string', 'description' => 'Code ISO 4217 cible  (ex : USD).'],
                        ],
                        'required' => ['amount', 'fromCurrency', 'toCurrency'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'toolDuckDuckGo',
                    'description' => 'Effectue une recherche web via DuckDuckGo et retourne les N premiers résultats (titre, URL, extrait). Utilise cet outil pour répondre à des questions nécessitant des informations récentes ou factuelles.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'La requête de recherche en langage naturel.'],
                            'numResults' => ['type' => 'integer', 'description' => 'Nombre de résultats à retourner (défaut 5, max 10).', 'default' => 5],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'toolFetchPage',
                    'description' => 'Télécharge et extrait le texte principal d\'une page web à partir de son URL. Utile pour lire le contenu complet d\'un article ou d\'une page.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'description' => 'L\'URL complète de la page à récupérer.'],
                            'maxChars' => ['type' => 'integer', 'description' => 'Nombre maximum de caractères à retourner (défaut 1024000).', 'default' => 1024000],
                        ],
                        'required' => ['url'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'toolDateTime',
                    'description' => 'Retourne la date et l\'heure actuelles (horloge locale du serveur).',
                    'parameters' => ['type' => 'object'],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generateFile',
                    'description' => 'Génère un fichier téléchargeable (txt, md, csv, pdf, docx, odt, xlsx, ods, pptx, odp) à partir d\'un contenu textuel. Utilise cet outil dès que l\'utilisateur veut un fichier qu\'il pourra télécharger (exporter, sauvegarder, télécharger, créer un fichier), y compris pour du markdown (md) ou du texte simple : le fichier est alors mis à disposition au téléchargement. Les formats ODF sont les équivalents ouverts des formats Office : odt = docx (document), ods = xlsx (tableur), odp = pptx (présentation) — utilise-les quand l\'utilisateur demande explicitement un fichier ODF / LibreOffice / OpenOffice. Pour une présentation (pptx ou odp), écris le contenu en markdown : le premier titre « # » devient la diapositive de titre, chaque « ## » ou « ### » ouvre une nouvelle diapositive, les listes (- ou 1.) deviennent des puces, et les tableaux en pipes (| a | b |) deviennent des tableaux. Ne te contente jamais d\'afficher le contenu dans la réponse ni de demander à l\'utilisateur de le copier-coller quand un fichier est attendu.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => ['type' => 'string', 'description' => 'Contenu complet à mettre dans le fichier (texte brut ou markdown).'],
                            'format' => ['type' => 'string', 'enum' => ['txt', 'md', 'csv', 'pdf', 'docx', 'odt', 'xlsx', 'ods', 'pptx', 'odp'], 'description' => 'Format du fichier à générer.'],
                            'fileName' => ['type' => 'string', 'description' => 'Nom du fichier sans extension (ex : rapport_meteo, synthese_2024).'],
                        ],
                        'required' => ['content', 'format', 'fileName'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generateImage',
                    'description' => 'Génère une image à partir d\'une description textuelle (prompt) via un serveur stablediffusion.cpp local. Utilise cet outil quand l\'utilisateur demande de créer, dessiner, générer ou illustrer une image.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt' => ['type' => 'string', 'description' => 'Description détaillée de l\'image à générer (en anglais de préférence pour de meilleurs résultats).'],
                            'negativePrompt' => ['type' => 'string', 'description' => 'Éléments à éviter dans l\'image générée (optionnel).'],
                            'size' => ['type' => 'string', 'description' => 'Dimensions de l\'image au format LARGEURxHAUTEUR (ex : 512x512). Optionnel, valeur par défaut définie dans la config.'],
                        ],
                        'required' => ['prompt'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'transcribeAudio',
                    'description' => 'Transcrit en texte le fichier audio ou vidéo joint au message courant (via un serveur whisper.cpp local). À utiliser uniquement lorsque l\'utilisateur a joint un fichier audio/vidéo et souhaite en connaître le contenu dicté (résumé, réponse à une question sur l\'enregistrement, etc.). Ne fonctionne que s\'il y a effectivement un fichier audio/vidéo joint. Si l\'utilisateur demande de retirer une partie du début ou de la fin avec par exemple « enlève les 5 premières minutes », « saute les 2 premières minutes », « coupe la dernière minute »), convertis sa demande en secondes et renseigne ignore_start_seconds et/ou ignore_end_seconds en conséquence.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'ignoreStartSeconds' => ['type' => 'number', 'description' => 'Nombre de secondes à couper au début du fichier avant transcription (0 par défaut). Ex : l\'utilisateur dit « enlève les 5 premières minutes » -> 300.'],
                            'ignoreEndSeconds' => ['type' => 'number', 'description' => 'Nombre de secondes à couper à la fin du fichier avant transcription (0 par défaut). Ex : l\'utilisateur dit « enlève la dernière minute » -> 60.'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'transcribeVideoUrl',
                    'description' => 'Récupère le contenu textuel (transcription) d\'une vidéo en ligne à partir de son URL. IMPORTANT : le modèle n\'a AUCUN moyen de connaître le contenu d\'une vidéo à partir de sa seule URL, dès qu\'un message contient un lien vers une vidéo (YouTube, Twitch, Dailymotion, Arte, etc., peu importe qu\'il soit collé seul ou dans une phrase), cet outil DOIT être appelé en premier pour en récupérer le contenu, AVANT de répondre à la demande de l\'utilisateur (résumer, analyser, traduire, répondre à une question, extraire des infos...), même si l\'utilisateur ne mentionne pas explicitement les mots « transcrire » ou « extraire ». Ne jamais deviner ou halluciner le contenu d\'une vidéo à partir de son URL. Quel que soit le site (YouTube compris), l\'outil télécharge la piste audio et la transcrit via whisper.cpp. Si l\'utilisateur demande de retirer une partie du début ou de la fin (par ex. « enlève les 5 premières minutes »), convertis sa demande en secondes et renseigne ignore_start_seconds et/ou ignore_end_seconds en conséquence.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'description' => 'URL de la vidéo (doit appartenir à un domaine autorisé par le serveur, ex : YouTube, Twitch, Arte).'],
                            'ignoreStartSeconds' => ['type' => 'number', 'description' => 'Secondes à couper au début avant transcription (0 par défaut).'],
                            'ignoreEndSeconds' => ['type' => 'number', 'description' => 'Secondes à couper à la fin avant transcription (0 par défaut).'],
                        ],
                        'required' => ['url'],
                    ],
                ],
            ],
        ];
    }

    /** Display icons of the tools (used in the web interface). */
    public static function icons(): array
    {
        return [
            'toolWeather' => '🌤️ Météo',
            'toolWiki' => '📖 Wikipédia',
            'toolCurrency' => '💱 Change',
            'toolDuckDuckGo' => '🔍 Recherche web',
            'toolFetchPage' => '🌐 Lecture page',
            'toolDateTime' => '🕐 Date & Heure',
            'generateFile' => '💾 Génération fichier',
            'transcribeAudio' => '🎙️ Transcription audio/vidéo',
            'transcribeVideoUrl' => '📡 Transcription vidéo (URL)',
            'generateImage' => '🎨 Génération d\'image',
        ];
    }

    /** List of tools enabled according to the [tools] section of the config. */
    public static function activeTools(Config $conf): array
    {
        $mapping = [
            'enable_meteo' => 'toolWeather',
            'enable_wiki' => 'toolWiki',
            'enable_argent' => 'toolCurrency',
            'enable_duckduckgo' => 'toolDuckDuckGo',
            'enable_recup_page' => 'toolFetchPage',
            'enable_datetime' => 'toolDateTime',
            'enable_generer_fichier' => 'generateFile',
            'enable_transcrire_audio' => 'transcribeAudio',
            'enable_transcrire_video_url' => 'transcribeVideoUrl',
            'enable_generation_image' => 'generateImage',
        ];
        $catalogue = self::catalogue();
        $active = [];
        foreach ($mapping as $key => $nom) {
            if ($conf->getbool('tools', $key, true)) {
                foreach ($catalogue as $o) {
                    if (($o['function']['name'] ?? '') === $nom) {
                        $active[] = $o;
                    }
                }
            }
        }
        return $active;
    }

    /**
     * Central dispatcher: calls the right function according to the name returned by the LLM.
     *
     * @param array<string,mixed> $args tool arguments
     * @param array{nom?:string, donnees?:string}|null $attachedFile attached audio/video file (web)
     */
    public static function executeTool(
        string $nom,
        array $args,
        ?Config $conf = null,
        ?array $attachedFile = null,
        int $userId = 0,
        string $convId = '',
    ): string {
        switch ($nom) {
            case 'toolWeather':
                return self::toolWeather((string) ($args['city'] ?? ''));
            case 'toolWiki':
                return self::toolWiki((string) ($args['topic'] ?? ''));
            case 'toolCurrency':
                return self::toolCurrency(
                    (float) ($args['amount'] ?? 0),
                    (string) ($args['fromCurrency'] ?? ''),
                    (string) ($args['toCurrency'] ?? ''),
                );
            case 'toolDuckDuckGo':
                return self::toolDuckDuckGo((string) ($args['query'] ?? ''), (int) ($args['numResults'] ?? 10));
            case 'toolFetchPage':
                return self::toolFetchPage((string) ($args['url'] ?? ''), (int) ($args['maxChars'] ?? 1024000));
            case 'toolDateTime':
                return self::toolDateTime();
            case 'generateFile':
                return self::generateFile(
                    (string) ($args['content'] ?? ''),
                    (string) ($args['format'] ?? ''),
                    (string) ($args['fileName'] ?? ''),
                    $userId,
                    $convId,
                );
            case 'generateImage':
                if ($conf === null) {
                    $conf = new Config();
                }
                return self::generateImage(
                    (string) ($args['prompt'] ?? ''),
                    $conf,
                    (string) ($args['negativePrompt'] ?? ''),
                    (string) ($args['size'] ?? ''),
                );
            case 'transcribeAudio':
                if ($attachedFile !== null && isset($attachedFile['donnees'], $attachedFile['nom'])) {
                    if ($conf === null) {
                        $conf = new Config();
                    }
                    return self::transcribeAudio(
                        (string) $attachedFile['donnees'],
                        (string) $attachedFile['nom'],
                        $conf,
                        (float) ($args['ignoreStartSeconds'] ?? 0),
                        (float) ($args['ignoreEndSeconds'] ?? 0),
                    );
                }
                return "⚠️ Aucun fichier audio/vidéo joint : cet outil nécessite un fichier attaché depuis l'interface web.";
            case 'transcribeVideoUrl':
                if ($conf === null) {
                    $conf = new Config();
                }
                return self::transcribeVideoUrl(
                    (string) ($args['url'] ?? ''),
                    $conf,
                    (float) ($args['ignoreStartSeconds'] ?? 0) ?: 0,
                    (float) ($args['ignoreEndSeconds'] ?? 0) ?: 0,
                );
        }
        return "⚠️ Outil inconnu : « $nom ».";
    }
}
