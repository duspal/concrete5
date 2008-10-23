<?

require_once(DIR_CLASSES . '/content.php');
require_once(DIR_CLASSES . '/block_types.php');
require_once(DIR_CLASSES . '/attribute.php');
require_once(DIR_CLASSES . '/permissions.php');

class Area extends Object {

	var $cID, $arID, $arHandle;
	var $c;

	/* area-specific attributes */

	var $maximumBlocks = -1; // limits the number of blocks in the area
	var $customTemplate; // sets a custom template for all blocks in the area
	var $firstRunBlockTypeHandle; // block type handle for the block to automatically activate on first_run
	var $ratingThreshold = 0; // if set higher, any blocks that aren't rated high enough aren't seen (unless you have sufficient privs)
	var $showControls = true;
	
	/* run-time variables */

	var $totalBlocks = 0; // the number of blocks currently rendered in the area
	var $areaBlocksArray; // not an array actually until it's set

	/*
		The constructor is used primarily on pages, to make an Area. We actually use Collection::getArea() when we want to interact with a fully
		qualified Area object
	*/

	function Area($arHandle) {
		$this->arHandle = $arHandle;
		
		if ($_REQUEST['ccm-disable-controls'] == true) {
			$this->showControls = false;
		}
	}

	function getCollectionID() {return $this->cID;}
	function getAreaCollectionObject() {return $this->c;}
	function getAreaID() {return $this->arID;}
	function getAreaHandle() {return $this->arHandle;}
	function getAreaCustomTemplate() {return $this->customTemplate;}
	function getTotalBlocksInArea() {return $this->totalBlocks; }
	function overrideCollectionPermissions() {return $this->arOverrideCollectionPermissions; }
	function getAreaCollectionInheritID() {return $this->arInheritPermissionsFromAreaOnCID;}
	
	function disableControls() {
		$this->showControls = false;
	}

	function areaAcceptsBlocks() {
		return (($this->maximumBlocks > $this->totalBlocks) || ($this->maximumBlocks == -1));
	}

	function getMaximumBlocks() {return $this->maximumBlocks;}
	
	function getAreaUpdateAction($alternateHandler = null) {
		$step = ($_REQUEST['step']) ? '&step=' . $_REQUEST['step'] : '';
		$c = $this->getAreaCollectionObject();
		if ($alternateHandler) {
			$str = $alternateHandler . "?atask=update&cID=" . $c->getCollectionID() . "&arHandle=" . $this->getAreaHandle() . $step;
		} else {
			$str = DIR_REL . "/" . DISPATCHER_FILENAME . "?atask=update&cID=" . $c->getCollectionID() . "&arHandle=" . $this->getAreaHandle() . $step;
		}
		return $str;
	}

	function get(&$c, $arHandle) {
		global $db;
		// First, we verify that this is a legitimate area
		$v = array($c->getCollectionID(), $arHandle);
		$q = "select arID, arOverrideCollectionPermissions, arInheritPermissionsFromAreaOnCID from Areas where cID = ? and arHandle = ?";
		$arRow = $db->getRow($q, $v);
		if ($arRow['arID'] > 0) {
			$area = new Area($arHandle);

			$area->arID = $arRow['arID'];
			$area->arOverrideCollectionPermissions = $arRow['arOverrideCollectionPermissions'];
			$area->arInheritPermissionsFromAreaOnCID = $arRow['arInheritPermissionsFromAreaOnCID'];
			$area->cID = $c->getCollectionID();
			$area->c = &$c;
			
			return $area;
		}
	}

