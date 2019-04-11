<?php

include_once("resources/php/config.php");
include_once("resources/php/helpers.php");

// Due to the time this script takes to run, it's not intended to be run directly through
// the browser, instead it should be called via a cron job using the php cli binary.
if ( php_sapi_name() != "cli" ) {
	header("Location: index.php");
} else {
	// When we run the update script, we first pull down any new or updates tournaments,
	// then run our cleansing scripts.
	updateAllTournaments();
	fixProvinces();
	addPremierGroups();
}

// Updates up to MAX_PER_RUN tournaments in the database.
function updateAllTournaments() {
	$webTournamentIDs = getAllTournamentIDs();
	$dbTournamentIDs = getAllCurrentStoredIDs();
	$deletedTournamentIDs = getDeletedEventIDs($webTournamentIDs, $dbTournamentIDs);
	$newTournamentIDs = getNewEventIDs($webTournamentIDs, $dbTournamentIDs);
	
	flushDeletedEventIDs($deletedTournamentIDs);
	$expiredTournamentIDs = getExpiredTournamentIDs();
	
	$updatedCount = 0;
	
	echo "Found Tournaments on Pokemon.com - " . count($webTournamentIDs) . "\r\n";
	echo "Expired Tournaments to Refresh - " . count($expiredTournamentIDs) . "\r\n";
	echo "New Tournaments to Add - " . count($newTournamentIDs) . "\r\n";
	echo "Cancelled Tournaments to Delete - " . count($deletedTournamentIDs) . " " . implode(",", $deletedTournamentIDs) . "\r\n";
	
	foreach ( $expiredTournamentIDs as $tournamentID ) {
		if ( $updatedCount++ == MAX_PER_RUN ) break;
		updateTournamentId($tournamentID);
	}
	
	foreach ( $newTournamentIDs as $tournamentID ) {
		if ( $updatedCount++ == MAX_PER_RUN ) break;
		updateTournamentId($tournamentID);
	}
}

// Compares the list of events on the Pokemon website to the list in the database, and
// returns any tournament IDs that are no longer on the website (presumably because they've
// been cancelled).
function getDeletedEventIDs($webTournamentIDs, $dbTournamentIDs) {
	$deletedEventIDs = array();
	
	foreach ( $dbTournamentIDs as $dbTournamentID ) {
		$deleted = true;
		
		foreach ( $webTournamentIDs as $webTournamentID ) {
			if ( $dbTournamentID == $webTournamentID ) {
				$deleted = false;
			}
		}
		
		if ( $deleted ) {
			$deletedEventIDs[count($deletedEventIDs)] = $dbTournamentID;
		}
	}
	
	return $deletedEventIDs;
}

// Compares the web tournament list with the database one to highlight any tournaments that
// are not currently in the database and need to be added.
function getNewEventIDs($webTournamentIDs, $dbTournamentIDs) {
	$newEventIDs = array();
	
	foreach ( $webTournamentIDs as $webTournamentID ) {
		$newEvent = true;
		
		foreach ( $dbTournamentIDs as $dbTournamentID ) {
			if ( $webTournamentID == $dbTournamentID ) {
				$newEvent = false;
			}
		}
		
		if ( $newEvent ) {
			$newEventIDs[count($newEventIDs)] = $webTournamentID;
		}
	}
	
	return $newEventIDs;
}

// This performs a search for all events on the Pokemon website. As of the latest commit this is around 27
// pages at 100 events per page, so this will make roughly that many external calls. It stops when it finds
// a page that doesn't have a "Next ->" link on it.
function getAllTournamentIDs() {
	$baseSearchUrl = "https://www.pokemon.com/us/play-pokemon/pokemon-events/find-an-event/?";
	$baseSearchUrl .= "city=Melbourne&results_pp=100&location_name=&event_type=tournament&event_type=premier&end_date=&event_name=&";
	$baseSearchUrl .= "country=10&sort_order=when&postal_code=&distance_within=99999&address=&product_type=tcg&product_type=vg&";
	$baseSearchUrl .= "start_date=0&state_other=&page_1=";

	$tournamentIDs = array();	
	$pageId = 1;
	
	while ( ! getTournamentIDs($baseSearchUrl, $tournamentIDs, $pageId) ) {
		$pageId++;
	}
	
	return $tournamentIDs;
}

