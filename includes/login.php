<?php
/**
 * Are we on the login page?
 * Checks for WP default, TML, and DmRFID login page.
 */
function dmrfid_is_login_page() {
	return ( in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ) ) || is_page( 'login' ) || ( dmrfid_getOption( 'login_page_id' ) && is_page( dmrfid_getOption( 'login_page_id' ) ) ) );
}

/**
 * If no redirect_to is set
 * then redirect members to the account page
 * and redirect non-members to the levels page.
 */
function dmrfid_login_redirect( $redirect_to, $request = NULL, $user = NULL ) {
	global $wpdb;

	$is_logged_in = ! empty( $user ) && ! empty( $user->ID );

	if ( $is_logged_in && empty( $redirect_to ) ) {
		// Can't use the dmrfid_hasMembershipLevel function because it won't be defined yet.
		$is_member = $wpdb->get_var( "SELECT membership_id FROM $wpdb->dmrfid_memberships_users WHERE status = 'active' AND user_id = '" . esc_sql( $user->ID ) . "' LIMIT 1" );
		if ( $is_member ) {
			$redirect_to = dmrfid_url( 'account' );
		} else {
			$redirect_to = dmrfid_url( 'levels' );
		}
	}

	// Custom redirect filters should use the core WordPress login_redirect filter instead of this one.
	// This filter is left in place for DmRFID versions dating back to 2014.
	return apply_filters( 'dmrfid_login_redirect_url', $redirect_to, $request, $user );
}
add_filter( 'login_redirect','dmrfid_login_redirect', 10, 3 );	

/**
 * Where is the sign up page? Levels page or default multisite page.
 */
function dmrfid_wp_signup_location( $location ) {
	if ( is_multisite() && dmrfid_getOption("redirecttosubscription") ) {
		$location = dmrfid_url("levels");
	}

	return apply_filters( 'dmrfid_wp_signup_location', $location );
}
add_filter('wp_signup_location', 'dmrfid_wp_signup_location');

/**
 * Redirect from default login pages to DmRFID.
 */
function dmrfid_login_head() {
	global $pagenow;

	$login_redirect = apply_filters("dmrfid_login_redirect", true);

	if ( ( dmrfid_is_login_page() || is_page("login") ) && $login_redirect ) {
		//redirect registration page to levels page
		if ( isset ($_REQUEST['action'] ) && $_REQUEST['action'] == "register" ||
			isset($_REQUEST['registration']) && $_REQUEST['registration'] == "disabled" ) {

				// don't redirect if in admin.
				if ( is_admin() ) {
					return;
				}

				//redirect to levels page unless filter is set.
				$link = apply_filters("dmrfid_register_redirect", dmrfid_url( 'levels' ));
				if(!empty($link)) {
					wp_redirect($link);
					exit;
				}

			} else {
				return; //don't redirect if dmrfid_register_redirect filter returns false or a blank URL
			}
	 	}
}
add_action('wp', 'dmrfid_login_head');
add_action('login_init', 'dmrfid_login_head');

/**
 * If a redirect_to value is passed into /login/ and you are logged in already, just redirect there
 *
 * @since 1.7.14
 */
function dmrfid_redirect_to_logged_in() {
	if((dmrfid_is_login_page() || is_page("login")) && !empty($_REQUEST['redirect_to']) && is_user_logged_in() && (empty($_REQUEST['action']) || $_REQUEST['action'] == 'login') && empty($_REQUEST['reauth'])) {
		wp_safe_redirect($_REQUEST['redirect_to']);
		exit;
	}
}
add_action("template_redirect", "dmrfid_redirect_to_logged_in", 5);
add_action("login_init", "dmrfid_redirect_to_logged_in", 5);

/**
 * Redirect to the login page for member login.
 * This filter is added on wp_loaded in the dmrfid_wp_loaded_login_setup() function.
 *
 * @since 2.3
 */
function dmrfid_login_url_filter( $login_url='', $redirect='' ) {
	// Don't filter when specifically on wp-login.php.
	if ( $_SERVER['SCRIPT_NAME'] === '/wp-login.php' ) {
		return $login_url;
	}
	
	// Check for a DmRFID Login page.
	$login_page_id = dmrfid_getOption( 'login_page_id' );
	if ( ! empty ( $login_page_id ) ) {
		$login_url = get_permalink( $login_page_id );
		
		if ( ! empty( $redirect ) ) {
			$login_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $login_url ) ;
		}
	}

	return $login_url;
}

/**
 * Add the filter for login_url after WordPress is loaded.
 * This avoids errors with certain setups that may call wp_login_url() very early.
 *
 * @since 2.4
 *
 */
function dmrfid_wp_loaded_login_setup() {
	add_filter( 'login_url', 'dmrfid_login_url_filter', 50, 2 );	
}
add_action( 'wp_loaded', 'dmrfid_wp_loaded_login_setup' );

