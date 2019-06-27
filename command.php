<?php

use WP_CLI\Formatter;
use WP_CLI\Utils;

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * Runs post content through Microsoft Azure's cognitive text API, finding Organizations and People, automatically adding them to the post's taxonomies
 * IMPORTANT: you must have created custom taxonomies non hierarchical for "organizations" and "people"
 *
 * ## OPTIONS
 *
 * <postID>
 * : The ID of the post you want to target
 *
 * ## EXAMPLES
 *
 *     wp taxcat 123
 *          Success: Organization Taxonomies
 *          +---------+------------------------------------+------------------------------------+--------------+
 *          | term_id | name                               | slug                               | taxonomy     |
 *          +---------+------------------------------------+------------------------------------+--------------+
 *          | 951     | Figure 1                           | figure-1                           | organization |
 *          | 962     | Toyota Prius                       | toyota-prius                       | organization |
 *          | 960     | United States Department of Energy | united-states-department-of-energy | organization |
 *          | 958     | WIDE Project                       | wide-project                       | organization |
 *          | 959     | Yes (Israel)                       | yes-israel                         | organization |
 *          +---------+------------------------------------+------------------------------------+--------------+
 *          Success: People Taxonomies
 *          +---------+------------+------------+----------+
 *          | term_id | name       | slug       | taxonomy |
 *          +---------+------------+------------+----------+
 *          | 961     | Henry Ford | henry-ford | people   |
 *          +---------+------------+------------+----------+
 *
 * @when after_wp_load
 */
$taxcat = function($args, $assoc_args) {

    $dotenv = Dotenv\Dotenv::create(__DIR__);
    $dotenv->load();
    try {
        $dotenv->required('AZURE_ACCESS_KEY')->notEmpty();
        $dotenv->required('WATSON_ACCESS_KEY')->notEmpty();
    } catch(Exception $e) {
        WP_CLI::error("Error caught: ". $e->getMessage(). "\n");
    }

    list( $postID ) = $args;

    file_put_contents('results.txt', "Results Below\n\n");

    $azureAccessKey = getenv('AZURE_ACCESS_KEY');
    $watsonAccessKey = getenv('WATSON_ACCESS_KEY');

    $azureHost = 'https://westus.api.cognitive.microsoft.com';
    $azurePath = '/text/analytics/v2.1/entities';

    $watsonHost = 'https://gateway.watsonplatform.net';
    $watsonPath = '/natural-language-understanding/api/v1/analyze?version=2018-11-16';

    // Run "wp post list" command through internal wp-cli API, save results
    $options = array(
        'return'     => true,   // Return 'STDOUT'; use 'all' for full object.
        'parse'      => 'json', // Parse captured STDOUT to JSON array.
        'launch'     => false,  // Reuse the current process.
        'exit_error' => true,   // Halt script execution on error.
    );

    $subPost = WP_CLI::runcommand( "post get ". $postID ." --field=post_content --format=json", $options );

    $watsonResult = getWatsonEntities($watsonHost, $watsonPath, $watsonAccessKey, $subPost);

    // Run each post through Azure
    $subPost = strip_tags($subPost);
    if(strlen($subPost) > 5000) {
        $subPost = substr($subPost, 0,5100);
    }
    if(strlen($subPost) < 50) {
        $subPost = "Failed to send post to Azure endpoint, either post_content is to short or empty, or other error has occured";
    }

    $data = array (
        'documents' => array (
            array ( 'id' => '1', 'language' => 'en', 'text' => $subPost ),
        )
    );

    // Send post to Azure and seperate relevent information (in this case: Organizations and Persons)
    $azureResult = getAzureEntities($azureHost, $azurePath, $azureAccessKey, $data);

    list($azureOrgArray, $azurePersonArray) = parseAzureResponse($azureResult);
    list($watsonOrgArray, $watsonPersonArray) = parseWatsonResponse($watsonResult);

    WP_CLI::runcommand( "post term remove ". $postID ." organization --all", $options );
    WP_CLI::runcommand( "post term remove ". $postID ." people --all", $options );

    for($i = 0; $i < sizeof($azureOrgArray); $i++) {
        WP_CLI::runcommand( "post term add ". $postID ." organization "."'".$azureOrgArray[$i]['name']."'", $options );
    }
    for($i = 0; $i < sizeof($azurePersonArray); $i++) {
        WP_CLI::runcommand( "post term add ". $postID ." people ".'"'.$azurePersonArray[$i]['name'].'"', $options );
    }

    // Write Post and associative arrays to results.txt
    // IMPORTANT: this file is overwritten every compile!
    file_put_contents('results.txt', json_encode(json_decode($watsonResult), JSON_PRETTY_PRINT), FILE_APPEND | LOCK_EX);

    #WP_CLI::log(sprintf("%s\n **Organizations**\n%s\n**Persons**\n%s\n\n", $subPost, json_encode($orgArray, JSON_PRETTY_PRINT), json_encode($personArray, JSON_PRETTY_PRINT)));

    $options = array(
        'return'     => true,   // Return 'STDOUT'; use 'all' for full object.
        'parse'      => 'table', // Parse captured STDOUT to JSON array.
        'launch'     => false,  // Reuse the current process.
        'exit_error' => true,   // Halt script execution on error.
    );
    $s1 = WP_CLI::runcommand("post term list ". $postID ." organization --format=table",$options);
    $s2 = WP_CLI::runcommand("post term list ". $postID ." people --format=table",$options);
    WP_CLI\Utils\format_items( 'table', $azureOrgArray, array( 'name', 'WikiScore', 'EntityScore' ) );
    WP_CLI\Utils\format_items( 'table', $watsonOrgArray, array( 'name', 'relevance' ) );

    #WP_CLI::success("Organization Taxonomies\n".$s1);
    #WP_CLI::success("People Taxonomies\n".$s2);

};

