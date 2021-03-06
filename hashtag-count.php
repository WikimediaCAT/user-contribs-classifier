<?php

require_once( __DIR__ . '/vendor/autoload.php' );
require_once( __DIR__ . '/lib/scores.php' );

use \Mediawiki\Api as MwApi;
use \Mediawiki\Api\ApiUser;
use \Wikibase\Api as WbApi;
use \Mediawiki\DataModel as MwDM;
use \Wikibase\DataModel as WbDM;

use \League\Csv\Reader;

ini_set('memory_limit', '-1'); # Comment if not needed

// Detect commandline args
$conffile = 'config.json';
$taskname = null; // If no task given, exit

if ( count( $argv ) > 1 ) {
	$conffile = $argv[1];
}

if ( count( $argv ) > 2 ) {
	$taskname = $argv[2];
}
// Detect if files
if ( ! file_exists( $conffile ) ) {
	die( "Config file needed" );
}

$confjson = json_decode( file_get_contents( $conffile ), 1 );

$wikiconfig = null;

if ( array_key_exists( "wikipedia", $confjson ) ) {
	$wikiconfig = $confjson["wikipedia"];
}

if ( array_key_exists( "tasks", $confjson ) ) {
	$tasksConf = $confjson["tasks"];
}

$tasks = array_keys( $tasksConf );
$props = null;

if ( count( $tasks ) < 1 ) {
	// No task, exit
	exit;
}

if ( ! $taskname ) {
	echo "No task specified!";
	exit;
} else {
	if ( in_array( $taskname, $tasks ) ) {
		$props = $tasksConf[ $taskname ];
	} else {
		// Some error here. Stop it
		exit;
	}
}

$pages = array( );
$newpages = array( );
$oldpages = array( );

$wpapi = Mwapi\MediawikiApi::newFromApiEndpoint( $wikiconfig["url"] );

$rclimit = 1000;


// Login
if ( array_key_exists( "user", $wikiconfig ) && array_key_exists( "password", $wikiconfig ) ) {

	$wpapi->login( new ApiUser( $wikiconfig["user"], $wikiconfig["password"] ) );

}

