<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * Authenticate using an embeded SimpleSamlPhp instance
 *
 * @package   auth_saml2
 * @copyright Brendan Heywood <brendan@catalyst-au.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir.'/authlib.php');

/**
 * Plugin for Saml2 authentication.
 *
 * @copyright  Brendan Heywood <brendan@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth_plugin_saml2_extendido extends auth_plugin_base {

    /**
     * @var $defaults The config defaults
     */
    public $defaults = array(
        'idpname'         => '',
        'entityid'        => '',
        'idpdefaultname'  => '', // Set in constructor.
        'idpmetadata'     => '',
        'debug'           => 0,
        'duallogin'       => 1,
        'anyauth'         => 1,
        'idpattr'         => 'uid',
        'mdlattr'         => 'username',
        'tolower'         => 0,
        'autocreate'      => 0,
        'spmetadatasign'  => true,
        'showidplink'     => true,
        'alterlogout'     => '',
        'entityid_pers' => '',
        'baseurl' => '',
        'unidad_academica' => ''
    );

    /**
     * Constructor.
     */
    public function __construct() {
        global $CFG;
        $this->defaults['idpdefaultname'] = get_string('idpnamedefault', 'auth_saml2_extendido');
        $this->authtype = 'saml2_extendido';
        $mdl = new moodle_url($CFG->wwwroot);
        $this->spname = $mdl->get_host();
        $this->certdir = "$CFG->dataroot/saml2/";
        $this->certpem = $this->certdir . $this->spname . '.pem';
        $this->certcrt = $this->certdir . $this->spname . '.crt';
        $this->config = (object) array_merge($this->defaults, (array) get_config('auth/saml2_extendido') );
    }

    /**
     * A debug function, dumps to the php log
     *
     * @param string $msg Log message
     */
    private function log($msg) {
        if ($this->config->debug) {
            // @codingStandardsIgnoreStart
            error_log('auth_saml2_extendido: ' . $msg);
            // @codingStandardsIgnoreEnd
        }
    }

    /**
     * Returns a list of potential IdPs that this authentication plugin supports.
     * This is used to provide links on the login page.
     *
     * @param string $wantsurl the relative url fragment the user wants to get to.
     *
     * @return array of IdP's
     */
    public function loginpage_idp_list($wantsurl) {
        // If we have disabled the visibility of the idp link, return with an empty array right away.
        if (!$this->config->showidplink) {
            return array();
        }

        // If the plugin has not been configured then do not return an IdP link.
        if ($this->is_configured() === false) {
            return array();
        }

        // The wants url may already be routed via login.php so don't re-re-route it.
        if (strpos($wantsurl, '/auth/saml2_extendido/login.php')) {
            $wantsurl = new moodle_url($wantsurl);
        } else {
            $wantsurl = new moodle_url('/auth/saml2_extendido/login.php', array('wants' => $wantsurl));
        }

        $conf = $this->config;
        return array(
            array(
                'url'  => $wantsurl,
                'icon' => new pix_icon('i/user', 'Login'),
                'name' => (!empty($conf->idpname) ? $conf->idpname : $conf->idpdefaultname),
            ),
        );
    }

    /**
     * We don't manage passwords internally.
     *
     * @return bool Always false
     */
    public function is_internal() {
        return false;
    }

    /**
     * Checks to see if the plugin has been configured and the IdP/SP metadata files exist.
     *
     * @return bool
     */
    public function is_configured() {
        $file = $this->certdir . $this->spname . '.crt';
        if (!file_exists($file)) {
            $this->log(__FUNCTION__ . ' file not found, ' . $file);
            return false;
        }

        $file = $this->certdir . $this->spname . '.pem';
        if (!file_exists($file)) {
            $this->log(__FUNCTION__ . ' file not found, ' . $file);
            return false;
        }

        $file = $this->certdir . 'idp.xml';
        if (!file_exists($file)) {
            $this->log(__FUNCTION__ . ' file not found, ' . $file);
            return false;
        }

        return true;
    }

    /**
     * Shows an error page for various authentication issues.
     *
     * @param string $msg The error message.
     */
    public function error_page($msg) {
        global $PAGE, $OUTPUT, $SITE;

        $logouturl = new moodle_url('/auth/saml2_extendido/logout.php');

        $PAGE->set_course($SITE);
        $PAGE->set_url('/');
        echo $OUTPUT->header();
        echo $OUTPUT->box($msg);
        echo html_writer::link($logouturl, get_string('logout'));
        echo $OUTPUT->footer();
        exit;
    }

    /**
     * All the checking happens before the login page in this hook
     */
    public function pre_loginpage_hook() {

        global $SESSION;

        $this->log(__FUNCTION__ . ' enter');

        // If we previously tried to force saml on, but then navigated
        // away, and come in from another deep link while dual auth is
        // on, then reset the previous session memory of forcing SAML.
        if (isset($SESSION->saml)) {
            $this->log(__FUNCTION__ . ' unset $SESSION->saml');
            unset($SESSION->saml);
        }

        $this->loginpage_hook();
        $this->log(__FUNCTION__ . ' exit');
    }

    /**
     * All the checking happens before the login page in this hook
     */
    public function loginpage_hook() {
        $this->log(__FUNCTION__ . ' enter');

        // If the plugin has not been configured then do NOT try to use saml2.
        if ($this->is_configured() === false) {
            return;
        }

        if ($this->should_login_redirect()) {
            $this->saml_login();
        } else {
            $this->log(__FUNCTION__ . ' exit');
            return;
        }

    }

    /**
     * Determines if we will redirect to the SAML login.
     *
     * @return bool If this returns true then we redirect to the SAML login.
     */
    public function should_login_redirect() {
        global $SESSION;

        $this->log(__FUNCTION__ . ' enter');

        $saml = optional_param('saml', 0, PARAM_BOOL);

        // If dual auth then stop and show login page.
        if ($this->config->duallogin == 1 && $saml == 0) {
            $this->log(__FUNCTION__ . ' skipping due to dual auth');
            return false;
        }

        // If ?saml=on even when duallogin is on, go directly to IdP.
        if ($saml == 1) {
            $this->log(__FUNCTION__ . ' redirecting due to query param ?saml=on');
            return true;
        }

        // Check whether we've skipped saml already.
        // This is here because loginpage_hook is called again during form
        // submission (all of login.php is processed) and ?saml=off is not
        // preserved forcing us to the IdP.
        //
        // This isn't needed when duallogin is on because $saml will default to 0
        // and duallogin is not part of the request.
        if ((isset($SESSION->saml) && $SESSION->saml == 0)) {
            $this->log(__FUNCTION__ . ' skipping due to no sso session');
            return false;
        }

        // If ?saml=off even when duallogin is off, then always show the login page.
        // Additionally store this in the session so if the password fails we get
        // the login page again, and don't get booted to the IdP on the second
        // attempt to login manually.
        $saml = optional_param('saml', 1, PARAM_BOOL);
        if ($saml == 0) {
            $SESSION->saml = $saml;
            $this->log(__FUNCTION__ . ' skipping due to ?saml=off');
            return false;
        }

        // We are off to SAML land so reset the force in SESSION.
        if (isset($SESSION->saml)) {
            $this->log(__FUNCTION__ . ' unset SESSION->saml');
            unset($SESSION->saml);
        }

        return true;
    }

    /**
     * All the checking happens before the login page in this hook
     */
    public function saml_login() {

        // @codingStandardsIgnoreStart
        global $CFG, $DB, $USER, $SESSION, $PAGE, $saml2auth;
        // @codingStandardsIgnoreEnd

        require_once('setup.php');
        require_once("$CFG->dirroot/login/lib.php");
        $auth = new SimpleSAML_Auth_Simple($this->spname);
        $auth->requireAuth();

        $context = context_system::instance();
        $PAGE->set_context($context);

        $attributes = $auth->getAttributes();

		//UNLP atributos del sso para verificar la ua y nro_inscripcion
        //print_r($attributes);
        $guarani_data = $attributes["guarani_data"][0];
        $guarani_data = json_decode($guarani_data);
        $ok=false;


		if ($guarani_data->codigo) {
                $guarani_uas = $guarani_data->datos;
                foreach ($guarani_uas as $ua) {
                        if ($ua->unidad_academica == $this->config->unidad_academica) {
                                $ok=true; 
                                $nro_inscripcion = $ua->nro_inscripcion;
                                break;
                        }
                }

        }
        else {
         echo $this->error_page(get_string('no_data','auth_saml2_extendido')); 
        }

        

        $attr = $this->config->idpattr;
        /* No se controla si no se configuro un atributo para matchear, ya que solo se podria matchear con el nro_inscripcion dentro de guarani_data[x]
         * if (empty($attributes[$attr]) ) {
            $this->error_page(get_string('noattribute', 'auth_saml2', $attr));
        }
        */



        $user = null;
        foreach ($attributes[$attr] as $key => $uid) {
            if ($this->config->tolower) {
                $this->log(__FUNCTION__ . " to lowercase for $key => $uid");
                $uid = strtolower($uid);
            }
            if ($user = $DB->get_record('user', array( $this->config->mdlattr => $uid, 'deleted' => 0 ))) {
                continue;
            }
        }

		/* obtener por nro_inscripcion */
        //$nro_inscripcion = 200700211; /*BORRAR! hardcodeado para probar*/
        $user = $DB->get_record('user', array( 'idnumber' => $nro_inscripcion, 'deleted' => 0 ));

        // Prevent access to users who are suspended
        if ($user->suspended) {
            $this->error_page(get_string('suspendeduser', 'auth_saml2', $uid));
        }

        $newuser = false;
        if (!$user) {
            if ($this->config->autocreate) {
                $this->log(__FUNCTION__ . " user '$uid' is not in moodle so autocreating");
                $user = create_user_record($uid, '', 'saml2');
                $newuser = true;
            } else {
                $this->log(__FUNCTION__ . " user '$uid' is not in moodle so error");
                $this->error_page(get_string('nouser', 'auth_saml2', $uid));
            }
        } else {
            // Make sure all user data is fetched.
            $user = get_complete_user_data('username', $user->username);
            $this->log(__FUNCTION__ . ' found user '.$user->username);
        }

        // Do we need to update any user fields? Unlike ldap, we can only do
        // this now. We cannot query the IdP at any time.
        $this->update_user_profile_fields($user, $attributes, $newuser);

        if (!$this->config->anyauth && $user->auth != 'saml2') {
            $this->log(__FUNCTION__ . " user $uid is auth type: $user->auth");
            $this->error_page(get_string('wrongauth', 'auth_saml2', $uid));
        }

        // Make sure all user data is fetched.
        $user = get_complete_user_data('username', $user->username);

        complete_user_login($user);
        $USER->loggedin = true;
        $USER->site = $CFG->wwwroot;
        set_moodle_cookie($USER->username);

        $urltogo = core_login_get_return_url();
        // If we are not on the page we want, then redirect to it.
        if ( qualified_me() !== $urltogo ) {
            $this->log(__FUNCTION__ . " redirecting to $urltogo");
            redirect($urltogo);
            exit;
        } else {
            $this->log(__FUNCTION__ . " continuing onto " . qualified_me() );
        }

        return;
    }

    /**
     * Checks the field map config for values that update onlogin or when a new user is created
     * and returns true when the fields have been merged into the user object.
     *
     * @param $attributes
     * @param bool $newuser
     * @return bool true on success
     */
    public function update_user_profile_fields(&$user, $attributes, $newuser = false) {
        global $CFG;

        $mapconfig = get_config('auth/saml2_extendido');
        $allkeys = array_keys(get_object_vars($mapconfig));
        $update = false;

        foreach ($allkeys as $key) {
            if (preg_match('/^field_updatelocal_(.+)$/', $key, $match)) {
                $field = $match[1];
                if (!empty($mapconfig->{'field_map_'.$field})) {
                    $attr = $mapconfig->{'field_map_'.$field};
                    $updateonlogin = $mapconfig->{'field_updatelocal_'.$field} === 'onlogin';

                    if ($newuser || $updateonlogin) {
                        // Basic error handling, check to see if the attributes exist before mapping the data.
                        if (array_key_exists($attr, $attributes)) {
                            // Handing an empty array of attributes.
                            if (!empty($attributes[$attr])) {
                                // Custom profile fields have the prefix profile_field_ and will be saved as profile field data.
                                $user->$field = $attributes[$attr][0];
                                $update = true;
                            }
                        }
                    }
                }
            }
        }

        if ($update) {
            require_once($CFG->dirroot . '/user/lib.php');
            user_update_user($user, false, false);
            // Save custom profile fields.
            profile_save_data($user);
        }

        return $update;
    }

    /**
     * Make sure we also cleanup the SAML session AND log out of the IdP
     */
    public function logoutpage_hook() {

        // This is a little tricky, there are 3 sessions we need to logout:
        //
        // 1) The moodle session.
        // 2) The SimpleSAML SP session.
        // 3) The IdP session, if the IdP supports SingleSignout.

        global $CFG, $saml2auth, $redirect;

        $this->log(__FUNCTION__ . ' Do moodle logout');

        // Do the normal moodle logout first as we may redirect away before it
        // gets called by the normal core process.
        require_logout();

        require_once('setup.php');
        $auth = new SimpleSAML_Auth_Simple($this->spname);

        // Only log out of the IdP if we logged in via the IdP. TODO check session timeouts.
        if ($auth->isAuthenticated()) {
            $this->log(__FUNCTION__ . ' Do SSP logout');
            $alterlogout = $this->config->alterlogout;
            if (!empty($alterlogout)) {
                $this->log(__FUNCTION__ . " Do SSP alternate URL logout $alterlogout");
                $redirect = $alterlogout;
            }
            $auth->logout($redirect);
        }
    }

    /**
     * Returns false regardless of the username and password as we never get
     * to the web form. If we do, some other auth plugin will handle it
     *
     * @param string $username The username
     * @param string $password The password
     * @return bool Authentication success or failure.
     */
    public function user_login ($username, $password) {
        return false;
    }

    /**
     * Prints a form for configuring this authentication plugin.
     *
     * This function is called from admin/auth.php, and outputs a full page with
     * a form for configuring this plugin.
     *
     * @param object $config
     * @param object $err
     * @param array $userfields
     */
    public function config_form($config, $err, $userfields) {
        $config = (object) array_merge($this->defaults, (array) $config );
        global $CFG, $OUTPUT;
        include($CFG->dirroot.'/auth/saml2_extendido/settings.html');
    }

    /**
     * A chance to validate form data, and last chance to
     * do stuff before it is inserted in config_plugin
     *
     * @param object $form with submitted configuration settings (without system magic quotes)
     * @param array $err array of error messages
     *
     * @return array of any errors
     */
    public function validate_form($form, &$err) {

        global $CFG, $saml2auth;
        require_once('setup.php');

        // The IdP entityID needs to be parsed out of the XML.
        // It will use the first IdP entityID it finds.
        $form->entityid = '';
        $form->idpdefaultname = $this->defaults['idpdefaultname'];
        try {
            $rawxml = $form->idpmetadata;

            // If rawxml looks like a url, then go scrape it first.
            if (substr($rawxml, 0, 8) == 'https://' ||
                substr($rawxml, 0, 7) == 'http://') {
                $rawxml = @file_get_contents($rawxml);

                if (!$rawxml) {
                    $err['idpmetadata'] = get_string('idpmetadata_badurl', 'auth_saml2');
                    return;
                }
            }

            $xml = new SimpleXMLElement($rawxml);
            $xml->registerXPathNamespace('md',   'urn:oasis:names:tc:SAML:2.0:metadata');
            $xml->registerXPathNamespace('mdui', 'urn:oasis:names:tc:SAML:metadata:ui');

            // Find all IDPSSODescriptor elements and then work back up to the entityID.
            $idps = $xml->xpath('//md:EntityDescriptor[//md:IDPSSODescriptor]');
            if ($idps && isset($idps[0])) {
                $form->entityid = (string)$idps[0]->attributes('', true)->entityID[0];

                $names = @$idps[0]->xpath('//mdui:DisplayName');
                if ($names && isset($names[0])) {
                    $form->idpdefaultname = (string)$names[0];
                }
            }

            if (empty($form->entityid)) {
                $err['idpmetadata'] = get_string('idpmetadata_noentityid', 'auth_saml2');
            } else {
                if (!file_exists($saml2auth->certdir)) {
                    mkdir($saml2auth->certdir);
                }
                file_put_contents($saml2auth->certdir . 'idp.xml' , $rawxml);
            }
        } catch (Exception $e) {
            $err['idpmetadata'] = get_string('idpmetadata_invalid', 'auth_saml2');
        }
    }

    /**
     * Processes and stores configuration data for this authentication plugin.
     *
     * @param object $config
     */
    public function process_config($config) {
        $haschanged = false;

        foreach ($this->defaults as $key => $value) {
            if ($config->$key != $this->config->$key) {
                set_config($key, $config->$key, 'auth/saml2_extendido');
                $haschanged = true;
            }
        }

        if ($haschanged) {
            $file = $this->certdir . $this->spname . '.xml';
            @unlink($file);
        }
        return true;
    }

    /**
     * A simple GUI tester which shows the raw API output
     */
    public function test_settings() {
        include('tester.php');
    }

    public function get_ssp_version() {
        global $CFG, $saml2auth;
        require_once('setup.php');
        $config = new SimpleSAML_Configuration(array(), '');
        return $config->getVersion();
    }

}