	function getOrCreate(&$c, $arHandle) {

		/*
			different than get(), getOrCreate() is called by the templates. If no area record exists for the
			permissions cID / handle combination, we create one. This is to make our lives easier
		*/
		global $db;

		$area = Area::get($c, $arHandle);
		if (is_object($area)) {
			return $area;
		}

		$cID = ($c->getCollectionInheritance()) ? $c->getCollectionID() : $c->getParentPermissionsCollectionID();
		$v = array($cID, $arHandle);
		$q = "insert into Areas (cID, arHandle) values (?, ?)";
		$db->query($q, $v);

		$area = Area::get($c, $arHandle); // we're assuming the insert succeeded
		$area->rescanAreaPermissionsChain();
		return $area;

	}

	function getAreaBlocksArray(&$c) {
		if (is_array($this->areaBlocksArray)) {
			return $this->areaBlocksArray;
		}

		$this->cID = $c->getCollectionID();
		$this->c = $c;

		require_once(DIR_CLASSES . '/block.php');
		global $db;

		$cp = new Permissions($c);
		if ($c->isReviewMode() && $cp->canReadVersions()) {
			$cvIDPre = $c->getPreviousVersionID();
		}

		$v = array($this->arHandle, $c->getCollectionID(), $c->getVersionID());
		$q = "select Blocks.bID, Blocks.btID, BlockTypes.btHandle, Blocks.bIsActive, bName, bDateAdded, bDateModified, bFilename, Blocks.uID, CollectionVersionBlocks.cbOverrideAreaPermissions, CollectionVersionBlocks.isOriginal ";
		$q .= "from CollectionVersionBlocks inner join Blocks on (CollectionVersionBlocks.bID = Blocks.bID) inner join BlockTypes on (Blocks.btID = BlockTypes.btID) where CollectionVersionBlocks.arHandle = ? ";
		$q .= "and CollectionVersionBlocks.cID = ? and (CollectionVersionBlocks.cvID = ? or CollectionVersionBlocks.cbIncludeAll=1) order by CollectionVersionBlocks.cbDisplayOrder asc";

		$r = $db->query($q, $v);
		$areaIDArray = array();
		$this->areaBlocksArray = array();
		while ($row = $r->fetchRow()) {
			$ab = new Block($row, $c, $this->arHandle);
			if ($c->isReviewMode() && $cp->canReadVersions()) {
				$v = array($ab->getBlockID());
				$q = "select originalBID from BlockRelations where BlockRelations.bID = ?";
				$originalBID = $db->getOne($q, $v);
				$ab->setOriginalBlockID($originalBID);
			}

			$btHandle = $ab->getBlockTypeHandle();
			$ab->setBlockAreaObject($this);
			$this->areaBlocksArray[] = $ab;
			$this->totalBlocks++;
		}

		$r->free();
		return $this->areaBlocksArray;
	}

	function getAddBlockTypes(&$c, &$ap) {
		if ($ap->canAddBlocks()) {
			$bt = new BlockTypeList($ap->addBlockTypes);
		} else {
			$bt = false;
		}
		return $bt;
	}
	
	function revertToPagePermissions() {
		// this function removes all permissions records for a particular area on this page
		// and sets it to inherit from the page above
		// this function will also need to ensure that pages below it do the same
		
		global $db;
		$v = array($this->getAreaHandle(), $this->getCollectionID());
		$db->query("delete from AreaGroups where arHandle = ? and cID = ?", $v);
		$db->query("delete from AreaGroupBlockTypes where arHandle = ? and cID = ?", $v);
		$db->query("update Areas set arOverrideCollectionPermissions = 0 where arID = ?", array($this->getAreaID()));
		
		// now we set rescan this area to determine where it -should- be inheriting from
		$this->arOverrideCollectionPermissions = false;
		$this->rescanAreaPermissionsChain();
		
		$areac = $this->getAreaCollectionObject();
		if ($areac->isMasterCollection()) {
			$this->rescanSubAreaPermissionsMasterCollection($areac);
		} else if ($areac->overrideTemplatePermissions()) {
			// now we scan sub areas
			$this->rescanSubAreaPermissions();
		}
	}
	