// Get all pages with a certain tag
if ( array_key_exists( "tag", $props )  &&  array_key_exists( "startdate", $props ) ) {

	#https://ca.wikipedia.org/w/api.php?action=query&list=recentchanges&rcprop=comment|user|title|timestamp|sizes|redirect

	$params = array( "list" => "recentchanges", "rcprop" => "comment|title|user", "rclimit" => $rclimit, "rcdir" => "newer", "rcnamespace" => 0 );

	if ( array_key_exists( "startdate", $props ) ) {
		$params["rcstart"] = $props["startdate"];
	}
	if ( array_key_exists( "enddate", $props ) ) {
		$params["rcend"] = $props["enddate"];
	}

	if ( array_key_exists( "namespace", $props ) ) {
		$params["rcnamespace"] = $props["namespace"];
	}

	$pages = array();

	if ( array_key_exists( "web", $props ) && $props["web"] ) {

		$pages = retrieveHashTagWeb( $pages, $params, $props );

	} else {

		$pages = retrieveWpQuery( $pages, $wpapi, $params, null, $props );
	}

	//var_dump( $pages );
	if ( count( $pages ) == 0 ) {
		# No pages, exit...
		exit();
	}
	//exit();

	if ( array_key_exists( "store", $props ) ) {

		echo "Database!";
		$database = new SQLite3($props['store']);

		$sqltable = "create table if not exists `tags` ( page varchar(255), user varchar(255), num int(5), primary key (page, user) ); ";
		$database->exec( $sqltable );

		// Store in DB
		storeInDb( $database, $pages );

		// Retrieve from DB
		// Here we retrieve from DB to $pages
		$pages = selectFromDb( $database );
		//var_dump( $pages );
		//exit();
	}

	if ( array_key_exists( "notnew", $props ) && $props["notnew"] ) {

		$pages = filterInNew( $pages, $wpapi, $props["startdate"], false );

	}

	if ( array_key_exists( "onlynew", $props ) && $props["onlynew"] ) {

		$pages = filterInNew( $pages, $wpapi, $props["startdate"], true );

	}

	if ( array_key_exists( "checknew", $props ) && $props["checknew"] ) {

		$newpages = filterInNew( $pages, $wpapi, $props["startdate"], true, true );
	}

	$arrpages = array_keys( $pages );
	$arrnewpages = array_keys( $newpages );

	if ( count( $arrpages ) > 0 ) {
		foreach ( $arrpages as $page ) {
			if ( in_array( $page, $arrnewpages ) ) {
				continue;
			} else {
				$oldpages[$page] = 1;
			}
		}
	}

	echo "NEW\n";
	var_dump( $newpages );
	echo "OLD\n";
	var_dump( $oldpages );

	list( $history, $elements ) = retrieveHistoryPages( $pages, $wpapi, $props );
	// var_dump( $history );
	var_dump( $elements );
	// exit();

	$filterin = null;
	if ( array_key_exists( "filterin", $props ) ) {
		$filterin = applyFilterIn( $history, $props["filterin"] );
	}


	if ( array_key_exists( "filterout", $props ) ) {
		$history = applyFilterOut( $history, $props["filterout"] );
	}
	// var_dump( $history );

	// Get users from tags
	$users = retrieveUsers( $pages );
	// var_dump( $users );

	// Get counting from users
	// TODO: Provide extra counts: e. g., images and refs

	// var_dump( $filterin );
	$counts = getCounts( $history, $users, $filterin );
	$edits = getTotalNumEditions( $history, $users );
	echo "COUNTS\n";
	var_dump( $counts );

	// Counts of Biblio, Images and so (aka elements)
	$elements_counts = getElementsCounts( $elements, $users );
	echo "ELCOUNTS\n";
	var_dump( $elements_counts );

	// Assign scores
	if ( array_key_exists( "scores", $props ) ) {
		$scores = assignScores( $counts, $edits, $elements_counts, $wpapi, $props, $newpages, $oldpages );
		echo "SCORES\n";
		var_dump( $scores );

		printScores( $scores, "wiki", $wpapi, $counts, $elements_counts, $edits, $props );

	} else {
		var_dump( $counts );
		printScores( null, "wiki", $wpapi, $counts, $elements_counts, $edits, $props );

	}



}

function storeInDb( $database, $pages ) {

	$inserts = array();

	foreach ( $pages as $page => $users ) {

		if ( ! array_key_exists( $page, $inserts ) ) {
			$inserts[ $page ] = array( );
		}

		foreach ( $users as $user ) {

			if ( ! array_key_exists( $user, $inserts[$page] ) ) {
				$inserts[ $page ][ $user ] = 1;
			} else {
				$inserts[ $page ][ $user ]++;
			}

		}
	}

	foreach ( $inserts as $page => $structUser ) {


		foreach ( $structUser as $user => $count )  {

			// echo $page."\t".$user."\t".$count."\n";

			$sqlquery = " ";
			$statement = $database->prepare('select num from `tags` where page = :page and user = :user ');
			$statement->bindValue(':page', $page);
			$statement->bindValue(':user', $user);

			$results = $statement->execute();
			$rows = $results->fetchArray();

			if ( ! $rows ) {

				$statement = $database->prepare('insert into `tags` ( page, user, num ) values ( :page, :user, :num ) ');
				$statement->bindValue(':page', $page);
				$statement->bindValue(':user', $user);
				$statement->bindValue(':num', $count);

				$results = $statement->execute();

			} else {

				if ( count( $rows ) > 0 ) {

					$preCount = $rows[0]["num"];

					if ( $preCount < $count ) {

						$statement = $database->prepare('update `tags` set num = :num where page = :page and user = :user  ');
						$statement->bindValue(':page', $page);
						$statement->bindValue(':user', $user);
						$statement->bindValue(':num', $count);

						$results = $statement->execute();

					}

				}
			}

		}

	}

	return true;

}

function selectFromDb( $database ) {

	$pages = array();

	$sql = 'select page, user, num from `tags`';

	$result = $database->query($sql);

	while($res = $result->fetchArray(SQLITE3_ASSOC)){

		if ( ! array_key_exists( $res['page'], $pages ) ) {
			$pages[ $res['page'] ] = array( );
		}

		if ( ! array_key_exists( $res['user'], $pages[ $res['page'] ] ) ) {
			array_push( $pages[ $res['page'] ], $res['user'] );
		}

		$count = $res['num'] - 1;

		for ( $c= 0; $c < $count; $c++ ) {

			array_push( $pages[ $res['page'] ], $res['user'] );


		}

	}


	return $pages;

}

