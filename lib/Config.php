<?php
/**
 * phpBorgConfig - Chargement de la configuration depuis conf/phpborg.conf
 */

namespace phpBorg;

/**
 * Class Config
 * @package phpBorg
 */
class Config
{
    /** @var array|null Configuration chargee (cache statique) */
    private static $data = null;

    /** @var string Chemin du fichier de configuration */
    private static $file = null;

    /**
     * Valeurs par defaut, utilisees quand une cle est absente du fichier.
     * @var array
     */
    private static $defaults = array(
        'general' => array(
            // vide = fuseau du systeme (/etc/timezone)
            'timezone' => '',
        ),
        'db' => array(
            'host'    => '127.0.0.1',
            'user'    => 'phpborg',
            'pass'    => '',
            'name'    => 'phpborg',
            'charset' => 'utf8',
        ),
        'smtp' => array(
            'host'      => '',
            'port'      => 587,
            'secure'    => 'tls',
            'user'      => '',
            'pass'      => '',
            'from'      => '',
            'from_name' => 'phpBorg',
            'to'        => '',
            'timeout'   => 30,
        ),
        'backup' => array(
            'retries'     => 3,
            'retry_delay' => 30,
            'parallel'    => 1,
            'repo_path'   => '/data/backups',
        ),
        'alert' => array(
            'subject_prefix'     => '[phpBorg]',
            'send_on_success'    => 1,
            'stale_days'         => 2,
            'max_full_age_hours' => 26,
            'disk_warn_percent'  => 90,
        ),
    );

    /**
     * Charge le fichier de configuration (une seule fois par process)
     * @param string|null $file Chemin alternatif du fichier
     * @return array
     */
    public static function load($file = null) {
        if (self::$data !== null && $file === null) return self::$data;

        if ($file === null) $file = dirname(__DIR__) . '/conf/phpborg.conf';
        self::$file = $file;

        $parsed = array();
        if (is_readable($file)) {
            $ini = parse_ini_file($file, true, INI_SCANNER_TYPED);
            if (is_array($ini)) $parsed = $ini;
        }

        // Fusion section par section avec les valeurs par defaut
        $merged = self::$defaults;
        foreach ($parsed as $section => $values) {
            if (!is_array($values)) continue;
            $merged[$section] = array_merge(
                isset($merged[$section]) ? $merged[$section] : array(),
                $values
            );
        }

        self::$data = $merged;
        return self::$data;
    }

    /**
     * Recupere une valeur de configuration
     * @param string $section
     * @param string|null $key Si null, retourne la section entiere
     * @param mixed $default
     * @return mixed
     */
    public static function get($section, $key = null, $default = null) {
        $cfg = self::load();
        if (!isset($cfg[$section])) return $default;
        if ($key === null) return $cfg[$section];
        return isset($cfg[$section][$key]) ? $cfg[$section][$key] : $default;
    }

    /**
     * Indique si le fichier de configuration existe reellement
     * @return bool
     */
    public static function exists() {
        self::load();
        return self::$file !== null && is_readable(self::$file);
    }

    /**
     * Chemin du fichier de configuration utilise
     * @return string|null
     */
    public static function file() {
        self::load();
        return self::$file;
    }
}
