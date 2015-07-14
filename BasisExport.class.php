<?php
/**
 *
 * Basis Data Export
 *
 * Utility that exports and saves into a file sensor data from the Basis web site for specified accounts.
 * You can learn more about Basis at http://www.mybasis.com/
 *
 * Original @author Bob Troia <bob@quantifiedbob.com>
 * @link   http://www.quantifiedbob.com
 *
 * Modified by @author Leonard Ng'eno
 * 
*/

class BasisExport
{
    // Enable/disable debugging
    public $debug = false;

    // Access token
    private $access_token;

    // Acceptable export formats
    private $export_formats = array('json', 'csv');

    // These settings should be left as-is
    private $export_interval = 60; // data granularity (60 = 1 reading per minute)

    // Used for cURL cookie storage (needed for api access)
    private $cookie_jar;
    
    public function __construct()
    {
        // Location to store cURL's CURLOPT_COOKIEJAR (for access_token cookie)
        $this->cookie_jar = dirname(__FILE__) . '/cookie.txt';
    }

    /**
    * Attempts to login/authenticate to Basis server
    * @return bool
    * @throws Exception
    */
    function doLogin($username, $password)
    {
        $login_data = array(
            'username' => $username,
            'password' => $password,
        );

        // Test configuration
        if ($this->debug) {
            $this->testConfig();
        }

        // Initialize the cURL resource and make login request
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => 'https://app.mybasis.com/login',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $login_data,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => 1,
            CURLOPT_COOKIESESSION => true,
            CURLOPT_COOKIEJAR => $this->cookie_jar
        ));
        $result = curl_exec($ch);

        if($result === false) {
            // A cURL error occurred
            throw new Exception('ERROR: cURL error - ' . curl_error($ch) . "\n");
            return false;
        }

        curl_close($ch);

        // Make sure login was successful and save access_token cookie for api requests.
        preg_match('/^Set-Cookie:\s*([^;]*)/mi', $result, $m);
        if (empty($m)) {
            throw new Exception('ERROR: Unable to login! Check your username and password.');
            return false;
        } else {
            parse_str($m[1], $cookies);
            if (empty($cookies['access_token'])) {
                throw new Exception('ERROR: Unable to get an access token!');
                return false;
            } else {
                $this->access_token = $cookies['access_token'];
                if ($this->debug) {
                    echo 'access_token cookie: ' . $this->access_token . "\n";
                }
            }
        }

    } 

    /**
    * Retrieve user's biometric readings for given date and save to file
    * @param string $export_start_date Date in YYYY-MM-DD format
     * @param string $export_end_date Date in YYYY-MM-DD format
    * @param string $export_format Export type (json,csv)
    * @return bool
    * @throws Exception
    */
    function getMetrics($export_start_date = '', $export_end_date = '', $export_format = 'json')
    {
        // Check for YYYY-MM-DD start date format, else throw error
        $export_start_date = $this->getStartDate($export_start_date);
        if (!$this->isValidDate($export_start_date)) {
            throw new Exception('ERROR: Invalid date -  ' . $export_start_date . "\n");
            return false;
        }
        
        // Check for YYYY-MM-DD end date format, else throw error
        $export_end_date = $this->getEndDate($export_end_date);
        if (!$this->isValidDate($export_end_date)) {
            throw new Exception('ERROR: Invalid date -  ' . $export_end_date . "\n");
            return false;
        }
        
        //get end of day of end_date
        if($export_end_date == date('Y-m-d', strtotime('now', time()))) {
            $export_end_date_mod = strtotime('now', time());
        } else {
            $end_date = new DateTime($export_end_date);
            $end_date = $end_date->modify('+1 day');
            $export_end_date_mod = strtotime($end_date->format('Y-m-d')) - 1;
        }

        // Make sure export format is valid
        if (!in_array($export_format, $this->export_formats)) {
            throw new Exception('ERROR: Invalid export format -  ' . $export_format . "\n");
            return false;
        }
        
        if ($export_format == 'csv') {   
            // Save results as .csv file
            $file = dirname(__FILE__) . '/data/basis-data-' . $export_start_date . '-' . $export_end_date . '-metrics.csv';
            $fp = fopen($file, 'w');
            if(!$fp) {
                throw new Exception("ERROR: Could not save data to file $file!");
                return false;
            }
            fputcsv($fp, array('username', 'user_id', 'timestamp', 'heartrate', 'steps', 'calories', 'gsr', 'skintemp', 'airtemp'));
            fclose($fp);
        } else {
            // Save results as .json file
            $file = dirname(__FILE__) . '/data/basis-data-' . $export_start_date . '-' . $export_end_date . '-metrics.json';
        }
        
        $accounts = $this->getAccounts();
        foreach ($accounts as $user=>$pword) {
           echo "Get biometrics data for user " . $user . "\n";     
            try {
                $this->doLogin($user, $pword);
            } catch (Exception $e) {
                echo 'Caught exception: ',  $e->getMessage(), "\n";
                return false;
            }
            
            // Request data from Basis for selected dates. Note we're requesting all available data.
            $metrics_url = 'https://app.mybasis.com/api/v1/metrics/me?'
                . 'start=' .strtotime($export_start_date)
                .'&end=' .$export_end_date_mod
                . '&heartrate=true'
                . '&steps=true'
                . '&calories=true'
                . '&gsr=true'
                . '&skin_temp=true'
                . '&air_temp=true';
                
            // Initialize the cURL resource and make api request
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $metrics_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_COOKIEFILE => $this->cookie_jar
            ));
            $result = curl_exec($ch);
            $response_code = curl_getinfo ($ch, CURLINFO_HTTP_CODE);

            if ($response_code == '401') {
                throw new Exception("ERROR: Unauthorized!\n");
                return false;
            }
    
            curl_close($ch);  
            
            // Parse data from JSON response
            $json = json_decode($result, true);
            $report_date = $json['starttime']; // report date, as UNIX timestamp
            $heartrates = $json['metrics']['heartrate']['values'];
            $steps = $json['metrics']['steps']['values'];
            $calories = $json['metrics']['calories']['values'];
            $gsrs = $json['metrics']['gsr']['values'];
            $skintemps = $json['metrics']['skin_temp']['values'];
            $airtemps = $json['metrics']['air_temp']['values'];
               
            if ($export_format == 'csv') {   
                $fp = fopen($file, 'a');
                if(!$fp) {
                    throw new Exception("ERROR: Could not save data to file $file!");
                    return false;
                }
                $study_ids = $this->getStudyIds();
                for ($i=0; $i<count($heartrates); $i++) {
                    // HH:MM:SS timestamp
                    $timestamp = strftime("%Y-%m-%d %H:%M:%S", mktime(0, 0, $i*$this->export_interval, date("n", $report_date), date("j", $report_date), date("Y", $report_date)));                    
                    if (!($heartrates[$i] === NULL) && !($gsrs[$i] === NULL)) {
                        $row = array($user, $study_ids[$user], $timestamp, $heartrates[$i], $steps[$i], $calories[$i], $gsrs[$i], $skintemps[$i], $airtemps[$i]);
                        // Add row to csv file
                        fputcsv($fp, $row);
                    }
                }
                fclose($fp);
            } else {
                if (!file_put_contents($file, $result)) {
                    throw new Exception("ERROR: Could not save data to file $file!");
                    return false;
                }
            }            
        }

    }

   /**
    * Retrieve user's sleep data for given date and save to file
    * @param string $export_start_date Date in YYYY-MM-DD format
    * @param string $export_end_date Date in YYYY-MM-DD format
    * @param string $export_format Export type (json,csv)
    * @return bool
    * @throws Exception
    */
    function getSleep($export_start_date = '', $export_end_date = '', $export_format = 'json')
    {
        // Check for YYYY-MM-DD start date format, else throw error
        $export_start_date = $this->getStartDate($export_start_date);
        if (!$this->isValidDate($export_start_date)) {
            throw new Exception('ERROR: Invalid date -  ' . $export_start_date . "\n");
            return false;
        }
        
        // Check for YYYY-MM-DD end date format, else throw error
        $export_end_date = $this->getEndDate($export_end_date);
        if (!$this->isValidDate($export_end_date)) {
            throw new Exception('ERROR: Invalid date -  ' . $export_end_date . "\n");
            return false;
        }

        // Make sure export format is valid
        if (!in_array($export_format, $this->export_formats)) {
            throw new Exception('ERROR: Invalid export format -  ' . $export_format . "\n");
            return false;
        }
     
        if ($export_format == 'csv') {
            // Save results as .csv file
            $file = dirname(__FILE__) . '/data/basis-data-' . $export_start_date . '-' .$export_end_date . '-sleep.csv';
            $fp = fopen($file, 'w');
            if(!$fp) {
                throw new Exception("ERROR: Could not save data to file $file!");
                return false;
            }
            fputcsv($fp, array(
                'username', 'user_id', 'start time', 'start time ISO', 'start time timezone', 'start time offset',
                'end time', 'end time ISO', 'end time timezone', 'end time offset',
                'light mins', 'deep mins', 'rem mins', 'interruption mins', 'unknown mins', 'interruptions', 
                'toss turns', 'type', 'actual seconds', 'calories', 'heart rate avg', 'heart rate min', 
                'heart rate max', 'state', 'version', 'id'
                )
            );
            fclose($fp);
        } else {
            // Save results as .json file
            $file = dirname(__FILE__) . '/data/basis-data-' . $export_start_date . '-' . $export_end_date . '-sleep.json';
        }
        
        $accounts = $this->getAccounts();
        foreach ($accounts as $user=>$pword) {
           echo "Get sleep data for user " . $user . "\n";     
            try {
                $this->doLogin($user, $pword);
            } catch (Exception $e) {
                echo 'Caught exception: ',  $e->getMessage(), "\n";
                return false;
            }
            
            $dates_range = $this->getDatesInRange($export_start_date, $export_end_date);
            foreach ($dates_range as $export_date) {
                // Request sleep data from Basis for selected date. Note we're requesting all available data.
                $sleep_url = 'https://app.mybasis.com/api/v2/users/me/days/' . $export_date . '/activities?'
                    . 'type=sleep'
                    . '&expand=activities.stages,activities.events';
                
                // Initialize the cURL resource and make api request
                $ch = curl_init();
                curl_setopt_array($ch, array(
                    CURLOPT_URL => $sleep_url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_COOKIEFILE => $this->cookie_jar
                ));
                $result = curl_exec($ch);
                $response_code = curl_getinfo ($ch, CURLINFO_HTTP_CODE);
        
                if ($response_code == '401') {
                    throw new Exception("ERROR: Unauthorized!\n");
                    return false;
                }
        
                curl_close($ch);
                
                // Parse data from JSON response
                $json = json_decode($result, true);
        
                // Create an array of sleep activities. Basis breaks up sleep into individual
                // events if there is an interruption longer than 15 minutes.
                $sleep = array();
                $sleep_activities = $json['content']['activities'];
                foreach ($sleep_activities as $sleep_activity) {
                    // Add sleep event to array
                    $sleep[] = array(
                        'start_time'            => isset($sleep_activity['start_time']['timestamp']) ? $sleep_activity['start_time']['timestamp'] : '',
                        'start_time_iso'        => isset($sleep_activity['start_time']['iso']) ? $sleep_activity['start_time']['iso'] : '',
                        'start_time_timezone'   => isset($sleep_activity['start_time']['time_zone']['name']) ? $sleep_activity['start_time']['time_zone']['name'] : '',
                        'start_time_offset'     => isset($sleep_activity['start_time']['time_zone']['offset']) ? $sleep_activity['start_time']['time_zone']['offset'] : '',
                        'end_time'              => isset($sleep_activity['end_time']['timestamp']) ? $sleep_activity['end_time']['timestamp'] : '',
                        'end_time_iso'          => isset($sleep_activity['end_time']['iso']) ? $sleep_activity['end_time']['iso'] : '',
                        'end_time_timezone'     => isset($sleep_activity['end_time']['time_zone']['name']) ? $sleep_activity['end_time']['time_zone']['name'] : '',
                        'end_time_offset'       => isset($sleep_activity['end_time']['time_zone']['offset']) ? $sleep_activity['end_time']['time_zone']['offset'] : '',
                        'heart_rate_avg'        => isset($sleep_activity['heart_rate']['avg']) ? $sleep_activity['heart_rate']['avg'] : '',
                        'heart_rate_min'        => isset($sleep_activity['heart_rate']['min']) ? $sleep_activity['heart_rate']['min'] : '',
                        'heart_rate_max'        => isset($sleep_activity['heart_rate']['max']) ? $sleep_activity['heart_rate']['max'] : '',
                        'actual_seconds'        => isset($sleep_activity['actual_seconds']) ? $sleep_activity['actual_seconds'] : '',
                        'calories'              => isset($sleep_activity['calories']) ? $sleep_activity['calories'] : '',
                        'light_minutes'         => isset($sleep_activity['sleep']['light_minutes']) ? $sleep_activity['sleep']['light_minutes'] : '',
                        'deep_minutes'          => isset($sleep_activity['sleep']['deep_minutes']) ? $sleep_activity['sleep']['deep_minutes'] : '',
                        'rem_minutes'           => isset($sleep_activity['sleep']['rem_minutes']) ? $sleep_activity['sleep']['rem_minutes'] : '',
                        'interruption_minutes'  => isset($sleep_activity['sleep']['interruption_minutes']) ? $sleep_activity['sleep']['interruption_minutes'] : '',
                        'unknown_minutes'       => isset($sleep_activity['sleep']['unknown_minutes']) ? $sleep_activity['sleep']['unknown_minutes'] : '',
                        'interruptions'         => isset($sleep_activity['sleep']['interruptions']) ? $sleep_activity['sleep']['interruptions'] : '',
                        'toss_and_turn'         => isset($sleep_activity['sleep']['toss_and_turn']) ? $sleep_activity['sleep']['toss_and_turn'] : '',
                        'events'                => isset($sleep_activity['events']) ? $sleep_activity['events'] : '',
                        'type'                  => isset($sleep_activity['type']) ? $sleep_activity['type'] : '',
                        'state'                 => isset($sleep_activity['state']) ? $sleep_activity['state'] : '',
                        'version'               => isset($sleep_activity['version']) ? $sleep_activity['version'] : '',
                        'id'                    => isset($sleep_activity['id']) ? $sleep_activity['id'] : ''
                    );
                }
                
                $user_ids = $this->getStudyIds();
                if ($export_format == 'csv') {
                    $fp = fopen($file, 'a'); //Open file for appending
                    if(!$fp) {
                        throw new Exception("ERROR: Could not save data to file $file!");
                        return false;
                    }
                    for ($i=0; $i<count($sleep); $i++) {
                        // HH:MM:SS timestamp
                        $start_time = strftime("%Y-%m-%d %H:%M:%S", $sleep[$i]['start_time']);
                        $end_time = strftime("%Y-%m-%d %H:%M:%S", $sleep[$i]['end_time']);
                        $row = array(
                            $user, $user_ids[$user],
                            $start_time, $sleep[$i]['start_time_iso'], $sleep[$i]['start_time_timezone'], 
                            $sleep[$i]['start_time_offset'], $end_time, $sleep[$i]['end_time_iso'], 
                            $sleep[$i]['end_time_timezone'], $sleep[$i]['end_time_offset'],
                            $sleep[$i]['light_minutes'], $sleep[$i]['deep_minutes'], $sleep[$i]['rem_minutes'], 
                            $sleep[$i]['interruption_minutes'], $sleep[$i]['unknown_minutes'],
                            $sleep[$i]['interruptions'], $sleep[$i]['toss_and_turn'], $sleep[$i]['type'], 
                            $sleep[$i]['actual_seconds'], $sleep[$i]['calories'], $sleep[$i]['heart_rate_avg'], 
                            $sleep[$i]['heart_rate_min'], $sleep[$i]['heart_rate_max'], 
                            $sleep[$i]['state'], $sleep[$i]['version'], $sleep[$i]['id']                    
                        );
                        // Add row to csv file
                        fputcsv($fp, $row);
                    }
                    fclose($fp);
                } else {
                    if (!file_put_contents($file, $result)) {
                        throw new Exception("ERROR: Could not save data to file $file!");
                        return false;
                    }
                }        
            }
        }
    }


   /**
    * Retrieve user's activity data for given dates and save to file
    * @param string $export_start_date Date in YYYY-MM-DD format
    * @param string $export_end_date Date in YYYY-MM-DD format
    * @param string $export_format Export type (json,csv)
    * @return bool
    * @throws Exception
    */
    function getActivities($export_start_date = '', $export_end_date = '', $export_format = 'json')
    {
        // Check for YYYY-MM-DD start date format, else throw error
        $export_start_date = $this->getStartDate($export_start_date);
        if (!$this->isValidDate($export_start_date)) {
            throw new Exception('ERROR: Invalid date -  ' . $export_start_date . "\n");
            return false;
        }

        // Check for YYYY-MM-DD end date format, else throw error
        $export_end_date = $this->getEndDate($export_end_date);
        if (!$this->isValidDate($export_end_date)) {
            throw new Exception('ERROR: Invalid date -  ' . $export_end_date . "\n");
            return false;
        }
        
        // Make sure export format is valid
        if (!in_array($export_format, $this->export_formats)) {
            throw new Exception('ERROR: Invalid export format -  ' . $export_format . "\n");
            return false;
        }

        if ($export_format == 'csv') {
            // Save results as .csv file
            $file = dirname(__FILE__) . '/data/basis-data-' . $export_start_date . '-' . $export_end_date . '-activities.csv';
            $fp = fopen($file, 'w');
            if(!$fp) {
                throw new Exception("ERROR: Could not save data to file $file!");
                return false;
            }
            fputcsv($fp, array(
                'username', 'user_id',
                'start time', 'start time ISO', 'start time timezone', 'start time offset',
                'end time', 'end time ISO', 'end time timezone', 'end time offset',
                'type', 'actual seconds', 'steps', 'calories', 'minutes', 'heart rate avg', 'heart rate min', 'heart rate max',
                'state', 'version', 'id'
                )
            );
        } else {
            // Save results as .json file
            $file = dirname(__FILE__) . '/data/basis-data-' . $export_start_date . '-' . $export_end_date . '-activities.json'; 
        }
        
        $accounts = $this->getAccounts();
        foreach ($accounts as $user=>$pword) {
           echo "Get activity data for user " . $user . "\n";     
            try {
                $this->doLogin($user, $pword);
            } catch (Exception $e) {
                echo 'Caught exception: ',  $e->getMessage(), "\n";
                return false;
            }
          
            $dates_range = $this->getDatesInRange($export_start_date, $export_end_date);
            foreach ($dates_range as $export_date) {
                // Request activities data from Basis for selected date. Note we're requesting all available data.
                $activities_url = 'https://app.mybasis.com/api/v2/users/me/days/' . $export_date . '/activities?'
                    . 'type=run,walk,bike'
                    . '&expand=activities';
    
                // Initialize the cURL resource and make api request
                $ch = curl_init();
                curl_setopt_array($ch, array(
                    CURLOPT_URL => $activities_url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_COOKIEFILE => $this->cookie_jar
                ));
                $result = curl_exec($ch);
                $response_code = curl_getinfo ($ch, CURLINFO_HTTP_CODE);
    
                if ($response_code == '401') {
                    throw new Exception("ERROR: Unauthorized!\n");
                    return false;
                }
    
                curl_close($ch);
    
                // Parse data from JSON response
                $json = json_decode($result, true);
    
                // Create an array of activities.
                $activities = array();
                $activity_items = $json['content']['activities'];
                foreach ($activity_items as $activity_item) {
                    // Add activity to array
                    $activities[] = array(
                        'start_time'            => isset($activity_item['start_time']['timestamp']) ? $activity_item['start_time']['timestamp'] : '',
                        'start_time_iso'        => isset($activity_item['start_time']['iso']) ? $activity_item['start_time']['iso'] : '',
                        'start_time_timezone'   => isset($activity_item['start_time']['time_zone']['name']) ? $activity_item['start_time']['time_zone']['name'] : '',
                        'start_time_offset'     => isset($activity_item['start_time']['time_zone']['offset']) ? $activity_item['start_time']['time_zone']['offset'] : '',
                        'end_time'              => isset($activity_item['end_time']['timestamp']) ? $activity_item['end_time']['timestamp'] : '',
                        'end_time_iso'          => isset($activity_item['end_time']['iso']) ? $activity_item['end_time']['iso'] : '',
                        'end_time_timezone'     => isset($activity_item['end_time']['time_zone']['name']) ? $activity_item['end_time']['time_zone']['name'] : '',
                        'end_time_offset'       => isset($activity_item['end_time']['time_zone']['offset']) ? $activity_item['end_time']['time_zone']['offset'] : '',
                        'heart_rate_avg'        => isset($activity_item['heart_rate']['avg']) ? $activity_item['heart_rate']['avg'] : '',
                        'heart_rate_min'        => isset($activity_item['heart_rate']['min']) ? $activity_item['heart_rate']['min'] : '',
                        'heart_rate_max'        => isset($activity_item['heart_rate']['max']) ? $activity_item['heart_rate']['max'] : '',
                        'actual_seconds'        => isset($activity_item['actual_seconds']) ? $activity_item['actual_seconds'] : '',
                        'calories'              => isset($activity_item['calories']) ? $activity_item['calories'] : '',
                        'steps'                 => isset($activity_item['steps']) ? $activity_item['steps'] : '',
                        'minutes'               => isset($activity_item['minutes']) ? $activity_item['minutes'] : '',
                        'type'                  => isset($activity_item['type']) ? $activity_item['type'] : '',
                        'state'                 => isset($activity_item['state']) ? $activity_item['state'] : '',
                        'version'               => isset($activity_item['version']) ? $activity_item['version'] : '',
                        'id'                    => isset($activity_item['id']) ? $activity_item['id'] : ''
                    );
                }
    
                $user_ids = $this->getStudyIds();
                if ($export_format == 'csv') {
                    $fp = fopen($file, 'a'); 
                    if(!$fp) {
                        throw new Exception("ERROR: Could not save data to file $file!");
                        return false;
                    }
                    for ($i=0; $i<count($activities); $i++) {
                        // HH:MM:SS time stamp
                        $start_time = strftime("%Y-%m-%d %H:%M:%S", $activities[$i]['start_time']);
                        $end_time = strftime("%Y-%m-%d %H:%M:%S", $activities[$i]['end_time']);
                        $row = array(
                            $user, $user_ids[$user],
                            $start_time, $activities[$i]['start_time_iso'], $activities[$i]['start_time_timezone'], 
                            $activities[$i]['start_time_offset'], $end_time, $activities[$i]['end_time_iso'], 
                            $activities[$i]['end_time_timezone'], $activities[$i]['end_time_offset'],
                            $activities[$i]['type'], $activities[$i]['actual_seconds'], $activities[$i]['steps'],
                            $activities[$i]['calories'], $activities[$i]['minutes'], $activities[$i]['heart_rate_avg'], 
                            $activities[$i]['heart_rate_min'], $activities[$i]['heart_rate_max'], $activities[$i]['state'],
                            $activities[$i]['version'], $activities[$i]['id']
                        );    
                        // Add row to csv file
                        fputcsv($fp, $row);
                    }
                    fclose($fp);
                } else {
                    if (!file_put_contents($file, $result)) {
                        throw new Exception("ERROR: Could not save data to file $file!");
                        return false;
                    }
                }
            }
        }
    }

    /**
    * Utility function to check/echo system configuration
    */
    function testConfig()
    {
        $w = stream_get_wrappers();
        echo "------------------------------\n";
        echo "Checking system configuration:\n";
        echo "------------------------------\n";
        echo "OpenSSL: ",  extension_loaded  ('openssl') ? "yes":"NO", "\n";
        echo "HTTP wrapper: ", in_array('http', $w) ? "yes":'NO', "\n";
        echo "HTTPS wrapper: ", in_array('https', $w) ? "yes":"NO", "\n";
        echo "data/ writable: ", is_writable('./data') ? "yes":"NO", "\n";
        //echo "Wrappers: ", var_dump($w) . "\n";
        echo "------------------------------\n";
        return;
    }

    /**
    * Checks whether date string is in YYYY-MM-DD format
    * @param $str String containing date to check
    * @return bool
    */
    function isValidDate($str)
    {
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $str, $matches)) {
            if (checkdate($matches[2], $matches[3], $matches[1])) {
                return true;
            }
        }
        return false;
    }
    
    /**
    * Gets the start date in YYYY-MM-DD format
    * Defaults to yesterday's date if $str is empty
    * @param $str String containing start date
    * @return String containing date in format Y-M-D
    */
    function getStartDate($str_date)
    {
        $date;
        if (!isset($str_date)) {
            // default to yesterday
            $date = date('Y-m-d', strtotime('-1 day', time()));
        } else {
            $date = preg_replace('/[^-a-zA-Z0-9_]/', '', $str_date);
        }
        return $date;
    }

    /**
    * Gets the end date in YYYY-MM-DD format
    * Defaults to today's date if $str is empty
    * @param $str String containing end date
    * @return String containing date in format Y-M-D
    */
    function getEndDate($str_date)
    {
        $date;
        if (!isset($str_date)) {
            // default to today
            $date = date('Y-m-d', strtotime('now', time()));
        } else {
            $date = preg_replace('/[^-a-zA-Z0-9_]/', '', $str_date);
        }
        return $date;
    }

    /**
     * Get days between two dates - inclusive
     * @param $start_date String containing beginning date
     * @param $end_date String containing ending date
     * @return Array of dates in format Y-m-d
     */
    function getDatesInRange($start_date, $end_date) 
    {
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);
        $days = array();
        foreach ($period as $day) {
            array_push($days, $day -> format('Y-m-d'));
        }
        if ($start != $end) {
            array_push($days, $end -> format('Y-m-d'));
        } else {
            array_push($days, $start -> format('Y-m-d'));
        }
        return $days;
    }
    
    /**
     * Get usernames and passwords of Basis accounts from a csv file
     * @return Array with usernames as keys and passwords as values
     */
    function getAccounts() 
    {
        $accounts = array();
        $file = fopen(dirname(__FILE__) . "/users.csv", "r");
        while(!feof($file))
        {
            $user = fgetcsv($file);
            $accounts[$user[0]] = $user[1];
        }
        fclose($file);
        return $accounts;
    }
    
    /**
     * Get pre-assigned user ids for each Basis username from a csv file
     * @return Array with usernames as keys and passwords as values
     */
    function getStudyIds() 
    {
        $study_ids = array();
        $file = fopen(dirname(__FILE__) . "/user_ids.csv", "r");
        while(!feof($file))
        {
            $user = fgetcsv($file);
            $study_ids[$user[0]] = $user[1];
        }
        fclose($file);
        return $study_ids;
    }

}

?>
