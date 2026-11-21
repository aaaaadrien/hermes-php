<?php
/**
 * Configuration
 * =============================
 * Reading the PHP configuration file (data/config.php).
 */

declare(strict_types=1);

namespace Hermes;

class Config
{
    /** @var array<string, array<string, string>> */
    private array $raw = [];

    public function __construct(string $path = 'data/config.php')
    {
        if (!is_file($path)) {
            $path = HERMES_ROOT . '/' . $path;
        }
        if (!is_file($path)) {
            $exemple = HERMES_ROOT . '/ressources/config.php.example';
            $target = HERMES_ROOT . '/' . $path;
            if (is_file($exemple) && is_writable(dirname($target))) {
                copy($exemple, $target);
                $path = $target;
            } else {
                throw new \RuntimeException("Fichier de configuration introuvable : $path");
            }
        }
        $this->raw = require $path;
        if (!is_array($this->raw)) {
            throw new \RuntimeException("Le fichier de configuration doit retourner un tableau : $chemin");
        }
    }

    public function get(string $section, string $key, ?string $fallback = null): string
    {
        $v = $this->raw[$section][$key] ?? null;
        if ($v === null) return (string) $fallback;
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_int($v) || is_float($v)) return (string) $v;
        return (string) $v;
    }

    public function getint(string $section, string $key, int $fallback): int
    {
        $v = trim($this->get($section, $key, (string) $fallback));
        return $v === '' ? $fallback : (int) $v;
    }

    public function getfloat(string $section, string $key, float $fallback): float
    {
        $v = trim($this->get($section, $key, (string) $fallback));
        return $v === '' ? $fallback : (float) $v;
    }

    public function getbool(string $section, string $key, bool $fallback = false): bool
    {
        $v = strtolower(trim($this->get($section, $key, $fallback ? 'true' : 'false')));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
}
