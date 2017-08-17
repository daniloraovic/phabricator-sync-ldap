#!/usr/bin/php
<?php
assert_options(ASSERT_BAIL, 1);
define("ROOT", dirname(__FILE__));
define("BASENAME", basename(__FILE__, ".php"));

// LOAD PHABRICATOR

define("PHABRICATOR_ROOT", ROOT . "/../phabricator");
require_once PHABRICATOR_ROOT  . "/scripts/__init_script__.php";

// LOAD UTILITY FUNCTIONS

require_once ROOT . "/" . BASENAME . ".inc.php";

// LOAD CONFIG

require_once ROOT . "/" . BASENAME . ".cfg";

assert(LDAP_BASE !== null);
assert(LDAP_USER_SUBTREE !== null);
assert(LDAP_GROUP_SUBTREE !== null);
assert(LDAP_PROJECT_SUBTREE !== null);

assert(LDAP_USER_FILTER !== null);
assert(LDAP_USER_NAME_ATTR !== null);
assert(LDAP_USER_MAIL_ATTR !== null);
assert(LDAP_USER_GROUP_ATTR !== null);
assert(LDAP_USER_GROUP_TRGT !== null);

assert(LDAP_GROUP_FILTER !== null);
assert(LDAP_GROUP_NAME_ATTR !== null);
assert(LDAP_GROUP_MEMBER_ATTR !== null);
assert(LDAP_GROUP_MEMBER_TRGT !== null);

assert(LDAP_PROJECT_FILTER !== null);
assert(LDAP_PROJECT_NAME_ATTR !== null);
assert(LDAP_PROJECT_MEMBER_ATTR !== null);
assert(LDAP_PROJECT_MEMBER_TRGT !== null);

assert(PHAB_ADMIN_USERNAME !== null);
assert(PHAB_PROJECT_LDAP_DN_FIELD !== null);
assert(PHAB_USER_LDAP_DN_FIELD !== null);
assert(PHAB_USER_ACCOUNT_TYPE !== null);

// With PHP7 this variable can be changed to a constant
assert($PHAB_PROTECTED_USERS !== null);

// LOAD CONNECTION INFO FROM ENVIRONMENT

$ldap_uri = getenv('LDAP_URI');
assert($ldap_uri !== false);
$ldap_binddn = getenv('LDAP_BINDDN');
$ldap_bindpw = getenv('LDAP_BINDPW');
assert(($ldap_binddn === false && $ldap_bindpw !== false) || ($ldap_binddn !== false && $ldap_bindpw !== false));

if (DRY_RUN) {
	debug(">>> DRY RUN <<<\n");
}

// LDAP

if ($ldap_binddn === false && $ldap_bindpw === false) {
	debug("Attempting anonymous bind ...\n");
	$ldap_binddn = NULL;
	$ldap_bindpw = NULL;
}

$ld = array(
	"connection" => create_ldap_connection($ldap_uri, $ldap_binddn, $ldap_bindpw),
	"base_dn" => LDAP_BASE,
	"user" => array(
		"search_base" => LDAP_BASE ? LDAP_USER_SUBTREE . ',' . LDAP_BASE : LDAP_USER_SUBTREE,
		"filter" => LDAP_USER_FILTER,
		"name_attr" => LDAP_USER_NAME_ATTR,
	),
	"group" => array(
		"search_base" => LDAP_BASE ? LDAP_GROUP_SUBTREE . ',' . LDAP_BASE : LDAP_GROUP_SUBTREE,
		"filter" => LDAP_GROUP_FILTER,
		"name_attr" => LDAP_GROUP_NAME_ATTR,
		"member_attr" => LDAP_GROUP_MEMBER_ATTR,
	),
	"project" => array(
		"search_base" => LDAP_BASE ? LDAP_PROJECT_SUBTREE . ',' . LDAP_BASE : LDAP_PROJECT_SUBTREE,
		"filter" => LDAP_PROJECT_FILTER,
		"name_attr" => LDAP_PROJECT_NAME_ATTR,
	),
);

$ldap_users = query_ldap($ld["connection"], $ld["user"]["search_base"], $ld["user"]["filter"]);
$ldap_users = associate_ldap_list_with_dn($ldap_users);

$ldap_groups = query_ldap($ld["connection"], $ld["group"]["search_base"], $ld["group"]["filter"]);
$ldap_groups = associate_ldap_list_with_dn($ldap_groups);

$ldap_projects = query_ldap($ld["connection"], $ld["project"]["search_base"], $ld["project"]["filter"]);
$ldap_projects = associate_ldap_list_with_dn($ldap_projects);

// PHABRICATOR

$phab_admin = id(new PhabricatorPeopleQuery())
	->setViewer(PhabricatorUser::getOmnipotentUser())
	->withUsernames(array(PHAB_ADMIN_USERNAME))
	->executeOne();
assert($phab_admin !== null);

$phab = array(
	"admin" => $phab_admin,
	"project_ldap_dn_field" => PHAB_PROJECT_LDAP_DN_FIELD,
	"user_ldap_dn_field" => PHAB_USER_LDAP_DN_FIELD,
	"user_account_type" => PHAB_USER_ACCOUNT_TYPE,
	// With PHP7 this variable can be changed to a constant
	"protected_users" => $PHAB_PROTECTED_USERS,
);

$phab_projects = id(new PhabricatorProjectQuery())
		->setViewer(PhabricatorUser::getOmnipotentUser())
		->needMembers(true)
		->execute();
$phab_projects = mpull($phab_projects, null, "getPHID");

$phab_accounts = id(new PhabricatorExternalAccountQuery())
		->setViewer(PhabricatorUser::getOmnipotentUser())
		->withAccountTypes(array(PHAB_USER_ACCOUNT_TYPE))
		->execute();
// FIXME: Useful at least for creating debug output! Should be provided to the update_* functions!
$phab_user_account_map = mpull($phab_accounts, null, "getuserPHID");

$user_map = map_users($phab_accounts, $ld);
$project_map = map_projects($phab_projects, $phab);

update_phab_users($user_map, $ldap_users, $ld, $phab);

update_phab_projects($project_map, $ldap_groups, $ld, $phab, $ldap_projects, $phab_projects);

$phab_projects = id(new PhabricatorProjectQuery())
		->setViewer(PhabricatorUser::getOmnipotentUser())
		->needMembers(true)
		->execute();
$phab_projects = mpull($phab_projects, null, "getPHID");

// Recreate user/project maps and ignore those missing in LDAP
$user_map = map_users($phab_accounts, $ld, false);
$project_map = map_projects($phab_projects, $phab);

update_phab_project_members($project_map, $user_map, $phab_projects, $ldap_groups, $ld, $phab);
