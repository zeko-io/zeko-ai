<?php
/**
 * Zeko AI front-end AJAX handlers.
 *
 * Powers the public assistant chat, content writer, unified search,
 * recommendations and moderation checker. All authenticated handlers require
 * a logged-in user and verify the shared AI nonce; search is public.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by Zeko_AI_Ajax::respond() when the ajax exit is disabled (test
 * mode). Lets callers capture the JSON payload instead of terminating the
 * request the way wp_send_json() would.
 *
 * @package Zeko_AI
 */
class Zeko_AI_Ajax_Halt extends \Exception {
}
