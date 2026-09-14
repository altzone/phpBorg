<?php
/**
 * phpBorgMailer - Client SMTP minimal (socket, STARTTLS/SSL, AUTH LOGIN)
 *
 * Aucune dependance externe : la machine de backup n'a pas de MTA local.
 * Un echec d'envoi n'est jamais fatal, il est journalise et remonte via false.
 */

namespace phpBorg;

require_once __DIR__ . '/Config.php';

/**
 * Class Mailer
 * @package phpBorg
 */
class Mailer
{
    /** @var resource|null Socket SMTP */
    private $sock = null;

    /** @var array Parametres [smtp] */
    private $cfg;

    /** @var logWriter|null */
    private $log;

    /** @var string Dernier message d'erreur */
    public $error = '';

    /** @var array Transcript du dialogue SMTP (sans les identifiants) */
    private $trace = array();

    /**
     * Mailer constructor.
     * @param logWriter|null $log
     * @param array|null $cfg Surcharge de la section [smtp]
     */
    public function __construct($log = null, $cfg = null) {
        $this->log = $log;
        $this->cfg = $cfg !== null ? $cfg : Config::get('smtp');
    }

    /**
     * Journalise un message si un logger est disponible
     * @param string $level info|warning|error
     * @param string $msg
     * @return void
     */
    private function logMsg($level, $msg) {
        if ($this->log === null) return;
        $this->log->$level($msg, 'MAILER');
    }

    /**
     * Envoie un message
     * @param string $subject
     * @param string $html Corps HTML
     * @param string $text Corps texte alternatif
     * @param string|null $to Destinataires (defaut : config)
     * @return bool
     */
    public function send($subject, $html, $text = '', $to = null) {
        $this->error = '';
        $this->trace = array();

        $host = trim((string)$this->getCfg('host'));
        $from = trim((string)$this->getCfg('from'));
        $rcptRaw = $to !== null ? $to : $this->getCfg('to');

        $rcpts = array();
        foreach (explode(',', (string)$rcptRaw) as $r) {
            $r = trim($r);
            if ($r !== '') $rcpts[] = $r;
        }

        if ($host === '' || $from === '' || empty($rcpts)) {
            $this->error = 'Configuration SMTP incomplete (host, from ou to manquant)';
            $this->logMsg('error', $this->error);
            return false;
        }

        try {
            if (!$this->connect()) return false;
            if (!$this->handshake()) return false;
            if (!$this->authenticate()) return false;

            if (!$this->cmd('MAIL FROM:<' . $from . '>', 250)) return false;
            $accepted = 0;
            foreach ($rcpts as $r) {
                if ($this->cmd('RCPT TO:<' . $r . '>', array(250, 251))) $accepted++;
                else $this->logMsg('warning', "Destinataire refuse: $r ({$this->error})");
            }
            if ($accepted === 0) {
                $this->error = 'Aucun destinataire accepte par le serveur SMTP';
                $this->logMsg('error', $this->error);
                return false;
            }

            if (!$this->cmd('DATA', 354)) return false;
            $data = $this->buildMessage($subject, $html, $text, $from, $rcpts);
            $this->write($data . "\r\n.\r\n");
            if (!$this->expect(250)) return false;

            $this->cmd('QUIT', array(221, 250));
            $this->disconnect();

            $this->logMsg('info', 'Rapport envoye a ' . implode(', ', $rcpts) . " (sujet: $subject)");
            return true;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            $this->logMsg('error', 'Erreur SMTP: ' . $this->error);
            $this->disconnect();
            return false;
        }
    }

    /**
     * Lit une cle de configuration SMTP
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function getCfg($key, $default = '') {
        return isset($this->cfg[$key]) ? $this->cfg[$key] : $default;
    }

    /**
     * Ouvre la connexion TCP
     * @return bool
     */
    private function connect() {
        $host    = (string)$this->getCfg('host');
        $port    = (int)$this->getCfg('port', 587);
        $secure  = strtolower((string)$this->getCfg('secure', 'tls'));
        $timeout = (int)$this->getCfg('timeout', 30);

        $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

        $ctx = stream_context_create(array(
            'ssl' => array(
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ),
        ));

        $errno = 0; $errstr = '';
        $this->sock = @stream_socket_client($target, $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT, $ctx);

        if (!$this->sock) {
            $this->error = "Connexion a $target impossible: $errstr ($errno)";
            $this->logMsg('error', $this->error);
            return false;
        }
        stream_set_timeout($this->sock, $timeout);

        return $this->expect(220);
    }

    /**
     * EHLO, STARTTLS si demande, puis EHLO a nouveau
     * @return bool
     */
    private function handshake() {
        $secure = strtolower((string)$this->getCfg('secure', 'tls'));
        $helo   = $this->heloName();

        if (!$this->cmd('EHLO ' . $helo, 250)) return false;

        if ($secure === 'tls') {
            if (!$this->cmd('STARTTLS', 220)) return false;
            $ok = @stream_socket_enable_crypto($this->sock, true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$ok) {
                $this->error = 'Negociation STARTTLS echouee';
                $this->logMsg('error', $this->error);
                return false;
            }
            // Le serveur peut annoncer d'autres capacites apres TLS
            if (!$this->cmd('EHLO ' . $helo, 250)) return false;
        }
        return true;
    }

