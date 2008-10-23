<?
defined('C5_EXECUTE') or die(_("Access Denied."));

// Start the session
ini_set('session.use_trans_sid',0);
session_set_cookie_params(0, DIR_REL . '/');
/* ini_set('session.save_path', DIR_SESSIONS); */
ini_set('session.gc_maxlifetime', SESSION_MAX_LIFETIME);

//if we've set the _postSID variable, we populate session_id using it
if (isset($_REQUEST['ccm-session'])) {
	session_id($_REQUEST['ccm-session']);
}

session_name(SESSION);
session_start();