	function rescanAreaPermissionsChain() {
		// works on the current area object to ensure that inheritance makes sense
		// and that areas actually inherit their permissions correctly up the chain
		// of collections. This needs to be run any time a page is moved, deleted, etc..
		global $db;
		if ($this->overrideCollectionPermissions()) {
			return false;
		}
		// first, we obtain the inheritance of permissions for this particular collection
		$areac = $this->getAreaCollectionObject();
		if ($areac->getCollectionInheritance() == 'PARENT') {
			
			// now we go up the tree
			
			
			$cIDToCheck = $areac->getCollectionParentID();
			
			while ($cIDToCheck > 0) {
				$row = $db->getRow("select c.cParentID, c.cID, a.arHandle, a.arOverrideCollectionPermissions, a.arID from Collections c inner join Areas a on (c.cID = a.cID) where c.cID = ? and a.arHandle = ?", array($cIDToCheck, $this->getAreaHandle()));
				if ($row['arOverrideCollectionPermissions'] == 1) {
					break;
				} else {
					$cIDToCheck = $row['cParentID'];
				}
			}
			
			if (is_array($row)) {
				if ($row['arOverrideCollectionPermissions']) {
					// then that means we have successfully found a parent area record that we can inherit from. So we set
					// out current area to inherit from that COLLECTION ID (not area ID - from the collection ID)
					$db->query("update Areas set arInheritPermissionsFromAreaOnCID = ? where arID = ?", array($row['cID'], $this->getAreaID()));
					$this->arInheritPermissionsFromAreaOnCID = $row['cID']; 
				}
			}
		} else if ($areac->getCollectionInheritance() == 'TEMPLATE') {
			 // we grab an area on the master collection (if it exists)
			$doOverride = $db->getOne("select arOverrideCollectionPermissions from Collections c inner join Areas a on (c.cID = a.cID) where c.cID = ? and a.arHandle = ?", array($areac->getPermissionsCollectionID(), $this->getAreaHandle()));
			if ($doOverride) {
				$db->query("update Areas set arInheritPermissionsFromAreaOnCID = ? where arID = ?", array($areac->getPermissionsCollectionID(), $this->getAreaID()));
				$this->arInheritPermissionsFromAreaOnCID = $areac->getPermissionsCollectionID();
			}			
		}
	}
	
	function rescanSubAreaPermissions($cIDToCheck = null) {
		// works a lot like rescanAreaPermissionsChain() but it works down. This is typically only 
		// called when we update an area to have specific permissions, and all areas that are on pagesbelow it with the same 
		// handle, etc... should now inherit from it.
		global $db;
		if (!$cIDToCheck) {
			$cIDToCheck = $this->getCollectionID();
		}
		
		$v = array($this->getAreaHandle(), 'PARENT', $cIDToCheck);
		$r = $db->query("select Areas.arID, Areas.cID from Areas inner join Collections on (Areas.cID = Collections.cID) where Areas.arHandle = ? and cInheritPermissionsFrom = ? and arOverrideCollectionPermissions = 0 and cParentID = ?", $v);
		while ($row = $r->fetchRow()) {
			// these are all the areas we need to update.
			$db->query("update Areas set arInheritPermissionsFromAreaOnCID = " . $this->getAreaCollectionInheritID() . " where arID = " . $row['arID']);
			$this->rescanSubAreaPermissions($row['cID']);
		}
		
	}
	
	function rescanSubAreaPermissionsMasterCollection($masterCollection) {
		// like above, but for those who have setup their pages to inherit master collection permissions
		// this might make more sense in the collection class, but I'm putting it here
		if (!$masterCollection->isMasterCollection()) {
			return false;
		}
		
		// if we're not overriding permissions on the master collection then we set the ID to zero. If we are, then we set it to our own ID
		$toSetCID = ($this->overrideCollectionPermissions()) ? $masterCollection->getCollectionID() : 0;		
		
		global $db;
		$v = array($this->getAreaHandle(), 'TEMPLATE', $masterCollection->getCollectionID());
		$db->query("update Areas, Collections set Areas.arInheritPermissionsFromAreaOnCID = " . $toSetCID . " where Areas.cID = Collections.cID and Areas.arHandle = ? and cInheritPermissionsFrom = ? and arOverrideCollectionPermissions = 0 and cInheritPermissionsFromCID = ?", $v);
	}
	
