<?php

include_once ABSPATH . 'wp-admin/includes/admin.php';
include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

/*
 This class is going to be slightly different from the "normal" one:
  * Only uses the Direct access method
  * In cases where FTP is generally required, It'll attempt Direct IF and only if, no new files need to be created (and the modified files are writable obviously)
 
 The suggested method for this, since we can't use the existing plugin upgrader mentality of delete everything and bung the new files in is
  * Extract the zip into memory
  * In the case where Direct is not used, Loop over each file to ensure it exists, if not, mark as incompatible update and remind the owner to do it manually
  * Put the site into maintainence mode, or deactivate the plugin, one or the other
  * Loop over the files, modify the files contents directly with the new content
  * Done.
*/
class Background_Plugin_Upgrader extends Plugin_Upgrader {
	function __construct() {
		parent::__construct( new Background_Headless_Skin( array( 'nonce' => '', 'title' => '', 'plugin' => '', 'url' => false ) ) );
	}
	
	function fs_connect( $directories = array() ) {
		global $wp_filesystem;

		if ( false === ($credentials = $this->skin->request_filesystem_credentials()) )
			return false;

		if ( ! WP_Filesystem($credentials) )
			return false;

		if ( ! is_object($wp_filesystem) )
			return new WP_Error('fs_unavailable', $this->strings['fs_unavailable'] );

		if ( is_wp_error($wp_filesystem->errors) && $wp_filesystem->errors->get_error_code() )
			return new WP_Error('fs_error', $this->strings['fs_error'], $wp_filesystem->errors);

		foreach ( (array)$directories as $dir ) {
			switch ( $dir ) {
				case ABSPATH:
					if ( ! $wp_filesystem->abspath() )
						return new WP_Error('fs_no_root_dir', $this->strings['fs_no_root_dir']);
					break;
				case WP_CONTENT_DIR:
					if ( ! $wp_filesystem->wp_content_dir() )
						return new WP_Error('fs_no_content_dir', $this->strings['fs_no_content_dir']);
					break;
				case WP_PLUGIN_DIR:
					if ( ! $wp_filesystem->wp_plugins_dir() )
						return new WP_Error('fs_no_plugins_dir', $this->strings['fs_no_plugins_dir']);
					break;
				case WP_CONTENT_DIR . '/themes':
					if ( ! $wp_filesystem->find_folder(WP_CONTENT_DIR . '/themes') )
						return new WP_Error('fs_no_themes_dir', $this->strings['fs_no_themes_dir']);
					break;
				default:
					if ( ! $wp_filesystem->find_folder($dir) )
						return new WP_Error('fs_no_folder', sprintf($this->strings['fs_no_folder'], $dir));
					break;
			}
		}
		return true;
	} //end fs_connect();
	
	function upgrade($plugin) {
		global $wp_filesystem;
		$this->plugin = $plugin;

		$this->init();
		$this->upgrade_strings();

		$current = get_site_transient( 'update_plugins' );
		if ( !isset( $current->response[ $plugin ] ) )
			return false;

		// Get the URL to the zip file
		$r = $current->response[ $plugin ];

		//Download the package (Note, This just returns the filename of the file if the package is a local file)
		$download = $this->download_package( $r->package );
		if ( is_wp_error($download) ) {
			$this->skin->error($download);
			$this->skin->footer();
			return $download;
		}

		$zip = new ZipArchive();

		if ( true !== ($zip->open($download, ZIPARCHIVE::CHECKCONS)) ) {
			$error = new WP_Error('incompatible_archive', __('Incompatible Archive.'));
			$this->skin->error($error);
			$this->skin->footer(); 
			return $error;
		}

		$files = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			if ( ! $info = $zip->statIndex($i) ) {
				$error = new WP_Error('stat_failed', __('Could not retrieve file from archive.'));
				$this->skin->error($error);
				$this->skin->footer(); 
				return $error;
			}

			if ( '/' === substr($info['name'], -1) ) // No need to know about directories
				continue;

			$files[ $i ] = $info['name'];
		}

		asort($files);

		//rebase
		$first_item = reset($files);
		if ( '.' != dirname($first_item) )
			$first_item = dirname($first_item);
		foreach ( $files as $index => $file )
			$files[$index] = preg_replace('!^' . preg_quote($first_item, '!') . '/?!i', '', $file);
		$files = array_filter($files);

