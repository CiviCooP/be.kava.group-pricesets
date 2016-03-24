<?php
// Require CIVIX file
require_once 'pricesets.civix.php';

/**
 * Extend build form hook in order to create our group restriction elements
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_buildForm

	function pricesets_civicrm_buildForm($formName, &$form) {
		// If the form equals Price Form Field then we're taking action
		if ($formName == "CRM_Price_Form_Field") {
			// Add form element (select) with all groups
			$form->add("select", "option_group_restriction", "option_group_restriction", pricesets_fetch_all_groups());
			$element = $form->getElement('option_group_restriction');
			$element->setMultiple(true);
		}
	}
	
 */

/**
 * Temporarily fix for the html element
 *
 * Returns string with select box filled with groups
 */
function pricesets_build_new_selectbox($index) {
	// Construct return string 
	$_returnHtml = '<select id="option_group_restriction_'.$index.'" name="option_group_restriction['.$index.'][]" multiple>';
	// Fetch all groups
	$_allGroups = pricesets_fetch_all_groups();
	// Define each group as option
	foreach($_allGroups as $_groupIdentifier => $_group) {
		if($_groupIdentifier == 0) { 
			$_returnHtml .= '<option value="'.$_groupIdentifier.'" selected="selected">'.$_group.'</option>';
		} else {
			$_returnHtml .= '<option value="'.$_groupIdentifier.'">'.$_group.'</option>';
		}
	}
	// Close the select tag
	$_returnHtml .= '</select>';
	// Return html string
	return $_returnHtml;
}

/**
 * Temporarily fix for the html element
 *
 * Returns string with select box filled with connected groups
 */
function pricesets_build_existing_selectbox($priceFieldValueIdentifier) {
	// Construct return string 
	$_returnHtml = '<select id="option_group_restriction" name="option_group_restriction[]" multiple>';
	// Fetch all groups
	$_allGroups = pricesets_fetch_all_groups();
	// Fetch all connected groups
	$_connectedGroups = pricesets_civicrm_fetchPriceFieldGroupCombination($priceFieldValueIdentifier);
	// Check if array is valid
	if(!is_array($_connectedGroups)) $_connectedGroups = array();
	// Define each group as option
	foreach($_allGroups as $_groupIdentifier => $_group) {
		if(in_array($_groupIdentifier, $_connectedGroups) || (count($_connectedGroups) == 0 && $_groupIdentifier == 0)) { 
			$_returnHtml .= '<option value="'.$_groupIdentifier.'" selected="selected">'.$_group.'</option>';
		} else {
			$_returnHtml .= '<option value="'.$_groupIdentifier.'">'.$_group.'</option>';
		}
	}
	// Close the select tag
	$_returnHtml .= '</select>';
	// Return html string
	return $_returnHtml;
} 
 
/**
 * Fetches all groups 
 *
 * @returns an associative array with id => group
 */
function pricesets_fetch_all_groups() {
	// Define return variable
	$groups = array(0 => "-- no restriction --");
	// Fetch all groups
	$result = civicrm_api3("group","get",array("options" => array("sort" => "title", "limit" => 0)));
	// Loop through all groups and put them in return variable
	foreach ($result['values'] as $group) {
		$groups[$group['id']] = $group['title'];
	}
	// Return all the groups
	return $groups;
}

/**
 * This method handles the post values for the given restriction groups
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postProcess
 */
function pricesets_civicrm_postProcess($formName, &$form) {
	// Form for new price field options
	if ($formName == "CRM_Price_Form_Field") {
		// Fetch parent price set
		$_priceSet = civicrm_api3("PriceField", "getsingle", array("label" => $_POST['label'], "price_set_id" => $_POST['sid']));		
		// Loop through all the fields
		foreach($_POST['option_group_restriction'] as $_index => $_groups) {
			// Check if the label ain't empty
			if(!empty($_POST['option_label'][$_index])) {
				// Fetch the selected price field value identifier
				$_priceFieldValue = civicrm_api3("PriceFieldValue", "getsingle", array("price_field_id" => $_priceSet['id'], "label" => $_POST['option_label'][$_index]));	
				// Loop through all the selected groups
				foreach($_groups as $_group) {
					// If the group doesn't equal zero, then register the group
					if($_group != "0") pricesets_connect_group_pricefieldvalue($_group, $_priceFieldValue['id']);
				}
			}
		}
	}
	// If it's an existing option we're updating
	if ($formName == "CRM_Price_Form_Option") {

		// Delete all existing connections
		pricesets_delete_pricefieldvalue_connections($_POST['optionId']);

		// Connect all selected groups with the current price field value
		foreach($_POST['option_group_restriction'] as $_group) {

			if($_group == 0) {
				continue; // Fix constraint error -KL
			}
			pricesets_connect_group_pricefieldvalue($_group, $_POST['optionId']);
		}
	}
}