	function display(&$c, $alternateBlockArray = null) {

		$ourArea = Area::getOrCreate($c, $this->arHandle);
		if ($this->customTemplate) {
			$ourArea->customTemplate = $this->customTemplate;
		}
		$ap = new Permissions($ourArea);
		$blocksToDisplay = ($alternateBlockArray) ? $alternateBlockArray : $ourArea->getAreaBlocksArray($c, $ap);
		$u = new User();

		// now, we iterate through these block groups (which are actually arrays of block objects), and display them on the page
		if (($this->showControls) && ($c->isEditMode() && ($ap->canAddBlocks() || $u->isSuperUser()))) {
			Content::buildAreaEditStart($ourArea);
		}
		
		foreach ($blocksToDisplay as $b) {
			$p = new Permissions($b);
			if ($_GET['bID'] == $b->getBlockID() && $c->isEditMode()) {
				Content::buildBlockEdit($b, $p, $ourArea);
			} else {
				Content::buildBlockDisplay($b, $p, $ourArea);
			}
		}

		if ($_GET['ctask'] == 'first_run' && $this->firstRunBlockTypeHandle) {
			$bt = new BlockType($this->firstRunBlockTypeHandle);
			if ($bt) {
				Content::buildBlockAdd($bt, $ap, $ourArea);
			}
		}

		if ($_GET['btask'] == 'add' && $_GET['arHandle'] == $this->arHandle && $_REQUEST['btID']) {
			$bt = new BlockType($_REQUEST['btID']);
			if ($bt) {
				Content::buildBlockAdd($bt, $ap, $ourArea);
			}
		} else if (($this->showControls) && ($c->isEditMode() && ($ap->canAddBlocks() || $u->isSuperUser()))) {
			$bt = $this->getAddBlockTypes($c, $ap);
			Content::buildAreaEdit($bt, $ap, $ourArea);
		}
	}

