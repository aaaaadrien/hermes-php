<?php
/**
 * OIDC / SSO authentication
 * ===========================================
 * In addition to local authentication (userpass), standard OIDC flow
 * (Keycloak, or any IdP exposing .well-known/openid-configuration).
 *
 * Handles the protocol: discovery, authorization URL, code exchange,
 * id_token verification (RS256 signature via JWKS, audience, issuer).
 * Local account resolution (provisioning, session, cookie) is in
 * Auth.php: an OIDC user is treated as a local user once
 * authenticated.
 *
 * No external dependencies: HTTP via curl (Http::request), JWT
 * RS256 signature verification by building the PEM/DER public key from
 * the JWK parameters (n, e) then openssl_verify.
 */

declare(strict_types=1);

namespace Hermes;

class Oidc
{
    /** True if [oidc] enabled = true in hermes.conf. */
    public static function isActive(Config $conf): bool
    {
        return $conf->getbool('oidc', 'enabled', false);
    }

    /** Retrieves the OIDC discovery document. */
    public static function discover(Config $conf): array
    {
        $issuer = $conf->get('oidc', 'issuer');
        $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';
        [, $decoded, ] = Http::request('GET', $url, null, 10);
        if (!is_array($decoded)) {
            throw new OidcException("Impossible de lire le document de découverte OIDC ($issuer).");
        }
        return $decoded;
    }

    /**
     * Self-verifiable anti-CSRF `state` parameter (nonce + timestamp + HMAC),
     * independent of any server session between the outbound and return legs of the flow.
     */
    public static function generateSignedState(string $secret): string
    {
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $timestamp = (string) time();
        $base = "$nonce.$timestamp";
        return $base . '.' . hash_hmac('sha256', $base, $secret);
    }

    public static function verifySignedState(string $state, string $secret, int $maxDurationS = 600): bool
    {
        $parts = explode('.', $state);
        if (count($parts) !== 3) {
            return false;
        }
        [$nonce, $timestamp, $signature] = $parts;
        $expected = hash_hmac('sha256', "$nonce.$timestamp", $secret);
        if (!hash_equals($expected, $signature)) {
            return false;
        }
        if (!is_numeric($timestamp)) {
            return false;
        }
        return time() - (int) $timestamp <= $maxDurationS;
    }

