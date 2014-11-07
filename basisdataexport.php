<?php
/**
 *
 * Basis Data Export
 *
 * Utility that exports and saves sensor data from the Basis web site.
 * You can learn more about Basis at http://www.mybasis.com/
 *
 * Original @author Bob Troia <bob@quantifiedbob.com>
 * @link   http://www.quantifiedbob.com
 * 
 * Modified by @author Leonard Ng'eno
 * 
 * Usage:
 * 
 * This script can be run several ways.
 *
 * [Method 1] Via interactive mode
 *   a. Open a terminal window and cd to this script's directory.
 *   b. Type php basisdataexport.php
 *   c. Follow the prompts (hit ENTER to use default values)
 *   d. Your data will be save to /data/basis-data-[YYYY-MM-DD].[format]';
 *   
 * [Method 2] Via command-line arguments (useful for crons)
 *   php basisdataexport.php -s[YYYY-MM-DD] -e[YYYY-MM-DD] -f[json|csv]
 *
 *   Options:
 *   -s  Data export start date (YYYY-MM-DD) (if not used, defaults to yesterday's date)
 *   -e Data export end date (YYYY-MM-DD) (if not used, defaults to today's date)
 *   -f  Data export format (json|csv) (if not used, defaults to json)
 *   -h  Show this help text
 * 
 *  You can edit the BASIS_EXPORT_FORMAT value so you don't have to specify it every time the script is run. 
 *  Make sure the data/ folder is writeable.
 *  Enter the basis usernames and passwords in the users.csv file. Enter each pair on its own line
 *  and use commas to separate the username from the password field.
 *  
*/
require_once(dirname(__FILE__) . '/BasisExport.class.php'); 
//Set time zone
date_default_timezone_set('America/New_York'); 
//Set memory allowed
ini_set('memory_limit','512M');

// Specify the default export format. Leaving blank will require inputting it manually each time the script is run.
define('BASIS_EXPORT_FORMAT', 'csv');

// Enable/disable debug mode
define('DEBUG', false);

// See if we are running in command-line mode
if (php_sapi_name() == "cli") {
    // Check for command-line arguments, otherwise enter interactive mode.
    if($argc > 1) {
        $settings = runCommandLine();
    } else {
        // Enter interactive mode
        $settings = runInteractive();
    }
} 

// Create instance of BasisExport class
$basis = new BasisExport();
$basis->debug = DEBUG;

// Query Basis API for biometric data
try {
    $basis->getMetrics($settings['basis_export_start_date'], $settings['basis_export_end_date'], $settings['basis_export_format']);
} catch (Exception $e) {
    echo 'Exception: ',  $e->getMessage(), "\n";
}

// Query Basis API for sleep data
try {
    $basis->getSleep($settings['basis_export_start_date'], $settings['basis_export_end_date'], $settings['basis_export_format']);
} catch (Exception $e) {
    echo 'Exception: ',  $e->getMessage(), "\n";
}

// Query Basis API for activity data
try {
    $basis->getActivities($settings['basis_export_start_date'], $settings['basis_export_end_date'], $settings['basis_export_format']);
} catch (Exception $e) {
    echo 'Exception: ',  $e->getMessage(), "\n";
}

/**
* Take parameters via command-line args
**/
function runCommandLine()
{
    $options = getopt("h::s::e::f::");
    $settings = array();
    $settings['basis_export_start_date'] = date('Y-m-d', strtotime('-1 day', time()));
    $settings['basis_export_end_date'] = date('Y-m-d', strtotime('now', time()));
    $settings['basis_export_format'] = (!defined('BASIS_EXPORT_FORMAT')) ? 'json' : BASIS_EXPORT_FORMAT;

    while (list($key, $value) = each($options)) {
        if ($key == 'h') {
            echo "-------------------------\n";
            echo "Basis data export script.\n";
            echo "-------------------------\n";
            echo "Usage:\n";
            echo "php basisdataexport.php -s[YYYY-MM-DD] -e[YYYY-MM-DD] -f[json|csv]\n\n";
            echo "options:\n";
            echo "-s  Data export start date (YYYY-MM-DD). If blank will use yesterday's date\n";
            echo "-e  Data export end date (YYYY-MM-DD). If blank will use today's date\n";
            echo "-f  Data export format (json|csv)\n";
            echo "-h  Show this help text\n";
            echo "-------------------------\n";
            exit();
        }
        if ($key == 's') {
            if (empty($value)) {
                die ("No start date specified!\n");
            } else {
                $settings['basis_export_start_date'] = trim($value);
            }
        }
        if ($key == 'e') {
            if (empty($value)) {
                die ("No end date specified!\n");
            } else {
                $settings['basis_export_end_date'] = trim($value);
            }
        }
        if ($key == 'f') {
            if (empty($value)) {
                die ("No format specified!\n");
            } else {
                $settings['basis_export_format'] = trim($value);
            }
        }
    }
    
    return $settings;
}

/**
* Take parameters via interactive shell
**/
function runInteractive()
{
    $basis_export_start_date = date('Y-m-d', strtotime('-1 day', time()));
    $basis_export_end_date = date('Y-m-d', strtotime('now', time()));
    $basis_export_format = (!defined('BASIS_EXPORT_FORMAT')) ? 'json' : BASIS_EXPORT_FORMAT;
    $settings = array();

    echo "-------------------------\n";
    echo "Basis data export script.\n";
    echo "-------------------------\n";
    $handle = fopen ("php://stdin","r");
    echo "Enter data export start date (YYYY-MM-DD) [$basis_export_start_date] : ";
    $input_export_start_date = trim(fgets($handle));
    $settings['basis_export_start_date'] = (empty($input_export_start_date) ? $basis_export_start_date : $input_export_start_date);
    echo "Enter data export end date (YYYY-MM-DD) [$basis_export_end_date] : ";
    $input_export_end_date = trim(fgets($handle));
    $settings['basis_export_end_date'] = (empty($input_export_end_date) ? $basis_export_end_date : $input_export_end_date);    
    echo "Enter export format (json|csv) [$basis_export_format] : ";
    $input_export_format = trim(fgets($handle));
    $settings['basis_export_format'] = (empty($input_export_format) ? $basis_export_format : $input_export_format);
    fclose($handle);

    if (DEBUG ) {
        echo "-----------------------------\n";
        echo "Using the following settings:\n";
        echo "-----------------------------\n";
        echo 'Start Date: ' . $settings['basis_export_start_date'] . "\n";
        echo 'End Date: ' . $settings['basis_export_end_date'] . "\n";
        echo 'Format: ' . $settings['basis_export_format'] . "\n";
        echo "-----------------------------\n";
    }

    return ($settings);
}

?>
