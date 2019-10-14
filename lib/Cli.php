<?php

namespace phpBorg;

/**
 * Class Cli
 * @package phpBorg
 */
class Cli
{

    /**
     * @param int $inputSeconds
     * @return string
     */
    function secondsToTime($inputSeconds)
    {
        $secondsInAMinute = 60;
        $secondsInAnHour = 60 * $secondsInAMinute;
        $secondsInADay = 24 * $secondsInAnHour;

        // Extract days
        $days = floor($inputSeconds / $secondsInADay);

        // Extract hours
        $hourSeconds = $inputSeconds % $secondsInADay;
        $hours = floor($hourSeconds / $secondsInAnHour);

        // Extract minutes
        $minuteSeconds = $hourSeconds % $secondsInAnHour;
        $minutes = floor($minuteSeconds / $secondsInAMinute);

        // Extract the remaining seconds
        $remainingSeconds = $minuteSeconds % $secondsInAMinute;
        $seconds = ceil($remainingSeconds);

        // Format and return
        $timeParts = [];
        $sections = [
            'd' => (int)$days,
            'h' => (int)$hours,
            'm' => (int)$minutes,
            's' => (int)$seconds,
        ];

        foreach ($sections as $name => $value) {
            if ($value > 0) {
                $timeParts[] = $value . '' . $name . ($value == 1 ? '' : '');
            }
        }

        return implode('', $timeParts);
    }

    /**
     * @param $repo
     * @return array|bool|int
     */
    function parse($repo)
    {
        if (file_exists("$repo/conf/borg.conf")) {

            $exclude = $backup = "";
            $config = parse_ini_file("$repo/conf/borg.conf");
            $ex = explode(',', $config['exclude']);
            foreach ($ex as $zz) {
                $exclude .= "--exclude " . trim($zz) . " ";
            }
            $config['exclude'] = $exclude;
            $back = explode(',', $config['backup']);
            foreach ($back as $yy) {
                $backup .= trim($yy) . " ";
            }
            $config['backup'] = $backup;
            return $config;
        } else {
            return 0;
        }
    }

    /**
     * @param $cmd
     * @param string $input
     * @return array
     */
    function my_exec($cmd, $input = '')
    {
        $proc = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $rtn = proc_close($proc);
        return array('stdout' => $stdout,
            'stderr' => $stderr,
            'return' => $rtn
        );
    }

    /**
     * @param $keepday
     * @param $srv
     * @return array
     */
    function prune($keepday, $srv)
    {
        $deleted = NULL;
        $keepday = $keepday - 1;
        $e = $this->my_exec("/usr/bin/borg prune --save-space --force --list --keep-daily $keepday /data0/backup/$srv/backup");
        return ['return' => $e['return'], 'stdout' => $e['stdout'], 'stderr' => $e['stderr']];
    }

    /**
     * @param $file
     * @return array
     */
    function parselog($file)
    {
        $e = $this->my_exec("/usr/bin/borg info $file --json");
        $json = $e['stdout'];
        if ($e['return'] == 0) {
            $var = json_decode($json, true);
            $cmdline = "";
            $commandline = $var['archives'][0]['command_line'];
            $duration = $var['archives'][0]['duration'];
            $start = $var['archives'][0]['start'];
            $end = $var['archives'][0]['end'];
            $id = $var['archives'][0]['id'];
            $csize = $var['archives'][0]['stats']['compressed_size'];
            $dsize = $var['archives'][0]['stats']['deduplicated_size'];
            $nbfile = $var['archives'][0]['stats']['nfiles'];
            $osize = $var['archives'][0]['stats']['original_size'];
            $total_chunks = $var['cache']['stats']['total_chunks'];
            $total_csize = $var['cache']['stats']['total_csize'];
            $total_size = $var['cache']['stats']['total_size'];
            $total_unique_chunks = $var['cache']['stats']['total_unique_chunks'];
            $unique_csize = $var['cache']['stats']['unique_csize'];
            $unique_size = $var['cache']['stats']['unique_size'];
            $encryption = $var['encryption']['mode'];
            $location = $var['repository']['location'];
            $last_modified = $var['repository']['last_modified'];

            foreach ($commandline as $value) {
                $cmdline .= "$value ";
            }
            return array('archive_id' => $id, 'dur' => $duration, 'start' => $start, 'end' => $end, 'csize' => $csize, 'dsize' => $dsize, 'nfiles' => $nbfile, 'osize' => $osize, 'ttchunks' => $total_chunks, 'ttcsize' => $total_csize, 'ttsize' => $total_size, 'ttuchunks' => $total_unique_chunks, 'ucsize' => $unique_csize, 'usize' => $unique_size, 'location' => $location, 'modified' => $last_modified, 'encryption' => $encryption, 'error' => NULL);
        } else return array('error' => 1, 'stderr' => $e['stderr'], 'stdout' => $e['stdout']);
    }
}