    /**
     * Builds the redirect URL to the IdP (“Connexion SSO” button).
     * Returns null if the discovery document is inaccessible.
     */
    public static function buildAuthorizationUrl(Config $conf): ?string
    {
        try {
            $discovery = self::discover($conf);
        } catch (\Throwable) {
            return null;
        }
        $endpoint = (string) ($discovery['authorization_endpoint'] ?? '');
        if ($endpoint === '') {
            return null;
        }
        $params = [
            'response_type' => 'code',
            'client_id' => $conf->get('oidc', 'client_id'),
            'redirect_uri' => $conf->get('oidc', 'redirect_uri'),
            'scope' => $conf->get('oidc', 'scope', 'openid profile email'),
            'state' => self::generateSignedState($conf->get('oidc', 'client_secret')),
        ];
        return $endpoint . '?' . http_build_query($params, '', '&');
    }
    /**
     * Handles the IdP return (?code=&state=): verifies the state, exchanges the
     * code, verifies the id_token (signature + audience + issuer) and returns
     * the claims ({'sub', 'preferred_username', 'email', ...}).
     *
     * Raises OidcException with a displayable message on failure.
     *
     * @return array<string,mixed>
     */
    public static function processCallback(Config $conf, ?string $code, ?string $state): array
    {
        if ($code === null || $code === '') {
            throw new OidcException('Callback OIDC sans code.');
        }
        $issuer = $conf->get('oidc', 'issuer');
        $clientId = $conf->get('oidc', 'client_id');
        $clientSecret = $conf->get('oidc', 'client_secret');
        $redirectUri = $conf->get('oidc', 'redirect_uri');

        if ($state === null || !self::verifySignedState($state, $clientSecret)) {
            throw new OidcException('État OIDC invalide ou expiré (lien périmé). Merci de relancer la connexion.');
        }

        try {
            $discovery = self::discover($conf);
        } catch (\Throwable $e) {
            throw new OidcException("Impossible de contacter le fournisseur d'identité ($issuer) : " . $e->getMessage());
        }

        // Exchanges the code for the tokens
        try {
            [, $decoded, ] = Http::request(
                'POST',
                (string) $discovery['token_endpoint'],
                null,
                30,
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]),
            );
        } catch (\Throwable $e) {
            throw new OidcException('Échec de l\'échange du code OIDC : ' . $e->getMessage());
        }
        if (!is_array($decoded) || empty($decoded['id_token'])) {
            throw new OidcException('Échec de l\'échange du code OIDC (pas de id_token retourné).');
        }

        $claims = self::verifyIdToken(
            (string) $decoded['id_token'],
            (string) ($discovery['jwks_uri'] ?? ''),
            $issuer,
            $clientId,
        );

        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw new OidcException('id_token sans claim « sub » : connexion impossible.');
        }
        return $claims;
    }

    /**
     * Verifies a JWT id_token: header algorithm, RS256 signature (public
     * key from the JWKS), issuer, audience, expiration.
     *
     * @return array<string,mixed> claims
     */
    public static function verifyIdToken(
        string $jwt,
        string $jwksUri,
        string $expectedIssuer,
        string $expectedAudience,
    ): array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new OidcException('id_token malformé.');
        }
        [$b64head, $b64pay, $b64sig] = $parts;

        $header = self::decodeB64Json($b64head);
        $claims = self::decodeB64Json($b64pay);
        if (!is_array($header) || !is_array($claims)) {
            throw new OidcException('id_token illisible.');
        }
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new OidcException(
                "Algorithme de signature non supporté (attendu RS256, reçu « " . ($header['alg'] ?? '?') . " »)."
            );
        }

        $signature = self::base64urlDecode($b64sig);
        $key = self::getPublicKey($jwksUri, (string) ($header['kid'] ?? ''));
        if (!self::verifySignature($b64head . '.' . $b64pay, $signature, $key)) {
            throw new OidcException('Signature de l\'id_token invalide.');
        }

        if (($claims['iss'] ?? '') !== $expectedIssuer) {
            throw new OidcException('Émetteur (issuer) de l\'id_token inattendu.');
        }
        $aud = $claims['aud'] ?? '';
        $audOk = is_array($aud)
            ? in_array($expectedAudience, $aud, true)
            : $aud === $expectedAudience;
        if (!$audOk) {
            throw new OidcException('Audience (aud) de l\'id_token inattendue.');
        }
        if (isset($claims['exp']) && (int) $claims['exp'] < time()) {
            throw new OidcException('id_token expiré.');
        }
        return $claims;
    }

    /** Fetches the JWKS and extracts the RSA public key matching the kid. */
    private static function getPublicKey(string $jwksUri, string $kid): \OpenSSLAsymmetricKey
    {
        if ($jwksUri === '') {
            throw new OidcException('Aucun jwks_uri dans le document de découverte.');
        }
        [, $decoded, ] = Http::request('GET', $jwksUri, null, 10);
        if (!is_array($decoded) || !isset($decoded['keys'])) {
            throw new OidcException('Document JWKS illisible.');
        }
        foreach ((array) $decoded['keys'] as $jwk) {
            if (is_array($jwk) && ($jwk['kty'] ?? '') === 'RSA' && ($jwk['kid'] ?? '') === $kid) {
                $n = $jwk['n'] ?? null;
                $e = $jwk['e'] ?? null;
                if (is_string($n) && is_string($e) && $n !== '' && $e !== '') {
                    return self::jwkRsaToKey($n, $e);
                }
            }
        }
        throw new OidcException('Clé publique (kid « ' . $kid . " ») introuvable dans le JWKS.");
    }

    /**
     * Builds an RSA public key from JWK n (module) and e (exponent) parameters.
     */
    private static function jwkRsaToKey(string $n, string $e): \OpenSSLAsymmetricKey
    {
        $mod = self::base64urlDecode($n);
        $exp = self::base64urlDecode($e);
        if ($mod === '' || $exp === '') {
            throw new OidcException('Paramètres JWK n/e illisibles (n=' . strlen($n) . ' e=' . strlen($e) . ').');
        }
        // Strip leading zeros
        $mod = ltrim($mod, "\x00");
        $exp = ltrim($exp, "\x00");
        // Add 0x00 if the high bit is set (marks a positive integer)
        $modB = (ord($mod[0]) & 0x80) !== 0 ? "\x00" . $mod : $mod;
        $expB = (ord($exp[0]) & 0x80) !== 0 ? "\x00" . $exp : $exp;

        $intN = chr(0x02) . self::derLen(strlen($modB)) . $modB;
        $intE = chr(0x02) . self::derLen(strlen($expB)) . $expB;
        $rsaSeq = chr(0x30) . self::derLen(strlen($intN . $intE)) . $intN . $intE;
        $bitString = chr(0x03) . self::derLen(strlen("\x00" . $rsaSeq)) . "\x00" . $rsaSeq;
        $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // rsaEncryption
        $spki = chr(0x30) . self::derLen(strlen($alg . $bitString)) . $alg . $bitString;

        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64) . "-----END PUBLIC KEY-----\n";
        $cle = openssl_pkey_get_public($pem);
        if ($cle === false) {
            $err = openssl_error_string();
            throw new OidcException('Impossible de construire la clé publique JWK : ' . $err);
        }
        return $cle;
    }

    private static function derLen(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        $b = '';
        while ($len > 0) {
            $b = chr($len & 0xff) . $b;
            $len >>= 8;
        }
        return chr(0x80 | strlen($b)) . $b;
    }

    private static function derTlv(int $tag, string $content): string
    {
        return chr($tag) . self::derLen(strlen($content)) . $content;
    }

    private static function verifySignature(string $data, string $signature, \OpenSSLAsymmetricKey $key): bool
    {
        return openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function base64urlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $reste = strlen($s) % 4;
        if ($reste === 2) {
            $s .= '==';
        } elseif ($reste === 3) {
            $s .= '=';
        }
        $r = base64_decode($s, true);
        return $r === false ? '' : $r;
    }

    private static function decodeB64Json(string $b64): mixed
    {
        return json_decode(self::base64urlDecode($b64), true);
    }
}
