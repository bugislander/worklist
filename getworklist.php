<?php
//  vim:ts=4:et

//  Copyright (c) 2009-2010, LoveMachine Inc.
//  All Rights Reserved.
//  http://www.lovemachineinc.com

// AJAX request from ourselves to retrieve history


include("config.php");
include("class.session_handler.php");
require_once('lib/Agency/Worklist/Filter.php');

ob_start();
// Test for a string containing 0 characters of anything other than 0-9 and #
// After a quick trim ofcourse! :)
// I knowww regex is usually the bad first stop, but there would be no back tracking in this
// particular regular expression
if (preg_match("/^\#\d+$/",$query = trim($_REQUEST['query']))) {
	// if we reach here, include workitem package
	include_once("workitem.class.php");
	$workitem = new Workitem();
	if ($workitem->idExists($id = ltrim($query,"#"))) {
		$obj = array('redirect',$id);
		die(JSON_encode($obj));
	}
	// if we're not dead continue on!
}
$limit = 30;

$_REQUEST['name'] = '.worklist';
$filter = new Agency_Worklist_Filter($_REQUEST);

$is_runner = !empty( $_SESSION['is_runner'] ) ? 1 : 0;

$sfilter = explode('/', $filter->getStatus());
$ufilter = $filter->getUser();
$ofilter = $filter->getSort();
$dfilter = $filter->getDir();
$page = $filter->getPage();

$where = '';
$unpaid_join = '';
if (!empty($sfilter)) {
    $where = "where (";
    foreach ($sfilter as $val) {

        $val = strtoupper(mysql_real_escape_string($val));
        if ($val == 'ALL') {
            $where .= "1 or ";
        } else {
            $where .= "status='$val' or ";
        }
    }
    $where .= "0)";
}
if (!empty($ufilter) && $ufilter != 'ALL') {
    if (empty($where)) {
        $where = "where ";
    } else {
        $where .= " and ";
    }

    // Runner and query is User->Bidding we only show the items the user
    // is currently bidding on.
    if( $is_runner )    {
        foreach( $sfilter as $val ) {
            if( $val == 'BIDDING' ) {
                $where .= "( mechanic_id='$ufilter' or `bidder_id`='$ufilter' or `runner_id` = '$ufilter')";
            } else  {
                $where .= "(creator_id='$ufilter' or runner_id='$ufilter' or mechanic_id='$ufilter' or user_id='$ufilter')";
            }
        }
    } else { // Else if the current user is looking for his bids, we show, else nothing.
	$userId = isset($_SESSION['userid'])? $_SESSION['userid'] : 0;
        if( $userId == $ufilter )  {
            $where .= "(creator_id='$ufilter' or runner_id='$ufilter' or mechanic_id='$ufilter' or user_id='$ufilter'
                        or `bidder_id`='$ufilter')";
        }   else    {
            $where .= "(creator_id='$ufilter' or runner_id='$ufilter' or mechanic_id='$ufilter' or user_id='$ufilter')";
        }
    }
}

$query = $filter->getQuery();

if($query!='' && $query!='Search...') {
    $searchById = false;
     if(is_numeric(trim($query))) {
        $rt = mysql_query("select count(*) from ".WORKLIST." LEFT JOIN `".FEES."` ON `".WORKLIST."`.`id` = `".FEES."`.`worklist_id` $where AND `".WORKLIST."`.`id` = " .$query);
        $row = mysql_fetch_row($rt);
        $rowCount = intval($row[0]);
        if($rowCount >0)
        {
            $searchById = true;
            $where .= " AND `". WORKLIST ."`.`id` = " . $query;
        }
    }
    if(!$searchById) {
        $array=explode(" ",rawurldecode($query));

        foreach ($array as $item) {
            $item = mysql_escape_string($item);
            $where.=" AND ( summary LIKE '%$item%' OR `".WORKLIST."`.`notes` LIKE '%$item%' OR `".FEES."`.notes LIKE '%$item%') ";
        }
    }
}

$totals = 'CREATE TEMPORARY TABLE IF NOT EXISTS `tmp_totals` (
           `worklist_id` int(11) NOT NULL,
           `total_fees` decimal(10,2) NOT NULL,
           INDEX worklist_id(worklist_id))';

$emptyTotals = 'TRUNCATE `tmp_totals`';

$fillTotals = 'INSERT INTO `tmp_totals`
               SELECT `worklist_id`, SUM(amount) FROM `fees` WHERE `withdrawn` = 0 GROUP BY `worklist_id`';

mysql_query($totals);
mysql_query($emptyTotals);
mysql_query($fillTotals);

$latest = 'CREATE TEMPORARY TABLE IF NOT EXISTS `tmp_latest` (
           `worklist_id` int(11) NOT NULL,
           `latest` DATETIME NOT NULL,
           INDEX worklist_id(worklist_id))';

