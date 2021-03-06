<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2007-2012 Francois Suter (Cobweb) <typo3@cobweb.ch>
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
 * This class is used to parse a SELECT SQL query into a structured array
 * It rebuilds the query aferwards, automatically handling a number of TYPO3 constructs,
 * like enable fields and language overlays
 *
 * @author		Francois Suter (Cobweb) <typo3@cobweb.ch>
 * @package		TYPO3
 * @subpackage	tx_dataquery
 *
 * $Id$
 */
class tx_dataquery_parser {
	static public $extKey = 'dataquery';

		/**
		 * List of eval types which indicate non-text fields
		 * @var	array	$notTextTypes
		 */
	static protected $notTextTypes = array('date', 'datetime', 'time', 'timesec', 'year', 'num', 'md5', 'int', 'double2');

		/**
		 * Reference to the calling object
		 * @var tx_dataquery_wrapper $parentObject
		 */
	protected $parentObject;
		/**
		 * Unserialized extension configuration
		 * @var array	$configuration
		 */
	protected $configuration;

		/**
		 * Structured type containing the parts of the parsed query
		 * @var	tx_dataquery_queryobject	$queryObject
		 */
	protected $queryObject;

		/**
		 * True names for all the fields. The key is the actual alias used in the query.
		 * @var	array	$fieldTrueNames
		 */
	protected $fieldTrueNames = array();

		/**
		 * List of all fields being queried, arranged per table (aliased)
		 * @var	array	$queryFields
		 */
	protected $queryFields = array();

		/**
		 * Flag for each table whether to perform overlays or not
		 * @var	array
		 */
	protected $doOverlays = array();

		/**
		 * Flag for each table whether to perform versioning overlays or not
		 * @var	array
		 */
	protected $doVersioning = array();

		/**
		 * True if order by is processed using SQL, false otherwise (see preprocessOrderByFields())
		 * @var	boolean
		 */
	protected $processOrderBy = TRUE;
	protected $isMergedResult = FALSE;

		/**
		 * Cache array to store table name matches (@see matchAliasOrTableNeme())
		 * @var array
		 */
	protected $tableMatches = array();

		/**
		 * @var array $data: database record corresponding to the current Data Query
		 */
	protected $providerData;

