<?php

define("EOL", PHP_EOL);
define("LINE_LENGTH", 40);
define("SPARK", "/usr/local/bin/spark");

// echo "\e[3;0;0t";   //move to top left
// echo "\e[8;100;" . LINE_LENGTH . "t"; //reseize window
// echo "\e[1;1H\e[2J"; //clear screen

ini_set('display_startup_errors', 1);
ini_set('display_errors', 1);
error_reporting(-1);

$secrets = json_decode(file_get_contents(dirname(__FILE__) . "/Secrets.json"), true);

date_default_timezone_set('Europe/London');
$dtNow = new DateTime("now");
$beginOfDay = clone $dtNow;
// Go to midnight.  ->modify('midnight') does not do this for some reason
$beginOfDay->setTime(0, 0, 0);
$endOfDay = clone $beginOfDay;
$endOfDay->modify('tomorrow');
// adjust from the next day to the end of the day, per original question
$endOfDay->modify('1 second ago');
$start_date = $beginOfDay->format(DateTime::RFC3339);
$end_date = $endOfDay->format(DateTime::RFC3339);


/* * ************************************ */
/*           Centered Date             */
/* * ************************************ */

$date = $beginOfDay->format("l jS F 'y");

$dateLen = strlen($date);

$spaces = (LINE_LENGTH - $dateLen) / 2;

printText(str_repeat(" ", $spaces) . $date);


/* * ************************************ */
/*            Advice Slip              */
/* * ************************************ */
$adviceCH = curl_init();
$AdviceURL = "http://api.adviceslip.com/advice"; // where you want to post data
curl_setopt($adviceCH, CURLOPT_URL, $AdviceURL);
curl_setopt($adviceCH, CURLOPT_RETURNTRANSFER, true); // return the output in string format
$adviceOutput = curl_exec($adviceCH); // execute
curl_close($adviceCH); // close curl handle

$adviceResponse = json_decode($adviceOutput);
$slip = $adviceResponse->slip;
$advice = $slip->advice;

printH1("Advice Slip");
printText($advice);


/* * ************************************ */
/*            Reconnect                */
/* * ************************************ */

