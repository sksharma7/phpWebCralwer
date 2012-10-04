<?php

#Database Functions

include_once("CONFIG_db.php");
# Above file defines the following variables: 
#
# $db_host = "localhost";
# $db_username = "jrandom";
# $db_password = "ASDF!!1!one1";
# $db_name = "somedb";
## $operator_email is used to mail the user on script completion
# $operator_email = "j.random@example.com";
# $whitelistdomain, $whitelistdomainlevel, $whitelistdomainlist; //NEED to document SAH
# 

function /*public*/ db_connect() {
	global $db_username, $db_password, $db_dns;
	try {
		$GLOBALS["db"] = new PDO($db_dsn, $db_username, $db_password);
	} catch (Exception $e) {
		global $operator_email;
		fprintf(STDERR,"ERROR: db_connect().\n" . $e->getMessage() . "\n" . db_error_info($GLOBALS["db"]) . "\n");	
		mail($operator_email, "phpCrawler Error", "Could not connect to db: " . db_error_info($GLOBALS["db"]) . "\n" . date('Y-m-d H:i:s') ."\n","FROM: " . $operator_email);
		die("Could not connect: " . $GLOBALS["db"]->errorInfo());
	}
}

function /*private*/ db_check_connection() {
	//TODO: Port to PDO
	//if( !mysql_ping($GLOBALS["db"]) ) db_connect();
}

#db_get_next_spider_target();
#db_store_html($seed,$downloaded_page['FILE'])
#db_store_link($seed,$resolved_address;

function /*private*/ db_run_select($strSQL,$arrParams=null,$returnVal=false) {
	/*global $db_name;
	mysql_select_db($db_name);*/
	#echo $strSQL . "\n";	
	$result=false;
	try {
		db_check_connection();
		//$result = mysql_query($strSQL,$GLOBALS["db"]);
		$statement = $GLOBALS["db"]->prepare($strSQL);
		$result = $statement->execute($arrParams);

	}
	catch(Exception $e)
	{
      fprintf(STDERR,"ERROR: db_run_select.\n" . $e->getMessage() . "\n" . db_error_info($statement) . "\n");	
	  echo "$strSQL\n" . $e->getMessage() ."\n"  . db_error_info($statement);
	  die();
	}
	
	if (!$result) {
		return null;
	}elseif (!$returnVal) {
		//$row = mysql_fetch_array($result, MYSQL_ASSOC);
		//$output = $row;
		$output = $statement->fetchAll(PDO::FETCH_ASSOC);
	} else  {
		//$row = mysql_fetch_array($result, MYSQL_NUM);
		$row = $statement->fetch(PDO::FETCH_NUM);
		if ($row==Null)
			$output = Null;
		else 
			$output = $row[0];
	}
	//mysql_free_result($result);
	db_close_cursor($statement);
	return $output;
}

function /*private*/ db_run_query($strSQL,$arrParams) {
	//mysql_select_db("DBNAME");
	#echo $strSQL . "\n";
	$result=false;
	try {
		db_check_connection();	
		//$result = mysql_query($strSQL,$GLOBALS["db"]);
		$statement = $GLOBALS["db"]->prepare($strSQL);
		$result = $statement->execute($arrParams);

	}
	catch(Exception $e)
	{
	  echo "$strSQL\n" . $e->getMessage() ."\n"  . db_error_info($statement);
	  db_close_cursor($statement);
	}
	if (!$result) {
		global $operator_email;
		print "ERROR: Query returned false:  $strSQL\n";
		fprintf(STDERR,"ERROR: Query returned false.\n" . db_error_info($statement) . "\n");
		mail($operator_email, "phpCrawler Error", "Query returned false with error: " . db_error_info($statement) . "\n" . date('Y-m-d H:i:s') ."\nQuery was:\n$strSQL\n","FROM: " . $operator_email);
		throw new Exception("ERROR: Query returned false:  \n$strSQL\n\n\n");
		#die("Query returned false:  $strSQL\n");
	}
}

function /*public*/ db_close() {
	#mysql_close();
}

