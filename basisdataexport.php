<?php
/**
 *
 * Basis Data Export
 *
 * Utility that exports and saves your Basis device's uploaded sensor device data.
 * You can learn more about Basis at http://www.mybasis.com/
 *
 * @author Bob Troia <bob@quantifiedbob.com>
 * @link   http://www.quantifiedbob.com
 * 
 * Usage:
 * This script can be run several ways. You can edit the BASIS_USERNAME, BASIS_PASSWORD, 
 * and BASIS_EXPORT_FORMAT values under "Settings" below so you don't have to specify
 * them every time the script is run. Make sure the data/ folder is writeable!
 *
 * [Method 1] Via interactive mode
 *   a. Open a terminal window and cd to this script's directory.
 *   b. Type php basisdataexport.php
 *   c. Follow the prompts (hit ENTER to use default values)
 *   d. Your data will be save to /data/basis-data-[YYYY-MM-DD].[format]';
 *   
 * [Method 2] Via command-line arguments (useful for crons)
 *   php basisdataexport.php -h -u[username] -p[pass] -d[YYYY-MM-DD] -f[json|csv|html]
 *
 *   Options:
 *   -u  Basis username (if not used, defaults to BASIS_USERNAME)
 *   -p  Basis password (if not used, defaults to BASIS_PASSWORD)
 *   -d  Data export date (YYYY-MM-DD) (if not used, defaults to current date)
 *   -f  Data export format (json|csv|html) (if not used, defaults to json)
 *   -h  Show this help text
 * 
 * [Method 3] Via web browser 
 *   This assumes your script is in a location that is executable via a web server,
 *   i.e., http://localhost/basis-data-export/basisdataexport.php?u=[basis_username]&p=[basis_password]&d=[YYYY-MM-DD]&f=[format]
 *
 *  
*/
require_once(dirname(__FILE__) . '/BasisExport.class.php'); 
//Set time zone
date_default_timezone_set('America/New_York'); 
//Set memory allowed
ini_set('memory_limit','512M');

///////////////////////////////////////////////////////
// Settings
///////////////////////////////////////////////////////

// Specify your Basis username, password, and default export format. Leaving blank 
// will require inputting these values manually each time the script is run.
define('BASIS_USERNAME', '');
define('BASIS_PASSWORD', '');
define('BASIS_EXPORT_FORMAT', 'csv');

// Enable/disable debug mode
define('DEBUG', false);

///////////////////////////////////////////////////////
// You shouldn't need to edit anything below this line!
///////////////////////////////////////////////////////

// See if we are running in command-line mode
if (php_sapi_name() == "cli") {
    // Check for command-line arguments, otherwise enter interactive mode.
    if($argc > 1) {
        $settings = runCommandLine();
    } else {
        // Enter interactive mode
        $settings = runInteractive();
    }
} else {
    // Request has been make via web browser
    $settings = runHttp();
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
    $options = getopt("h::u::p::d::f::");
    $settings = array();
    $settings['basis_username'] = (!defined('BASIS_USERNAME')) ? '' : BASIS_USERNAME;
    $settings['basis_password'] = (!defined('BASIS_PASSWORD')) ? '' : BASIS_PASSWORD;
    $settings['basis_export_date'] = date('Y-m-d', strtotime('now', time()));
    $settings['basis_export_format'] = (!defined('BASIS_EXPORT_FORMAT')) ? 'json' : BASIS_EXPORT_FORMAT;

    while (list($key, $value) = each($options)) {
        if ($key == 'h') {
            echo "-------------------------\n";
            echo "Basis data export script.\n";
            echo "-------------------------\n";
            echo "Usage:\n";
            echo "php basisdataexport.php -u[username] -p[pass] -d[YYYY-MM-DD] -f[json|csv|html]\n\n";
            echo "options:\n";
            echo "-u  Basis username\n";
            echo "-p  Basis password\n";
            echo "-d  Data export date (YYYY-MM-DD). If blank will use today's date\n";
            echo "-f  Data export format (json|csv|html)\n";
            echo "-h  Show this help text\n";
            echo "-------------------------\n";
            exit();
        }
        if ($key == 'u') {
            if (empty($value)) {
                die ("No username specified!\n");
            } else {
                $settings['basis_username'] = trim($value);
            }
        }
        if ($key == 'p') {
            if (empty($value)) {
                die ("No password specified!\n");
            } else {
                $settings['basis_password'] = trim($value);
            }
        }
        if ($key == 'd') {
            if (empty($value)) {
                die ("No date specified!\n");
            } else {
                $settings['basis_export_date'] = trim($value);
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
    echo "Enter export format (json|csv|html) [$basis_export_format] : ";
    $input_export_format = trim(fgets($handle));
    $settings['basis_export_format'] = (empty($input_export_format) ? $basis_export_format : $input_export_format);
    fclose($handle);

    if (DEBUG ) {
        echo "-----------------------------\n";
        echo "Using the following settings:\n";
        echo "-----------------------------\n";
        echo 'Username: ' . $settings['basis_username'] . "\n";
        echo 'Password: ' . $settings['basis_password'] . "\n";
        echo 'Start Date: ' . $settings['basis_export_start_date'] . "\n";
        echo 'End Date: ' . $settings['basis_export_end_date'] . "\n";
        echo 'Format: ' . $settings['basis_export_format'] . "\n";
        echo "-----------------------------\n";
    }

    return ($settings);
}

/**
* Take parameters via http $_GET args
**/
function runHttp()
{
    $basis_username = (!defined('BASIS_USERNAME')) ? '' : BASIS_USERNAME;
    $basis_password = (!defined('BASIS_PASSWORD')) ? '' : BASIS_PASSWORD;
    $basis_export_date = date('Y-m-d', strtotime('now', time()));
    $basis_export_format = (!defined('BASIS_EXPORT_FORMAT')) ? 'json' : BASIS_EXPORT_FORMAT;
    $settings = array();

    $settings['basis_username'] = (empty($_GET['u']) ? $basis_username : strip_tags($_GET['u']));
    $settings['basis_password'] = (empty($_GET['p']) ? $basis_password : strip_tags($_GET['p']));
    $settings['basis_export_date'] = (empty($_GET['d']) ? $basis_export_date : strip_tags($_GET['d']));
    $settings['basis_export_format'] = (empty($_GET['f']) ? $basis_export_format : strip_tags($_GET['f']));

    return ($settings);
}

?>