if (rand(0, 1)) {

    $ch = curl_init();
    $friendsURL = $secrets['FriendsURL']; // where you want to post data
    curl_setopt($ch, CURLOPT_URL, $friendsURL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // return the output in string format
    $output = curl_exec($ch); // execute
    curl_close($ch); // close curl handle
    unset($ch);
    unset($response);


    $response = json_decode($output, true);

    $list = $response['feed']['entry'];
    //var_dump($list);

    $outOfTouch = array();
    foreach ($list as $friend) {

        if ($friend['gsx$rec']['$t'] === "a") {
            $outOfTouch[] = $friend;
        }
    }
    if (count($outOfTouch) > 0) {

        shuffle($outOfTouch);

        $lastSpokeDate = DateTime::createFromFormat('d/m/Y', $outOfTouch[0]['gsx$lastspoke']['$t']);
        //$lastSpokeDate = strtotime();
        $ago = time_elapsed_string($lastSpokeDate->getTimestamp());

        $REC = sprintf("You last spoke to %s %s, perhaps you should reconnect.", $outOfTouch[0]['gsx$name']['$t'], $ago);

        printText(EOL . $REC);
    }
}


/* * ************************************ */
/*            Weather                  */
/* * ************************************ */

$weatherCH = curl_init();
$WeatherURL = "https://api.forecast.io/forecast/" . $secrets['ForecastIO']['key'] . "/" . $secrets['ForecastIO']['coords']; // where you want to post data
curl_setopt($weatherCH, CURLOPT_URL, $WeatherURL);
curl_setopt($weatherCH, CURLOPT_RETURNTRANSFER, true); // return the output in string format
$weatherOutput = curl_exec($weatherCH); // execute
curl_close($weatherCH); // close curl handle


$weatherResponse = json_decode($weatherOutput, true);

//file_put_contents("/Users/robertshippey/Desktop/weatherDump.txt", $output);

if ($weatherResponse) {


    $summary = $weatherResponse['hourly']['summary'];
    $points = $weatherResponse['hourly']['data'];

    $temp_data = "";
    $averageRainData = "";

    $maxRainIntensity = -999;

    $min_tempF = 999;
    $max_tempF = -999;


    for ($x = 0; $x < LINE_LENGTH && $x < count($points); $x++) {

        if (($points[$x]['time']) > $endOfDay->getTimestamp()) {
            break;
        }

        if ($points[$x]['temperature'] < $min_tempF) {
            $min_tempF = $points[$x]['temperature'];
        }
        if ($points[$x]['temperature'] > $max_tempF) {
            $max_tempF = $points[$x]['temperature'];
        }

        if ($points[$x]['precipIntensity'] > $maxRainIntensity) {
            $maxRainIntensity = $points[$x]['precipIntensity'];
        }

        $temp_data .= $points[$x]['temperature'] . " ";
        $averageRainData .= (($points[$x]['precipIntensity'] * 100.00) * ($points[$x]['precipProbability'] * 100.0)) . " ";

        //$totalRainIntensity += $points[$x]['precipIntensity'];
    }


    $tempSpark = shell_exec(SPARK . " $temp_data");
    $averageRainSpark = shell_exec(SPARK . " $averageRainData");

    $min_tempC = round(($min_tempF - 32) * 5 / 9, 0);
    $max_tempC = round(($max_tempF - 32) * 5 / 9, 0);

    printH1("Weather");

    printText($summary);
    printText();

    printH2("Temperature");
    printText(trim($tempSpark));
    printText("Lows of {$min_tempC}ºC and highs of {$max_tempC}ºC.");


    $rainGuide = "no rain";

    if ($maxRainIntensity >= 0.4) {
        $rainGuide = "loads of rain";
    } else if ($maxRainIntensity >= 0.1) {
        $rainGuide = "moderate rain";
    } else if ($maxRainIntensity >= 0.017) {
        $rainGuide = "light rain";
    } else if ($maxRainIntensity >= 0.002) {
        $rainGuide = "very light rain";
    } else {
        $rainGuide = "no rain";
    }

    if ($maxRainIntensity > 0) {
        printText();
        printH2("Rain");
        printText(trim($averageRainSpark));
        printText("There will be mostly " . $rainGuide . ".");
    } else {
        printText("There will be no rain today.");
    }
}


/* * ************************************ */
/*            Calendar                 */
/* * ************************************ */

$CalendarURL = $secrets['MainCalendarURL'];
//descending

$params = array(
    "orderby" => "starttime",
    "sortorder" => "ascending",
    "singleevents" => "true",
    //	"futureevents" => "true",
    "start-min" => $start_date,
    "start-max" => $end_date,
    "alt" => "json"
);

// echo $start_date . EOL . $end_date . EOL;
$finalURL = $CalendarURL . "?" . http_build_query($params);
// echo $finalURL . EOL;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $finalURL);
// curl_setopt($ch, CURLOPT_POST, true);  // tell curl you want to post something
//curl_setopt($ch, CURLOPT_POSTFIELDS, $slack_msg); // define what you want to post
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // return the output in string format
$output = curl_exec($ch); // execute
curl_close($ch); // close curl handle
unset($ch);
unset($response);


$response = json_decode($output, true);

if ($response) {

    //var_dump($response);
    $feed = $response['feed'];

    if (array_key_exists('entry', $feed)) {
        $list = $feed['entry'];
        //var_dump($list);

        if (array_key_exists('entry', $response['feed'])) {
            $list = $response['feed']['entry'];

            printH1("Events");
            foreach ($list as $event) {

                $title = "Title: " . htmlspecialchars_decode($event['title']['$t']);
                printText($title);

                $summary_text = htmlspecialchars_decode($event['summary']['$t']);
                $summary_text = str_replace("&nbsp;", "", $summary_text);
                $summary_text = strip_tags($summary_text);

                $lines = explode("\n", $summary_text);
                //var_dump($lines);

                foreach ($lines as $line) {
                    if ($line !== "") {
                        if (startsWith($line, "Event Status:") || startsWith($line, "View event in Google+:") ||
                                startsWith($line, "GMT")) {
                            continue;
                        } elseif (startsWith($line, "Who:")) {
                        	$line = preg_replace("/(\s)[A-Z][A-z]*(?=(,|$))/", "", $line);
                        }
                        printText(" " . $line);
                    }
                }
                printText(str_repeat("-", LINE_LENGTH));
            }
        } else {
            printText(EOL . "You have no events today. Relax.");
        }
    }
}





/* * ************************************ */
/*            TV Shows                 */
/* * ************************************ */

$CalendarURL = $secrets['TVShowCalendarURL'];
//descending

$params = array(
    "orderby" => "starttime",
    "sortorder" => "ascending",
    "singleevents" => "true",
    //	"futureevents" => "true",
    "start-min" => $start_date,
    "start-max" => $end_date,
    "alt" => "json"
);

// echo $start_date . EOL . $end_date . EOL;
$finalURL = $CalendarURL . "?" . http_build_query($params);
// echo $finalURL . EOL;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $finalURL);
// curl_setopt($ch, CURLOPT_POST, true);  // tell curl you want to post something
//curl_setopt($ch, CURLOPT_POSTFIELDS, $slack_msg); // define what you want to post
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // return the output in string format
$output = curl_exec($ch); // execute
curl_close($ch); // close curl handle
unset($ch);
unset($response);

$response = json_decode($output, true);

if ($output) {
    //var_dump($response);

    if (array_key_exists('entry', $response['feed'])) {
        $list = $response['feed']['entry'];
        //var_dump($list);


        printH1("TV Shows");
        foreach ($list as $event) {

            $title = " * " . htmlspecialchars_decode($event['title']['$t']);
            printText($title);
        }
    }
}



/* * ************************************ */
/*            Functions                 */
/* * ************************************ */

function printH1($string) {
    printText(EOL . $string);
    printText(str_repeat("=", LINE_LENGTH));
}

function printH2($string) {
    printText($string);
    printText(str_repeat("-", LINE_LENGTH));
}

function printText($string = "") {
    echo wordwrap($string, LINE_LENGTH) . EOL;
}

function startsWith($haystack, $needle) {
    // search backwards starting from haystack length characters from the end
    return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
}

function time_elapsed_string($ptime) {
    $etime = time() - $ptime;

    if ($etime < 1) {
        return '0 seconds';
    }

    $a = array(365 * 24 * 60 * 60 => 'year',
        30 * 24 * 60 * 60 => 'month',
        24 * 60 * 60 => 'day',
        60 * 60 => 'hour',
        60 => 'minute',
        1 => 'second'
    );
    $a_plural = array('year' => 'years',
        'month' => 'months',
        'day' => 'days',
        'hour' => 'hours',
        'minute' => 'minutes',
        'second' => 'seconds'
    );

    foreach ($a as $secs => $str) {
        $d = $etime / $secs;
        if ($d >= 1) {
            $r = round($d);
            return $r . ' ' . ($r > 1 ? $a_plural[$str] : $str) . ' ago';
        }
    }
}