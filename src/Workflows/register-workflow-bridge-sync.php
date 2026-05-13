<?php
/**
 * Keep cron-triggered workflow schedules in sync with registrations.
 *
 * `wp_register_workflow()` is the declarative API. When a workflow includes
 * a `cron` trigger and Action Scheduler is available, the bridge should be
 * wired automatically the same way routines are.
 *
 * @package AgentsAPI
 * @since   0.107.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_agent_workflow_registered',
	static function ( WP_Agent_Workflow_Spec $spec ): void {
		WP_Agent_Workflow_Action_Scheduler_Bridge::register( $spec );
	}
);
