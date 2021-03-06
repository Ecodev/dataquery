<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 Francois Suter <typo3@cobweb.ch>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

/**
 * Testcase for the Data Query query builder in the Draft workspace
 *
 * @author		Francois Suter <typo3@cobweb.ch>
 * @package		TYPO3
 * @subpackage	tx_dataquery
 *
 * $Id$
 */
class tx_dataquery_sqlbuilder_Workspace_Test extends tx_dataquery_sqlbuilder_Test {
	/**
	 * @var	integer	ID of the current workspace
	 */
	protected $saveWorkspaceValue;

	/**
	 * Set up the workspace preview environment
	 */
	public function setUp() {
		parent::setUp();

			// Add version state to the SELECT fields
		$this->additionalFields[] = 't3ver_state';

			// Activate versioning preview
		$GLOBALS['TSFE']->sys_page->versioningPreview = TRUE;
			// Save current workspace (should the LIVE one really) and switch to Draft
		$this->saveWorkspaceValue = $GLOBALS['BE_USER']->workspace;
		$GLOBALS['BE_USER']->workspace = 42;

			// The base condition is different in the case of workspaces, because
			// versioning preview deactivates most of the enable fields check
		self::$minimalConditionForTable = '###TABLE###.deleted=0';
		self::$baseConditionForTable = '(###MINIMAL_CONDITION###)';
		self::$groupsConditionForTable = '';
			// Reset language condition which might have been altered by language unit test
		self::$baseLanguageConditionForTable = '(###TABLE###.sys_language_uid IN (0,-1))';
			// Add workspace condition, assuming some arbitrary workspace (= 42)
		self::$baseWorkspaceConditionForTable = '((###TABLE###.t3ver_state <= 0 AND ###TABLE###.t3ver_oid = 0) OR (###TABLE###.t3ver_state = 0 AND ###TABLE###.t3ver_wsid = 42) OR (###TABLE###.t3ver_state = 1 AND ###TABLE###.t3ver_wsid = 42) OR (###TABLE###.t3ver_state = 3 AND ###TABLE###.t3ver_wsid = 42)) ';
//		self::$fullConditionForTable = self::$baseConditionForTable . self::$baseLanguageConditionForTable . self::$baseWorkspaceConditionForTable;
			// NOTE: markers are used instead of the corresponding conditions, because the setUp() method
			// is not invoked inside the data providers. Thus when using a data provider, it's not possible
			// to refer to the conditions defined via setUp()
		self::$fullConditionForTable = '###BASE_CONDITION### AND ###LANGUAGE_CONDITION### AND ###WORKSPACE_CONDITION###';
	}

	/**
	 * Reset environment
	 */
	public function tearDown() {
		parent::tearDown();
		$GLOBALS['TSFE']->sys_page->versioningPreview = FALSE;
		$GLOBALS['BE_USER']->workspace = $this->saveWorkspaceValue;
	}
}
?>