$emptyLatest = 'TRUNCATE `tmp_latest`';

$fillLatest = 'INSERT INTO `tmp_latest`
               (SELECT `worklist_id`,
                MAX(`bid_created`) AS `latest`
                FROM `bids` GROUP BY `worklist_id`)';

mysql_query($latest);
mysql_query($emptyLatest);
mysql_query($fillLatest);

$showLatest = 'AND `bids`.`bid_created` = `tmp_latest`.`latest`';
if (($sfilter[0] == 'BIDDING') && (!empty($ufilter) && $ufilter != 'ALL')) {
    $showLatest = '';
}
$bids = 'CREATE TEMPORARY TABLE IF NOT EXISTS `tmp_bids` (
         `worklist_id` int(11) NOT NULL,
         `bid_amount` decimal(10,2) NOT NULL,
         `bidder_id`  int(11) NOT NULL,
         INDEX worklist_id(worklist_id))';

$emptyBids = 'TRUNCATE `tmp_bids`';

$fillBids = "INSERT INTO `tmp_bids`
             SELECT `bids`.`worklist_id`,`bids`.`bid_amount`,`bids`.`bidder_id`
             FROM `bids`, `tmp_latest`
             WHERE `bids`.`worklist_id` = `tmp_latest`.`worklist_id`
              $showLatest
              AND (`bids`.`withdrawn` = 0)";

mysql_query($bids);
mysql_query($emptyBids);
mysql_query($fillBids);

$qcnt  = "SELECT count(DISTINCT `".WORKLIST."`.`id`)";

//mega-query with total fees and latest bid for the worklist item
$qsel  = "SELECT DISTINCT  `".WORKLIST."`.`id`,`summary`,`status`,
	      `cu`.`nickname` AS `creator_nickname`,
	      `ru`.`nickname` AS `runner_nickname`,
	      `mu`.`nickname` AS `mechanic_nickname`,
	      TIMESTAMPDIFF(SECOND, `created`, NOW()) as `delta`,
	      `total_fees`,`bid_amount`,`creator_id`,
	      (SELECT COUNT(`".BIDS."`.id) FROM `".BIDS."`
	       WHERE `".BIDS."`.`worklist_id` = `".WORKLIST."`.`id` AND (`".BIDS."`.`withdrawn` = 0) LIMIT 1) as bid_count,
          TIMESTAMPDIFF(SECOND,NOW(), (SELECT `".BIDS."`.`bid_done` FROM `".BIDS."`
           WHERE `".BIDS."`.`worklist_id` = `".WORKLIST."`.`id` AND `".BIDS."`.`accepted` = 1 LIMIT 1)) as bid_done,
           (SELECT COUNT(`".COMMENTS."`.`id`) FROM `".COMMENTS."`
           WHERE `".COMMENTS."`.`worklist_id` = `".WORKLIST."`.`id`) AS `comments`";

$qbody = "FROM `".WORKLIST."`
          LEFT JOIN `".USERS."` AS cu ON `".WORKLIST."`.`creator_id` = `cu`.`id`
          LEFT JOIN `".USERS."` AS ru ON `".WORKLIST."`.`runner_id` = `ru`.`id`
          LEFT JOIN `".FEES."` ON `worklist`.`id` = `".FEES."`.`worklist_id`
          LEFT OUTER JOIN `".USERS."` AS mu ON `".WORKLIST."`.`mechanic_id` = `mu`.`id`
          LEFT JOIN `tmp_totals` AS `totals` ON `".WORKLIST."`.`id` = `totals`.`worklist_id`
          $unpaid_join
          LEFT JOIN `tmp_bids` AS `bids` ON `".WORKLIST."`.`id` = `bids`.`worklist_id`
          $where";

$qorder = "ORDER BY {$ofilter} {$dfilter} LIMIT " . ($page-1)*$limit . ",{$limit}";

$rtCount = mysql_query("$qcnt $qbody");
if ($rtCount) {
    $row = mysql_fetch_row($rtCount);
    $items = intval($row[0]);
} else {
    $items = 0;
}
$cPages = ceil($items/$limit);
$worklist = array(array($items, $page, $cPages));

// Construct json for history
$rtQuery = mysql_query("$qsel $qbody $qorder");
echo mysql_error();
while ($rtQuery && $row=mysql_fetch_assoc($rtQuery)) {

    $worklist[] = array(
         0 => $row['id'],
         1 => $row['summary'],
         2 => $row['status'],
         3 => $row['creator_nickname'],
         4 => $row['runner_nickname'],
         5 => $row['mechanic_nickname'],
         6 => $row['delta'],
         7 => $row['total_fees'],
         8 => $row['bid_amount'],
         9 => $row['creator_id'],
        10 => $row['bid_count'],
        11 => $row['bid_done'],
        12 => $row['comments']
	);
}

$json = json_encode($worklist);
echo $json;
ob_end_flush();
?>
