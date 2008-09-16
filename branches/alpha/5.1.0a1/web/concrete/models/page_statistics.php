<?
/**
 * @package Pages
 * @category Concrete
 * @author Andrew Embler <andrew@concrete5.org>
 * @copyright  Copyright (c) 2003-2008 Concrete5. (http://www.concrete5.org)
 * @license    http://www.concrete5.org/license/     MIT License
 *
 */

/**
 * PageStatistics functions as a name space containing functions that return page-level statistics.
 *
 * @package Pages
 * @author Andrew Embler <andrew@concrete5.org>
 * @category Concrete
 * @copyright  Copyright (c) 2003-2008 Concrete5. (http://www.concrete5.org)
 * @license    http://www.concrete5.org/license/     MIT License
 *
 */

class PageStatistics {
	
	/**
	 * Gets total page views across the entire site. 
	 * @param date $date
	 * @return int
	 */
	public static function getTotalPageViews($date = null) {
		$db = Loader::db();
		if ($date != null) {
			return $db->GetOne("select count(pstID) from PageStatistics where DATE_FORMAT(timestamp, '%Y-%m-%d') = ?", array($date));
		} else {
			return $db->GetOne("select count(pstID) from PageStatistics");
		}
	}

	/**
	 * Gets total page views for everyone but the passed user object
	 * @param User $u
	 * @param date $date
	 * @return int
	 */
	public static function getTotalPageViewsForOthers($u, $date = null) {
		$db = Loader::db();
		if ($date != null) {
			$v = array($u->getUserID(), $date);
			return $db->GetOne("select count(pstID) from PageStatistics where uID <> ? and DATE_FORMAT(timestamp, '%Y-%m-%d') = ?", $v);
		} else {
			$v = array($u->getUserID());
			return $db->GetOne("select count(pstID) from PageStatistics where uID <> ?", $v);
		}
	}

	/**
	 * Gets the total number of versions across all pages. Used in the dashboard.
	 * @todo It might be nice if this were a little more generalized
	 * @return int
	 */
	public static function getTotalPageVersions() {
		$db = Loader::db();
		return $db->GetOne("select count(cvID) from CollectionVersions");
	}
	
	/**
	 * Returns the datetime of the last edit to the site. Used in the dashboard
	 * @return datetime
	 */
	public static function getSiteLastEdit() {
		$db = Loader::db();
		return $db->GetOne("select max(Collections.cDateModified) from Collections");
	}
	
	/**
	 * Gets the total number of pages currently in edit mode
	 * @return int
	 */
	public static function getTotalPagesCheckedOut() {
		$db = Loader::db();
		return $db->GetOne("select count(cID) from Pages where cIsCheckedOut = 1");
	}
	


}