function filterInNew( $pages, $wpapi, $startdate, $onlynew=false, $checknew=false ) {

	$novelty = array( );
	$newpages = array( );

	$params = array( "prop" => "revisions", "redirects" => true, "rvlimit" => 1, "rvdir" => "newer", "rvprop" => "timestamp" );

	foreach ( $pages as $page => $struct ) {

		$params["titles"] = $page;
		$contribRequest = new Mwapi\SimpleRequest( 'query', $params  );

		$outcome = $wpapi->postRequest( $contribRequest );

		if ( array_key_exists( "query", $outcome ) ) {

			if ( array_key_exists( "pages", $outcome["query"] ) ) {

				foreach (  $outcome["query"]["pages"] as $key => $struct ) {

					if ( array_key_exists( "revisions", $struct ) ) {

						if ( array_key_exists( "timestamp", $struct["revisions"][0] ) ) {

							$timestamp = $struct["revisions"][0]["timestamp"];

							//if ( ! $onlynew ) {
							//
							//	# Remove new pages
							//	if ( strtotime( $timestamp ) >= strtotime( $startdate ) ) {
							//		if ( $checknew ) {
							//			$newpages[$page] = 1;
							//		} else {
							//			unset( $pages[$page] );
							//		}
							//	}
							//
							//} else {
							//
							//	# Remove old pages
							//	if ( strtotime( $timestamp ) < strtotime( $startdate ) ) {
							//		if ( $checknew ) {
							//			$newpages[$page] = 1;
							//		} else {
							//			unset( $pages[$page] );
							//		}
							//	}
							//
							//}

							if ( strtotime( $timestamp ) >= strtotime( $startdate ) ) {
								$novelty[$page] = 1;
							} else {
								$novelty[$page] = 0;
							}

						}
					}

				}


			}

		}
	}

	foreach( $novelty as $page => $val ) {

		if ( $checknew ) {

			if ( $val > 0 ) {
				$newpages[$page] = 1;
			}

		} else {

			if ( ! $onlynew ) {
				if ( $val > 0 ) {
					unset( $pages[$page] );
				}
			} else {
				if ( $val == 0 ) {
					unset( $pages[$page] );
				}
			}
		}

	}

	if ( $checknew ) {
		return $newpages;
	} else {
		return $pages;
	}
}

function retrieveHashTagWeb( $pages, $params, $props ) {

	$pages = array( );

	if ( array_key_exists( "tag", $props ) ) {

		$queryParams = array();

		$tag = $props["tag"];
		$project = $props["web"];

		if ( is_array( $tag ) ) {
			$tag = $tag[0];
		}

		$startDate = assignDateWeb( $props, "startdate" );
		$endDate = assignDateWeb( $props, "enddate" );

		$queryParams["query"] = $tag;
		$queryParams["project"] = $project;
		$queryParams["startdate"] = $startDate;
		if ( $endDate ) {
			$queryParams["enddate"] = $endDate;
		}

		// Perform query
		$queryStr =  http_build_query( $queryParams );

		$csvContent = file_get_contents( 'https://hashtags.wmflabs.org/csv/?'.$queryStr );
		$reader = Reader::createFromString( $csvContent );
		$reader->setDelimiter(",");

		$csvData = $reader->getRecords();
		$pages = getWebPages( $csvData, $props );

	}

	return $pages;
}

function getWebPages( $rows, $props ) {

	$pages = array( );
	$startDate = $props["startdate"];
	$endDate = $props["enddate"];

	foreach ( $rows as $row ) {

		# Skip pages in user namespace

		if ( preg_match ( "/^Us\S{2,8}:/" , $row[3] ) === 1 ) {
			continue;
		}

		if ( compareDates( $row[1], $startDate, $endDate ) ) {

			if ( ! array_key_exists( $row[3], $pages ) ) {
					$pages[$row[3]] = array( );
			}

			array_push( $pages[$row[3]], $row[2] );
		}

	}

	return $pages;
}