/**
 * Make sure confirm_admin_email actions go to the default WP login page.
 * Our login page is not set up to handle them.
 */
function dmrfid_use_default_login_for_confirm_admin_email( $location ) {
	if ( strpos( $location, 'action=confirm_admin_email' ) !== false ) {
		$login_url = wp_login_url();
		
		remove_filter( 'login_url', 'dmrfid_login_url_filter', 50, 2 );
		$default_login_url = wp_login_url();
		add_filter( 'login_url', 'dmrfid_login_url_filter', 50, 2 );
		
		if ( $login_url != $default_login_url ) {
			$location = str_replace( $login_url, $default_login_url, $location );
		}
	}
	
	return $location;
}
add_filter( 'wp_redirect', 'dmrfid_use_default_login_for_confirm_admin_email' );

/**
 * Get a link to the DmRFID login page.
 * Or fallback to WP default.
 * @since 2.3
 *
 * @param string $login_url    The login URL. Not HTML-encoded.
 * @param string $redirect     The path to redirect to on login, if supplied.
 * @param bool   $force_reauth Whether to force reauthorization, even if a cookie is present.
 */
function dmrfid_login_url( $redirect = '', $force_reauth = false ) {
	global $dmrfid_pages;
	
	if ( empty( $dmrfid_pages['login'] ) ) {
		// skip everything, including filter below
		return wp_login_url( $redirect, $force_reauth );
	}
	
	$login_url = get_permalink( $dmrfid_pages['login'] );
 
    if ( ! empty( $redirect ) ) {
        $login_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $login_url );
    }
 
    if ( $force_reauth ) {
        $login_url = add_query_arg( 'reauth', '1', $login_url );
    }
 
    /**
     * Filters the login URL.
     *
     * @since 2.3
     *
     * @param string $login_url    The login URL. Not HTML-encoded.
     * @param string $redirect     The path to redirect to on login, if supplied.
     * @param bool   $force_reauth Whether to force reauthorization, even if a cookie is present.
     */
    return apply_filters( 'dmrfid_login_url', $login_url, $redirect, $force_reauth );
}

/**
 * Get a link to the DmRFID lostpassword page.
 * Or fallback to the WP default.
 * @since 2.3
 *
 * @param string $redirect     The path to redirect to on login, if supplied.
 */
function dmrfid_lostpassword_url( $redirect = '' ) {
    global $dmrfid_pages;
	
	if ( empty( $dmrfid_pages['login'] ) ) {
		// skip everything, including filter below
		return wp_lostpassword_url( $redirect );
	}
	
	$args = array( 'action' => 'lostpassword' );
    if ( ! empty( $redirect ) ) {
        $args['redirect_to'] = urlencode( $redirect );		
    }
 
    $lostpassword_url = add_query_arg( $args, get_permalink( $dmrfid_pages['login'] ) );
 
    /**
     * Filters the Lost Password URL.
     *
     * @since 2.3
     *
     * @param string $lostpassword_url The lost password page URL.
     * @param string $redirect         The path to redirect to on login.
     */
    return apply_filters( 'dmrfid_lostpassword_url', $lostpassword_url, $redirect );
}

/**
 * Add a hidden field to our login form
 * so we can identify it.
 * Hooks into the WP core filter login_form_top.
 */
function dmrfid_login_form_hidden_field( $html ) {
	$html .= '<input type="hidden" name="dmrfid_login_form_used" value="1" />';

	return $html;
}

/**
 * Filter the_title based on the form action of the Log In Page assigned to $dmrfid_pages['login'].
 *
 * @since 2.3
 */
function dmrfid_login_the_title( $title, $id = NULL ) {
	global $dmrfid_pages, $wp_query;

	if ( is_admin() ) {
		return $title;
	}

	if ( isset( $wp_query ) && ( ! is_main_query() || ! is_page( $id ) ) ) {
		return $title;
	}

	if ( empty( $dmrfid_pages ) || empty( $dmrfid_pages['login'] ) || ! is_page( $dmrfid_pages['login'] ) ) {
		return $title;
	}

	if ( is_user_logged_in() ) {
		$title = __( 'Welcome', 'digital-members-rfid' );
	} elseif ( ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] === 'reset_pass' ) {
		$title = __( 'Lost Password', 'digital-members-rfid' );
	} elseif ( ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] === 'rp' ) {
		$title = __( 'Reset Password', 'digital-members-rfid' );
	}

	return $title;
}
add_filter( 'the_title', 'dmrfid_login_the_title', 10, 2 );

/**
 * Filter document_title_parts based on the form action of the Log In Page assigned to $dmrfid_pages['login'].
 *
 * @since 2.3
 */