// This extracts all the tournament IDs from a search page and adds them to the tournamentIDs array.
// The function returns true when there are no pages left.
function getTournamentIDs($baseSearchUrl, &$tournamentIDs, $pageId) {
	$dom = new DOMDocument;
	@$dom->loadHTML("<?xml encoding='utf-8' ?>" . file_get_contents($baseSearchUrl . $pageId));
	
	$main = $dom->getElementById("table-1");
	
	if ( $main ) {
		foreach ( $main->getElementsByTagName("tbody") as $content ) {
			foreach ( $content->getElementsByTagName("tr") as $row ) {
				$eventCancelled = false;
				
				foreach ( $row->getElementsByTagName("td") as $cell ) {
					if ( trim($cell->textContent) == "Cancelled" ) {
						$eventCancelled = true;
					}
				}
				
				if ( ! $eventCancelled ) {
					foreach ( $row->getElementsByTagName("a") as $link ) {
						$tournamentID = preg_replace("/.*([0-9][0-9])\-([0-9][0-9])\-([0-9][0-9][0-9][0-9][0-9][0-9]).*/", "$1$2$3", $link->getAttribute("href"));
						$tournamentIDs[count($tournamentIDs)] = $tournamentID;
					}
				}
			}			
		}
	}
	
	$lastPage = true;
	
	foreach ( $dom->getElementsByTagName("div") as $div ) {
		if ( $div->getAttribute("class") == "pagination" ) {
			$lastText = "";
			
			foreach ( $div->getElementsByTagName("a") as $url ) {
				$lastText = $url->textContent;
			}
			
			if ( strpos($lastText, "Next") !== false ) {
				$lastPage = false;
			}
		}
	}

	return $lastPage;
}