function compareDates( $date, $startDate, $endDate ) {

	$out = false;

	if ( $startDate ) {
		if ( $startDate <= $date ) {
			$out = true;
		}
	}

	if ( $endDate ) {
		if ( $endDate < $date ) {
			$out = false;
		}
	}

	return $out;

}

function assignDateWeb( $params, $key ) {

	$date = null;

	if ( array_key_exists( $key, $params ) ) {

		$preDateArr = explode( " ",  $params[$key] );
		$date = $preDateArr[0];
	}

	if ( $key == "startdate" ) {
		$date = date('Y-m-d', strtotime($date. ' - 1 days'));
	}

	if ( $key == "enddate" ) {
		$date = date('Y-m-d', strtotime($date. ' + 1 days'));
	}

	return $date;
}

function retrieveWpQuery( $pages, $wpapi, $params, $uccontinue, $props ) {

	if ( $uccontinue ) {
		$params["rccontinue"] = $uccontinue;
	}

	// echo "*" .$uccontinue."\n";

	$userContribRequest = new Mwapi\SimpleRequest( 'query', $params  );

	$outcome = $wpapi->postRequest( $userContribRequest );

	$uccontinue = null;

	if ( array_key_exists( "continue", $outcome ) ) {

		if ( array_key_exists( "rccontinue", $outcome["continue"] ) ) {

			$uccontinue = $outcome["continue"]["rccontinue"];
		}
	}

	if ( array_key_exists( "query", $outcome ) ) {

		if ( array_key_exists( "recentchanges", $outcome["query"] ) ) {

			$pages = processPages( $pages, $outcome["query"]["recentchanges"], $props );

		}

	}

	if ( $uccontinue ) {
		$pages = retrieveWpQuery( $pages, $wpapi, $params, $uccontinue, $props );
	}

	return $pages;

}

function retrieveHistoryPages( $pages, $wpapi, $props ) {

	$history = array();
	$elements = array();
	$batch = 5; // Batch to query, less API requests

	$rvlimit = 2500;
	$params = array( "prop" => "revisions", "redirects" => true, "rvlimit" => $rvlimit, "rvdir" => "newer", "rvprop" => "user|size|ids" );


	if ( array_key_exists( "startdate", $props ) ) {
		$params["rvstart"] = $props["startdate"];
	}

	// If history start date, preference
	if ( array_key_exists( "hstartdate", $props ) ) {
		$params["rvstart"] = $props["hstartdate"];
	}

	if ( array_key_exists( "enddate", $props ) ) {
		$params["rvend"] = $props["enddate"];
	}

	// If history end date, preference
	if ( array_key_exists( "henddate", $props ) ) {
		$params["rvend"] = $props["henddate"];
	}

	// If history end date, preference
	if ( array_key_exists( "checkcomment", $props ) ) {
		$params["rvprop"] = $params["rvprop"]."|comment";
	}


	$s = 0;

	foreach( array_keys( $pages ) as $page ) {

		$params["titles"] = $page;
		$userContribRequest = new Mwapi\SimpleRequest( 'query', $params  );
		$outcome = $wpapi->postRequest( $userContribRequest );

		list( $history, $elements ) = processHistory( $history, $elements, $wpapi, $outcome, $props );

	}

	return array( $history, $elements );

}