		$local_destination = trailingslashit( dirname( trailingslashit(WP_PLUGIN_DIR) . $plugin) );

		if ( 'direct' != get_filesystem_method( $local_destination ) ) {
			// Make sure all the files exist and are writable
			foreach ( $files as $file ) {
				if ( ! file_exists( $local_destination . $file ) || ! is_writable( $local_destination . $file ) ) {
					// Switch to FTP
					if ( defined('FTP_HOST') && defined('FTP_USER') && defined('FTP_PASS') ) {
						// Host is configured to automatically login?
						$this->skin->feedback('Updating plugin via FTP with pre-loaded credentials.'); //todo
						$use_ftp_anyway = true;
						break;
					} else {
						//Failure case
						$error = new WP_Error('must_do_manually', 'Plugin XYZ must be upgraded manually.', $file);
						$this->skin->error($error);
						$this->skin->footer(); 
						return $error;
					}
				}
			}

			// Forcing direct
			if ( empty($use_ftp_anyway) && ! defined('FS_METHOD') )
				define('FS_METHOD', 'direct'); // @TODO Use filter instead.
		}

		$this->skin->feedback("Using '" . get_filesystem_method( $local_destination ) . "' filesystem transport.");

		$this->fs_connect( array( $local_destination ) );

		$wp_filesystem->verbose = true;

		$remote_destination = $wp_filesystem->find_folder( $local_destination );
		if ( ! $remote_destination ) {
			$error = new WP_Error('fs_error', 'Could not find folder (%s).', $local_destination);
			$this->skin->error($error, $local_destination);
			$this->skin->footer(); 
			return $error;
		}

		$this->skin->feedback(" Local: $local_destination; Remote: $remote_destination; Remote Raw: " . $wp_filesystem->wp_plugins_dir() );

		// Core: Maintainence; Plugin: deactivate
		//if context - plugin
		deactivate_plugins( $plugin );
		//else core
		//$this->maintenance_mode(true);
		
		foreach ( $files as $index => $file ) {
			$contents = $zip->getFromIndex($index);
			if ( false === $contents || ! $wp_filesystem->put_contents( $remote_destination . $file, $contents, FS_CHMOD_FILE) ) {
				$error = new WP_Error('copy_failed', __('Could not copy file (%s).'), $remote_destination . $file );
				$this->skin->error($error, $remote_destination . $file);
				$this->skin->footer(); 
				return $error;
			}
		}

		// Core: Maintainence; Plugin: activate/scrape
		$result = activate_plugin($plugin, false);
		if ( is_wp_error($result) ) {
			$this->skin->error($error);
			$this->skin->footer(); 
			return $error;
		}
		//$this->maintenance_mode(false);
		
		$this->skin->feedback('process_success');
		
		$this->skin->footer(); //email
		$zip->close();
		unlink($download);

		// Force refresh of plugin update information
		delete_site_transient('update_plugins');
		
		return true;
	}

}

class Background_Headless_Skin extends WP_Upgrader_Skin {
	var $text;
	function __construct($args) {
		parent::__construct($args);
	}

	function error($string) {
		if ( is_wp_error($string) ) {
			$string = $string->get_error_message();
		}
		if ( isset( $this->upgrader->strings[$string] ) )
			$string = $this->upgrader->strings[$string];
	
		if ( strpos($string, '%') !== false ) {
			$args = func_get_args();
			$args = array_splice($args, 1);
			if ( !empty($args) )
				$string = vsprintf($string, $args);
		}
		$this->text .= "Error: $string\n"; 
	}
	function feedback($string) {
		if ( isset( $this->upgrader->strings[$string] ) )
			$string = $this->upgrader->strings[$string];

		if ( strpos($string, '%') !== false ) {
			$args = func_get_args();
			$args = array_splice($args, 1);
			if ( !empty($args) )
				$string = vsprintf($string, $args);
		}
		$this->text .= "$string\n";
	}
	function head(){
		ob_start();
	}
	function footer() {
		wp_mail( get_option('admin_email'), $this->upgrader->plugin . ': Plugin Update complete!', $this->text . "\n\n\n" . ob_get_contents() );
	}
}