/**
 * This method connects the price field value with the selected groups
 *
 * Returns void
 */
function pricesets_connect_group_pricefieldvalue($groupIdentifier, $priceFieldValueIdentifier) {
	CRM_Core_DAO::executeQuery("INSERT INTO `civicrm_group_pricefieldvalue` SET `civicrm_group_id` = ".$groupIdentifier.", `civicrm_price_field_value_id` = ".$priceFieldValueIdentifier);
}

/**
 * This method deletes all existing connections between a price field value identifier and groups
 *
 * Returns void
 */
function pricesets_delete_pricefieldvalue_connections($priceFieldValueIdentifier) {
	if(!empty($priceFieldValueIdentifier)) CRM_Core_DAO::executeQuery("DELETE FROM `civicrm_group_pricefieldvalue` WHERE `civicrm_price_field_value_id` = ".$priceFieldValueIdentifier);
}

/**
 * Implementation of hook_civicrm_buildAmount
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_buildAmount
 */
function pricesets_civicrm_buildAmount($pageType, &$form, &$amount) {

	// Define admin variable (was hardcoded as group 1, now checking for administer CiviCRM -KL)
	$_isAdmin 			= CRM_Core_Permission::check('administer CiviCRM');
	// Get global session
	$_session			= &CRM_Core_Session::singleton();
	// Fetch user identifier
	$_userIdentifier	= $_session->get('userID');
	// Fetch all groups this user is part of
	$_userGroups 		= pricesets_civicrm_fetch_user_groups($_userIdentifier);
	// Save cheapest option to mark the cheapest option as default (-KL)
	$_cheapestOption   = 1000000;

	// Loop through every price set
	foreach ($amount as $_amountIndex => &$_priceSetSettings) {
		foreach($_priceSetSettings['options'] as $_priceSetIndex => &$_priceSet) {
			// Check for matching groups for the given priceSet
			$_matchingGroups = pricesets_civicrm_fetchPriceFieldGroupCombination($_priceSet['id']);
			// Check if we do have matching groups for the given price set field
			if(isset($_matchingGroups) && !empty($_matchingGroups)) {
				// Check if the current user is NOT allowed to register with this price set field
				if(!array_intersect($_userGroups, $_matchingGroups)) {
					// Check if the user is an administrator
					if($_isAdmin) {
						// Alter label of the given price set
						$_priceSet['label'] = $_priceSet['label'] . " <i>(visible because of admin status)</i>";
					} else {
						// Remove price set from original array
						unset($_priceSetSettings['options'][$_priceSetIndex]);
						continue;
					}
				}
			}

			if($_priceSet['amount'] < $_cheapestOption) {
				$_cheapestOption = $_priceSet['amount'];
			}
		}
		// Check if we do have any remaining price-sets left
		if(!count($_priceSetSettings['options'])) {

			// We don't have any options left in the price-set, delete it
			unset($amount[$_amountIndex]);

			// Quick hack to disable the option to register completely if there is no price available
			// People should never see this page - the event shouldn't be shown in the views if they can't register
			/** @var $form CRM_Event_Form_Registration_Register */

			// New version: set (Drupal, if possible) error message and redirect to event info page
			$event = $form->get_template_vars('event');
			if(!empty($event) && !empty($event['id'])) {

				if (CRM_Core_Config::singleton()->userSystem->is_drupal) {
					drupal_set_message('U hebt geen toegang tot de inschrijving voor dit evenement.', 'error');
					CRM_Utils_System::redirect('/evenementen/info/' . $event['id']);
				} else {
					CRM_Core_Session::setStatus('Dit evenement is beperkt toegankelijk.', '', 'error');
					CRM_Utils_System::redirect('/civicrm/event/info/' . $event['id']);
				}
			}

			// Old code to empty form fields
			$form->assign(array(
				'event' => array(),
				'priceSet' => array(),
				'customPre' => array(),
				'customPost' => array(),
				'noCalcValueDisplay' => true,
			));
		}

		// Mark the cheapest option as default - we could of course hide / disable other options entirely
		foreach($_priceSetSettings['options'] as $_priceSetIndex => &$_priceSet) {
				$_priceSet['is_default'] = ($_priceSet['amount'] == $_cheapestOption) ? 1 : 0;
		}
	}
}

/**
 * This method searches for matching groups on via price field id
 *
 * Returns an array with matching groups
 */