// This will take a tournament ID, download the latest information for that tournament, parse
// it into JSON format and save it to the database.
function updateTournamentId($tournamentID) {
	$url = "https://www.pokemon.com/us/play-pokemon/pokemon-events/" . preg_replace("/(..)(..)(......)/", "$1-$2-$3", $tournamentID) . "/";
	
	$dom = new DOMDocument;
	@$dom->loadHTML("<?xml encoding='utf-8' ?>" . file_get_contents($url));
	
	$main = $dom->getElementById("mainContent");
	
	$eventData = array();
	
	if ( $main ) {
		foreach ( $main->getElementsByTagName("form") as $form ) {
			foreach ( $form->getElementsByTagName("li") as $items ) {
				foreach ( $items->getElementsByTagName("label") as $label ) {
					$heading = $dom->saveXML($label);
					$heading = preg_replace("/^<label[^>]*>/i", "", $heading);
					$heading = preg_replace("/<\/label>$/i", "", $heading);
					$heading = camelCase($heading);
					
					$items->removeChild($label);
				}
				
				$content = $dom->saveXML($items);
				$content = preg_replace("/^<li>/i", "", $content);
				$content = preg_replace("/<\/li>$/i", "", $content);
				$content = preg_replace("/^<span[^>]*>/i", "", $content);
				$content = preg_replace("/<\/span>$/i", "", $content);
				
				if ( $heading == "organizerEmail" ) {
					// This isn't accessible, so we skip it.
				} elseif ( $heading == "tournamentID" ) {
					$content = preg_replace("/[^0-9]/", "", $content);
					$eventData[$heading] = trim($content);	
				} elseif ( $heading == "viewOnMap" ) {
					foreach ( $items->getElementsByTagName("a") as $mapUrl ) {
						$content = $mapUrl->getAttribute("href");
					}
					
					$content = preg_replace("/^.*q=/", "", $content);
					$content = preg_replace("/ \(.*$/", "", $content);
					
					$eventData["coordinates"] = explode(", ", $content);	
				} elseif ( $heading == "website" || $heading == "onlineRegistration" ) {
					foreach ( $items->getElementsByTagName("a") as $baseUrl ) {
						$content = $baseUrl->getAttribute("href");
					}
		
					$eventData[$heading] = trim($content);	
				} elseif ( $heading == "date" ) {
					$eventData[$heading] = strtotime($content);
				} elseif ( $heading == "registration" ) {
					$eventData[$heading] = explode(" to ", $content);
				} elseif ( $heading == "leagueChallenge" || $heading == "leagueCup" ) {
					$content = preg_replace("/<a[^>]*>/i", "", $content);
					$content = preg_replace("/<\/a> */i", "", $content);

					$eventData[$heading] = trim($content);						
				} elseif ( $heading == "details" ) {
					$content = preg_replace("/<p><\/p>/i", "", $content);
					$content = preg_replace("/<p>/i", "", $content);
					$content = preg_replace("/<br[^>]*>/i", " ", $content);
					$content = preg_replace("/<\/p> */i", "[~lf~]", $content);
					$content = str_replace("\n", "", $content);
					$content = str_replace("\r", "", $content);
					$content = preg_replace("/\[~lf~\]$/", "", $content);
					
					$eventData[$heading] = explode("[~lf~]", $content);
				} else {
					$content = preg_replace("/^None$/", "", trim($content));
					$eventData[$heading] = $content;
				}
			}
		}
		
		$tzUrl = "http://api.timezonedb.com/v2.1/get-time-zone?key=" . TIMEZONE_API_KEY . "&format=json&by=position&lat=";
		$tzUrl .= $eventData["coordinates"][0] . "&lng=" . $eventData["coordinates"][1];
		
		$tzData = json_decode(@file_get_contents($tzUrl), true);
	
		$eventData["countryCode"] = $tzData["countryCode"];
		$eventData["countryName"] = $tzData["countryName"];
		$eventData["zoneName"] = $tzData["zoneName"];
		
		$json = json_encode($eventData, JSON_UNESCAPED_UNICODE);
		if ( $eventData["countryName"] ) {
			// We also cache the timezone data at this point. This means write access is only needed in
			// this script, which helps prevent people trying to exploit the input form.
			cacheTimezoneData($tzData["zoneName"]);
		
			echo json_encode($eventData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
			saveToDatabase($json);
		}	
	} else {
		flushDeletedEventIDs([$tournamentID]);
	}
}

// Uses tzurl.org to create VTIMEZONE blocks.
function cacheTimezoneData($timezone) {
	$mysqli = new mysqli(DB_HOST, DB_UPDATE_USER, DB_UPDATE_PASS, DB_NAME);

	$sql = "Select vTimezone From timezones Where timezone = '" . $timezone . "';";
	$result = $mysqli->query($sql);
	$data = null;
	
	if ( $result->num_rows == 0 ) {
		$timezoneData = file_get_contents("http://tzurl.org/zoneinfo-outlook/" . $timezone);	
		$timezoneData = preg_replace("/^.*BEGIN\:VTIMEZONE/si", "BEGIN:VTIMEZONE", $timezoneData);
		$timezoneData = preg_replace("/END\:VTIMEZONE.*$/si", "END:VTIMEZONE\r\n", $timezoneData);
		
		$sql = "Insert Into timezones ( timezone, vTimezone ) Values ( '" . $timezone . "', '";
		$sql .= $mysqli->real_escape_string($timezoneData) . "' );";
		
		$mysqli->query($sql);
	}
	
	$result->free();
	$mysqli->close();
}

// This function saves the actual JSON tournament to the database. It assumes a delete first even
// if the tournament isn't already there.
function saveToDatabase($json) {
	$mysqli = new mysqli(DB_HOST, DB_UPDATE_USER, DB_UPDATE_PASS, DB_NAME);
	$data = json_decode($json, true);
	
	$sql = "Delete From events Where tournamentID = " . $data["tournamentID"];
	$mysqli->query($sql);
	
	$sql = "Insert Into events ( tournamentID, category, date, product, premierEvent, premierGroup, countryName, provinceState, eventJson ) Values ( ";
	$sql .= $mysqli->real_escape_string($data["tournamentID"]) . ", '" . $mysqli->real_escape_string($data["category"]) . "', '";
	$sql .= date('Y/m/d', $data["date"]) . "', '" . $mysqli->real_escape_string($data["product"]) . "', '";
	$sql .= $mysqli->real_escape_string($data["premierEvent"]) . "', '', '" . $mysqli->real_escape_string($data["countryName"]);
	$sql .= "', '" . $mysqli->real_escape_string($data["provinceState"]) . "', '";
	$sql .= $mysqli->real_escape_string($json) . "' );";
	
	$mysqli->query($sql);
	echo "\nError: " . $mysqli->error . "\n";
	$mysqli->close();
}

// This function deletes tournament IDs in bulk.
function flushDeletedEventIDs($deletedTournamentIDs) {
	if ( count($deletedTournamentIDs) == 0 ) return;
	
	$mysqli = new mysqli(DB_HOST, DB_UPDATE_USER, DB_UPDATE_PASS, DB_NAME);
	$sql = "Update events Set deleted = 1 Where tournamentID In ( " . implode(",", $deletedTournamentIDs) . " )";

	$mysqli->query($sql);	
	$mysqli->close();
}

// This returns an array of all the current tournament IDs starting from today's date. This is because
// once tournaments are finished, you need to be logged into the website to see them, so we don't touch
// tournaments that occur in the past.
function getAllCurrentStoredIDs() {
	$mysqli = new mysqli(DB_HOST, DB_UPDATE_USER, DB_UPDATE_PASS, DB_NAME);
	$sql = "Select tournamentID From events Where date >= CURRENT_DATE And deleted = 0;";
	$result = $mysqli->query($sql);
	
	$tournamentIDs = array();
	
	while ( $tournament = $result->fetch_assoc() ) {
		$tournamentIDs[count($tournamentIDs)] = $tournament["tournamentID"];
	}
	
	$result->free();
	$mysqli->close();
	
	return $tournamentIDs;
}

// To avoid excessive downloads, we only refresh tournament details once a week, or daily if the event is
// less than a week away. This returns all the tournament IDs that meet that criteria to queue them to be
// refreshed.
function getExpiredTournamentIDs() {
	$cacheTime = 86400 * 7;
	
	$mysqli = new mysqli(DB_HOST, DB_UPDATE_USER, DB_UPDATE_PASS, DB_NAME);
	$sql = "Select tournamentID From events Where (Date_Add(lastUpdated, INTERVAL " . $cacheTime . " second) < CURRENT_TIMESTAMP Or ";
	$sql .= "(Date_Sub(date, INTERVAL 7 day) < CURRENT_DATE And Date_Add(lastUpdated, INTERVAL 86400 second) < CURRENT_TIMESTAMP)) And ";
	$sql .= "deleted = 0 And date >= CURRENT_DATE;";
	$result = $mysqli->query($sql);

	$tournamentIDs = array();
	
	while ( $tournament = $result->fetch_assoc() ) {
		$tournamentIDs[count($tournamentIDs)] = $tournament["tournamentID"];
	}
	
	$result->free();
	$mysqli->close();
	
	return $tournamentIDs;	
}

// Handled cleansing of the province names. Seems some countries don't have a standard list
// available on the Pokemon site and are freeform text.
function fixProvinces() {
	$sql = "Update events Set provinceState = Case ";
	$sql .= "When provinceState = 'Vic' Then 'Victoria' ";
	$sql .= "When provinceState = 'NSW' Then 'New South Wales' ";
	$sql .= "When provinceState = 'NT' Then 'Northern Territory' ";
	$sql .= "When provinceState = 'QLD' Then 'Queensland' ";
	$sql .= "When provinceState = 'Select a State' Then '' ";
	$sql .= "When provinceState = 'Tas' Then 'Tasmania' ";
	$sql .= "When provinceState = 'WA' Then 'Western Australia' ";
	$sql .= "When provinceState = 'ACT' Then 'Australian Capital Territory' ";
	$sql .= "Else provinceState End ";
	$sql .= "Where countryName = 'Australia';";

	$mysqli = new mysqli(DB_HOST, DB_UPDATE_USER, DB_UPDATE_PASS, DB_NAME);
	$mysqli->query($sql);
	$mysqli->close();
}

// Group premier events into single items. This is helpful when dealing with things like League
// or Premier Challenges that have a different premier event label every month.
function addPremierGroups() {
	$sql = "Update events Set premierGroup = Case ";
	$sql .= "When premierEvent Like '%Regional%' Then 'Regional Championship' ";
	$sql .= "When premierEvent Like '%Special%' Then 'Special Championship' ";
	$sql .= "When premierEvent Like '%Cup%' Then 'League Cup' ";
	$sql .= "When premierEvent Like '%League%Challenge%' Then 'League Challenge' ";
	$sql .= "When premierEvent Like '%Premier%Challenge%' Then 'Premier Challenge' ";
	$sql .= "When premierEvent Like '%Midseason%Showdown%' Then 'Midseason Showdown' ";
	$sql .= "When premierEvent Like '%Prerelease%' Then 'Prerelease' ";
	$sql .= "Else '' End ";
	$sql .= "Where premierEvent <> '';";

	$mysqli = new mysqli(DB_HOST, DB_UPDATE_USER, DB_UPDATE_PASS, DB_NAME);
	$mysqli->query($sql);
	$mysqli->close();
}

?>