function processHistory( $history, $elements, $wpapi, $outcome, $props ) {

	if ( array_key_exists( "query", $outcome ) ) {

		if ( array_key_exists( "pages", $outcome["query"] ) ) {

			foreach ( $outcome["query"]["pages"] as $page )  {

				$title = $page["title"];

				$history[$title] = array();
				$history[$title]["parentrev"] = null;
				$history[$title]["contribs"] = array();

				$elements[$title] = array();

				$parentid = null;
				$accsize = 0;

				if ( array_key_exists( "revisions", $page ) ) {

					$revisions = $page["revisions"];

					if ( count( $revisions ) > 0 ) {

						# Starting point
						if ( array_key_exists( "parentid", $revisions[0] ) ) {

							$parentid = $revisions[0]["parentid"];

							# If page not created, proceed
							if ( $parentid > 0 ) {

								#https://ca.wikipedia.org/w/api.php?action=query&revids=20872217&rvprop=size|user|timestamp&prop=revisions|categories
								$params = array( "revids" => $parentid, "rvprop" => "size|user|timestamp", "prop" => "revisions|categories" );
								$userContribRequest = new Mwapi\SimpleRequest( 'query', $params  );
								$outcome = $wpapi->postRequest( $userContribRequest );

								if ( array_key_exists( "query", $outcome ) ) {

									if ( array_key_exists( "pages", $outcome["query"] ) ) {

										foreach ( $outcome["query"]["pages"] as $pageid => $struct ) {

											if ( array_key_exists( "revisions", $struct ) ) {
												if ( count( $struct["revisions"] ) > 0 ) {

													$history[$title]["parentrev"] = $struct["revisions"][0];
													$history[$title]["parentrev"]["categories"] = array();
													$accsize = $struct["revisions"][0]["size"];
												}
											}

											if ( array_key_exists( "categories", $struct ) ) {

												foreach ( $struct["categories"] as $cat ) {

													if ( array_key_exists( "title", $cat ) ) {
														array_push( $history[$title]["parentrev"]["categories"], $cat["title"] );
													}

												}

											}

										}

									}
								}

							}

						}

						$presize = $accsize;

						# Iterate revisions and add user contributions
						foreach( $revisions as $revision ) {

							$user = $revision["user"];
							$size = $revision["size"];
							$revid = $revision["revid"];
							$parentid = $revision["parentid"];

							$comment = null;

							if ( array_key_exists( "comment", $revision ) ) {

								$comment = $revision["comment"];
							}

							if ( ! array_key_exists( $user, $history[$title]["contribs"] ) ) {

								$history[$title]["contribs"][$user] = array();
							}

							array_push( $history[$title]["contribs"][$user], $size - $presize );

							$presize = $size;

							# Only if diff
							if ( array_key_exists( "checkcontent", $props ) ) {

								#https://ca.wikipedia.org/w/api.php?action=compare&torelative=next&fromrev=22684935&prop=diff
								#Provide comparison

								if ( $parentid > 0 ) {

									$params = array( "torev" => $revid, "fromrev" => $parentid, "prop" => "diff" );
									$userContribRequest = new Mwapi\SimpleRequest( 'compare', $params  );
									$outcome = $wpapi->postRequest( $userContribRequest );

									if ( ! array_key_exists( $user, $elements[$title] ) ) {
										$elements[$title][$user] = array();
									}

									if ( $outcome && array_key_exists( "compare", $outcome ) && array_key_exists( "*", $outcome["compare"] ) ) {

										echo "?? GLOBAL\n";
										echo "$user\n";


										foreach ( $props["checkcontent"] as $key => $patterns ) {

											$mode = "default";

											// Temporal hack
											if ( $key === "efemèrides" || $key === "imatges" ) {
												$mode = "noins";
											}

											$content = parseMediaWikiDiff( $outcome["compare"]["*"], $mode );

											$elements[$title][$user] = processCheckContent( $elements[$title][$user], $content, $key, $patterns );

										}
										//var_dump( $elements );
									}

								} else {

									// https://ca.wikipedia.org/w/api.php?action=query&prop=revisions&revids=22894857&rvprop=content&rvslots=main
									$params = array( "revids" => $revid, "prop" => "revisions", "rvprop" => "content", "rvslots" => "main" );
									$userContribRequest = new Mwapi\SimpleRequest( 'query', $params  );
									$outcome = $wpapi->postRequest( $userContribRequest );

									if ( ! array_key_exists( $user, $elements[$title] ) ) {
										$elements[$title][$user] = array();
									}

									if ( $outcome && array_key_exists( "query", $outcome ) && array_key_exists( "pages", $outcome["query"] ) ) {

										$pagesQuery = array_keys( $outcome["query"]["pages"] );

										if ( count( $pagesQuery ) > 0 ) {

											$page = $pagesQuery[0];

											if ( array_key_exists( "revisions", $outcome["query"]["pages"][$page] ) ) {


												$revs = $outcome["query"]["pages"][$page]["revisions"];

												if ( count( $revs ) > 0 ) {

													$revContent = $revs[0];

													if ( array_key_exists( "slots", $revContent ) ) {

														if ( array_key_exists( "main", $revContent["slots"] ) ) {

															$content = $revContent["slots"]["main"]["*"];

															echo "?? GLOBAL\n";
															echo "$user\n";


															foreach ( $props["checkcontent"] as $key => $patterns ) {

																$mode = "default";

																$elements[$title][$user] = processCheckContent( $elements[$title][$user], $content, $key, $patterns );

															}
															//var_dump( $elements );

														}
													}

												}

											}

										}

									}


								}

							}

							// Checking comment for /* Revisada */ o /* Validada */
							if ( array_key_exists( "checkcomment", $props ) ) {

								if ( ! array_key_exists( $user, $elements[$title] ) ) {
									$elements[$title][$user] = array();
								}

								foreach ( $props["checkcomment"] as $key => $patterns ) {

									$elements[$title][$user] = processCheckContent( $elements[$title][$user], $comment, $key, $patterns );

								}
							}
						}

					}

				}

			}

		}

	}

	return array( $history, $elements );
}