function pricesets_civicrm_fetchPriceFieldGroupCombination($priceFieldValueIdentifier) {
	// Define return variable
	$_matchingGroupsArray = array();
	// Check if the given priceFieldValueIdentifier isn't empty or not set
	if(!empty($priceFieldValueIdentifier) && isset($priceFieldValueIdentifier)) {
		// Try to fetch all the matching groups via the priceFieldValueIdentifier variable
		$_matchingGroups = CRM_Core_DAO::executeQuery("SELECT `civicrm_group_id` FROM `civicrm_group_pricefieldvalue` WHERE `civicrm_price_field_value_id` = ".$priceFieldValueIdentifier);
		// Check if we do have any matches
		if($_matchingGroups) {
			// Loop through all the matches
			while($_matchingGroups->fetch()) {
				// Put the given match in the return array
				$_matchingGroupsArray[] = $_matchingGroups->civicrm_group_id;
			}
			// Return the array since it's filled
			return $_matchingGroupsArray;
		} else {
			// We didn't find any matching groups, return false
			return false;
		}
	} else {
		// It wasn't set or it was empty so we're returning a false
		return false;
	}
}

/**
 * This method fetches all groups where the given user is part of
 *
 * Returns an array with all the groups
 */
function pricesets_civicrm_fetch_user_groups($userIdentifier) {

	// If user identifier is null, return no groups (otherwise all groups will be returned -KL)
	if($userIdentifier == null)
		return array();

	// Define return and temporarily arrays
	$_userGroups 		= array();
	$_regularGroups 	= array();
	$_smartGroups 		= array();
	// Fetch all regular groups
	$_fetchRegularGroups = civicrm_api3("GroupContact", "get", array("contact_id" => $userIdentifier));
	if (!$_fetchRegularGroups['is_error'] && !empty($_fetchRegularGroups['values'])) {
		foreach($_fetchRegularGroups['values'] as $_group) {
			// Push every group to the user groups array
			$_regularGroups[$_group['group_id']] = $_group['group_id'];
		}
	}
	// Fetch all smart groups
	$_fetchSmartGroups = civicrm_api3("Group","get", array("options" => array("sort" => "title", "limit" => 0)));
	// Check if the groups aint empty
	if(empty($_fetchSmartGroups['is_error']) && !empty($_fetchSmartGroups['values'])) {
		// Loop through all the results
		foreach($_fetchSmartGroups['values'] as $_smartGroup) {
			// Check if current group is a smart group
			if(array_key_exists('saved_search_id', $_smartGroup) && $_smartGroup['saved_search_id'] > 0) {
				// Attempt to fetch contact with smart group id and user identifier
				$_attemptToFetchContact = civicrm_api3("Contact","get",array("contact_id" => $userIdentifier, "group" => $_smartGroup['id']));
				// Check if we did find the contact
				if(empty($_attemptToFetchContact['is_error']) && !empty($_attemptToFetchContact['values'])) {
					$_smartGroups[$_smartGroup['id']] = $_smartGroup['id'];
				}
			}
		}		
	}
	// Merge the two arrays
	$_userGroups = $_regularGroups + $_smartGroups;
	// Return the user groups
	return $_userGroups;
} 
 
/*** SYSTEM METHODS ***/

/**
 * Implementation of hook_civicrm_config
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function pricesets_civicrm_config(&$config) {
	_pricesets_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function pricesets_civicrm_xmlMenu(&$files) {
	_pricesets_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function pricesets_civicrm_install() {
	CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS `civicrm_group_pricefieldvalue` (
		`id` int(11) NOT NULL AUTO_INCREMENT,
		`civicrm_group_id` int(10) unsigned NOT NULL COMMENT 'group id',
		`civicrm_price_field_value_id` int(10) unsigned NOT NULL COMMENT 'price field value id',
		PRIMARY KEY (`id`),
		KEY `civicrm_group_id` (`civicrm_group_id`,`civicrm_price_field_value_id`),
		KEY `civicrm_price_field_value_id` (`civicrm_price_field_value_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8");
	CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_group_pricefieldvalue` ADD FOREIGN KEY (`civicrm_group_id`) REFERENCES `civicrm_group`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
		ADD FOREIGN KEY (`civicrm_price_field_value_id`) REFERENCES `civicrm_price_field_value`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT");
	return _pricesets_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function pricesets_civicrm_uninstall() {
	CRM_Core_DAO::executeQuery("DROP TABLE `civicrm_group_pricefieldvalue`");
	return _pricesets_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function pricesets_civicrm_enable() {
	return _pricesets_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function pricesets_civicrm_disable() {
	return _pricesets_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function pricesets_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
	return _pricesets_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function pricesets_civicrm_managed(&$entities) {
	return _pricesets_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_caseTypes
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function pricesets_civicrm_caseTypes(&$caseTypes) {
	_pricesets_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implementation of hook_civicrm_alterSettingsFolders
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function pricesets_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
	_pricesets_civix_civicrm_alterSettingsFolders($metaDataFolders);
}