	public function  __construct($parentObject) {
		$this->parentObject = $parentObject;
		$this->configuration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['dataquery']);
	}

	/**
	 * This method is used to parse a SELECT SQL query.
	 * It is a simple parser and no way generic. It expects queries to be written a certain way.
	 *
	 * @param string $query The query to be parsed
	 * @throws tx_tesseract_exception
	 * @return string A warning message, if any (fatal errors throw exceptions)
	 */
	public function parseQuery($query) {
		$warning = '';
			// Clean up and prepare the query string
		$query = $this->prepareQueryString($query);

			// Parse the SQL query
			/** @var $sqlParser tx_dataquery_sqlparser */
		$sqlParser = t3lib_div::makeInstance('tx_dataquery_sqlparser');
			// NOTE: the following call may throw exceptions,
			// but we let them bubble up
		$this->queryObject = $sqlParser->parseSQL($query);
			// Perform some further analysis on the query components
		$this->analyzeQuery();
			// Make sure the list of selected fields contains base fields
			// like uid and pid (if available)
			// Don't do this for queries using the DISTINCT keyword, as it may mess it up
		if (!$this->queryObject->structure['DISTINCT']) {
			$this->addBaseFields();

			// If the query uses the DISTINCT keyword, check if a "uid" field has been defined manually
			// If not, issue warning
		} else {
			if (!$this->checkUidForDistinctUsage()) {
				throw new tx_tesseract_exception('"uid" field missing with DISTINCT usage', 1313354033);
			}
		}
			// Check if the query selects the same field multiple times
			// Issue a warning if yes, since the results may be unpredictable
		$duplicates = $this->checkForDuplicateFields();
		if (count($duplicates) > 0) {
			$warning = 'Duplicate fields in query: ' . implode(' / ', $duplicates);
		}

//t3lib_utility_Debug::debug($this->queryObject->aliases, 'Table aliases');
//t3lib_utility_Debug::debug($this->fieldAliases, 'Field aliases');
//t3lib_utility_Debug::debug($this->fieldTrueNames, 'Field true names');
//t3lib_utility_Debug::debug($this->queryFields, 'Query fields');
//t3lib_utility_Debug::debug($this->queryObject->structure, 'Structure');
		return $warning;
	}

	/**
	 * This method performs a number of operations on a given string,
	 * supposed to be a SQL query
	 * It is meant to be called before the query is actually parsed
	 *
	 * @param	string	$string: a SQL query
	 * @return	string	Cleaned up SQL query
	 */
	public function prepareQueryString($string) {
			// Put the query through the field parser to filter out commented lines
		$queryLines = tx_tesseract_utilities::parseConfigurationField($string);
			// Put the query into a single string
		$query = implode(' ', $queryLines);
			// Strip backquotes
		$query = str_replace('`', '', $query);
			// Strip trailing semi-colon if any
		if (strrpos($query, ';') == strlen($query) - 1) {
			$query = substr($query, 0, -1);
		}
			// Parse query for subexpressions
		$query = tx_expressions_parser::evaluateString($query, FALSE);
		return $query;
	}

	/**
	 * This method further analyzes the query
	 * Im particular, it loop on all SELECT field and makes sure every field
	 * has a proper alias
	 *
	 * @return	void
	 */
	protected function analyzeQuery() {
			// Loop on all query fields to assemble additional information structures
		foreach ($this->queryObject->structure['SELECT'] as $index => $fieldInfo) {
				// Assemble list of fields per table
				// The name of the field is used both as key and value, but the value will be replaced by the fields' labels in getLocalizedLabels()
			if (!isset($this->queryFields[$fieldInfo['tableAlias']])) {
				$this->queryFields[$fieldInfo['tableAlias']] = array('name' => $fieldInfo['table'], 'table' => $fieldInfo['tableAlias'], 'fields' => array());
			}
			$this->queryFields[$fieldInfo['tableAlias']]['fields'][] = array('name' => $fieldInfo['field'], 'function' => $fieldInfo['function']);

				// Assemble full names for each field
				// The full name is:
				//	1) the name of the table or its alias
				//	2) a dot
				//	3) the name of the field
				//
				// => If it's the main table and there's an alias for the field
				//
				//	4a) AS and the field alias
				//
				//	4a-2)	if the alias contains a dot (.) it means it contains a table name (or alias)
				//			and a field name. So we use this information
				//
				// This means something like foo.bar AS hey.you will get transformed into foo.bar AS hey$you
				//
				// In effect this means that you can "reassign" a value from one table (foo) to another (hey)
				//
				// => If it's not the main table, all fields get an alias using either their own name or the given field alias
				//
				//	4b) AS and $ and the field or its alias
				//
				// So something like foo.bar AS boink will get transformed into foo.bar AS foo$boink
				//
				//	4b-2)	like 4a-2) above, but for subtables
				//
				// The $ sign is used in class tx_dataquery_wrapper for building the data structure
				// Initialize values
			$mappedField = '';
			$mappedTable = '';
			$fullField = $fieldInfo['tableAlias'] . '.' . $fieldInfo['field'];
			if ($fieldInfo['function']) {
				$fullField = $fieldInfo['field'];
			}
			$theField = $fieldInfo['field'];
				// Case 4a
			if ($fieldInfo['tableAlias'] == $this->queryObject->mainTable) {
				if (empty($fieldInfo['fieldAlias'])) {
					$theAlias = $theField;
				} else {
					$fullField .= ' AS ';
					if (strpos($fieldInfo['fieldAlias'], '.') === FALSE) {
						$theAlias = $fieldInfo['fieldAlias'];
						$mappedTable = $fieldInfo['tableAlias'];
						$mappedField = $fieldInfo['fieldAlias'];
					}
						// Case 4a-2
					else {
						list($mappedTable, $mappedField) = explode('.', $fieldInfo['fieldAlias']);
						$theAlias = str_replace('.', '$', $fieldInfo['fieldAlias']);
					}
					$fullField .= $theAlias;
				}
			} else {
				$fullField .= ' AS ';
				if (empty($fieldInfo['fieldAlias'])) {
					$theAlias = $fieldInfo['tableAlias'] . '$' . $fieldInfo['field'];
				}
				else {
						// Case 4b
					if (strpos($fieldInfo['fieldAlias'], '.') === FALSE) {
						$theAlias = $fieldInfo['tableAlias'] . '$' . $fieldInfo['fieldAlias'];
					}
						// Case 4b-2
					else {
						list($mappedTable, $mappedField) = explode('.', $fieldInfo['fieldAlias']);
						$theAlias = str_replace('.', '$', $fieldInfo['fieldAlias']);
					}
				}
				$fullField .= $theAlias;
			}
			if (empty($mappedTable)) {
				$mappedTable = $fieldInfo['tableAlias'];
				$mappedField = $theField;
			}
			$this->fieldTrueNames[$theAlias] = array(
													'table' => $fieldInfo['table'],
													'aliasTable' => $fieldInfo['tableAlias'],
													'field' => $theField,
													'mapping' => array('table' => $mappedTable, 'field' => $mappedField)
												);
			$this->queryObject->structure['SELECT'][$index] = $fullField;
        }
	}

	/**
	 * This method checks every table that doesn't have a uid or pid field and tries to add it
	 * to the list of fields to select
	 *
	 * @return	void
	 */
	protected function addBaseFields() {
			// Loop on the tables that don't have a uid field
        foreach ($this->queryObject->hasBaseFields as $alias => $listOfFields) {
				// Get all fields for the given table
			$fieldsInfo = tx_overlays::getAllFieldsForTable($this->queryObject->aliases[$alias]);
			foreach ($listOfFields as $baseField => $flag) {
				if (!$flag) {
						// Add the uid field only if it exists
					if (isset($fieldsInfo[$baseField])) {
						$this->addExtraField($baseField, $alias, $this->getTrueTableName($alias));
					}
				}
			}
        }
	}

	/**
	 * This method gets the localized labels for all tables and fields in the query in the given language
	 *
	 * @param	string		$language: two-letter ISO code of a language
	 * @return	array		list of all localized labels
	 */
	public function getLocalizedLabels($language = '') {
			// Make sure we have a lang object available
			// Use the global one, if it exists
		if (isset($GLOBALS['lang'])) {
			$lang = $GLOBALS['lang'];

			// If no language object is available, create one
        } else {
			require_once(PATH_typo3 . 'sysext/lang/lang.php');
				/** @var $lang language */
			$lang = t3lib_div::makeInstance('language');
			$languageCode = '';
				// Find out which language to use
			if (empty($language)) {
					// If in the BE, it's taken from the user's preferences
				if (TYPO3_MODE == 'BE') {
					$languageCode = $GLOBALS['BE_USER']->uc['lang'];

					// In the FE, we use the config.language TS property
                } else {
					if (isset($GLOBALS['TSFE']->tmpl->setup['config.']['language'])) {
						$languageCode = $GLOBALS['TSFE']->tmpl->setup['config.']['language'];
					}
                }
            } else {
				$languageCode = $language;
            }
			$lang->init($languageCode);
		}

			// Include the full TCA ctrl section
		if (TYPO3_MODE == 'FE') {
			$GLOBALS['TSFE']->includeTCA();
		}
			// Now that we have a properly initialised language object,
			// loop on all labels and get any existing localised string
		$localizedStructure = array();
		foreach ($this->queryFields as $alias => $tableData) {
			$table = $tableData['name'];
				// Initialize structure for table, if not already done
			if (!isset($localizedStructure[$alias])) {
				$localizedStructure[$alias] = array('table' => $table, 'fields' => array());
			}
				// Load the full TCA for the table
			t3lib_div::loadTCA($table);
				// Get the labels for the tables
			if (isset($GLOBALS['TCA'][$table]['ctrl']['title'])) {
				$tableName = $lang->sL($GLOBALS['TCA'][$table]['ctrl']['title']);
				$localizedStructure[$alias]['name'] = $tableName;
			}
			else {
				$localizedStructure[$alias]['name'] = $table;
			}
				// Get the labels for the fields
			foreach ($tableData['fields'] as $fieldData) {
					// Set default values
				$tableAlias = $alias;
				$field = $fieldData['name'];
					// Get the localized label, if it exists, otherwise use field name
					// Skip if it's a function (it will have no TCA definition anyway)
				$fieldName = $field;
				if (!$fieldData['function'] && isset($GLOBALS['TCA'][$table]['columns'][$fieldData['name']]['label'])) {
					$fieldName = $lang->sL($GLOBALS['TCA'][$table]['columns'][$fieldData['name']]['label']);
				}
					// Check if the field has an alias, if yes use it
					// Otherwise use the field name itself as an alias
				$fieldAlias = $field;
				if (isset($this->queryObject->fieldAliases[$alias][$field])) {
					$fieldAlias = $this->queryObject->fieldAliases[$alias][$field];
						// If the alias contains a dot (.), it means it contains the alias of a table name
						// Explode the name on the dot and use the parts as a new table alias and field name
					if (strpos($fieldAlias, '.') !== false) {
						list($tableAlias, $fieldAlias) = t3lib_div::trimExplode('.', $fieldAlias);
							// Initialize structure for table, if not already done
						if (!isset($localizedStructure[$tableAlias])) $localizedStructure[$tableAlias] = array('table' => $tableAlias, 'fields' => array());
					}
				}
					// Store the localized label
				$localizedStructure[$tableAlias]['fields'][$fieldAlias] = $fieldName;
            }
        }
//		t3lib_utility_Debug::debug($localizedStructure, 'Localized structure');
		return $localizedStructure;
    }

	/**
	 * Set the data coming from the Data Provider class
	 *
	 * @param array $providerData Database record corresponding to the current Data Query record
	 * @return void
	 */
	public function setProviderData($providerData) {
		$this->providerData = $providerData;
			// Perform some processing on some fields
			// Mostly this is about turning into arrays the fields containing comma-separated values
		$this->providerData['ignore_time_for_tables_exploded'] = t3lib_div::trimExplode(',', $this->providerData['ignore_time_for_tables']);
		$this->providerData['ignore_disabled_for_tables_exploded'] = t3lib_div::trimExplode(',', $this->providerData['ignore_disabled_for_tables']);
		$this->providerData['ignore_fegroup_for_tables_exploded'] = t3lib_div::trimExplode(',', $this->providerData['ignore_fegroup_for_tables']);
		$this->providerData['get_versions_directly_exploded'] = t3lib_div::trimExplode(',', $this->providerData['get_versions_directly']);
	}

	/**
	 * This method returns an associative array containing information for method enableFields.
	 * enableFields() will skip each enable field condition from the returned array.
	 *
	 * @param	string		$tableName: the name of the table
	 * @return	array		the array containing the keys to be ignored
	 */
	protected function getIgnoreArray($tableName) {
		$ignoreArray = array();
			// Handle case when some fields should be partially excluded from enableFields()
		if ($this->providerData['ignore_enable_fields'] == '2') {

				// starttime / endtime field
			if (in_array($tableName, $this->providerData['ignore_time_for_tables_exploded']) ||
					$this->providerData['ignore_time_for_tables'] == '*') {
				$ignoreArray['starttime'] = TRUE;
				$ignoreArray['endtime'] = TRUE;
			}

				// disabled field
			if (in_array($tableName, $this->providerData['ignore_disabled_for_tables_exploded']) ||
					$this->providerData['ignore_disabled_for_tables'] == '*') {
				$ignoreArray['disabled'] = TRUE;
			}

				// fe_group field
			if (in_array($tableName, $this->providerData['ignore_fegroup_for_tables_exploded']) ||
					$this->providerData['ignore_fegroup_for_tables'] == '*') {
				$ignoreArray['fe_group'] = TRUE;
			}
		}
		return $ignoreArray;
	}

	/**
	 * This method adds where clause elements related to typical TYPO3 control parameters:
	 *
	 * 	- the enable fields
	 * 	- the language handling
	 * 	- the versioning system
	 *
	 * @return	void
	 */
	public function addTypo3Mechanisms() {
			// Add enable fields conditions
		$this->addEnableFieldsCondition();
			// Assemble a list of all currently selected fields for each table,
			// skipping function calls (which can't be overlayed anyway)
			// This is used by the next two methods, which may add some necessary fields,
			// if not present already
		$fieldsPerTable = array();
		foreach ($this->queryFields as $alias => $tableData) {
			$fieldsPerTable[$alias] = array();
			foreach ($tableData['fields'] as $fieldData) {
				if (!$fieldData['function']) {
					$fieldsPerTable[$alias][] = $fieldData['name'];
				}
			}
		}
			// Add language-related conditions
		$this->addLanguageCondition($fieldsPerTable);
			// Add versioning-related conditions
		$this->addVersioningCondition($fieldsPerTable);
	}

	/**
	 * This method adds all SQL conditions needed to enforce the enable fields for
	 * all tables involved
	 *
	 * @return	void
	 */
	protected function addEnableFieldsCondition() {
			// First check if enable fields must really be added or should be ignored
		if ($this->providerData['ignore_enable_fields'] == '0' || $this->providerData['ignore_enable_fields'] == '2') {

				// Start with main table
				// Define parameters for enable fields condition
			$trueTableName = $this->queryObject->aliases[$this->queryObject->mainTable];
			$showHidden = ($trueTableName == 'pages') ? $GLOBALS['TSFE']->showHiddenPage : $GLOBALS['TSFE']->showHiddenRecords;
			$ignoreArray = $this->getIgnoreArray($this->queryObject->mainTable);

			$enableClause = tx_overlays::getEnableFieldsCondition($trueTableName, $showHidden, $ignoreArray);
				// Replace the true table name by its alias if necessary
				// NOTE: there's a risk that a field containing the table name might be modified abusively
				// There's no real way around it except changing tx_overlays::getEnableFieldsCondition()
				// to re-implement a better t3lib_page::enableFields()
				// Adding the "." in the replacement reduces the risks
			if ($this->queryObject->mainTable != $trueTableName) {
				$enableClause = str_replace($trueTableName . '.', $this->queryObject->mainTable . '.', $enableClause);
			}
			$this->addWhereClause($enableClause);

				// Add enable fields to JOINed tables
			if (isset($this->queryObject->structure['JOIN']) && is_array($this->queryObject->structure['JOIN'])) {
				foreach ($this->queryObject->structure['JOIN'] as $joinData) {

						// Define parameters for enable fields condition
					$table = $joinData['table'];
					$showHidden = ($table == 'pages') ? $GLOBALS['TSFE']->showHiddenPage : $GLOBALS['TSFE']->showHiddenRecords;
					$ignoreArray = $this->getIgnoreArray($joinData['alias']);

					$enableClause = tx_overlays::getEnableFieldsCondition($table, $showHidden, $ignoreArray);
					if (!empty($enableClause)) {
						if ($table != $joinData['alias']) {
							$enableClause = str_replace($table . '.', $joinData['alias'] . '.', $enableClause);
						}
						$this->addOnClause($enableClause, $joinData['alias']);
					}
				}
			}
		}
	}

	/**
	 * Add SQL conditions related to language handling
	 * Also add the necessary fields to the list of SELECTed fields
	 *
	 * @param	array	$fieldsPerTable: List of all fields already SELECTed, per table
	 *
	 * @return	void
	 */
	protected function addLanguageCondition($fieldsPerTable) {
		$skippedTablesForLanguageOverlays = t3lib_div::trimExplode(',', $this->providerData['skip_overlays_for_tables'], TRUE);

			// Add the language condition, if necessary
		if (empty($this->providerData['ignore_language_handling']) && !$this->queryObject->structure['DISTINCT']) {

				// Add the DB fields and the SQL conditions necessary for having everything ready to handle overlays
				// as per the standard TYPO3 mechanism
				// Loop on all tables involved
			foreach ($this->queryFields as $alias => $tableData) {
				$table = $tableData['name'];

					// First entirely skip tables which are defined in the skip list
				if (in_array($table, $skippedTablesForLanguageOverlays)) {
					$this->doOverlays[$table] = FALSE;

					// Check which handling applies, based on existing TCA structure
					// The table must at least have a language field or point to a foreign table for translation
				} elseif (isset($GLOBALS['TCA'][$table]['ctrl']['languageField']) || isset($GLOBALS['TCA'][$table]['ctrl']['transForeignTable'])) {

						// The table uses translations in the same table (transOrigPointerField) or in a foreign table (transForeignTable)
						// Prepare for overlays
					if (isset($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']) || isset($GLOBALS['TCA'][$table]['ctrl']['transForeignTable'])) {
							// For each table, make sure that the fields necessary for handling the language overlay are included in the list of selected fields
						try {
							$fieldsForOverlayArray = tx_overlays::selectOverlayFieldsArray($table, implode(',', $fieldsPerTable[$alias]));
								// Extract which fields were added and add them to the list of fields to select
							$addedFields = array_diff($fieldsForOverlayArray, $fieldsPerTable[$alias]);
							if (count($addedFields) > 0) {
								foreach ($addedFields as $aField) {
									$this->addExtraField($aField, $alias, $table);
								}
							}
							$this->doOverlays[$table] = TRUE;
								// Add the language condition for the given table (only for tables containing their own translations)
							if (isset($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])) {
								$languageCondition = tx_overlays::getLanguageCondition($table, $alias);
								if ($alias == $this->queryObject->mainTable) {
									$this->addWhereClause($languageCondition);
								} else {
									$this->addOnClause($languageCondition, $alias);
								}
							}
						}
						catch (Exception $e) {
							$this->doOverlays[$table] = FALSE;
						}
					}

					// The table simply contains a language flag.
					// This is just about adding the proper condition on the language field and nothing more
					// No overlays will be handled at a later time
				} else {
					if (isset($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
							// Take language that corresponds to current language or [All]
						$languageCondition = $alias . '.' . $GLOBALS['TCA'][$table]['ctrl']['languageField'] . ' IN (' . $GLOBALS['TSFE']->sys_language_content . ', -1)';
						if ($alias == $this->queryObject->mainTable) {
							$this->addWhereClause($languageCondition);
						} else {
							$this->addOnClause($languageCondition, $alias);
						}
					}
				}
			}
		}
//t3lib_utility_Debug::debug($this->doOverlays);
	}

	/**
	 * Add SQL conditions related version handling
	 * Also add the necessary fields to the list of SELECTed fields
	 * Contrary to the other conditions, versioning conditions are always added,
	 * if only to make sure that only LIVE records are selected
	 *
	 * @param	array	$fieldsPerTable: List of all fields already SELECTed, per table
	 *
	 * @return	void
	 */
	protected function addVersioningCondition($fieldsPerTable) {
		foreach ($this->queryFields as $alias => $tableData) {
			$table = $tableData['name'];
			$this->doVersioning[$table] = FALSE;

				// Continue if table indeed supports versioning
			if (!empty($GLOBALS['TCA'][$table]['ctrl']['versioningWS'])) {
					// By default make sure to take only LIVE version
				$workspaceCondition = $alias . ".t3ver_oid = '0'";
					// If in preview mode, assemble condition according to current workspace
				if ($GLOBALS['TSFE']->sys_page->versioningPreview) {
						// For each table, make sure that the fields necessary for handling the language overlay are included in the list of selected fields
					try {
						$fieldsForOverlayArray = tx_overlays::selectVersioningFieldsArray($table, implode(',', $fieldsPerTable[$alias]));
							// Extract which fields were added and add them to the list of fields to select
						$addedFields = array_diff($fieldsForOverlayArray, $fieldsPerTable[$alias]);
						if (count($addedFields) > 0) {
							foreach ($addedFields as $aField) {
								$this->addExtraField($aField, $alias, $table);
							}
						}
						$this->doVersioning[$table] = TRUE;
						$getVersionsDirectly = FALSE;
						if ($this->providerData['get_versions_directly'] == '*' || in_array($alias, $this->providerData['get_versions_directly_exploded'])) {
							$getVersionsDirectly = TRUE;
						}
						$workspaceCondition = tx_overlays::getVersioningCondition($table, $alias, $getVersionsDirectly);
					}
					catch (Exception $e) {
						$this->doVersioning[$table] = FALSE;
						$this->parentObject->getController()->addMessage(
							self::$extKey,
							'A problem happened with versioning: ' . $e->getMessage() . ' (' . $e->getCode() . ')',
							'Falling back to LIVE records for table ' . $table,
							t3lib_FlashMessage::WARNING
						);
					}
				}
				if ($alias == $this->queryObject->mainTable) {
					$this->addWhereClause($workspaceCondition);
				} else {
					$this->addOnClause($workspaceCondition, $alias);
				}
			}
		}
//t3lib_utility_Debug::debug($this->doVersioning);
	}

	/**
	 * This method takes a Data Filter structure and processes its instructions
	 *
	 * @param	array		$filter: Data Filter structure
	 * @return	void
	 */
	public function addFilter($filter) {
			// First handle the "filter" part, which will be turned into part of a SQL WHERE clause
		$completeFilters = array();
		$logicalOperator = (empty($filter['logicalOperator'])) ? 'AND' : $filter['logicalOperator'];
		if (isset($filter['filters']) && is_array($filter['filters'])) {
			foreach ($filter['filters'] as $index => $filterData) {
				$table = '';
					// Check if the condition must be explicitly ignored
					// (i.e. it is transmitted by the filter only for information)
					// If not, resolve the table name, if possible
				if ($filterData['void']) {
					$ignoreCondition = TRUE;
				} else {
					$ignoreCondition = FALSE;
					$table = (empty($filterData['table'])) ? $this->queryObject->mainTable : $filterData['table'];
						// Check if the table is available in the query
					try {
						$table = $this->matchAliasOrTableName($table, 'Filter - ' . ((empty($filterData['string'])) ? $index : $filterData['string']));
					}
					catch (tx_tesseract_exception $e) {
						$ignoreCondition = TRUE;
						$this->parentObject->getController()->addMessage(
							self::$extKey,
							'The condition did not apply to a table used in the query.',
							'Condition ignored',
							t3lib_FlashMessage::NOTICE,
							$filterData
						);
					}
				}
					// If the table is not in the query, ignore the condition
				if (!$ignoreCondition) {
					$field = $filterData['field'];
					$fullField = $table . '.' . $field;
						// If the field is an alias, override full field definition
						// to whatever the alias is mapped to
					if (isset($this->queryObject->fieldAliasMappings[$field])) {
						$fullField = $this->queryObject->fieldAliasMappings[$field];
					}
					$condition = '';
						// Define table on which to apply the condition
						// Conditions will normally be applied in the WHERE clause
						// if the table is the main one, otherwise it is applied
						// in the ON clause of the relevant JOIN statement
						// However the application of the condition may be forced to be in the WHERE clause,
						// no matter which table it targets
					$tableForApplication = $table;
					if ($filterData['main']) {
						$tableForApplication = $this->queryObject->mainTable;
					}
					foreach ($filterData['conditions'] as $conditionData) {
							// If the value is special value "\all", all values must be taken,
							// so the condition is simply ignored
						if ($conditionData['value'] != '\all') {
							if (!empty($condition)) {
								$condition .= ' AND ';
							}
							$condition .= '(' . tx_dataquery_SqlUtility::conditionToSql($fullField, $table, $conditionData) . ')';
						}
					}
						// Add the condition only if it wasn't empty
					if (!empty($condition)) {
						if (empty($completeFilters[$tableForApplication])) {
							$completeFilters[$tableForApplication] = '';
						} else {
							$completeFilters[$tableForApplication] .= ' ' . $logicalOperator . ' ';
						}
						$completeFilters[$tableForApplication] .= '(' . $condition . ')';
					}
				}
			}
			foreach ($completeFilters as $table => $whereClause) {
				$whereClause = '(' . $whereClause . ')';
				if ($table == $this->queryObject->mainTable) {
					$this->addWhereClause($whereClause);
				} elseif (in_array($table, $this->queryObject->subtables)) {
					$this->addOnClause($whereClause, $table);
				}
			}
				// Free some memory
			unset($completeFilters);
		}
			// Add the eventual raw SQL in the filter
			// Raw SQL is always added to the main where clause
		if (!empty($filter['rawSQL'])) {
			$this->addWhereClause($filter['rawSQL']);
		}
			// Handle the order by clauses
		if (count($filter['orderby']) > 0) {
			foreach ($filter['orderby'] as $orderData) {
					// Special case if ordering is random
				if ($orderData['order'] == 'RAND') {
					$this->queryObject->structure['ORDER BY'][] = 'RAND()';

					// Handle normal configuration
				} else {
					$table = ((empty($orderData['table'])) ? $this->queryObject->mainTable : $orderData['table']);
						// Try applying the order clause to an existing table
					try {
						$table = $this->matchAliasOrTableName($table, 'Order clause - ' . $table . ' - ' . $orderData['field'] . ' - ' . $orderData['order']);
						$completeField = $table . '.' . $orderData['field'];
						$orderbyClause = $completeField . ' ' . $orderData['order'];
						$this->queryObject->structure['ORDER BY'][] = $orderbyClause;
						$this->queryObject->orderFields[] = array(
							'field' => $completeField,
							'order' => $orderData['order'],
							'engine' => isset($orderData['engine']) ? $orderData['engine'] : ''
						);
					}
						// Table was not matched
					catch (tx_tesseract_exception $e) {
						$this->parentObject->getController()->addMessage(
							self::$extKey,
							'The ordering clause did not apply to a table used in the query.',
							'Ordering ignored',
							t3lib_FlashMessage::NOTICE,
							$orderData
						);
					}
				}
			}
		}
	}

	/**
	 * This method takes a list of uid's prepended by their table name,
	 * as returned in the "uidListWithTable" property of a idList-type SDS,
	 * and makes it into appropriate SQL IN conditions for every table that matches those used in the query
	 *
	 * @param	string		$idList: Comma-separated list of uid's prepended by their table name
	 * @return	void
	 */
	public function addIdList($idList) {
		if (!empty($idList)) {
			$idArray = t3lib_div::trimExplode(',', $idList);
			$idlistsPerTable = array();
				// First assemble a list of all uid's for each table
			foreach ($idArray as $item) {
					// Code inspired from t3lib_loadDBGroup
					// String is reversed before exploding, to get uid first
				list($uid, $table) = explode('_', strrev($item), 2);
					// Exploded parts are reversed back
				$uid = strrev($uid);
					// If table is not defined, assume it's the main table
				if (empty($table)) {
					$table = $this->queryObject->mainTable;
					if (!isset($idlistsPerTable[$table])) {
						$idlistsPerTable[$table] = array();
					}
					$idlistsPerTable[$table][] = $uid;
				} else {
					$table = strrev($table);
						// Make sure the table name matches one used in the query
					try {
						$table = $this->matchAliasOrTableName($table, 'Id list - ' . $item);
						if (!isset($idlistsPerTable[$table])) {
							$idlistsPerTable[$table] = array();
						}
						$idlistsPerTable[$table][] = $uid;
					}
					catch (tx_tesseract_exception $e) {
						$this->parentObject->getController()->addMessage(
							self::$extKey,
							'An item from the id list did not apply, because table ' . $table . ' is not used in the query.',
							'Id ignored',
							t3lib_FlashMessage::NOTICE,
							$item
						);
					}
				}
			}
				// Loop on all tables and add test on list of uid's, if table is indeed in query
			foreach ($idlistsPerTable as $table => $uidArray) {
				$condition = $table . '.uid IN (' . implode(',', $uidArray) . ')';
				if ($table == $this->queryObject->mainTable) {
					$this->addWhereClause($condition);
				} elseif (in_array($table, $this->queryObject->subtables)) {
					if (!empty($this->queryObject->structure['JOIN'][$table]['on'])) {
						$this->queryObject->structure['JOIN'][$table]['on'] .= ' AND ';
					}
					$this->queryObject->structure['JOIN'][$table]['on'] .= $condition;
				}
			}
				// Free some memory
			unset($idlistsPerTable);
		}
	}

	/**
	 * This method builds up the query with all the data stored in the structure
	 *
	 * @return	string		the assembled SQL query
	 */
	public function buildQuery() {
			// First check what to do with ORDER BY fields
		$this->preprocessOrderByFields();
			// Start assembling the query
		$query  = 'SELECT ';
		if ($this->queryObject->structure['DISTINCT']) {
			$query .= 'DISTINCT ';
		}
		$query .= implode(', ', $this->queryObject->structure['SELECT']) . ' ';
		$query .= 'FROM ' . $this->queryObject->structure['FROM']['table'];
		if (!empty($this->queryObject->structure['FROM']['alias'])) {
			$query .= ' AS ' . $this->queryObject->structure['FROM']['alias'];
		}
		$query .= ' ';
		if (isset($this->queryObject->structure['JOIN'])) {
			foreach ($this->queryObject->structure['JOIN'] as $theJoin) {
				$query .= strtoupper($theJoin['type']) . ' JOIN ' . $theJoin['table'];
				if (!empty($theJoin['alias'])) {
					$query .= ' AS ' . $theJoin['alias'];
				}
				if (!empty($theJoin['on'])) {
					$query .= ' ON ' . $theJoin['on'];
				}
				$query .= ' ';
			}
		}
		if (count($this->queryObject->structure['WHERE']) > 0) {
			$whereClause = '';
			foreach ($this->queryObject->structure['WHERE'] as $clause) {
				if (!empty($whereClause)) {
					$whereClause .= ' AND ';
				}
				$whereClause .= '(' . $clause . ')';
			}
			$query .= 'WHERE ' . $whereClause . ' ';
		}
		if (count($this->queryObject->structure['GROUP BY']) > 0) {
			$query .= 'GROUP BY ' . implode(', ', $this->queryObject->structure['GROUP BY']) . ' ';
		}
			// Add order by clause if defined and if applicable (see preprocessOrderByFields())
		if ($this->processOrderBy && count($this->queryObject->structure['ORDER BY']) > 0) {
			$query .= 'ORDER BY ' . implode(', ', $this->queryObject->structure['ORDER BY']) . ' ';
		}
		if (isset($this->queryObject->structure['LIMIT'])) {
			$query .= 'LIMIT ' . $this->queryObject->structure['LIMIT'];
			if (isset($this->queryObject->structure['OFFSET'])) {
				$query .= ' OFFSET ' . $this->queryObject->structure['OFFSET'];
			}
		}
//t3lib_utility_Debug::debug($query);
		return $query;
	}

	/**
	 * This method performs some operations on the fields used for ordering the query, if any
	 * If the language is not the default one, order may not be desirable in SQL
	 * As translations are handled using overlays in TYPO3, it is not possible
	 * to sort the records alphabetically in the SQL statement, because the SQL
	 * statement gets only the records in original language
	 *
	 * @return	boolean		true if order by must be processed by the SQL query, false otherwise
	 */
	protected function preprocessOrderByFields() {
/*
t3lib_utility_Debug::debug($this->queryObject->orderFields, 'Order fields');
t3lib_utility_Debug::debug($this->queryObject->fieldAliases, 'Field aliases');
t3lib_utility_Debug::debug($this->fieldTrueNames, 'Field true names');
t3lib_utility_Debug::debug($this->queryFields, 'Query fields');
t3lib_utility_Debug::debug($this->queryObject->structure['SELECT'], 'Select structure');
 *
 */
		if (count($this->queryObject->orderFields) > 0) {
				// If in the FE context and not the default language, start checking for possible use of SQL or not
			if (isset($GLOBALS['TSFE']) && $GLOBALS['TSFE']->sys_language_content > 0) {
					// Include the complete ctrl TCA
				$GLOBALS['TSFE']->includeTCA();
					// Initialise sorting mode flag
				$cannotUseSQLForSorting = FALSE;
					// Initialise various arrays
				$newQueryFields = array();
				$newSelectFields = array();
				$newTrueNames = array();
				$countNewFields = 0;
				foreach ($this->queryObject->orderFields as $index => $orderInfo) {
						// Define the table and field names
					$fieldParts = explode('.', $orderInfo['field']);
					if (count($fieldParts) == 1) {
						$alias = $this->queryObject->mainTable;
						$field = $fieldParts[0];
					} else {
						$alias = $fieldParts[0];
						$field = $fieldParts[1];
					}
						// Skip all the rest of the logic for some special values
					if ($field !== 'RAND()' && $field !== 'NULL') {
							// If the field has an alias, change the order fields list to use it
						if (isset($this->queryObject->fieldAliases[$alias][$field])) {
							$this->queryObject->orderFields[$index]['alias'] = $this->queryObject->orderFields[$index]['field'];
							$this->queryObject->orderFields[$index]['field'] = $this->queryObject->fieldAliases[$alias][$field];
						}
							// Get the field's true table and field name, if defined, in case an alias is used in the ORDER BY statement
						if (isset($this->fieldTrueNames[$field])) {
							$alias = $this->fieldTrueNames[$field]['aliasTable'];
							$field = $this->fieldTrueNames[$field]['field'];
						}
							// Get the true table name and initialize new field array, if necessary
						$table = $this->getTrueTableName($alias);
						if (!isset($newQueryFields[$alias])) {
							$newQueryFields[$alias] = array(
								'name' => $alias,
								'table' => $table,
								'fields' => array()
							);
						}
							// Check if there's some explicit engine information
						if (!empty($orderInfo['engine'])) {
								// If at least one field must be handled by the provider, set the flag to true
							if ($orderInfo['engine'] == 'provider') {
								$cannotUseSQLForSorting |= TRUE;
							} else {
								// Nothing to do here. If the field was forced to be applied to the source,
								// it does not need to be checked further
							}
						} else {

								// Check the type of the field in the TCA
								// If the field is of some text type and that the table uses overlays,
								// ordering cannot happen in SQL.
							if (isset($GLOBALS['TCA'][$table])) {
									// Check if table uses overlays
								$usesOverlay = isset($GLOBALS['TCA'][$table]['ctrl']['languageField']) || isset($GLOBALS['TCA'][$table]['ctrl']['transForeignTable']);
									// Check the field type (load full TCA first)
								$isTextField = $this->isATextField($table, $field);
								$cannotUseSQLForSorting |= ($usesOverlay && $isTextField);
							}
						}
							// Check if the field is already part of the SELECTed fields (under its true name or an alias)
							// If not, get ready to add it by defining all necessary info in temporary arrays
							// (it will be added only if necessary, i.e. if at least one field needs to be ordered later)
						if (!$this->isAQueryField($alias, $field) && !isset($this->queryObject->fieldAliases[$alias][$field])) {
							$fieldAlias = $alias . '$' . $field;
							$newQueryFields[$alias]['fields'][] = array('name' => $field, 'function' => FALSE);
							$newSelectFields[] = $alias . '.' . $field . ' AS ' . $fieldAlias;
							$newTrueNames[$fieldAlias] = array(
								'table' => $table,
								'aliasTable' => $alias,
								'field' => $field,
								'mapping' => array('table' => $alias, 'field' => $field)
							);
							$countNewFields++;
						}
					}
				}
					// If sorting cannot be left simply to SQL, prepare to return false
					// and add the necessary fields to the SELECT statement
				if ($cannotUseSQLForSorting) {
					if ($countNewFields > 0) {
						$this->queryFields = t3lib_div::array_merge_recursive_overrule($this->queryFields, $newQueryFields);
						$this->queryObject->structure['SELECT'] = array_merge($this->queryObject->structure['SELECT'], $newSelectFields);
						$this->fieldTrueNames = t3lib_div::array_merge_recursive_overrule($this->fieldTrueNames, $newTrueNames);
/*
t3lib_utility_Debug::debug($newQueryFields, 'New query fields');
t3lib_utility_Debug::debug($this->queryFields, 'Updated query fields');
t3lib_utility_Debug::debug($newTrueNames, 'New field true names');
t3lib_utility_Debug::debug($this->fieldTrueNames, 'Updated field true names');
t3lib_utility_Debug::debug($newSelectFields, 'New select fields');
t3lib_utility_Debug::debug($this->queryObject->structure['SELECT'], 'Updated select structure');
 *
 */
							// Free some memory
						unset($newQueryFields);
						unset($newSelectFields);
						unset($newTrueNames);
					}
					$this->processOrderBy = FALSE;
				} else {
					$this->processOrderBy = TRUE;
				}
			} else {
				$this->processOrderBy = TRUE;
			}
		} else {
			$this->processOrderBy = TRUE;
		}
	}

	/**
	 * Tries to figure out if a given field of a given table is a text field, based on its TCA definition
	 *
	 * @param string $table Name of the table
	 * @param string $field Name of the field
	 * @return bool TRUE is the field can be considered to be text
	 */
	public function isATextField($table, $field) {
		t3lib_div::loadTCA($table);
		$isTextField = TRUE;
			// We can guess only if there's a TCA definition
		if (isset($GLOBALS['TCA'][$table]['columns'][$field])) {
			$fieldConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'];
				// It's text, easy :-)
			if ($fieldConfig['type'] == 'text') {
				$isTextField = TRUE;

				// It's input, further check the "eval" property
			} elseif ($fieldConfig['type'] == 'input') {
					// If the field has no eval property, assume it's just text
				if (empty($fieldConfig['eval'])) {
					$isTextField = TRUE;
				} else {
					$evaluations = explode(',', $fieldConfig['eval']);
						// Check if some eval types are common to both array. If yes, it's not a text field.
					$foundTypes = array_intersect($evaluations, self::$notTextTypes);
					$isTextField = (count($foundTypes) > 0) ? FALSE : TRUE;
				}

				// It's another type, it's definitely not text
			} else {
				$isTextField = FALSE;
			}
		}
		return $isTextField;
	}

	/**
	 * This is an internal utility method that checks whether a given field
	 * can be found in the fields reference list (i.e. $this->queryFields) for
	 * a given table
	 *
	 * @param	string		$table: name of the table inside which to look up
	 * @param	string		$field: name of the field to search for
	 * @return	boolean		True if the field was found, false otherwise
	 */
	protected function isAQueryField($table, $field) {
		$isAQueryField = FALSE;
		if (isset($this->queryFields[$table]['fields'])) {
			foreach ($this->queryFields[$table]['fields'] as $fieldData) {
				if ($fieldData['name'] == $field) {
					$isAQueryField = TRUE;
					break;
				}
			}
		}
		return $isAQueryField;
	}

	/**
	 * This method tries to match a name to the name or alias of a table used in the query
	 * If no alias or straight table name is found, it looks for a true table name instead
	 * If nothing is found, an exception is thrown
	 *
	 * Explanations: a table name may come from an outside source, a Data Filter or another provider.
	 * In order to apply the condition from that other element to the query,
	 * the table(s) referenced in that other element must match tables used in the query.
	 * If the query uses aliases and the other element not, dataquery tries
	 * (using this method) to match the tables from the other element to aliases used
	 * in the query. This may lead to some kind of guess work in which case a warning is logged.
	 *
	 * @param string $name Name to match
	 * @param string $identifier Some key identifying the circumstances in which the call was made (used for logging)
	 * @throws tx_tesseract_exception
	 * @return string Alias or table name
	 */
	protected function matchAliasOrTableName($name, $identifier) {
		$returnedName = $name;

			// If the name was already match, reuse result
		if (isset($this->tableMatches[$name])) {
			$returnedName = $this->tableMatches[$name];

			// If not, perform matching
		} else {
				// If the name matches an existing alias, use it as is
			if (isset($this->queryObject->aliases[$name])) {
				$this->tableMatches[$name] = $name;

				// If the name is not in the list of aliases, try to match it
				// to a true table name
			} else {
					// Get the relation of true table names to aliases
					// NOTE: true table names are not necessarily unique
				$reversedAliasTable = array_flip($this->queryObject->aliases);
				if (isset($reversedAliasTable[$name])) {
					$returnedName = $reversedAliasTable[$name];
					$this->tableMatches[$name] = $reversedAliasTable[$name];
						// Write a notice to the message queue
					$message = sprintf('Potentially unreliable match of table %1$s from component %2$s', $name, $identifier);
					$this->parentObject->getController()->addMessage(
						self::$extKey,
						$message,
						'Unreliable alias match',
						t3lib_FlashMessage::WARNING
					);

					// No match found, throw exception
				} else {
					$message = sprintf('No match found for table %1$s from component %2$s', $name, $identifier);
					throw new tx_tesseract_exception($message, 1291753564);
				}
			}
		}
		return $returnedName;
	}

	/**
	 * This method is used to add an extra field to be SELECTed
	 * It must be added to the SELECT list, to the list of fields being queried
	 * and to the registry of true names
	 *
	 * @param	string	$field: name of the field to add
	 * @param	string	$tableAlias: alias of the table to add the field to
	 * @param	string	$table: true name of the table to add the field to
	 */
	protected function addExtraField($field, $tableAlias, $table) {
		$newFieldName = $tableAlias . '.' . $field;
		$newFieldAlias = $field;
		if ($tableAlias != $this->queryObject->mainTable) {
			$newFieldAlias = $tableAlias . '$' . $field;
			$newFieldName .= ' AS ' . $newFieldAlias;
		}
		$this->queryObject->structure['SELECT'][] = $newFieldName;
		$this->queryFields[$tableAlias]['fields'][] = array('name' => $field, 'function' => FALSE);
		$this->fieldTrueNames[$newFieldAlias] = array(
													'table' => $table,
													'aliasTable' => $tableAlias,
													'field' => $field,
													'mapping' => array('table' => $tableAlias, 'field' => $field)
												);
	}

// Setters and getters

	/**
	 * Add a condition for the WHERE clause
	 *
	 * @param string $clause SQL WHERE clause (without WHERE)
	 * @return void
	 */
	public function addWhereClause($clause) {
		if (!empty($clause)) {
			$this->queryObject->structure['WHERE'][] = $clause;
		}
	}

	/**
	 * Add a condition to the ON clause of a given table
	 *
	 * @param	string	$clause: SQL to add to the ON clause
	 * @param	string	$alias: alias of the table to the statement to
	 * @return	void
	 */
	public function addOnClause($clause, $alias) {
		if (!empty($this->queryObject->structure['JOIN'][$alias]['on'])) {
			$this->queryObject->structure['JOIN'][$alias]['on'] .= ' AND ';
		}
		$this->queryObject->structure['JOIN'][$alias]['on'] .= '(' . $clause . ')';
	}

	/**
	 * This method returns the structure of the parsed query
	 * There should be little real-life uses for this, but it is used by the
	 * test case to get the parsed structure
	 *
	 * @return	array	The parsed query
	 */
	public function getQueryStructure() {
		return $this->queryObject->structure;
	}

	/**
	 * This method returns the name (alias) of the main table of the query,
	 * which is the table name that appears in the FROM clause, or the alias, if any
	 *
	 * @return	string		main table name (alias)
	 */
	public function getMainTableName() {
		return $this->queryObject->mainTable;
	}

	/**
	 * This method returns an array containing the list of all subtables in the query,
	 * i.e. the tables that appear in any of the JOIN statements
	 *
	 * @return	array		names of all the joined tables
	 */
	public function getSubtablesNames() {
		return $this->queryObject->subtables;
	}

	/**
	 * This method takes an alias and returns the true table name
	 *
	 * @param	string		$alias: alias of a table
	 * @return	string		True name of the corresponding table
	 */
	public function getTrueTableName($alias) {
		return $this->queryObject->aliases[$alias];
	}

	/**
	 * This method takes the alias and returns it's true name
	 * The alias is the full alias as used in the query (e.g. table$field)
	 *
	 * @param	string		$alias: alias of a field
	 * @return	array		Array with the true name of the corresponding field
	 *						and the true name of the table it belongs and the alias of that table
	 */
	public function getTrueFieldName($alias) {
		$trueNameInformation = $this->fieldTrueNames[$alias];
			// Assemble field key (possibly disambiguated with function name)
		$fieldKey = $trueNameInformation['field'];
//		if (!empty($trueNameInformation['function'])) {
//			$fieldKey .= '_' . $trueNameInformation['function'];
//		}
			// If the field has an explicit alias, we must also pass back that information
		if (isset($this->queryObject->fieldAliases[$trueNameInformation['aliasTable']][$fieldKey])) {
			$alias = $this->queryObject->fieldAliases[$trueNameInformation['aliasTable']][$fieldKey];
				// Check if the alias contains a table name
				// If yes, strip it, as this information is already handled
			if (strpos($alias, '.') !== FALSE) {
				list(, $field) = explode('.', $alias);
				$alias = $field;
			}
			$trueNameInformation['mapping']['alias'] = $alias;
		}
		return $trueNameInformation;
	}

	/**
	 * This method returns the list of fields defined for ordering the data
	 *
	 * @return	array	Fields for ordering (and sort order)
	 */
	public function getOrderByFields() {
		return $this->queryObject->orderFields;
	}

	public function getSQLObject() {
		return $this->queryObject;
	}

	/**
	 * This method returns the value of the isMergedResult flag
	 *
	 * @return	boolean
	 */
	public function hasMergedResults() {
		return $this->isMergedResult;
	}

	/**
	 * This method indicates whether the language overlay mechanism must/can be handled for a given table
	 *
	 * @param	string		$table: true name of the table to handle
	 * @return	boolean		true if language overlay must and can be performed, false otherwise
	 * @see tx_dataquery_parser::addTypo3Mechanisms()
	 */
	public function mustHandleLanguageOverlay($table) {
		return (isset($this->doOverlays[$table])) ? $this->doOverlays[$table] : FALSE;
	}

	/**
	 * This method indicates whether the language overlay mechanism must/can be handled for a given table
	 *
	 * @param	string		$table: true name of the table to handle
	 * @return	boolean		true if language overlay must and can be performed, false otherwise
	 * @see tx_dataquery_parser::addTypo3Mechanisms()
	 */
	public function mustHandleVersioningOverlay($table) {
		return (isset($this->doVersioning[$table])) ? $this->doVersioning[$table] : FALSE;
	}

	/**
	 * This method returns whether the ordering of the records was done in the SQL query
	 * or not
	 *
	 * @return	boolean	true if SQL was used, false otherwise
	 */
	public function isSqlUsedForOrdering() {
		return $this->processOrderBy;
	}

	/**
	 * This method returns true if any ordering has been defined at all
	 * False otherwise
	 *
	 * @return	boolean	true if there's at least one ordering criterion, false otherwise
	 */
	public function hasOrdering() {
		return count($this->queryObject->orderFields) > 0;
	}

	/**
	 * This method returns the name of the first significant table to be INNER JOINed
	 * A "significant table" is a table that has a least one field SELECTed
	 * If the first significant table is not INNER JOINed or if there are no JOINs
	 * or no INNER JOINs, an empty string is returned
	 *
	 * @return	string	alias of the first significant table, if INNER JOINed, empty string otherwise
	 */
	public function hasInnerJoinOnFirstSubtable() {
		$returnValue = '';
		if (count($this->queryObject->structure['JOIN']) > 0) {
			foreach ($this->queryObject->structure['JOIN'] as $alias => $joinInfo) {
				if (isset($this->queryFields[$alias])) {
					if ($joinInfo['type'] == 'inner') {
						$returnValue = $alias;
					}
					break;
				}
			}
		}
		return $returnValue;
	}

	/**
	 * This method can be used to get the limit that was defined for a given subtable
	 * (i.e. a JOINed table). If no limit exists, 0 is returned
	 *
	 * @param	string		$table: name of the table to find the limit for
	 * @return	integer		Value of the limit, or 0 if not defined
	 */
	public function getSubTableLimit($table) {
		return isset($this->queryObject->structure['JOIN'][$table]['limit']) ? $this->queryObject->structure['JOIN'][$table]['limit'] : 0;
	}

	/**
	 * This method checks for the existence of a field (possibly with alias) called "uid"
	 * for the query's main table
	 *
	 * @return bool TRUE if a "uid" field is present, FALSE otherwise
	 */
	protected function checkUidForDistinctUsage() {
		$hasUid = FALSE;
			// There should be either an alias called "uid" or "table$uid"
			// (where "table" is the name of the main table)
		$possibleKeys = array('uid', $this->queryObject->mainTable . '$uid');
			// Also add the possible aliases of the main table
			// NOTE: this may be wrong when there are more than 1 alias for the main table,
			// as the uid may actually belong to another table
		$reversedAliases = array_flip($this->queryObject->aliases);
		foreach ($reversedAliases as $table => $alias) {
			if ($table == $this->queryObject->mainTable) {
				$possibleKeys[] = $alias . '$uid';
			}
		}
			// Loop on all possible keys and exit successfully if one matches a field mapped to "uid"
		foreach ($possibleKeys as $key) {
			if (isset($this->fieldTrueNames[$key]) && $this->fieldTrueNames[$key]['mapping']['field'] == 'uid') {
				$hasUid = TRUE;
				break;
			}
		}
		return $hasUid;
	}

	/**
	 * Returns information about each field that appears more than once in the current query
	 *
	 * @return array
	 */
	protected function checkForDuplicateFields() {
		$duplicates = array();
		$fieldCountPerTable = array();
			// Loop on all included fields and make a list of aliases per table and field
			// Note that these are the fields explicitly entered in the SELECT statement
			// plus all base fields added for the needs of dataquery
		foreach ($this->fieldTrueNames as $alias => $aliasInformation) {
			$table = $aliasInformation['aliasTable'];
			$field = $aliasInformation['field'];
			if (!isset($fieldCountPerTable[$table])) {
				$fieldCountPerTable[$table] = array();
			}
			if (!isset($fieldCountPerTable[$table][$field])) {
				$fieldCountPerTable[$table][$field] = array($alias);
			} else {
				$fieldCountPerTable[$table][$field][] = $alias;
			}
		}
			// Loop on the aliases list found in the first loop
			// List a warning for each field (in a given table) with multiple aliases
		foreach ($fieldCountPerTable as $table => $countPerField) {
			foreach ($countPerField as $field => $aliases) {
				if (count($aliases) > 1) {
					$duplicates[] = 'In table ' . $table . ', duplicates for ' . $field . ' as: ' . implode(',', $aliases);
				}
			}

		}
		return $duplicates;
	}
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/dataquery/class.tx_dataquery_parser.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/dataquery/class.tx_dataquery_parser.php']);
}

?>