function dmrfid_login_document_title_parts( $titleparts ) {
	global $dmrfid_pages;

	if ( empty( $dmrfid_pages ) || empty ( $dmrfid_pages['login'] ) || ! is_page( $dmrfid_pages['login'] ) ) {
		return $titleparts;
	}
	
	if ( is_user_logged_in() ) {
		$titleparts['title'] = __( 'Welcome', 'digital-members-rfid' );
	} elseif ( ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] === 'reset_pass' ) {
		$titleparts['title'] = __( 'Lost Password', 'digital-members-rfid' );
	} elseif ( ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] === 'rp' ) {
		$titleparts['title'] = __( 'Reset Password', 'digital-members-rfid' );
	}

	return $titleparts;
}
add_filter( 'document_title_parts', 'dmrfid_login_document_title_parts' );

/**
 * Show a member login form or logged in member widget.
 *
 * @since 2.3
 */
function dmrfid_login_forms_handler( $show_menu = true, $show_logout_link = true, $display_if_logged_in = true, $location = '', $echo = true ) {
	// Don't show widgets on the login page.
	if ( $location === 'widget' && dmrfid_is_login_page() ) {
		return '';
	}
	
	// Set the message return string.
	$message = '';
	$msgt = 'dmrfid_alert';
	if ( isset( $_GET['action'] ) ) {
		switch ( sanitize_text_field( $_GET['action'] ) ) {
			case 'failed':
				$message = __( 'There was a problem with your username or password.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			case 'invalid_username':
				$message = __( 'Unknown username. Check again or try your email address.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			case 'empty_username':
				$message = __( 'Empty username. Please enter your username and try again.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			case 'empty_password':
				$message = __( 'Empty password. Please enter your password and try again.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			case 'incorrect_password':
				$message = __( 'The password you entered for the user is incorrect. Please try again.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			case 'recovered':
				$message = __( 'Check your email for the confirmation link.', 'digital-members-rfid' );
				break;
		}
	}

	// Logged Out Errors.
	if ( isset( $_GET['loggedout'] ) ) {
		switch ( sanitize_text_field( $_GET['loggedout'] ) ) {
			case 'true':
				$message = __( 'You are now logged out.', 'digital-members-rfid' );
				$msgt = 'dmrfid_success';
				break;
			default:
				$message = __( 'There was a problem logging you out.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
		}
	}

	// Password reset email confirmation.
	if ( isset( $_GET['checkemail'] ) ) {

		switch ( sanitize_text_field( $_GET['checkemail'] ) ) {
			case 'confirm':
				$message = __( 'Check your email for a link to reset your password.', 'digital-members-rfid' );
				break;
			default:
				$message = __( 'There was an unexpected error regarding your email. Please try again', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
		}
	}

	// Password errors
	if ( isset( $_GET['login'] ) ) {
		switch ( sanitize_text_field( $_GET['login'] ) ) {
			case 'invalidkey':
				$message = __( 'Your reset password key is invalid.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			case 'expiredkey':
				$message = __( 'Your reset password key is expired, please request a new key from the password reset page.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			default:
			break;

		}
	}

	if ( isset( $_GET['password'] ) ) {
		switch( $_GET['password'] ) {
			case 'changed':
				$message = __( 'Your password has successfully been updated.', 'digital-members-rfid' );
				$msgt = 'dmrfid_success';
				break;
			default:
				$message = __( 'There was a problem updating your password', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
		}
	}

	// Get Errors from password reset.
	if ( isset( $_REQUEST['errors'] ) ) {
		$password_reset_errors = sanitize_text_field( $_REQUEST['errors'] );
	} elseif ( isset( $_REQUEST['error'] ) ) {
		$password_reset_errors = sanitize_text_field( $_REQUEST['error'] );
	}
	if ( isset( $password_reset_errors ) ) {
		switch ( $password_reset_errors ) {
			case 'invalidcombo':
				$message = __( 'There is no account with that username or email address.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			case 'empty_username':
				$message = __( 'Please enter a valid username.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			case 'invalid_email':
				$message = __( "You've entered an invalid email address.", 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			case 'password_reset_mismatch':
				$message = __( 'New passwords do not match.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			case 'password_reset_empty':
				$message = __( 'Please complete all fields.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
			case 'retrieve_password_email_failure':
				$message = __( 'The email could not be sent. This site may not be correctly configured to send emails.', 'digital-members-rfid' );
				$msgt = 'dmrfid_error';
				break;
		}
	}

	ob_start();

	// Note we don't show messages on the widget form.
	if ( $message && $location !== 'widget' ) {
		echo '<div class="' . dmrfid_get_element_class( 'dmrfid_message ' . $msgt, esc_attr( $msgt ) ) . '">'. esc_html( $message ) .'</div>';
	}

	// Get the form title HTML tag.
	if ( $location === 'widget' ) {
		$before_title = '<h3>';
		$after_title = '</h3>';
	} else {
		$before_title = '<h2>';
		$after_title = '</h2>';
	}

	if ( isset( $_REQUEST['action'] ) ) {
		$action = sanitize_text_field( $_REQUEST['action'] );
	} else {
		$action = false;
	}

	// Figure out which login view to show.
	if ( ! is_user_logged_in() ) {			
		if ( ! in_array( $action, array( 'reset_pass', 'rp' ) ) ) {
			// Login form.
			if ( empty( $_GET['login'] ) || empty( $_GET['key'] ) ) {
				$username = isset( $_REQUEST['username'] ) ? sanitize_text_field( $_REQUEST['username'] ) : NULL;
				$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url( $_REQUEST['redirect_to'] ) : NULL;
        
				// Redirect users back to their page that they logged-in from via the widget.  
				if( empty( $redirect_to ) && $location === 'widget' && apply_filters( 'dmrfid_login_widget_redirect_back', true ) ) {		
					$redirect_to = esc_url( site_url( $_SERVER['REQUEST_URI'] ) );
				}
				?>
				<div class="<?php echo dmrfid_get_element_class( 'dmrfid_login_wrap' ); ?>">
					<?php 
						if ( ! dmrfid_is_login_page() ) {
							echo $before_title . esc_html( 'Log In', 'digital-members-rfid' ) . $after_title;
						}
					?>
					<?php
						dmrfid_login_form( array( 'value_username' => esc_html( $username ), 'redirect' => esc_url( $redirect_to ) ) );
						dmrfid_login_forms_handler_nav( 'login' );
					?>
				</div> <!-- end dmrfid_login_wrap -->				
				<?php if ( dmrfid_is_login_page() ) { ?>
				<script>
					document.getElementById('user_login').focus();
				</script>
				<?php } ?>
				
				<?php
			}
		} elseif ( $location !== 'widget' && ( $action === 'reset_pass' || ( $action === 'rp' && in_array( $_REQUEST['login'], array( 'invalidkey', 'expiredkey' ) ) ) ) ) {
			// Reset password form.			
			?>
			<div class="<?php echo dmrfid_get_element_class( 'dmrfid_lost_password_wrap' ); ?>">
				<?php 
					if ( ! dmrfid_is_login_page() ) {
						echo $before_title . esc_html( 'Password Reset', 'digital-members-rfid' ) . $after_title;
					}
				?>
				<p class="<?php echo dmrfid_get_element_class( 'dmrfid_lost_password-instructions' ); ?>">
					<?php
						esc_html_e( 'Please enter your username or email address. You will receive a link to create a new password via email.', 'digital-members-rfid' );
					?>
				</p>
				<?php
					dmrfid_lost_password_form();
					dmrfid_login_forms_handler_nav( 'lost_password' );
				?>
			</div> <!-- end dmrfid_lost_password_wrap -->
			<?php
		} elseif ( $location !== 'widget' && $action === 'rp' ) {
			// Password reset processing key.			
			?>
			<div class="<?php echo dmrfid_get_element_class( 'dmrfid_reset_password_wrap' ); ?>">
				<?php 
					if ( ! dmrfid_is_login_page() ) {
						echo $before_title . esc_html( 'Reset Password', 'digital-members-rfid' ) . $after_title;
					}
				?>
				<?php dmrfid_reset_password_form(); ?>
			</div> <!-- end dmrfid_reset_password_wrap -->
			<?php
		}
	} else {
		// Already signed in.
		if ( isset( $_REQUEST['login'] ) && isset( $_REQUEST['key'] ) ) {
			esc_html_e( 'You are already signed in.', 'digital-members-rfid' );
		} elseif ( ! empty( $display_if_logged_in ) ) { ?>
			<div class="<?php echo dmrfid_get_element_class( 'dmrfid_logged_in_welcome_wrap' ); ?>">
				<?php dmrfid_logged_in_welcome( $show_menu, $show_logout_link ); ?>
			</div> <!-- end dmrfid_logged_in_welcome_wrap -->
			<?php
		}
	}
	
	$content = ob_get_clean();
	if ( $echo ) {
		echo $content;
	}
	
	return $content;
}

/**
 * Generate a login form for front-end login.
 * @since 2.3
 */
function dmrfid_login_form( $args = array() ) {
	add_filter( 'login_form_top', 'dmrfid_login_form_hidden_field' );
	wp_login_form( $args );
	remove_filter( 'login_form_top', 'dmrfid_login_form_hidden_field' );
}

/**
 * Generate a lost password form for front-end login.
 * @since 2.3
 */
function dmrfid_lost_password_form() { ?>
	<form id="lostpasswordform" class="<?php echo dmrfid_get_element_class( 'dmrfid_form', 'lostpasswordform' ); ?>" action="<?php echo wp_lostpassword_url(); ?>" method="post">
		<div class="<?php echo dmrfid_get_element_class( 'dmrfid_lost_password-fields' ); ?>">
			<div class="<?php echo dmrfid_get_element_class( 'dmrfid_lost_password-field dmrfid_lost_password-field-user_login', 'dmrfid_lost_password-field-user_login' ); ?>">
				<label for="user_login"><?php esc_html_e( 'Username or Email Address', 'digital-members-rfid' ); ?></label>
				<input type="text" name="user_login" id="user_login" class="<?php echo dmrfid_get_element_class( 'input', 'user_login' ); ?>" size="20" />
			</div>
		</div> <!-- end dmrfid_lost_password-fields -->
		<div class="<?php echo dmrfid_get_element_class( 'dmrfid_submit' ); ?>">
			<input type="submit" name="submit" class="<?php echo dmrfid_get_element_class( 'dmrfid_btn dmrfid_btn-submit', 'dmrfid_btn-submit' ); ?>" value="<?php esc_attr_e( 'Get New Password', 'digital-members-rfid' ); ?>" />
		</div>
	</form>
	<?php
}

/**
 * Handle the password reset functionality. Redirect back to login form and show message.
 * @since 2.3
 */
function dmrfid_lost_password_redirect() {
	if ( 'POST' == $_SERVER['REQUEST_METHOD'] ) {
		$login_page = dmrfid_getOption( 'login_page_id' );
		
		if ( empty( $login_page ) ) {
			return;
		}
		
		$redirect_url = $login_page ? get_permalink( $login_page ): '';

		$errors = retrieve_password();
		if ( is_wp_error( $errors ) ) {
		$redirect_url = add_query_arg( array( 'errors' => join( ',', $errors->get_error_codes() ), 'action' => urlencode( 'reset_pass' ) ), $redirect_url );
		} else {
			$redirect_url = add_query_arg( array( 'checkemail' => urlencode( 'confirm' ) ), $redirect_url );
		}

		wp_redirect( $redirect_url );
		exit;
	}
}
add_action( 'login_form_lostpassword', 'dmrfid_lost_password_redirect' );

/**
 * Redirect Password reset to our own page.
 * @since 2.3
 */
function dmrfid_reset_password_redirect() {	
	if ( 'GET' == $_SERVER['REQUEST_METHOD'] ) {
		$login_page = dmrfid_getOption( 'login_page_id' );
		
		if ( empty( $login_page ) ) {
			return;
		}
		
		$redirect_url = $login_page ? get_permalink( $login_page ): '';
		$user = check_password_reset_key( $_REQUEST['rp_key'], $_REQUEST['rp_login'] );
		
		if ( ! $user || is_wp_error( $user ) ) {
            if ( $user && $user->get_error_code() === 'expired_key' ) {
				wp_redirect( add_query_arg( 'login', urlencode( 'expiredkey' ), $redirect_url ) );
            } else {
                wp_redirect( add_query_arg( 'login', urlencode( 'invalidkey' ), $redirect_url ));
            }
            exit;
        }

        $redirect_url = add_query_arg( array( 'login' => esc_attr( sanitize_text_field( $_REQUEST['rp_login'] ) ), 'action' => urlencode( 'rp' ) ), $redirect_url );
        $redirect_url = add_query_arg( array( 'key' => esc_attr( sanitize_text_field( $_REQUEST['rp_key'] ) ), 'action' => urlencode( 'rp' ) ), $redirect_url );

        wp_redirect( $redirect_url );
        exit;
	}
}
add_action( 'login_form_rp', 'dmrfid_reset_password_redirect' );
add_action( 'login_form_resetpass', 'dmrfid_reset_password_redirect' );

/**
 * Show the password reset form after user redirects from email link.
 * @since 2.3
 */
function dmrfid_reset_password_form() {
	if ( isset( $_REQUEST['login'] ) && isset( $_REQUEST['key'] ) ) {

		// Error messages
		$errors = array();
		if ( isset( $_REQUEST['error'] ) ) {
			$error_codes = explode( ',', sanitize_text_field( $_REQUEST['error'] ) );
		} ?>
		<form name="resetpassform" id="resetpassform" class="<?php echo dmrfid_get_element_class( 'dmrfid_form', 'resetpassform' ); ?>" action="<?php echo esc_url( site_url( 'wp-login.php?action=resetpass' ) ); ?>" method="post" autocomplete="off">
			<input type="hidden" id="user_login" name="rp_login" value="<?php echo esc_attr( sanitize_text_field( $_REQUEST['login'] ) ); ?>" autocomplete="off" />
			<input type="hidden" name="rp_key" value="<?php echo esc_attr( sanitize_text_field( $_REQUEST['key'] ) ); ?>" />
			<div class="<?php echo dmrfid_get_element_class( 'dmrfid_reset_password-fields' ); ?>">
				<div class="<?php echo dmrfid_get_element_class( 'dmrfid_reset_password-field dmrfid_reset_password-field-pass1', 'dmrfid_reset_password-field-pass1' ); ?>">
					<label for="pass1"><?php esc_html_e( 'New Password', 'digital-members-rfid' ) ?></label>
					<input type="password" name="pass1" id="pass1" class="<?php echo dmrfid_get_element_class( 'input pass1', 'pass1' ); ?>" size="20" value="" autocomplete="off" />
					<div id="pass-strength-result" class="hide-if-no-js" aria-live="polite"><?php _e( 'Strength Indicator', 'digital-members-rfid' ); ?></div>
					<p class="<?php echo dmrfid_get_element_class( 'lite' ); ?>"><?php echo wp_get_password_hint(); ?></p>
				</div>
				<div class="<?php echo dmrfid_get_element_class( 'dmrfid_reset_password-field dmrfid_reset_password-field-pass2', 'dmrfid_reset_password-field-pass2' ); ?>">
					<label for="pass2"><?php esc_html_e( 'Confirm New Password', 'digital-members-rfid' ) ?></label>
					<input type="password" name="pass2" id="pass2" class="<?php echo dmrfid_get_element_class( 'input', 'pass2' ); ?>" size="20" value="" autocomplete="off" />
				</div>
			</div> <!-- end dmrfid_reset_password-fields -->
			<div class="<?php echo dmrfid_get_element_class( 'dmrfid_submit' ); ?>">
				<input type="submit" name="submit" id="resetpass-button" class="<?php echo dmrfid_get_element_class( 'dmrfid_btn dmrfid_btn-submit', 'dmrfid_btn-submit' ); ?>" value="<?php esc_attr_e( 'Reset Password', 'digital-members-rfid' ); ?>" />
			</div>
		</form>
		<?php
	}
}

/**
 * Show the nav links below the login form.
 */
function dmrfid_login_forms_handler_nav( $dmrfid_form ) { ?>
	<hr />
	<p class="<?php echo dmrfid_get_element_class( 'dmrfid_actions_nav' ); ?>">
		<?php
			// Build the links to return.
			$links = array();

			if ( $dmrfid_form != 'login' ) {
				$links['login'] = sprintf( '<a href="%s">%s</a>', esc_url( dmrfid_login_url() ), esc_html__( 'Log In', 'digital-members-rfid' ) );
			}

			if ( apply_filters( 'dmrfid_show_register_link', get_option( 'users_can_register' ) ) ) {
				$levels_page_id = dmrfid_getOption( 'levels_page_id' );

				if ( $levels_page_id && dmrfid_are_any_visible_levels() ) {
					$links['register'] = sprintf( '<a href="%s">%s</a>', esc_url( dmrfid_url( 'levels' ) ), esc_html__( 'Join Now', 'digital-members-rfid' ) );
				} else {
					$links['register'] = sprintf( '<a href="%s">%s</a>', esc_url( wp_registration_url() ), esc_html__( 'Register', 'digital-members-rfid' ) );
				}
			}

			if ( $dmrfid_form != 'lost_password' ) {
				$links['lost_password'] = sprintf( '<a href="%s">%s</a>', esc_url( add_query_arg( 'action', urlencode( 'reset_pass' ), dmrfid_login_url() ) ), esc_html__( 'Lost Password?', 'digital-members-rfid' ) );
			}

			$links = apply_filters( 'dmrfid_login_forms_handler_nav', $links, $dmrfid_form );

			$allowed_html = array(
				'a' => array (
					'class' => array(),
					'href' => array(),
					'id' => array(),
					'target' => array(),
					'title' => array(),
				),
			);
			echo wp_kses( implode( dmrfid_actions_nav_separator(), $links ), $allowed_html );
		?>
	</p> <!-- end dmrfid_actions_nav -->
	<?php
}

/**
 * Function to handle the actualy password reset and update password.
 * @since 2.3
 */
function dmrfid_do_password_reset() {
    if ( 'POST' == $_SERVER['REQUEST_METHOD'] ) {
        $login_page = dmrfid_getOption( 'login_page_id' );
		
		if ( empty( $login_page ) ) {
			return;
		}
		
		$rp_key = sanitize_text_field( $_REQUEST['rp_key'] );
		$rp_login = sanitize_text_field( $_REQUEST['rp_login'] );	
		
		$redirect_url = $login_page ? get_permalink( $login_page ): '';
		$user = check_password_reset_key( $rp_key, $rp_login );

        if ( ! $user || is_wp_error( $user ) ) {
            if ( $user && $user->get_error_code() === 'expired_key' ) {
				wp_redirect( add_query_arg( array( 'login' => urlencode( 'expiredkey' ), 'action' => urlencode( 'rp' ) ), $redirect_url ) );
            } else {
                wp_redirect( add_query_arg( array( 'login' => urlencode( 'invalidkey' ), 'action' => urlencode( 'rp' ) ), $redirect_url ) );
            }
            exit;
        }

        if ( isset( $_POST['pass1'] ) ) {
            if ( $_POST['pass1'] != $_POST['pass2'] ) {
				// Passwords don't match
				$redirect_url = add_query_arg( array(
					'key' => urlencode( $rp_key ),
					'login' => urlencode( $rp_login ),
					'error' => urlencode( 'password_reset_mismatch' ),
					'action' => urlencode( 'rp' )
				), $redirect_url );

                wp_redirect( $redirect_url );
                exit;
            }

            if ( empty( $_POST['pass1'] ) ) {
				// Password is empty
				$redirect_url = add_query_arg( array(
					'key' => urlencode( $rp_key ),
					'login' => urlencode( $rp_login ),
					'error' => urlencode( 'password_reset_empty' ),
					'action' => urlencode( 'rp' )
				), $redirect_url );

                wp_redirect( $redirect_url );
                exit;
            }

            // Parameter checks OK, reset password
            reset_password( $user, $_POST['pass1'] );
            wp_redirect( add_query_arg( urlencode( 'password' ), urlencode( 'changed' ), $redirect_url ) );
        } else {
           esc_html_e( 'Invalid Request', 'digital-members-rfid' );
        }

        exit;
    }
}
add_action( 'login_form_rp', 'dmrfid_do_password_reset' );
add_action( 'login_form_resetpass', 'dmrfid_do_password_reset' );

/**
 * Replace the default URL inside the password reset email
 * with the membership account page login URL instead.
 *
 * @since 2.3
 */
function dmrfid_password_reset_email_filter( $message, $key, $user_login, $user_data ) {

	$login_page_id = dmrfid_getOption( 'login_page_id' );
    if ( ! empty ( $login_page_id ) ) {
		$login_url = get_permalink( $login_page_id );
		if ( strpos( $login_url, '?' ) ) {
			// Login page permalink contains a '?', so we need to replace the '?' already in the login URL with '&'.
			$message = str_replace( site_url( 'wp-login.php' ) . '?', site_url( 'wp-login.php' ) . '&', $message );
		}
		$message = str_replace( site_url( 'wp-login.php' ), $login_url, $message );
	}

	return $message;
}
add_filter( 'retrieve_password_message', 'dmrfid_password_reset_email_filter', 10, 4 );

/**
 * Replace the default login URL in the new user notification email
 * with the membership account page login URL instead.
 *
 * @since 2.3.4
 */
function dmrfid_new_user_notification_email_filter( $message, $user, $blogname ) {

	$login_page_id = dmrfid_getOption( 'login_page_id' );
    if ( ! empty ( $login_page_id ) ) {
        $login_url = get_permalink( $login_page_id );
		$message = str_replace( network_site_url( 'wp-login.php' ), $login_url, $message );
	}

	return $message;
}
add_filter( 'wp_new_user_notification_email', 'dmrfid_new_user_notification_email_filter', 10, 3 );

/**
 * Authenticate the frontend user login.
 *
 * @since 2.3
 *
 */
 function dmrfid_authenticate_username_password( $user, $username, $password ) {

	// Only work when the DmRFID login form is used.
	if ( empty( $_REQUEST['dmrfid_login_form_used'] ) ) {
		return $user;
	}

	// Already logged in.
	if ( is_a( $user, 'WP_User' ) ) {
		return $user;
	}

	// For some reason, WP core doesn't recognize this error.
	if ( ! empty( $username ) && empty( $password ) ) {
		$user = new WP_Error( 'invalid_username', __( 'There was a problem with your username or password.', 'digital-members-rfid' ) );
	}

	// check what page the login attempt is coming from
	$referrer = wp_get_referer();

	if ( !empty( $referrer ) && is_wp_error( $user ) ) {

		$error = $user->get_error_code();

		if ( $error ) {
				wp_redirect( add_query_arg( 'action', urlencode( $error ), dmrfid_login_url() ) );
			} else {
				wp_redirect( dmrfid_login_url() );
			}
	}

	return $user;
}
add_filter( 'authenticate', 'dmrfid_authenticate_username_password', 30, 3);

/**
 * Redirect failed login to referrer for frontend user login.
 *
 * @since 2.3
 *
 */
function dmrfid_login_failed( $username ) {

	$login_page = dmrfid_getOption( 'login_page_id' );	
	if ( empty( $login_page ) ) {
		return;
	}

	$referrer = wp_get_referer();
	if ( ! empty( $_REQUEST['redirect_to'] ) ) {
		$redirect_to = esc_url( $_REQUEST['redirect_to'] );
	} else {
		$redirect_to = '';
	}

	if ( $referrer && ! strstr( $referrer, 'wp-login' ) && ! strstr( $referrer, 'wp-admin' ) ) {
		if ( ! strstr( $referrer, '?login=failed') ) {
			wp_redirect( add_query_arg( array( 'action'=>'failed', 'username' => sanitize_text_field( $username ), 'redirect_to' => urlencode( $redirect_to ) ), dmrfid_login_url() ) );
		} else {
			wp_redirect( add_query_arg( 'action', 'loggedout', dmrfid_login_url() ) );
		}
		exit;
	}
}
add_action( 'wp_login_failed', 'dmrfid_login_failed', 10, 2 );

/**
 * Show welcome content for a "Logged In" member with Display Name, Log Out link and a "Log In Widget" menu area.
 *
 * @since 2.3
 *
 */
function dmrfid_logged_in_welcome( $show_menu = true, $show_logout_link = true ) {
	if ( is_user_logged_in( ) ) {
		// Set the location the user's display_name will link to based on level status.
		global $current_user, $dmrfid_pages;
		if ( ! empty( $dmrfid_pages ) && ! empty( $dmrfid_pages['account'] ) ) {
			$account_page      = get_post( $dmrfid_pages['account'] );
			$user_account_link = '<a href="' . esc_url( dmrfid_url( 'account' ) ) . '">' . esc_html( preg_replace( '/\@.*/', '', $current_user->display_name ) ) . '</a>';
		} else {
			$user_account_link = '<a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html( preg_replace( '/\@.*/', '', $current_user->display_name ) ) . '</a>';
		}
		?>
		<h3 class="<?php echo dmrfid_get_element_class( 'dmrfid_member_display_name' ); ?>">
			<?php
				/* translators: a generated link to the user's account or profile page */
				printf( esc_html__( 'Welcome, %s', 'digital-members-rfid' ), $user_account_link );
			?>
		</h3>

		<?php do_action( 'dmrfid_logged_in_welcome_before_menu' ); ?>

		<?php
		/**
		 * Show the "Log In Widget" menu to users.
		 * The menu can be customized per level using the Nav Menus Add On for Digital Members RFID.
		 *
		 */
		if ( ! empty( $show_menu ) ) {
			$dmrfid_login_widget_menu_defaults = array(
				'theme_location'  => 'dmrfid-login-widget',
				'container'       => 'nav',
				'container_id'    => 'dmrfid-member-navigation',
				'container_class' => 'dmrfid-member-navigation',
				'fallback_cb'	  => false,
				'items_wrap'      => '<ul id="%1$s" class="%2$s">%3$s</ul>',
			);
			wp_nav_menu( $dmrfid_login_widget_menu_defaults );
		}
		?>

		<?php do_action( 'dmrfid_logged_in_welcome_after_menu' ); ?>

		<?php
		/**
		 * Optionally show a Log Out link.
		 * User will be redirected to the Membership Account page if no other redirect intercepts the process.
		 *
		 */
		if ( ! empty ( $show_logout_link ) ) { ?>
			<div class="<?php echo dmrfid_get_element_class( 'dmrfid_member_log_out' ); ?>"><a href="<?php echo esc_url( wp_logout_url() ); ?>"><?php esc_html_e( 'Log Out', 'digital-members-rfid' ); ?></a></div>
			<?php
		}
	}
}

/**
 * Allow default WordPress registration page if no level page is set and registrations are open for a site.
 * @since 2.3
 */
function dmrfid_no_level_page_register_redirect( $url ) {
	$level = dmrfid_url( 'levels' );

	if ( empty( dmrfid_url( 'levels' ) ) && get_option( 'users_can_register' ) && ! dmrfid_are_any_visible_levels() ) {
		return false;
	}

	return $url;
}
add_action( 'dmrfid_register_redirect', 'dmrfid_no_level_page_register_redirect' );

/**
 * Process Data Request confirmaction URLs.
 * Called from Account page preheader.
 * Checks first for action=confirmaction param.
 * Code pulled from wp-login.php.
 */
function dmrfid_confirmaction_handler() {
	if ( empty( $_REQUEST['action'] ) || $_REQUEST['action'] !== 'confirmaction' ) {
		return false;
	}
	
	if ( ! isset( $_GET['request_id'] ) ) {
		wp_die( __( 'Missing request ID.' ) );
	}

	if ( ! isset( $_GET['confirm_key'] ) ) {
		wp_die( __( 'Missing confirm key.' ) );
	}

	$request_id = (int) $_GET['request_id'];
	$key        = sanitize_text_field( wp_unslash( $_GET['confirm_key'] ) );
	$result     = wp_validate_user_request_key( $request_id, $key );

	if ( is_wp_error( $result ) ) {
		wp_die( $result );
	}

	/** This action is documented in wp-login.php */
	do_action( 'user_request_action_confirmed', $request_id );
}