function db_close_cursor($statement) {
	try {
		$statement->closeCursor();
	} catch(Exception $e) {
		//ignore
	}
}

function db_error_info($obj) {
	$out="";
	try {
		$out=$obj->errorInfo();
	} catch(Exception $e) {
		try {
			$out=$GLOBALS["db"]->errorInfo();
		} catch(Exception $e) {
			$out="(error info unavailable)";
		}
	}
	return $out;
}


#Cache
$cache=array();
$maxCacheSize=1500;
$minCacheSize=1000;
function checkCache($key) {
	global $cache;
	if (array_key_exists($key,$cache)) {
		return $cache[$key];
	}
	return null;
}

function addToCache($key,$val) {
	global $cache;
	global $maxCacheSize;
	
	$size=count($cache);
	if ($size>$maxCacheSize) {
		//remove elements from the front (low indicies) until we reach minCacheSize
		foreach ($cache as $key) {
			unset($cache[$key]);
			$size--;
			if ($size<$minCacheSize) break;
		}
	}
	$cahce[$key]=$val;
}
	

function db_get_next_to_harvest() {
	global $MAX_PENETRATION,$SAME_DOMAIN_FETCH_DELAY;
	$strSQL = "SELECT tblPages.*, tblDomains.dtLastAccessed from tblPages LEFT JOIN tblDomains ON tblPages.strDomain=tblDomains.strDomain WHERE " .
		" bolHarvested=0 ";
	if ($MAX_PENETRATION!=-1) $strSQL.=" AND iLevel < " . $MAX_PENETRATION;
	$strSQL .= " AND (ADDTIME(tblDomains.dtLastAccessed,'$SAME_DOMAIN_FETCH_DELAY')<CURRENT_TIMESTAMP OR tblDomains.dtLastAccessed IS NULL)";
	$strSQL.=" ORDER BY tblDomains.dtLastAccessed";
	$strSQL.=" LIMIT 1";
	
	//print "$strSQL\n";
	
	$result = db_run_select($strSQL);
	
	//print_r($result);
	
	if ($result==NULL) {//try without domain table
		$strSQL = "SELECT tblPages.*, CURRENT_TIMESTAMP AS dtLastAccessed from tblPages WHERE bolHarvested=0 LIMIT 1";
		//print "$strSQL\n";
		$result = db_run_select($strSQL);
		if ($result == NULL) return NULL; //No more pages
		//else wait the appropriate time to return a page of the same domain
		//print "SLEEP for same-domain page";
		sleep($SAME_DOMAIN_FETCH_DELAY);
	}
	
	//If we get here we do have a page to return and it is from a different domain or if from the same domain we have waited appropriately
	echo "Have page with id " . $result['iPageID'] . " about to update/insert tblDoamins\n";
	//is it in the domain table?
	if ($result['dtLastAccessed']==NULL) {
		//no. insert it
		$strUpdate = "INSERT into tblDomains (strDomain,dtLastAccessed) VALUES ('" . $result['strDomain'] . "',CURRENT_TIMESTAMP)";
	} else {
		$strUpdate = "UPDATE tblDomains SET dtLastAccessed=CURRENT_TIMESTAMP WHERE strDomain='" . $result['strDomain'] . "'";
	}
	//print "$strUpdate\n";
	db_run_query($strUpdate);
	
	//Housekeeping done, ready to return result
	return $result;
}

function db_marked_harvested($seed) {
	$strSQL = "UPDATE tblPages SET bolHarvested=1 WHERE iPageID=" . $seed["iPageID"];
	db_run_query($strSQL);
}

