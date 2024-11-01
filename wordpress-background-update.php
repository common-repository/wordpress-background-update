<?php
/*
 Plugin Name: WordPress Background Updates
 Plugin URI: 
 Description: Adds background updating to WordPress
 Author: Dion Hulse
 Version: 0.5
 Author URI: http://dd32.id.au/
 */

$GLOBALS['wp_background_update'] = new WP_Background_Update();
class WP_Background_Update {
	var $maximum_version = '3.2.9'; // The most recent version of WordPress that this plugin can upgrade to.

	function __construct() {
		add_action( 'set_site_transient__site_transient_update_plugins', array(&$this, 'check_plugin_update') );
		add_action( 'set_site_transient__site_transient_update_core', array(&$this, 'check_core_update') );

		add_action( 'wp_background_update-update_self_plugin', array(&$this, 'update_self_plugin') );
		add_action( 'wp_background_update-update_core', array(&$this, 'update_core') );

		//add_action( 'init',  array(&$this, 'check_plugin_update') );
		//add_action( 'admin_init',  array(&$this, 'update_self_plugin') );
	}
	function check_core_update() {
		$updates = get_site_transient('update_core');
		if ( empty($updates->updates) )
			return false;

		// If a plugin update needs to happen first:
		if ( $this->check_plugin_update() )
			return true;

		// If a cron is already scheduled to run shortly
		if ( wp_next_scheduled( 'wp_background_update-update_core' ) )
			return false;

		// If we're not on a cron run, schedule a cronjob for this
		if ( ! defined('DOING_CRON') || ! DOING_CRON )
			wp_schedule_single_event( time(), 'wp_background_update-update_core' );
		else
			$this->update_core();
	}

	function check_plugin_update() {
		$updates = get_site_transient('update_plugins');

		// If there are no updated available, no point going any further huh?
		if ( ! isset($updates->response[ plugin_basename(__FILE__) ]) )
			return false;

		// An update is available!

		// If a cron is already scheduled to run shortly
		if ( wp_next_scheduled( 'wp_background_update-update_self_plugin' ) )
			return true;

		// If we're not on a cron run, schedule a cronjob for this
		if ( ! defined('DOING_CRON') || ! DOING_CRON )
			wp_schedule_single_event( time(), 'wp_background_update-update_self_plugin' );
		else
			$this->update_self_plugin();

		return true;
	}
	
	function update_core() {
		global $wp_version;
		$updates = get_site_transient('update_core');
		
		if ( empty($updates->updates) )
			return false;

		$current = $updates->updates[0];

		if ( 'upgrade' != $current->response )
			return;

		if ( $current->packages->partial && 'reinstall' != $current->response && $wp_version == $current->partial_version )
			$to_download = 'partial';
		elseif ( $current->packages->new_bundled && version_compare( $wp_version, $current->new_bundled, '<' )
			&& ( ! defined( 'CORE_UPGRADE_SKIP_NEW_BUNDLED' ) || ! CORE_UPGRADE_SKIP_NEW_BUNDLED ) )
			$to_download = 'new_bundled';
		elseif ( $current->packages->no_content )
			$to_download = 'no_content';
		else
			$to_download = 'full';

		wp_mail( get_option('admin_email'), 'WordPress needs updating!', 'You heard me, WordPress Needs an update! Go do it!' . var_export( array($current->packages->$to_download, $current), true) );

		return;
		
		$download = $this->download_package( $current->packages->$to_download );

	}
	
	function update_self_plugin() {
		$updates = get_site_transient('update_plugins');
		if ( empty( $updates->response[ plugin_basename(__FILE__) ] ) )
			return;

		include_once dirname(__FILE__) . '/class-background_plugin_upgrader.php';

		if ( $this->background_plugin_upgrader_usable() ) {
			$update = new Background_Plugin_Upgrader();
			$result = $update->upgrade( plugin_basename(__FILE__) );
		} elseif ( $this->standard_update_usable( dirname(__FILE__) ) )  {
			// Use the alternate method of using the builtin upgrader.
			// Pass Headless_Skin to Plugin_Upgrader.
			$skin = new Background_Headless_Skin( array( 'nonce' => '', 'title' => '', 'plugin' => '', 'url' => false ) );
			$update = new Plugin_Upgrader( $skin );
			$result = $update->upgrade( plugin_basename(__FILE__) );

			if ( ! is_wp_error($result) )
				$result = activate_plugin( plugin_basename(__FILE__), false);
		} else {
			$result = new WP_Error('cant_do_it', "Can't upgrade plugin due to incompatibility");
		}
		wp_mail( get_option('admin_email'), 'Update Result for plugin', var_export($result, true) );

		delete_site_transient('update_plugins'); // Plugin updates need re-checking
		delete_site_transient('update_core'); // so does core, In the event a plugin update had to happen before the core update
	}
	
	function background_plugin_upgrader_usable( $plugin = false ) {
		// false = not usable.  true = usable, or possibly usable.
		if ( ! $plugin )
			$plugin = plugin_basename( __FILE__ );

		if ( $this->standard_update_usable( WP_PLUGIN_DIR . '/' . $plugin ) )
			return true;

		if ( ! class_exists('ZipArchive') ) // Background plugin updater requires it.
			return false;

		if ( is_writable( WP_PLUGIN_DIR . '/' . $plugin ) )
			return true;

		return false;
	}
	function standard_update_usable( $context ) {
		if ( 'direct' == get_filesystem_method( $context ) )
			return true;

		if ( defined('FTP_HOST') && defined('FTP_USER') && defined('FTP_PASS') )
			return true;

		return false;
	}
}