    /**
     * AUTH LOGIN si un utilisateur est configure
     * @return bool
     */
    private function authenticate() {
        $user = (string)$this->getCfg('user');
        $pass = (string)$this->getCfg('pass');
        if ($user === '') return true;

        if (!$this->cmd('AUTH LOGIN', 334)) return false;
        // Les identifiants ne sont jamais ajoutes au transcript
        $this->write(base64_encode($user) . "\r\n");
        if (!$this->expect(334)) return false;
        $this->write(base64_encode($pass) . "\r\n");
        if (!$this->expect(235)) {
            $this->error = 'Authentification SMTP refusee: ' . $this->error;
            $this->logMsg('error', $this->error);
            return false;
        }
        return true;
    }

    /**
     * Nom annonce dans EHLO
     * @return string
     */
    private function heloName() {
        $h = gethostname();
        if ($h === false || $h === '') $h = 'localhost';
        // EHLO attend un FQDN ou un literal ; un nom court passe chez la plupart des MTA
        return $h;
    }

    /**
     * Envoie une commande et verifie le code de retour
     * @param string $cmd
     * @param int|array $expect
     * @return bool
     */
    private function cmd($cmd, $expect) {
        $this->write($cmd . "\r\n");
        $this->trace[] = '> ' . $cmd;
        return $this->expect($expect);
    }

    /**
     * Ecrit sur le socket
     * @param string $data
     * @return void
     * @throws \Exception
     */
    private function write($data) {
        if (!is_resource($this->sock)) throw new \Exception('Socket SMTP fermee');
        $written = @fwrite($this->sock, $data);
        if ($written === false) throw new \Exception('Ecriture SMTP impossible');
    }

    /**
     * Lit la reponse serveur (gere les reponses multi-lignes) et compare le code
     * @param int|array $expect
     * @return bool
     */
    private function expect($expect) {
        if (!is_array($expect)) $expect = array($expect);

        $response = '';
        $code = 0;
        while (is_resource($this->sock) && ($line = fgets($this->sock, 1024)) !== false) {
            $response .= $line;
            // "250-..." = suite, "250 ..." = derniere ligne
            if (strlen($line) >= 4 && $line[3] === ' ') {
                $code = (int)substr($line, 0, 3);
                break;
            }
            $meta = stream_get_meta_data($this->sock);
            if ($meta['timed_out']) {
                $this->error = 'Timeout en lecture SMTP';
                return false;
            }
        }

        $response = trim($response);
        if ($response !== '') $this->trace[] = '< ' . $response;

        if (in_array($code, $expect, true)) return true;

        $this->error = $response !== '' ? $response : 'Aucune reponse du serveur SMTP';
        return false;
    }

    /**
     * Ferme la connexion
     * @return void
     */
    private function disconnect() {
        if (is_resource($this->sock)) @fclose($this->sock);
        $this->sock = null;
    }

    /**
     * Construit le message MIME (multipart/alternative)
     * @param string $subject
     * @param string $html
     * @param string $text
     * @param string $from
     * @param array $rcpts
     * @return string
     */
    private function buildMessage($subject, $html, $text, $from, $rcpts) {
        $fromName = (string)$this->getCfg('from_name', 'phpBorg');
        $boundary = 'phpborg_' . bin2hex(random_bytes(12));

        if ($text === '') $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

        $h  = 'Date: ' . date('r') . "\r\n";
        $h .= 'From: ' . $this->encodeHeader($fromName) . ' <' . $from . ">\r\n";
        $h .= 'To: ' . implode(', ', $rcpts) . "\r\n";
        $h .= 'Subject: ' . $this->encodeHeader($subject) . "\r\n";
        $h .= 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->heloName() . ">\r\n";
        $h .= "MIME-Version: 1.0\r\n";
        $h .= 'X-Mailer: phpBorg' . "\r\n";
        $h .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";

        $b  = "\r\n--" . $boundary . "\r\n";
        $b .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $b .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $b .= quoted_printable_encode($this->fixNewlines($text)) . "\r\n";
        $b .= '--' . $boundary . "\r\n";
        $b .= "Content-Type: text/html; charset=UTF-8\r\n";
        $b .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $b .= quoted_printable_encode($this->fixNewlines($html)) . "\r\n";
        $b .= '--' . $boundary . "--\r\n";

        return $h . $this->dotStuff($b);
    }

    /**
     * Encode un en-tete en UTF-8 si necessaire (RFC 2047)
     * @param string $value
     * @return string
     */
    private function encodeHeader($value) {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) return $value;
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * Normalise les fins de ligne en CRLF
     * @param string $s
     * @return string
     */
    private function fixNewlines($s) {
        return preg_replace('/\r\n|\r|\n/', "\r\n", $s);
    }

    /**
     * Protege les lignes commencant par un point (RFC 5321 section 4.5.2)
     * @param string $s
     * @return string
     */
    private function dotStuff($s) {
        return preg_replace('/^\./m', '..', $s);
    }

    /**
     * Transcript du dialogue SMTP, pour diagnostic
     * @return array
     */
    public function trace() {
        return $this->trace;
    }
}