function processPages( $pages, $contribs, $props ) {

	foreach ( $contribs as $contrib ) {

		if ( array_key_exists( "title", $contrib ) ) {

			$title = strval( $contrib["title"] );
			// echo $title, "\n";

			$user = strval( $contrib["user"] );

			if ( array_key_exists( "comment", $contrib ) ) {

				$comment = strval( $contrib["comment"] );

				if ( detectTag( $comment, $props["tag"] ) ) {

					if ( array_key_exists( $title, $pages ) ) {
						array_push( $pages[$title], $user );
					} else {
						$pages[$title] = array( $user );
					}
				}

			}

		}
	}
	return $pages;
}

function detectTag( $comment, $tags ){

	foreach ( $tags as $tag ) {

		if ( strpos( strtolower( $comment ), strtolower( $tag ) ) !== false ) {
			return true;
		}

	}

	return false;

}

function retrieveUsers( $pages ) {

	$users = array();

	foreach ( $pages as $page => $us ) {

		foreach ( array_unique( $us ) as $elm ) {
			array_push( $users, $elm );
		}
	}

	return array_unique( $users );

}

function applyFilterIn( $history, $filterin ) {

	$toinclude = array( );

	foreach ( $filterin as $filter ) {

		if ( array_key_exists( "maxsize", $filter ) ) {

			$maxsize = $filter["maxsize"];

			foreach( $history as $page => $struct ) {

				if ( array_key_exists( "parentrev", $struct ) ) {

					if ( $struct["parentrev"] && array_key_exists( "size", $struct["parentrev"] ) ) {

						if ( $struct["parentrev"]["size"] <= $maxsize ) {
							$toinclude[$page] = true;
						}

					}
				}
			}

		}

		if ( array_key_exists( "catregex", $filter ) ) {

			$catregex = $filter["catregex"];

			foreach( $history as $page => $struct ) {

				if ( array_key_exists( "parentrev", $struct ) ) {

					if ( is_array( $struct["parentrev"] ) && array_key_exists( "categories", $struct["parentrev"] ) ) {

						foreach ( $struct["parentrev"]["categories"] as $category ) {

							preg_match( $catregex, $category, $matches );

							if ( count( $matches ) > 1 ) {

								$toinclude[$page] = true;
							}
						}
					}
				}
			}
		}
	}

	// TO CHECK THIS

	return $toinclude;

}

function applyFilterOut( $history, $filterout ) {

	foreach ( $filterout as $filter ) {

		if ( array_key_exists( "pages", $filter ) ) {

			$pages = $filter["pages"];

			foreach( $history as $page => $struct ) {


				if ( in_array( $page, $pages ) ) {
					unset( $history[$page] );
				}

			}

		}

	}

	return $history;
}

function getCounts( $history, $users ) {

	$counts = array();

	// Iterate by user
	foreach ( $users as $user ) {

		$counts[$user] = array();

		foreach ( $history as $page => $struct ) {

			$contribs = $struct["contribs"];

			if ( array_key_exists( $user, $contribs ) ) {

				$counts[$user][$page] = array_sum( $contribs[$user] );

			}

		}
	}

	return( $counts );
}