WP_CLI::add_command( 'taxcat', $taxcat );





//* * * * * * * * * * * * //
// Helper functions below //
//* * * * * * * * * * * * //


function getAzureEntities ($host, $path, $key, $data) {

    $headers = "Content-type: text/json\r\n" .
        "Ocp-Apim-Subscription-Key: $key\r\n";

    $data = json_encode ($data);

    $options = array (
        'http' => array (
            'header' => $headers,
            'method' => 'POST',
            'content' => $data
        )
    );
    $context  = stream_context_create ($options);
    $result = file_get_contents($host . $path, false, $context);
    return $result;
};

function getWatsonEntities ($host, $path, $key, $data) {

    $auth =  base64_encode('apikey:'.$key);

    $headers = "Content-type: application/json\r\n" .
        "Authorization: Basic $auth\r\n";


    $params = array (
        "text" => $data,
        "features" => array (
            "entities" => array (
                "emotion" => false,
                "sentiment" => false,
                "limit" => 50
            ),
            "concepts" => array(
                "limit" => 8
            )
        )
    );

    $data = json_encode ($params);

    #WP_CLI::log(json_encode(json_decode($data), JSON_PRETTY_PRINT));

    $options = array (
        'http' => array (
            'header' => $headers,
            'method' => 'POST',
            'content' => $data
        )
    );
    $context  = stream_context_create ($options);
    $result = file_get_contents($host . $path, false, $context);
    return $result;
};

function parseAzureResponse($json) {

    $orgArray = [];
    $personArray = [];
    $string =json_decode($json, true);

    foreach($string["documents"][0]["entities"] as $entities) {
        if($entities["type"] == "Organization" && array_key_exists("entityTypeScore",$entities['matches'][0])) {
            foreach($entities['matches'] as $match) {
                if(array_key_exists("wikipediaScore",$match)) {
                    $orgArray[] = [
                        'name' => $entities["name"],
                        'WikiScore' => $match["wikipediaScore"],
                        'EntityScore' => $entities['matches'][0]["entityTypeScore"]
                    ];
                }
            }
        }
        if($entities["type"] == "Person") {
            if (array_key_exists("entityTypeScore",$entities['matches'][0])) {
                $personArray[] = [
                    'name' => $entities["name"],
                    //'WikiScore' => $entities['matches'][0]["wikipediaScore"],
                    'EntityScore' => $entities['matches'][0]["entityTypeScore"]
                ];
            }
        }
    }

    return array(array_unique($orgArray, SORT_REGULAR),$personArray);

};

function parseWatsonResponse($json) {
    $orgArray = [];
    $personArray = [];
    $string = json_decode($json, true);

    foreach($string["entities"] as $entities) {
        if($entities['type'] == "Company" || $entities['type'] == "Organization") {
            $orgArray[] = [
                'name' => $entities["text"],
                'relevance' => $entities["relevance"]
            ];
        }
        if($entities['type'] == "Person") {
            $personArray[] = [
                'name' => $entities["text"],
                'relevance' => $entities["relevance"]
            ];
        }
    }

    return array($orgArray, $personArray);
}