function db_store_html($seed,$strHTML,$strURL) {/*!! TODO: Encoding Issues, pull date from header*/
	#store strHTML and current datetime
	#echo "start db_store_html(....)\n";
	#echo "$strHTML";
	//ENCODING!!!!!!!!!!
	try {
		$enc = get_encoding($strHTML,true);
		if (strlen($enc)>0) 
			$enc = $enc . ",";
		$enc = $enc . "x-euc-jp,EUC-JP,JIS,SJIS,iso-8859-1,ASCII";
		#mb_detect_encoding($strHTML,$enc) 
		$strHTML = mb_convert_encoding($strHTML, "UTF-8", $enc);
		//$strHTML = html_entity_decode( $strHTML, ENT_QUOTES, "UTF-8" );
		//$strHTML = remove($strHTML,"<script","</script>");//strip JavaScript
		//$strHTML=strip_tags($strHTML);//Remove html tags
		$strHTML = strtolower_utf8($strHTML);
		$return = $strHTML;
		try {
			$strHTML = mysql_real_escape_string($strHTML);
			if ($strURL && clean_url($strURL)!=clean_url($seed["strURL"])) {
				$domain=mysql_real_escape_string(get_domain($strURL));
				$cleanURL=mysql_real_escape_string(clean_url($strURL));
				$url=mysql_real_escape_string($strURL);
				$strSQL = "UPDATE tblPages SET strURL='$url', "
					. " strCleanURL='$cleanURL', strHTML='" . $strHTML . "' WHERE iPageID=" . $seed["iPageID"];	
			} else {
				$strSQL = "UPDATE tblPages SET " .
					"strHTML='" . $strHTML . "' WHERE iPageID=" . $seed["iPageID"];
			}
			db_run_query($strSQL);
			#echo "end db_store_html(...)\n";
			#'" . date("YmdHi___NEED SECONDS__") . "'"
			#e.g. '20100131000000' 2010-01-31 00:00:00
			#CurDate(), CurTime, Now()
		} catch (Exception $e) {
			//ignore
		}
		return $return;
	} catch (Exception $e) {
		print "Exeception $e\n";
		return null;
	}
}


function db_store_link($seed,$link) {
	global $SAME_DOMAIN_FETCH_LEVEL;
	//echo "db_store_link($seed,$link)\n";
	#check if in tblPages
	#if not, store
	#get unique id, tblPages.iPageID
	#get if there is link from seed[iPageID] to $resolved address
	#if so increment link count
	#if not, insert new record with link count = 0

	#echo "start......db_store_link(...)\n";
	#echo "link is: $link\n";
	$link=html_entity_decode($link);
	$cleanUrl=clean_url($link);
	$cleanUrl=mysql_real_escape_string($cleanUrl);
	$domain = get_domain_part($link,$SAME_DOMAIN_FETCH_LEVEL);
	$link=mysql_real_escape_string($link);
	$page_id = checkCache($cleanUrl);
	if ($page_id==null) {
		$strSQL="SELECT iPageID FROM tblPages WHERE strCleanURL=?";
		$page_id = db_run_select($strSQL,$cleanUrl,true);
		addToCache($cleanUrl,$page_id);
	}
	if ($page_id==NULL) {
		$strSQL="INSERT INTO tblPages SET fkQueryID=?,strURL=?,strCleanURL=?,iLevel=?,strDomain=?";
		db_run_query($strSQL,array($seed["fkQueryID"],$link,$cleanUrl,($seed["iLevel"]+1),$domain));
		//$strSQL="SELECT LAST_INSERT_ID();";//TODO: ONLY MYSQL
			//"SELECT iPageID FROM tblPages WHERE strCleanURL='" . $cleanUrl . "'";
		$page_id = $GLOBALS["db"]->lastInsertId();//db_run_select($strSQL,true);
		addToCache($cleanUrl,$page_id);
	} else {
		//check current level and give shorter level if possible?
	}

	/*$strSQL="SELECT iLinkID FROM tblLinks " .
			"WHERE fkParentID=" . $seed["iPageID"] . " AND fkChildID=" . $page_id;
	$link_id = db_run_select($strSQL,true);
	if ($link_id==NULL) {
		$strSQL="INSERT INTO tblLinks(fkParentID,fkChildID,fkQueryID,iNumberTimes) VALUES (" .
			$seed["iPageID"] . "," . $page_id . "," . $seed["fkQueryID"] . ",1)";
		db_run_query($strSQL);
	} else {
		//update
		$strSQL="UPDATE tblLinks SET iNumberTimes=iNumberTimes+1 WHERE iLinkID=" . $link_id;
		db_run_query($strSQL);	
	}*/

	return "(" . 	$seed["iPageID"] . "," . $page_id . "," . $seed["fkQueryID"] . ",1)";
}