	function update($aKeys, $aValues) {
		global $db;

		// now it's permissions time

		$gIDArray = array();
		$uIDArray = array();
		if (is_array($_POST['areaRead'])) {
			foreach ($_POST['areaRead'] as $ugID) {
				if (strpos($ugID, 'uID') > -1) {
					$uID = substr($ugID, 4);
					$uIDArray[$uID] .= "r:";
				} else {
					$gID = substr($ugID, 4);
					$gIDArray[$gID] .= "r:";
				}
			}
		}

		if (is_array($_POST['areaReadAll'])) {
			foreach ($_POST['areaReadAll'] as $ugID) {
				if (strpos($ugID, 'uID') > -1) {
					$uID = substr($ugID, 4);
					$uIDArray[$uID] .= "rb:";
				} else {
					$gID = substr($ugID, 4);
					$gIDArray[$gID] .= "rb:";
				}
			}
		}

		if (is_array($_POST['areaEdit'])) {
			foreach ($_POST['areaEdit'] as $ugID) {
				if (strpos($ugID, 'uID') > -1) {
					$uID = substr($ugID, 4);
					$uIDArray[$uID] .= "wa:";
				} else {
					$gID = substr($ugID, 4);
					$gIDArray[$gID] .= "wa:";
				}
			}
		}

		if (is_array($_POST['areaDelete'])) {
			foreach ($_POST['areaDelete'] as $ugID) {
				if (strpos($ugID, 'uID') > -1) {
					$uID = substr($ugID, 4);
					$uIDArray[$uID] .= "db:";
				} else {
					$gID = substr($ugID, 4);
					$gIDArray[$gID] .= "db:";
				}
			}
		}

		$gBTArray = array();
		$uBTArray = array();
		if (is_array($_POST['areaAddBlockType'])) {
			foreach($_POST['areaAddBlockType'] as $btID => $ugArray) {
				// this gets us the block type that particular groups/users are given access to
				foreach($ugArray as $ugID) {
					if (strpos($ugID, 'uID') > -1) {
						$uID = substr($ugID, 4);
						$uBTArray[$uID][] = $btID;
					} else {
						$gID = substr($ugID, 4);
						$gBTArray[$gID][] = $btID;
					}
				}
			}
		}

		global $db;
		$cID = $this->getCollectionID();
		$v = array($cID, $this->getAreaHandle());
		// update the Area record itself. Hopefully it's been created.
		$db->query("update Areas set arOverrideCollectionPermissions = 1, arInheritPermissionsFromAreaOnCID = 0 where arID = ?", array($this->getAreaID()));
		
		$db->query("delete from AreaGroups where cID = ? and arHandle = ?", $v);
		$db->query("delete from AreaGroupBlockTypes where cID = ? and arHandle = ?", $v);

		// now we iterate through, and add the permissions
		foreach ($gIDArray as $gID => $perms) {
		   // since this can now be either groups or users, we have prepended gID or uID to each gID value
			// we have to trim the trailing colon, if there is one
			$permissions = (strrpos($perms, ':') == (strlen($perms) - 1)) ? substr($perms, 0, strlen($perms) - 1) : $perms;
			$v = array($cID, $this->getAreaHandle(), $gID, $permissions);
			$q = "insert into AreaGroups (cID, arHandle, gID, agPermissions) values (?, ?, ?, ?)";
			$r = $db->prepare($q);
			$res = $db->execute($r, $v);
		}

		// iterate through and add user-level permissions
		foreach ($uIDArray as $uID => $perms) {
		   // since this can now be either groups or users, we have prepended gID or uID to each gID value
			// we have to trim the trailing colon, if there is one
			$permissions = (strrpos($perms, ':') == (strlen($perms) - 1)) ? substr($perms, 0, strlen($perms) - 1) : $perms;
			$v = array($cID, $this->getAreaHandle(), $uID, $permissions);
			$q = "insert into AreaGroups (cID, arHandle, uID, agPermissions) values (?, ?, ?, ?)";
			$r = $db->prepare($q);
			$res = $db->execute($r, $v);
		}

		foreach($uBTArray as $uID => $uBTs) {
			foreach($uBTs as $btID) {
				$v = array($cID, $this->getAreaHandle(), $uID, $btID);
				$q = "insert into AreaGroupBlockTypes (cID, arHandle, uID, btID) values (?, ?, ?, ?)";
				$r = $db->query($q, $v);
			}
		}

		foreach($gBTArray as $gID => $gBTs) {
			foreach($gBTs as $btID) {
				$v = array($cID, $this->getAreaHandle(), $gID, $btID);
				$q = "insert into AreaGroupBlockTypes (cID, arHandle, gID, btID) values (?, ?, ?, ?)";
				$r = $db->query($q, $v);
			}
		}
		
		// finally, we rescan subareas so that, if they are inheriting up the tree, they inherit from this place
		$this->arInheritPermissionsFromAreaOnCID = $this->getCollectionID(); // we don't need to actually save this on the area, but we need it for the rescan function
		$this->arOverrideCollectionPermissions = 1; // to match what we did above - useful for the rescan functions below
		
		$acobj = $this->getAreaCollectionObject();
		if ($acobj->isMasterCollection()) {
			// if we're updating the area on a master collection we need to go through to all areas set on subpages that aren't set to override to change them to inherit from this area
			$this->rescanSubAreaPermissionsMasterCollection($acobj);
		} else {
			$this->rescanSubAreaPermissions();
		}
	}
}

?>