function getElementsCounts( $elements, $users ) {

	$counts = array();

	// Iterate by user
	foreach ( $users as $user ) {

		if ( ! array_key_exists( $user, $counts) ) {
			$counts[$user] = array();
		}

		foreach ( $elements as $page => $struct ) {

			if ( array_key_exists( $user, $struct ) ) {

				if ( ! array_key_exists( $page, $counts[$user] ) ) {
					$counts[$user][$page] = array();
				}

				foreach ( $struct[$user] as $type => $val ) {

					if ( ! array_key_exists( $type, $counts[$user][$page] ) ) {
						$counts[$user][$page][$type] = 0 ;
					}

					$counts[$user][$page][$type] = $counts[$user][$page][$type] + $val;
				}

			}
		}
	}

	return( $counts );
}

function getTotalNumEditions( $history, $users ) {

	$counts = array();

	// Iterate by user
	foreach ( $users as $user ) {

		$counts[$user] = 0;

		foreach ( $history as $page => $struct ) {

			$contribs = $struct["contribs"];

			if ( array_key_exists( $user, $contribs ) ) {

				$counts[$user] =  $counts[$user] + count( $contribs[$user] );

			}

		}
	}

	return( $counts );
}

/** Function for parsing diff HTML from MediaWiki **/
function parseMediaWikiDiff( $diffhtml, $mode="default" ){

	$text = "";
	// var_dump( $diffhtml );
	$lines = explode( "\n", $diffhtml );

	foreach ( $lines as $line ) {

		if ( $mode == "noins" ) {

			if ( preg_match( "/diff-addedline/", $line ) && ! preg_match( "/ins class\=/", $line ) && ! preg_match( "/del class\=/", $line ) ) {
					$text.= $line;
			} else {

				if ( preg_match( "/diffchange/", $line ) && ! preg_match( "/ins class\=/", $line ) && ! preg_match( "/del class\=/", $line ) ) {
						$text.= $line;
				}
			}

		} else {
			if ( preg_match( "/diff-addedline/", $line ) ) {

				$text.= $line."\n";

			} else {

				if ( preg_match( "/diffchange/", $line ) ) {
					$text.= $line;
				}
			}
		}

	}

	// <td class="diff-addedline"><div>*<ins class="diffchange diffchange-inline">[[</ins>1930<ins class="diffchange diffchange-inline">]]</ins> -<ins class="diffchange diffchange-inline"> </ins>Thomasville, [[Geòrgia]], [[Estats Units]]: '''[[Joanne Woodward]]''', [[actriu]] estatunidenca guanyadora d'un [[Oscar a la millor actriu]] el [[1957]].</div></td>


	// content to parse: diff-addedline <td class=\"diff-addedline\"><div>| [[Maria Teresa Casals i Rubio]]</div></td>
	// TODO: Consider deletedline
	// <ins class=\"diffchange diffchange-inline\">4t</ins>
	// Consider: <del class=\"diffchange diffchange-inline\">4art</del>

	//echo "**TAL\n";
	//echo $text;
	//echo "\n";

	return $text;
}


/** Process content types inside rev content, e.g., ref, img, etc. **/
function processCheckContent( $prev, $content, $key, $patterns ) {

	$elements = array();

	if ( ! array_key_exists( $key, $elements ) ) {
		$elements[$key] = 0;
	}

	$elements[$key] = checkContent( $content, $patterns );

	if ( ! array_key_exists( $key, $prev ) ) {
		$prev[ $key ] = $elements[$key];
	} else {
		$prev[ $key ] = $prev[ $key ] + $elements[$key];
	}


	return $prev;
}

/** Simple function for checking content of revision or comment **/
function checkContent( $text, $patterns ) {

	$count = 0;

	$lines = explode( "\n", htmlspecialchars( $text ) );

	foreach( $lines as $line ) {

		$p = 0;


		foreach ( $patterns as $pattern ) {

			if ( $p == 0 ) {


				if ( substr_count( $line, $pattern ) > 0 ) {

					echo "** LINE: ", $line, "\n";
					echo "@ ", $pattern, "\n";

					$count++;
					$p++;

				}

			}
		}

	}

	echo "-- COUNT: $count\n";
	return $count;

}