function db_store_link_internal_only($seed,$link) {
	//echo "db_store_link_internal_only($seed,$link)\n";
	#check if in tblPages
	#if not, store
	#get unique id, tblPages.iPageID
	#get if there is link from seed[iPageID] to $resolved address
	#if so increment link count
	#if not, insert new record with link count = 0

	#echo "start......db_store_link(...)\n";
	#echo "link is: $link\n";
	$link=html_entity_decode($link);
	$link=clean_url($link);
	$link=mysql_real_escape_string($link);
	//$strSQL="SELECT iPageID FROM tblPages WHERE bolExclude=0 AND strCleanURL='" . $link . "'";
	$page_id = checkCache($cleanUrl);
	if ($page_id==null) {
		$strSQL="SELECT iPageID FROM tblPages WHERE strCleanURL=?";
		$page_id = db_run_select($strSQL,$cleanUrl,true);
		addToCache($cleanUrl,$page_id);
	}
	//$strSQL="SELECT iPageID FROM tblPages WHERE strCleanURL='$link' ORDER BY bolExclude,iPageID LIMIT 1";
	//$page_id = db_run_select($strSQL,true);
	if ($page_id==NULL) {
		/*$strSQL="SELECT iPageID FROM tblPages WHERE strCleanURL='" . $link . "'";
		$page_id = db_run_select($strSQL,true);
		if ($page_id==NULL) {
			return; //skip  if not in set
		} else {
			print "[W] Warning: Link to excluded page found: " .
				$seed["iPageID"] ."->$page_id\n";
		}*/
		return;
	} else {
		//check current level and give shorter level if possible?
	}

	//$strSQL="SELECT iLinkID FROM tblLinks " .
	//		"WHERE fkParentID=" . $seed["iPageID"] . " AND fkChildID=" . $page_id;
	//$link_id = db_run_select($strSQL,true);
	//if ($link_id==NULL) {
		$strSQL="INSERT INTO tblLinks SET fkParentID=?,fkChildID=?,fkQueryID=?,iNumberTimes=?,iLevel=?";
		db_run_query($strSQL,array($seed["iPageID"],$page_id,$seed["fkQueryID"],1,1));
	//} else {
		//update
		#$strSQL="UPDATE tblLinks SET iNumberTimes=iNumberTimes+1 WHERE iLinkID=" . $link_id;
		#db_run_query($strSQL);	
	//}

	return "(" .$seed["iPageID"] . "," . $page_id . "," . $seed["fkQueryID"] . ",1,1)";
}

/*function db_mark_all_unprocessed() {
	$strSQL="UPDATE tblPages SET bolProcessed=0";
	db_run_query($strSQL);
}*/

function db_get_next_to_process() {
	$strSQL = "SELECT * FROM tblPages WHERE bolProcessed=0 AND bolHarvested=1 AND bolCentral=1 LIMIT 1";
	$seed=db_run_select($strSQL);
	return $seed;
}

function db_marked_processed($seed) {
	$strSQL = "UPDATE tblPages SET bolProcessed=1 WHERE iPageID=?";
	db_run_query($strSQL,$seed["iPageID"]);
}



function db_update_domain_links($strFromDomain,$strToDomain) {
	$strSQL="SELECT iHostID,iCount FROM tblExternalHosts WHERE strFromDomain=? AND strToDomain=?";
	$result=db_run_select($strSQL,array($strFromDomain,$strToDomain));
	if ($result==NULL) {
		$strSQL="INSERT INTO tblExternalHosts SET strFromDomain=?, strToDomain=?, iCount=?";
		$params=array($strFromDomain,$strToDomain,1);
	} else {
		$iCount=int($result['iCount'])+1;
		$strSQL="UPDATE tblExternalHosts SET iCount=? WHERE iHostID=?";
		$params=array($iCount,$result['iHostID']);
	}
	db_run_query($strSQL,$params);
}
